<?php
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../config/db.php';require __DIR__.'/../includes/queue-actions.php';
if(!in_array('--apply',$argv,true)){echo "Use --apply to install patient completion confirmation and request confirmation for existing finalized, uncompleted visits.\n";exit;}
$pdo=getDbConnection();if(!$pdo)exit(1);
$pdo->exec(file_get_contents(__DIR__.'/../database/migrations/20261120_patient_visit_confirmation.sql'));
$rows=$pdo->query("SELECT AppointmentID,ClinicID FROM Appointments WHERE Status='Confirmed' ORDER BY AppointmentID")->fetchAll();$count=0;
foreach($rows as $r){if(!latestFinalizedVisitVersion($pdo,(int)$r['AppointmentID']))continue;$pdo->beginTransaction();try{lockQueueClinic($pdo,(int)$r['ClinicID']);requestVisitConfirmation($pdo,(int)$r['AppointmentID']);$pdo->commit();$count++;}catch(Throwable $e){$pdo->rollBack();throw $e;}}
echo "Completion review installed; finalized uncompleted visits reviewed: $count. No visits completed or earnings released. No SMS/email sent.\n";
