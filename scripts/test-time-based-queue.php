<?php
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../config/db.php';require __DIR__.'/../includes/queue-actions.php';
putenv('HQ_SMS_ENABLED=0');$pdo=getDbConnection();
foreach($pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN) as $t){$ddl=$pdo->query('SHOW CREATE TABLE `'.$t.'`')->fetch(PDO::FETCH_NUM)[1];$ddl=preg_replace('/^CREATE TABLE/','CREATE TEMPORARY TABLE',$ddl);$ddl=preg_replace('/^.*CONSTRAINT.*FOREIGN KEY.*\n/m','',$ddl);$ddl=preg_replace('/,\s*\)/',')',$ddl);$pdo->exec($ddl);}
function tqCheck(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n";}
function tqReject(callable $fn,string $label):void{try{$fn();}catch(RuntimeException $e){if($e instanceof PDOException)throw $e;tqCheck(true,$label);return;}throw new RuntimeException($label);}
$date=date('Y-m-d');function tqClock(PDO $p,string $time):DateTimeImmutable{global $date;$now=new DateTimeImmutable($date.' '.$time);$p->exec('SET timestamp='.$now->getTimestamp());return $now;}
tqClock($pdo,'15:30:00');
$pdo->exec("INSERT INTO Roles VALUES(1,'Patient'),(2,'Physician'),(3,'Staff');INSERT INTO Clinic(ClinicID,ClinicName,Address,ContactNumber,BaseConsultationFee,OpenDays,OpenTime,CloseTime) VALUES(1,'Fixture','Fixture','09000000000',500,'1,2,3,4,5,6,7','08:00','18:00')");
$s=$pdo->prepare("INSERT INTO Users(UserID,RoleID,ClinicID,FirstName,LastName,Email,PasswordHash) VALUES(?,?,?,'Fixture','User',?,'fixture')");foreach([1,2,3,4,5,10,11,20] as $id)$s->execute([$id,$id===20?3:($id>=10?2:1),$id>=10?1:null,'fixture'.$id.'@example.test']);
foreach([10,11] as $doctor)$pdo->prepare("INSERT INTO PhysicianDateAvailability(PhysicianID,AvailDate,StartTime,EndTime) VALUES(?,?,'08:00','18:00')")->execute([$doctor,$date]);
foreach([[1,1,10,null],[2,2,10,'16:00:00'],[3,3,10,null],[4,4,10,null],[5,5,11,null]] as [$id,$patient,$doctor,$time]){
 $pdo->prepare("INSERT INTO Appointments(AppointmentID,PatientID,ClinicID,PhysicianID,AppointmentDate,AppointmentTime,Status,BookingFeePaid) VALUES(?,?,1,?,?,?,'Confirmed',?)")->execute([$id,$patient,$doctor,$date,$time,$id===3?0:1]);
 $pdo->prepare("INSERT INTO Queue(QueueID,ClinicID,AppointmentID,PhysicianID,QueueNumber,RegularNumber,ScheduledNumber,Position,Status,CreatedAt) VALUES(?,1,?,?,?,?,?,?,'Waiting',?)")->execute([$id,$id,$doctor,$id,$time?null:$id,$time?1:null,$id*10,$date.' 15:30:00']);
}
$line=clinicTimeQueue($pdo,1,10);tqCheck(array_column($line,'QueueID')==[1,3,2,4],'Regular -> walk-in -> 4PM timed -> following regular');
tqCheck(array_column($line,'EstimatedTurnAt')==[$date.' 15:30:00',$date.' 15:45:00',$date.' 16:00:00',$date.' 16:15:00'],'15-minute projection inserts the reserved 4PM slot');
tqCheck((int)nextTimeQueuePatient($pdo,1,10)['QueueID']===1,'Call-next matches first displayed patient');
$pdo->exec("UPDATE Queue SET Status='Completed' WHERE QueueID IN(1,3);UPDATE Appointments SET Status='Completed' WHERE AppointmentID IN(1,3)");
tqClock($pdo,'15:50:00');tqCheck((int)nextTimeQueuePatient($pdo,1,10)['QueueID']===4,'Exactly ten minutes remaining allows a regular visit to fill the gap');
tqClock($pdo,'15:51:00');tqCheck(nextTimeQueuePatient($pdo,1,10)===null,'Nine minutes remaining reserves the doctor for the timed visit');
tqClock($pdo,'16:00:00');tqCheck((int)nextTimeQueuePatient($pdo,1,10)['QueueID']===2,'Timed visit takes priority once due');
$pdo->exec("UPDATE Queue SET Status='Waiting' WHERE QueueID IN(1,3);UPDATE Appointments SET Status='Confirmed' WHERE AppointmentID IN(1,3)");tqClock($pdo,'15:30:00');
$late=reportPatientRunningLate($pdo,1,1);tqCheck($late['moved']&&!$late['duplicate'],'Late patient moves back one place');
tqCheck(array_column(clinicTimeQueue($pdo,1,10),'QueueID')==[3,1,2,4],'Successor takes late patient spot while timed visit remains reserved');
$again=reportPatientRunningLate($pdo,1,1);tqCheck($again['duplicate']&&array_column(clinicTimeQueue($pdo,1,10),'QueueID')==[3,1,2,4],'Repeated late clicks across requests cannot keep moving the patient');
tqReject(fn()=>reportPatientRunningLate($pdo,1,2),'Another patient cannot move this queue entry');
tqCheck((int)clinicTimeQueue($pdo,1,11)[0]['QueueID']===5,'Late swap does not touch another physician queue');
$pdo->exec("UPDATE Queue SET Status='Completed' WHERE QueueID IN(1,3);UPDATE Appointments SET Status='Completed' WHERE AppointmentID IN(1,3)");tqClock($pdo,'16:00:00');
reportPatientRunningLate($pdo,2,2);tqCheck((int)nextTimeQueuePatient($pdo,1,10)['QueueID']===4,'Late timed patient yields to their following regular patient');
tqCheck(callNextClinicPatient($pdo,1,20,10)==='#4','Actual calling uses the same late-adjusted order');
tqCheck(callNextClinicPatient($pdo,1,20,10)===null,'Busy physician cannot receive a second call');
tqCheck(callNextClinicPatient($pdo,1,20,11)==='#5','Another physician can call while first physician is busy');
$pdo->exec("UPDATE Queue SET Status='Completed' WHERE QueueID=4;UPDATE Appointments SET Status='Completed' WHERE AppointmentID=4");
tqCheck(callNextClinicPatient($pdo,1,20,10)==='S-1','Late timed patient becomes next after successor finishes');
$pdo->exec("UPDATE Queue SET Status='Serving' WHERE QueueID=2");tqReject(fn()=>reportPatientRunningLate($pdo,2,2),'A consultation in progress cannot be moved by a late notice');
$pdo->exec('SET timestamp=0');echo "Temporary tables and connection-local clock only. No SMS/email or paid services called.\n";
