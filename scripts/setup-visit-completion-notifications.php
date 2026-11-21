<?php
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../config/db.php';if(!in_array('--apply',$argv,true))exit("Use --apply to install completion notification links.\n");
$pdo=getDbConnection();$pdo->exec(file_get_contents(__DIR__.'/../database/migrations/20261121_visit_completion_notifications.sql'));
$message='Your physician finalized the consultation notes. Open My Appointments to confirm that your visit was completed or report an issue.';
$q=$pdo->prepare('SELECT MAX(n.NotificationID) AS NotificationID,r.AppointmentID,r.FinalizedVersionID FROM VisitCompletionReviews r JOIN PatientNotifications n ON n.PatientID=r.PatientID AND n.RelatedAppointmentID=r.AppointmentID WHERE n.Message=? GROUP BY r.AppointmentID,r.FinalizedVersionID');$q->execute([$message]);$count=0;foreach($q->fetchAll() as $r){$s=$pdo->prepare('INSERT IGNORE INTO VisitCompletionNotifications(NotificationID,AppointmentID,FinalizedVersionID) VALUES(?,?,?)');$s->execute([$r['NotificationID'],$r['AppointmentID'],$r['FinalizedVersionID']]);$count+=$s->rowCount();}
echo "Completion notification links installed; $count existing messages linked. No messages sent.\n";
