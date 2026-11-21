<?php
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../config/db.php';
if(!in_array('--apply',$argv,true))exit("Use --apply to link record access notifications.\n");
$pdo=getDbConnection();$pdo->exec(file_get_contents(__DIR__.'/../database/migrations/20261121_record_access_notifications.sql'));
// Link only exact legacy request messages belonging to the same patient and visit.
$rows=$pdo->query("SELECT r.*,u.FirstName,u.LastName FROM MedicalRecordAccessRequests r JOIN Users u ON u.UserID=r.PhysicianID LEFT JOIN MedicalRecordAccessNotifications m ON m.RequestID=r.RequestID WHERE m.RequestID IS NULL")->fetchAll();$count=0;
foreach($rows as $r){$message='Dr. '.$r['FirstName'].' '.$r['LastName'].' requests access to your uploaded medical records for this consultation. Open any patient page and choose Review request to select files to share.';$q=$pdo->prepare('SELECT NotificationID FROM PatientNotifications WHERE PatientID=? AND RelatedAppointmentID=? AND Message=? AND CreatedAt BETWEEN ? AND DATE_ADD(?,INTERVAL 1 MINUTE) ORDER BY NotificationID LIMIT 1');$q->execute([$r['PatientID'],$r['AppointmentID'],$message,$r['RequestedAt'],$r['RequestedAt']]);if($id=$q->fetchColumn()){$pdo->prepare('INSERT IGNORE INTO MedicalRecordAccessNotifications(NotificationID,RequestID) VALUES(?,?)')->execute([$id,$r['RequestID']]);$count++;}}
echo "Record access notification links installed; $count existing messages linked. No messages sent.\n";
