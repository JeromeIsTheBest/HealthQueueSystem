<?php
require_once __DIR__.'/notifications.php';require_once __DIR__.'/sms.php';require_once __DIR__.'/queue-email.php';require_once __DIR__.'/audit.php';
const HQ_QUEUE_ORDER_SQL="CASE WHEN a.AppointmentTime IS NOT NULL AND TIMESTAMP(a.AppointmentDate,a.AppointmentTime)<=NOW() THEN 0 ELSE 1 END, CASE WHEN a.AppointmentTime IS NOT NULL AND TIMESTAMP(a.AppointmentDate,a.AppointmentTime)<=NOW() THEN TIMESTAMP(a.AppointmentDate,a.AppointmentTime) END, COALESCE(q.Position,q.QueueNumber*10),q.QueueNumber,q.QueueID";
function assertClinicPhysician(PDO $pdo,int $clinicId,?int $physicianId):void
{
 if(!$physicianId)return;
 $stmt=$pdo->prepare("SELECT UserID FROM Users WHERE UserID=? AND ClinicID=? AND Status='Active' AND DeletedAt IS NULL AND RoleID=(SELECT RoleID FROM Roles WHERE RoleName='Physician')");$stmt->execute([$physicianId,$clinicId]);
 if(!$stmt->fetchColumn())throw new RuntimeException('Choose an active physician from your clinic.');
}
function lockQueueClinic(PDO $pdo,int $clinicId):void
{
 $stmt=$pdo->prepare('SELECT ClinicID FROM Clinic WHERE ClinicID=? FOR UPDATE');$stmt->execute([$clinicId]);if(!$stmt->fetchColumn())throw new RuntimeException('Clinic unavailable.');
}
function callNextClinicPatient(PDO $pdo,int $clinicId,int $staffId,?int $physicianId=null):?string
{
 $pdo->beginTransaction();
 try{
  lockQueueClinic($pdo,$clinicId);
  $stmt=$pdo->prepare("SELECT COUNT(*) FROM Queue WHERE ClinicID=? AND DATE(CreatedAt)=CURDATE() AND Status IN ('Calling','Serving')");$stmt->execute([$clinicId]);
  if((int)$stmt->fetchColumn()){$pdo->commit();return null;}
  $sql="SELECT q.QueueID,q.QueueNumber,q.ScheduledNumber,q.RegularNumber,a.PatientID,a.AppointmentID FROM Queue q JOIN Appointments a ON a.AppointmentID=q.AppointmentID WHERE q.ClinicID=? AND DATE(q.CreatedAt)=CURDATE() AND q.Status='Waiting' AND a.Status='Confirmed' AND (a.AppointmentTime IS NULL OR TIMESTAMP(a.AppointmentDate,a.AppointmentTime)<=NOW())";$params=[$clinicId];
  if($physicianId){assertClinicPhysician($pdo,$clinicId,$physicianId);$sql.=' AND q.PhysicianID=?';$params[]=$physicianId;}
  $stmt=$pdo->prepare($sql.' ORDER BY '.HQ_QUEUE_ORDER_SQL.' LIMIT 1 FOR UPDATE');$stmt->execute($params);$entry=$stmt->fetch();
  if(!$entry){$pdo->commit();return null;}
  $pdo->prepare("UPDATE Queue SET Status='Calling',CalledAt=NOW() WHERE QueueID=? AND Status='Waiting'")->execute([$entry['QueueID']]);
  notifyPatient($pdo,(int)$entry['PatientID'],"You're being called now - please proceed to the counter.",(int)$entry['AppointmentID']);
  queuePatientSms($pdo,(int)$entry['PatientID'],(int)$entry['AppointmentID'],'called:'.$entry['QueueID'],'called','HealthQueue: You are being called now. Please proceed to the clinic counter.');
  enqueueQueueEmail($pdo,(int)$entry['QueueID'],(int)$entry['PatientID'],(int)$entry['AppointmentID'],'called');
  $ticket=$entry['ScheduledNumber']!==null?'S-'.(int)$entry['ScheduledNumber']:'#'.(int)($entry['RegularNumber']??$entry['QueueNumber']);
  logActivity($pdo,$staffId,$clinicId,'Updated queue status','Calling queue '.$ticket);$pdo->commit();return $ticket;
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function approveClinicAppointment(PDO $pdo,int $appointmentId,int $clinicId,?int $physicianId,int $staffId):array
{
 $pdo->beginTransaction();
 try{
  lockQueueClinic($pdo,$clinicId);
  $stmt=$pdo->prepare('SELECT PhysicianID,PatientID,AppointmentDate,AppointmentTime,Status,BookingFeePaid FROM Appointments WHERE AppointmentID=? AND ClinicID=? FOR UPDATE');$stmt->execute([$appointmentId,$clinicId]);$a=$stmt->fetch();
  if(!$a||$a['Status']!=='Pending'||!(int)$a['BookingFeePaid'])throw new RuntimeException('This paid request is no longer awaiting approval.');
  if($a['AppointmentDate']<(string)$pdo->query('SELECT CURDATE()')->fetchColumn())throw new RuntimeException('This appointment date has passed. Review its schedule before approval.');
  $physicianId=$physicianId?:($a['PhysicianID']?(int)$a['PhysicianID']:null);if(!$physicianId)throw new RuntimeException('Assign a physician before accepting this request.');assertClinicPhysician($pdo,$clinicId,$physicianId);
  $stmt=$pdo->prepare("SELECT COUNT(*) FROM Queue WHERE AppointmentID=? AND Status IN ('Waiting','Calling','Serving')");$stmt->execute([$appointmentId]);if((int)$stmt->fetchColumn())throw new RuntimeException('This appointment already has an active queue entry.');
  $pdo->prepare("UPDATE Appointments SET Status='Confirmed',PhysicianID=? WHERE AppointmentID=? AND ClinicID=? AND Status='Pending'")->execute([$physicianId,$appointmentId,$clinicId]);
  $stmt=$pdo->prepare('SELECT COALESCE(MAX(QueueNumber),0)+1 FROM Queue WHERE ClinicID=? AND DATE(CreatedAt)=?');$stmt->execute([$clinicId,$a['AppointmentDate']]);$number=(int)$stmt->fetchColumn();
  $column=$a['AppointmentTime']!==null?'ScheduledNumber':'RegularNumber';$stmt=$pdo->prepare("SELECT COALESCE(MAX({$column}),0)+1 FROM Queue WHERE ClinicID=? AND DATE(CreatedAt)=?");$stmt->execute([$clinicId,$a['AppointmentDate']]);$display=(int)$stmt->fetchColumn();
  $pdo->prepare("INSERT INTO Queue(ClinicID,AppointmentID,PhysicianID,QueueNumber,ScheduledNumber,RegularNumber,Status,CreatedByStaffID,CreatedAt) VALUES(?,?,?,?,?,?,'Waiting',?,GREATEST(NOW(),TIMESTAMP(?,COALESCE(?,'00:00:00'))))")->execute([$clinicId,$appointmentId,$physicianId,$number,$column==='ScheduledNumber'?$display:null,$column==='RegularNumber'?$display:null,$staffId,$a['AppointmentDate'],$a['AppointmentTime']]);
  $pdo->commit();$a['ticket']=$column==='ScheduledNumber'?'S-'.$display:'#'.$display;return $a;
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
