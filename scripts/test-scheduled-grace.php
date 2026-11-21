<?php
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../config/db.php';require __DIR__.'/../includes/queue-actions.php';require __DIR__.'/../includes/appointment-rules.php';
putenv('HQ_SMS_ENABLED=0');$pdo=getDbConnection();
foreach($pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN) as $table){$ddl=$pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];$ddl=preg_replace('/^CREATE TABLE/','CREATE TEMPORARY TABLE',$ddl);$ddl=preg_replace('/^.*CONSTRAINT.*FOREIGN KEY.*\n/m','',$ddl);$ddl=preg_replace('/,\s*\)/',')',$ddl);$pdo->exec($ddl);}
function graceCheck(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n";}
function graceReject(callable $fn,string $label):void{try{$fn();}catch(RuntimeException $e){if($e instanceof PDOException)throw $e;graceCheck(true,$label);return;}throw new RuntimeException($label);}
$date=date('Y-m-d');function graceClock(string $time):DateTimeImmutable{global $pdo,$date;$now=new DateTimeImmutable($date.' '.$time);$pdo->exec('SET timestamp='.$now->getTimestamp());return $now;}
graceClock('15:30:00');$pdo->exec("INSERT INTO Roles VALUES(1,'Patient'),(2,'Physician'),(3,'Staff');INSERT INTO Clinic(ClinicID,ClinicName,Address,ContactNumber,OpenDays,OpenTime,CloseTime) VALUES(1,'Fixture','Test','09000000000','1,2,3,4,5,6,7','08:00','18:00')");
$s=$pdo->prepare("INSERT INTO Users(UserID,RoleID,ClinicID,FirstName,LastName,Email,PasswordHash,WalletBalance) VALUES(?,?,?,'Fixture','User',?,'fixture',1000)");foreach([1,2,3,4,5,6,7,8,9,10,11,20] as $id)$s->execute([$id,$id===20?3:($id===10||$id===11?2:1),$id>=10?1:null,'fixture'.$id.'@example.test']);
$pdo->prepare("INSERT INTO PhysicianDateAvailability(PhysicianID,AvailDate,StartTime,EndTime) VALUES(10,?,'08:00','18:00')")->execute([$date]);
function graceFixture(int $id,?string $time='16:00:00',bool $managed=true,string $status='Waiting'):void{global $pdo,$date;
 $pdo->prepare("INSERT INTO Appointments(AppointmentID,PatientID,ClinicID,PhysicianID,AppointmentDate,AppointmentTime,Status,BookingFeePaid) VALUES(?,?,1,10,?,?,'Confirmed',1)")->execute([$id,$id,$date,$time]);
 $pdo->prepare('INSERT INTO Queue(QueueID,ClinicID,AppointmentID,PhysicianID,QueueNumber,ScheduledNumber,RegularNumber,Position,Status,CreatedAt,GracePolicyEnabledAt) VALUES(?,1,?,10,?,?,?,?,?,?,?)')->execute([$id,$id,$id,$time?$id:null,$time?null:$id,$id*10,$status,$date.' 15:30:00',$managed?$date.' 15:30:00':null]);
}
graceFixture(1);graceFixture(2,null);
graceClock('15:50:00');graceCheck((int)nextTimeQueuePatient($pdo,1,10)['QueueID']===2,'A real ten-minute gap can be filled before the scheduled time');
graceClock('15:51:00');graceCheck(nextTimeQueuePatient($pdo,1,10)===null,'Under ten minutes the scheduled slot remains reserved');
graceClock('16:00:00');graceCheck((int)nextTimeQueuePatient($pdo,1,10)['QueueID']===2,'At booked time an absent scheduled patient no longer blocks the next patient');
$line=clinicTimeQueue($pdo,1,10);$absent=array_values(array_filter($line,fn($r)=>(int)$r['QueueID']===1))[0];graceCheck($absent['GraceHeld']&&$absent['GraceDeadline']===$date.' 16:30:00','Absent visit has a thirty-minute deadline and no fabricated ETA');
graceCheck(currentQueueReminderMessage($pdo,['EventKey'=>'near:1','EventType'=>'near','AppointmentID'=>1,'PatientID'=>1])===null,'Absent grace-held patient cannot receive an incorrect near-turn SMS');
graceCheck(appointmentGraceRescheduleOpen($pdo,1,1)&&!appointmentChangeOpen($pdo,1,1),'Grace allows reschedule without reopening cancellation');
graceClock('16:10:00');confirmQueueArrival($pdo,1,1,20);graceCheck($pdo->query('SELECT Status FROM Queue WHERE QueueID=1')->fetchColumn()==='Waiting','Staff arrival does not start the consultation');
graceCheck((int)nextTimeQueuePatient($pdo,1,10)['QueueID']===1,'Arrived overdue scheduled patient becomes eligible');
graceReject(fn()=>reportPatientRunningLate($pdo,1,1),'Already-arrived patient cannot report absent again');
graceReject(fn()=>confirmQueueArrival($pdo,1,2,20),'Another clinic cannot confirm this arrival');
$pdo->exec('UPDATE Queue SET ArrivedAt=NULL WHERE QueueID=1');
graceClock('16:20:00');$late=reportPatientRunningLate($pdo,1,1);graceCheck($late['grace_extended']&&$late['deadline']===$date.' 17:00:00','Timely running-late notice grants one extra thirty minutes');
graceClock('16:35:00');reportPatientRunningLate($pdo,1,1);$r=$pdo->query('SELECT q.*,a.AppointmentDate,a.AppointmentTime FROM Queue q JOIN Appointments a ON a.AppointmentID=q.AppointmentID WHERE q.QueueID=1')->fetch();graceCheck(scheduledGraceInfo($r,strtotime($date.' 16:35:00'))['deadline']===strtotime($date.' 17:00:00'),'Repeated late notice cannot extend beyond one hour from booked time');
graceClock('16:59:00');graceCheck(processScheduledGrace($pdo)['no_show']===0,'Extended visit is protected before the extended deadline');
graceClock('17:00:00');graceCheck(processScheduledGrace($pdo)['no_show']===1,'Absent extended visit becomes no-show at one-hour deadline');
graceCheck($pdo->query('SELECT Status FROM Appointments WHERE AppointmentID=1')->fetchColumn()==='NoShow'&&$pdo->query('SELECT Status FROM Queue WHERE QueueID=1')->fetchColumn()==='Forfeited_Late','Automatic expiry synchronizes appointment and queue statuses');
graceCheck(processScheduledGrace($pdo)['no_show']===0,'Repeating maintenance does not duplicate expiry');
graceCheck((float)$pdo->query('SELECT WalletBalance FROM Users WHERE UserID=1')->fetchColumn()===1000.0&&(int)$pdo->query('SELECT COUNT(*) FROM AppointmentPayments')->fetchColumn()===0,'No-show neither refunds nor creates completion commission');
graceFixture(3);graceClock('16:29:59');graceCheck(processScheduledGrace($pdo)['no_show']===0,'Unextended visit remains active until thirty-minute boundary');
graceClock('16:30:00');graceCheck(!appointmentGraceRescheduleOpen($pdo,3,3),'Reschedule exception closes at the deadline');
graceReject(fn()=>reportPatientRunningLate($pdo,3,3),'Late extension is rejected once the original deadline has expired');
$before=$pdo->query('SELECT Status FROM Appointments WHERE AppointmentID=3')->fetchColumn();$dry=processScheduledGrace($pdo,null,true);graceCheck($dry['no_show']===1&&$pdo->query('SELECT Status FROM Appointments WHERE AppointmentID=3')->fetchColumn()===$before,'Read-only check rolls back all changes');
graceCheck(processScheduledGrace($pdo)['no_show']===1,'No action causes no-show at original thirty-minute deadline');
graceFixture(4);$pdo->exec("UPDATE Queue SET GraceRescheduleRequestedAt=CONCAT(CURDATE(),' 16:25:00') WHERE QueueID=4;INSERT INTO AppointmentRescheduleRequests(AppointmentID,PatientID,ClinicID,RequestedDate,RequestedTime) VALUES(4,4,1,DATE_ADD(CURDATE(),INTERVAL 1 DAY),'10:00:00')");
graceFixture(5);$pdo->exec("INSERT INTO Consultations(ConsultationID,ClinicID,AppointmentID,PatientID,DoctorID) VALUES(5,1,5,5,10);INSERT INTO ConsultationVersions(ConsultationID,EditedByPhysicianID,RevisionNumber,ClinicalNotes,Status) VALUES(5,10,1,'Existing clinical draft','Draft')");
graceFixture(6, '16:00:00',false);graceFixture(7,'16:00:00',true,'Serving');graceFixture(8);$pdo->exec("UPDATE Queue SET ArrivedAt=CONCAT(CURDATE(),' 15:55:00') WHERE QueueID=8");
graceClock('17:10:00');processScheduledGrace($pdo);foreach([4,5,6,7,8] as $id)graceCheck($pdo->query('SELECT Status FROM Appointments WHERE AppointmentID='.$id)->fetchColumn()==='Confirmed','Protect reschedule, clinical record, legacy, serving or arrived fixture '.$id);
graceCheck($pdo->query("SELECT Status FROM AppointmentRescheduleRequests WHERE AppointmentID=4")->fetchColumn()==='Pending','Timely reschedule request remains available to staff');
graceFixture(9,'16:00:00',true,'Calling');graceClock('16:05:00');$released=processScheduledGrace($pdo);graceCheck($released['released']===1&&$pdo->query('SELECT Status FROM Queue WHERE QueueID=9')->fetchColumn()==='Waiting','Absent called visit releases its calling state during grace');
confirmQueueArrival($pdo,9,1,20);graceClock('17:30:00');processScheduledGrace($pdo);graceCheck($pdo->query('SELECT Status FROM Appointments WHERE AppointmentID=9')->fetchColumn()==='Confirmed','Recorded arrival protects visit after grace expiry');
$pdo->exec('SET timestamp=0');echo "Temporary fixtures and connection-local clock only. No actual messages, payments or appointments changed.\n";
