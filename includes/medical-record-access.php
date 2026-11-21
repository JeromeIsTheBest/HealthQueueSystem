<?php
require_once __DIR__.'/notifications.php';
require_once __DIR__.'/audit.php';
function recordAccessActor(PDO $pdo,int $id,string $role):void {
 $q=$pdo->prepare("SELECT COUNT(*) FROM Users u JOIN Roles r ON r.RoleID=u.RoleID WHERE u.UserID=? AND r.RoleName=? AND u.Status='Active' AND u.DeletedAt IS NULL");$q->execute([$id,$role]);if(!$q->fetchColumn())throw new RuntimeException('Record access is not available for this account.');
}
/** Active access ends on the FIRST finalization, even if a later revision is drafted. */
function recordAccessLiveSql(string $a='a'):string {
 return "$a.Status='Confirmed' AND EXISTS(SELECT 1 FROM Queue q WHERE q.AppointmentID=$a.AppointmentID AND q.PhysicianID=$a.PhysicianID AND q.Status IN ('Calling','Serving')) AND NOT EXISTS(SELECT 1 FROM Consultations c JOIN ConsultationVersions v ON v.ConsultationID=c.ConsultationID WHERE c.AppointmentID=$a.AppointmentID AND v.Status='Finalized')";
}
function recordAccessVisit(PDO $pdo,int $appointmentId,int $actor,string $role,bool $lock=false):array {
 recordAccessActor($pdo,$actor,$role);$column=$role==='Patient'?'PatientID':'PhysicianID';$clinicGuard=$role==='Physician'?' AND a.ClinicID=(SELECT ClinicID FROM Users WHERE UserID=a.PhysicianID)':'';$q=$pdo->prepare("SELECT a.* FROM Appointments a WHERE a.AppointmentID=? AND a.$column=?$clinicGuard".($lock?' FOR UPDATE':''));$q->execute([$appointmentId,$actor]);$a=$q->fetch();if(!$a)throw new RuntimeException('Consultation not found.');return $a;
}
function recordAccessIsLive(PDO $pdo,int $id):bool {$q=$pdo->prepare('SELECT COUNT(*) FROM Appointments a WHERE a.AppointmentID=? AND '.recordAccessLiveSql());$q->execute([$id]);return (bool)$q->fetchColumn();}
function requestMedicalRecordAccess(PDO $pdo,int $doctor,int $appointment):int {
 $pdo->beginTransaction();try{
  $a=recordAccessVisit($pdo,$appointment,$doctor,'Physician',true);if(!recordAccessIsLive($pdo,$appointment))throw new RuntimeException('Record sharing is available only during an active, unfinalized consultation.');
  $q=$pdo->prepare('SELECT RequestID FROM MedicalRecordAccessRequests WHERE AppointmentID=? AND PhysicianID=? FOR UPDATE');$q->execute([$appointment,$doctor]);if($id=$q->fetchColumn()){$pdo->commit();return (int)$id;}
  $pdo->prepare('INSERT INTO MedicalRecordAccessRequests(AppointmentID,PatientID,PhysicianID) VALUES(?,?,?)')->execute([$appointment,$a['PatientID'],$doctor]);$id=(int)$pdo->lastInsertId();
  $q=$pdo->prepare('SELECT FirstName,LastName FROM Users WHERE UserID=?');$q->execute([$doctor]);$d=$q->fetch();
  if(patientAllowsNotification($pdo,(int)$a['PatientID'],'in_app_notifications')){
   $message='Dr. '.$d['FirstName'].' '.$d['LastName'].' requests permission to view your uploaded medical records for this consultation. Choose Review request to select the files you want to share.';
   $pdo->prepare('INSERT INTO PatientNotifications(PatientID,Message,RelatedAppointmentID) VALUES(?,?,?)')->execute([$a['PatientID'],$message,$appointment]);$noteId=(int)$pdo->lastInsertId();
   $pdo->prepare('INSERT INTO MedicalRecordAccessNotifications(NotificationID,RequestID) VALUES(?,?)')->execute([$noteId,$id]);
  }
  logActivity($pdo,$doctor,(int)$a['ClinicID'],'Requested medical record access','Appointment #'.$appointment);$pdo->commit();return $id;
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function respondMedicalRecordAccess(PDO $pdo,int $patient,int $request,string $action,array $records=[],?string $expectedSelection=null):void {
 if(!in_array($action,['approve','decline','revoke','update'],true))throw new RuntimeException('Choose a valid response.');
 recordAccessActor($pdo,$patient,'Patient');$q=$pdo->prepare('SELECT AppointmentID FROM MedicalRecordAccessRequests WHERE RequestID=? AND PatientID=?');$q->execute([$request,$patient]);$appointment=$q->fetchColumn();if(!$appointment)throw new RuntimeException('Request not found.');
 $pdo->beginTransaction();try{
  $a=recordAccessVisit($pdo,(int)$appointment,$patient,'Patient',true);$q=$pdo->prepare('SELECT * FROM MedicalRecordAccessRequests WHERE RequestID=? AND PatientID=? FOR UPDATE');$q->execute([$request,$patient]);$r=$q->fetch();
  if($action==='revoke'){
   if($r['Status']==='Approved'){$pdo->prepare("UPDATE MedicalRecordAccessRequests SET Status='Revoked',EndedAt=NOW() WHERE RequestID=?")->execute([$request]);logActivity($pdo,$patient,(int)$a['ClinicID'],'Revoked medical record access','Request #'.$request);}
   $pdo->commit();return;
  }
  if(!recordAccessIsLive($pdo,(int)$appointment)||(int)$r['PhysicianID']!==(int)$a['PhysicianID'])throw new RuntimeException('This access request has expired.');
  if($action==='update'&&$r['Status']!=='Approved')throw new RuntimeException('Only an active approved request can be updated.');
  if($action!=='update'&&$r['Status']!=='Pending')throw new RuntimeException('This request has already been answered.');
  if(in_array($action,['approve','update'],true)){
   if(!$records||count($records)>500)throw new RuntimeException('Select at least one file to share (maximum 500).');
   $ids=[];foreach($records as $v){if(!is_scalar($v)||!ctype_digit((string)$v)||(int)$v<1)throw new RuntimeException('Choose valid records.');$ids[]=(int)$v;}$ids=array_values(array_unique($ids));sort($ids);
   $q=$pdo->prepare('SELECT RecordID FROM MedicalRecords WHERE PatientID=? AND RecordID IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY RecordID FOR UPDATE');$q->execute(array_merge([$patient],$ids));$found=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));if($ids!==$found)throw new RuntimeException('One or more selected files are not available in your records.');
   if($action==='update'){
    $current=$pdo->prepare('SELECT RecordID FROM MedicalRecordAccessFiles WHERE RequestID=? ORDER BY RecordID FOR UPDATE');$current->execute([$request]);$oldIds=array_map('intval',$current->fetchAll(PDO::FETCH_COLUMN));
    if($expectedSelection===null||!hash_equals(hash('sha256',implode(',',$oldIds)),$expectedSelection))throw new RuntimeException('Your shared records changed. Close and reopen this request before saving again.');
    $pdo->prepare('DELETE FROM MedicalRecordAccessFiles WHERE RequestID=?')->execute([$request]);
   }
   $q=$pdo->prepare('INSERT INTO MedicalRecordAccessFiles(RequestID,RecordID) VALUES(?,?)');foreach($ids as $id)$q->execute([$request,$id]);
  }
  $status=in_array($action,['approve','update'],true)?'Approved':'Declined';$pdo->prepare('UPDATE MedicalRecordAccessRequests SET Status=?,RespondedAt=NOW() WHERE RequestID=?')->execute([$status,$request]);logActivity($pdo,$patient,(int)$a['ClinicID'],($action==='update'?'Updated':$status).' medical record access','Request #'.$request.(isset($ids)?'; selected record IDs: '.implode(',',$ids):''));$pdo->commit();
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function physicianSharedRecords(PDO $pdo,int $doctor,int $appointment):array {
 recordAccessVisit($pdo,$appointment,$doctor,'Physician');if(!recordAccessIsLive($pdo,$appointment))return [];
 $q=$pdo->prepare("SELECT m.RecordID,m.Title,m.Category,m.UploadedAt FROM MedicalRecordAccessRequests r JOIN MedicalRecordAccessFiles f ON f.RequestID=r.RequestID JOIN MedicalRecords m ON m.RecordID=f.RecordID JOIN Appointments a ON a.AppointmentID=r.AppointmentID WHERE r.AppointmentID=? AND r.PhysicianID=? AND r.Status='Approved' AND a.PhysicianID=r.PhysicianID AND m.PatientID=a.PatientID AND r.PatientID=a.PatientID");$q->execute([$appointment,$doctor]);return $q->fetchAll();
}
function physicianRecordAccessState(PDO $pdo,int $doctor,int $appointment):array {
 recordAccessVisit($pdo,$appointment,$doctor,'Physician');if(!recordAccessIsLive($pdo,$appointment))return ['status'=>'Expired','files'=>[]];
 $q=$pdo->prepare('SELECT Status FROM MedicalRecordAccessRequests WHERE AppointmentID=? AND PhysicianID=?');$q->execute([$appointment,$doctor]);return ['status'=>$q->fetchColumn()?:'Locked','files'=>physicianSharedRecords($pdo,$doctor,$appointment)];
}
function patientRecordAccessRequests(PDO $pdo,int $patient):array {
 recordAccessActor($pdo,$patient,'Patient');$live=recordAccessLiveSql();$q=$pdo->prepare("SELECT r.RequestID,r.Status,r.AppointmentID,CONCAT('Dr. ',u.FirstName,' ',u.LastName) AS Physician,c.ClinicName FROM MedicalRecordAccessRequests r JOIN Appointments a ON a.AppointmentID=r.AppointmentID JOIN Users u ON u.UserID=r.PhysicianID JOIN Clinic c ON c.ClinicID=a.ClinicID WHERE r.PatientID=? AND a.PatientID=r.PatientID AND a.PhysicianID=r.PhysicianID AND r.Status IN ('Pending','Approved') AND $live ORDER BY (r.Status='Pending') DESC,r.RequestedAt DESC LIMIT 20");$q->execute([$patient]);$requests=$q->fetchAll();
 foreach($requests as &$request){$f=$pdo->prepare('SELECT RecordID FROM MedicalRecordAccessFiles WHERE RequestID=?');$f->execute([$request['RequestID']]);$request['SharedRecordIDs']=array_map('intval',$f->fetchAll(PDO::FETCH_COLUMN));sort($request['SharedRecordIDs']);$request['GrantVersion']=hash('sha256',implode(',',$request['SharedRecordIDs']));}unset($request);
 $q=$pdo->prepare('SELECT RecordID,Title,Category FROM MedicalRecords WHERE PatientID=? ORDER BY UploadedAt DESC');$q->execute([$patient]);return ['requests'=>$requests,'files'=>$q->fetchAll()];
}
/** Every read of bytes is authorized, not just the list or View button. */
function authorizedPhysicianRecord(PDO $pdo,int $doctor,int $appointment,int $record):array {
 $files=physicianSharedRecords($pdo,$doctor,$appointment);if(!in_array($record,array_map('intval',array_column($files,'RecordID')),true))throw new RuntimeException('Patient approval is required for this file.');
 $q=$pdo->prepare('SELECT m.FilePath,m.FileType,m.Title,a.ClinicID FROM MedicalRecords m JOIN Appointments a ON a.PatientID=m.PatientID WHERE m.RecordID=? AND a.AppointmentID=? AND a.PhysicianID=?');$q->execute([$record,$appointment,$doctor]);$row=$q->fetch();if(!$row)throw new RuntimeException('Record not found.');logActivity($pdo,$doctor,(int)$row['ClinicID'],'Viewed shared medical record','Appointment #'.$appointment.', record #'.$record);return $row;
}
function expireMedicalRecordAccess(PDO $pdo,int $appointment):void {
 if(!$pdo->inTransaction())throw new LogicException('Access expiry requires the finalization transaction.');
 $pdo->prepare("UPDATE MedicalRecordAccessRequests SET Status='Expired',EndedAt=NOW() WHERE AppointmentID=? AND Status IN ('Pending','Approved')")->execute([$appointment]);
}
