<?php
require_once __DIR__.'/notifications.php';
require_once __DIR__.'/audit.php';
require_once __DIR__.'/medical-record-access.php';
function latestFinalizedVisitVersion(PDO $pdo,int $appointmentId):?int
{
 $s=$pdo->prepare('SELECT v.VersionID,v.Status FROM ConsultationVersions v JOIN Consultations c ON c.ConsultationID=v.ConsultationID WHERE c.AppointmentID=? ORDER BY v.RevisionNumber DESC,v.VersionID DESC LIMIT 1');$s->execute([$appointmentId]);$v=$s->fetch();return $v&&$v['Status']==='Finalized'?(int)$v['VersionID']:null;
}
/** Caller owns finalization transaction and the appointment lock. No completion or earnings here. */
function requestVisitConfirmation(PDO $pdo,int $appointmentId):void
{
 if(!$pdo->inTransaction())throw new LogicException('Finalization transaction required.');
 $s=$pdo->prepare('SELECT AppointmentID,PatientID,ClinicID,Status FROM Appointments WHERE AppointmentID=? FOR UPDATE');$s->execute([$appointmentId]);$a=$s->fetch();if(!$a||$a['Status']!=='Confirmed')return;
 $version=latestFinalizedVisitVersion($pdo,$appointmentId);if(!$version)throw new RuntimeException('Finalize the latest notes before requesting patient confirmation.');
 expireMedicalRecordAccess($pdo,$appointmentId);
 $s=$pdo->prepare('SELECT * FROM VisitCompletionReviews WHERE AppointmentID=? FOR UPDATE');$s->execute([$appointmentId]);$old=$s->fetch();$changed=!$old||(int)$old['FinalizedVersionID']!==$version;
 if(!$old)$pdo->prepare('INSERT INTO VisitCompletionReviews(AppointmentID,PatientID,ClinicID,FinalizedVersionID) VALUES(?,?,?,?)')->execute([$appointmentId,$a['PatientID'],$a['ClinicID'],$version]);
 else $pdo->prepare('UPDATE VisitCompletionReviews SET FinalizedVersionID=? WHERE AppointmentID=?')->execute([$version,$appointmentId]);
 // The clinical slot is free; financial completion still waits for Patient/Staff confirmation.
 $pdo->prepare("UPDATE Queue SET Status='AwaitingConfirmation' WHERE AppointmentID=? AND Status IN ('Waiting','Calling','Serving')")->execute([$appointmentId]);
 if($changed&&(!$old||$old['Status']==='AwaitingPatient')&&patientAllowsNotification($pdo,(int)$a['PatientID'],'in_app_notifications')){
  $pdo->prepare('INSERT INTO PatientNotifications(PatientID,Message,RelatedAppointmentID) VALUES(?,?,?)')->execute([$a['PatientID'],'Your physician finalized the consultation notes. Review your visit to confirm completion or report an issue. Opening this message does not confirm the consultation.',$appointmentId]);$notification=(int)$pdo->lastInsertId();
  $pdo->prepare('INSERT INTO VisitCompletionNotifications(NotificationID,AppointmentID,FinalizedVersionID) VALUES(?,?,?)')->execute([$notification,$appointmentId,$version]);
 }

}
/** All completion routes hold clinic then appointment locks before entering here. */
function finishVerifiedVisit(PDO $pdo,array $a,int $actorId,string $source,string $resolution=''):void
{
 $id=(int)$a['AppointmentID'];$version=latestFinalizedVisitVersion($pdo,$id);if(!$version)throw new RuntimeException('The latest consultation note must be finalized before completing this visit.');
 $s=$pdo->prepare('SELECT * FROM VisitCompletionReviews WHERE AppointmentID=? FOR UPDATE');$s->execute([$id]);$review=$s->fetch();
 if($source==='Patient'&&(!$review||$review['Status']!=='AwaitingPatient'||(int)$review['FinalizedVersionID']!==$version))throw new RuntimeException('This visit needs Staff review before it can be confirmed.');
 if($source==='Staff'&&$review&&$review['Status']==='IssueReported'&&mb_strlen(trim($resolution))<10)throw new RuntimeException('Describe how the patient report was reviewed before confirming completion (at least 10 characters).');
 if(mb_strlen($resolution)>2000)throw new RuntimeException('Review notes must be 2,000 characters or fewer.');
 if(!$review)$pdo->prepare('INSERT INTO VisitCompletionReviews(AppointmentID,PatientID,ClinicID,FinalizedVersionID) VALUES(?,?,?,?)')->execute([$id,$a['PatientID'],$a['ClinicID'],$version]);
 $pdo->prepare('UPDATE VisitCompletionReviews SET Status=?,FinalizedVersionID=?,CompletedAt=NOW(),CompletedByUserID=?,CompletionSource=?,StaffResolution=? WHERE AppointmentID=?')->execute([$source==='Patient'?'PatientConfirmed':'StaffConfirmed',$version,$actorId,$source,$resolution?:null,$id]);
 $pdo->prepare("UPDATE Appointments SET Status='Completed' WHERE AppointmentID=?")->execute([$id]);
 $pdo->prepare("UPDATE Queue SET Status='Completed' WHERE AppointmentID=? AND Status IN ('Waiting','Calling','Serving','AwaitingConfirmation')")->execute([$id]);
 $pdo->prepare("UPDATE AppointmentRescheduleRequests SET Status='Withdrawn',ReviewedAt=NOW() WHERE AppointmentID=? AND Status='Pending'")->execute([$id]);
 recordCompletedDemoPayment($pdo,$id);
 notifyPatient($pdo,(int)$a['PatientID'],($source==='Staff'&&$review&&$review['Status']==='IssueReported'?'The clinic reviewed your report. Outcome: '.$resolution.'. ':'').'Your consultation is confirmed complete. Your finalized medical notes are ready to view.',$id);
 logActivity($pdo,$actorId,(int)$a['ClinicID'],'Confirmed consultation complete','Appointment #'.$id.'; source '.$source.($resolution!==''?'; reviewed report: '.$resolution:''));
}
function respondToVisitCompletion(PDO $pdo,int $patientId,int $appointmentId,string $action,string $issue=''):bool
{
 if(!in_array($action,['confirm','issue'],true))throw new RuntimeException('Choose confirm or report an issue.');
 $issue=trim($issue);if($action==='issue'&&(mb_strlen($issue)<10||mb_strlen($issue)>2000))throw new RuntimeException('Describe the issue using 10 to 2,000 characters.');
 $s=$pdo->prepare('SELECT ClinicID FROM Appointments WHERE AppointmentID=? AND PatientID=?');$s->execute([$appointmentId,$patientId]);$clinic=(int)$s->fetchColumn();if(!$clinic)throw new RuntimeException('Consultation unavailable.');
 $pdo->beginTransaction();try{
  lockQueueClinic($pdo,$clinic);
  $s=$pdo->prepare("SELECT 1 FROM Users u JOIN Roles r ON r.RoleID=u.RoleID WHERE u.UserID=? AND r.RoleName='Patient' AND u.Status='Active' AND u.DeletedAt IS NULL");$s->execute([$patientId]);if(!$s->fetchColumn())throw new RuntimeException('Patient access required.');
  $s=$pdo->prepare('SELECT * FROM Appointments WHERE AppointmentID=? AND PatientID=? FOR UPDATE');$s->execute([$appointmentId,$patientId]);$a=$s->fetch();if($a['Status']==='Completed'){if($action==='issue')throw new RuntimeException('This visit has already been completed. Contact the clinic about your concern.');$pdo->commit();return false;}
  if($a['Status']!=='Confirmed')throw new RuntimeException('This visit is no longer awaiting completion.');
  $version=latestFinalizedVisitVersion($pdo,$appointmentId);$s=$pdo->prepare('SELECT * FROM VisitCompletionReviews WHERE AppointmentID=? FOR UPDATE');$s->execute([$appointmentId]);$review=$s->fetch();
  if(!$version||!$review||(int)$review['FinalizedVersionID']!==$version)throw new RuntimeException('Please wait for your physician to finalize the latest notes.');
  if($review['Status']==='IssueReported'){$pdo->commit();if($action==='issue')return false;throw new RuntimeException('Staff must review the issue you reported before completing this visit.');}
  if($review['Status']!=='AwaitingPatient')throw new RuntimeException('This response has already been processed.');
  if($action==='confirm'){$pdo->prepare('UPDATE VisitCompletionReviews SET PatientRespondedAt=NOW() WHERE AppointmentID=?')->execute([$appointmentId]);finishVerifiedVisit($pdo,$a,$patientId,'Patient');notifyClinic($pdo,$clinic,'A patient confirmed consultation #'.$appointmentId.' as completed.',$appointmentId);}
  else{$pdo->prepare("UPDATE VisitCompletionReviews SET Status='IssueReported',IssueText=?,PatientRespondedAt=NOW() WHERE AppointmentID=?")->execute([$issue,$appointmentId]);notifyClinic($pdo,$clinic,'Patient reported an issue for consultation #'.$appointmentId.'. Review Appointments → To confirm before completing the visit.',$appointmentId);logActivity($pdo,$patientId,$clinic,'Reported consultation completion issue','Appointment #'.$appointmentId);}
  $pdo->commit();return true;
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

/** Read-only reminder feed; no completion, payment, or notification mutations. */
function pendingPatientVisitConfirmations(PDO $pdo,int $patient):array {
 recordAccessActor($pdo,$patient,'Patient');
 $q=$pdo->prepare("SELECT a.AppointmentID,a.AppointmentDate,c.ClinicName,CONCAT('Dr. ',u.FirstName,' ',u.LastName) AS Physician,r.FinalizedVersionID
 FROM VisitCompletionReviews r JOIN Appointments a ON a.AppointmentID=r.AppointmentID AND a.PatientID=r.PatientID JOIN Clinic c ON c.ClinicID=a.ClinicID JOIN Users u ON u.UserID=a.PhysicianID
 JOIN Consultations co ON co.AppointmentID=a.AppointmentID JOIN ConsultationVersions v ON v.VersionID=r.FinalizedVersionID AND v.ConsultationID=co.ConsultationID
 WHERE r.PatientID=? AND r.Status='AwaitingPatient' AND a.Status='Confirmed' AND v.Status='Finalized'
 AND v.VersionID=(SELECT latest.VersionID FROM ConsultationVersions latest WHERE latest.ConsultationID=co.ConsultationID ORDER BY latest.RevisionNumber DESC,latest.VersionID DESC LIMIT 1)
 ORDER BY r.RequestedAt DESC LIMIT 20");$q->execute([$patient]);return $q->fetchAll();
}
