<?php
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../config/db.php';require __DIR__.'/../includes/sms.php';
$pdo=getDbConnection();if(!$pdo)exit(1);
// Every table is connection-local. The dummy key is never used for a network request.
foreach($pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN) as $table){$sql=$pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];$sql=preg_replace('/^CREATE TABLE/','CREATE TEMPORARY TABLE',$sql);$sql=preg_replace('/^.*CONSTRAINT.*FOREIGN KEY.*\n/m','',$sql);$sql=preg_replace('/,\s*\)/',')',$sql);$pdo->exec($sql);}
putenv('HQ_SMS_ENABLED=1');putenv('HQ_TEXTBEE_API_KEY=isolated-no-network-fixture');
function nearCheck(bool $ok,string $label):void{if(!$ok)throw new LogicException($label);echo 'PASS: '.$label.PHP_EOL;}
$pdo->exec("INSERT INTO Roles VALUES(1,'Patient'),(2,'Physician');INSERT INTO Clinic(ClinicID,ClinicName,Address,ContactNumber,OpenDays,OpenTime,CloseTime) VALUES(1,'Bayview Health Hub','Test','09000000001','1,2,3,4,5,6,7','08:00','17:00')");
$s=$pdo->prepare("INSERT INTO Users(UserID,RoleID,ClinicID,FirstName,LastName,Email,PasswordHash,ContactNumber) VALUES(?,?,?,'Fixture','User',?,'fixture',?)");foreach([1,2,3,4,5,6,10,11] as $id)$s->execute([$id,$id>=10?2:1,$id>=10?1:null,'fixture'.$id.'@example.test','09'.str_pad((string)$id,9,'0',STR_PAD_LEFT)]);
$date=date('Y-m-d');$now=new DateTimeImmutable($date.' 09:45:00');$s=$pdo->prepare("INSERT INTO PhysicianDateAvailability(PhysicianID,AvailDate,StartTime,EndTime) VALUES(?,?,'08:00','17:00')");foreach([10,11] as $doctor)$s->execute([$doctor,$date]);
function nearFixture(PDO $p,int $id,int $patient,int $doctor,string $date,?string $time,string $status='Waiting',?string $served=null):void{
 $p->prepare("INSERT INTO Appointments(AppointmentID,PatientID,ClinicID,PhysicianID,AppointmentDate,AppointmentTime,Status) VALUES(?,?,1,?,?,?,'Confirmed')")->execute([$id,$patient,$doctor,$date,$time]);
 $p->prepare('INSERT INTO Queue(QueueID,ClinicID,AppointmentID,PhysicianID,QueueNumber,RegularNumber,ScheduledNumber,Position,Status,CreatedAt,ServedAt) VALUES(?,1,?,?,?,?,?,?,?,?,?)')->execute([$id,$id,$doctor,$id,$time?null:$id,$time?$id:null,$id*10,$status,$date.' '.($time??'09:00:00'),$served]);
}
nearFixture($pdo,1,1,10,$date,null,'Serving',$date.' 09:35:00');nearFixture($pdo,2,2,10,$date,null);nearFixture($pdo,3,3,10,$date,null);nearFixture($pdo,4,4,11,$date,'10:00:00');nearFixture($pdo,5,5,11,$date,'11:00:00');
$timeline=queueSmsEstimates($pdo,$date,$now);
nearCheck($timeline[2]['EstimatedTurnAt']===$date.' 09:50:00'&&$timeline[2]['EstimatedWaitMinutes']===5,'Queue estimate includes remaining time of the active consultation');
nearCheck($timeline[3]['EstimatedTurnAt']===$date.' 10:05:00'&&$timeline[3]['EstimatedWaitMinutes']===20,'Following queue patient is not alerted 20 minutes early');
nearCheck($timeline[4]['EstimatedTurnAt']===$date.' 10:00:00'&&$timeline[4]['EstimatedWaitMinutes']===15,'Scheduled patient becomes near 15 minutes before appointed time');
$eligible=collectQueueSmsReminders($pdo,$now);nearCheck(isset($eligible['near:2'],$eligible['near:4'])&&!isset($eligible['near:3'],$eligible['near:5']),'Only patients within the 15-minute estimated window are queued');
nearCheck(str_contains($eligible['near:2'],'Estimated turn: 9:50 AM (approx.)')&&str_contains($eligible['near:2'],'Bayview Health Hub'),'Queue reminder includes estimated time, physician and clinic');
nearCheck(str_contains($eligible['near:4'],'Appointment time: 10:00 AM')&&!str_contains($eligible['near:4'],'Estimated turn:'),'Timed reminder retains the appointed schedule');
collectQueueSmsReminders($pdo,$now);nearCheck((int)$pdo->query('SELECT COUNT(*) FROM SmsOutbox')->fetchColumn()===2,'Repeated worker collection does not duplicate reminders');
$eligible=collectQueueSmsReminders($pdo,$now->modify('+5 minutes'));nearCheck(isset($eligible['near:3']),'The following patient is alerted when the estimate reaches 15 minutes');
// Reordering can delay a turn. The worker uses eligibility again before sending.
$pdo->exec('UPDATE Queue SET Position=100 WHERE QueueID=2');$eligible=collectQueueSmsReminders($pdo,$now);nearCheck(!isset($eligible['near:2']),'A patient moved farther back is no longer eligible to send');$pending=$pdo->query("SELECT * FROM SmsOutbox WHERE EventKey='near:2'")->fetch();nearCheck(currentQueueReminderMessage($pdo,$pending,$now)===null,'Actual send-time recheck suppresses an outdated near-turn alert');
$pdo->exec("UPDATE SmsOutbox SET Status='skipped',Attempts=0 WHERE EventKey='near:2';UPDATE Queue SET Position=20 WHERE QueueID=2");collectQueueSmsReminders($pdo,$now);nearCheck($pdo->query("SELECT Status FROM SmsOutbox WHERE EventKey='near:2'")->fetchColumn()==='pending','A never-attempted delayed alert can become eligible again');
$pdo->exec("UPDATE SmsOutbox SET Status='accepted',Attempts=1 WHERE EventKey='near:2'");collectQueueSmsReminders($pdo,$now);nearCheck($pdo->query("SELECT Status FROM SmsOutbox WHERE EventKey='near:2'")->fetchColumn()==='accepted','Provider-accepted alerts are never automatically resent');
$pdo->exec("UPDATE Appointments SET Status='Cancelled' WHERE AppointmentID=2");$eligible=collectQueueSmsReminders($pdo,$now);nearCheck(!isset($eligible['near:2']),'Cancelled visits cannot receive a near-turn alert');
$pdo->exec("INSERT INTO PatientProfileDetails(UserID,Details) VALUES(4,'{\"sms_notifications\":false}')");$eligible=collectQueueSmsReminders($pdo,$now);nearCheck(!isset($eligible['near:4']),'SMS opt-out is respected');
$future=date('Y-m-d',strtotime('+1 day'));$pdo->prepare("INSERT INTO PhysicianDateAvailability(PhysicianID,AvailDate,StartTime,EndTime) VALUES(10,?,'09:00','12:00')")->execute([$future]);nearFixture($pdo,6,6,10,$future,null);$futureLine=queueSmsEstimates($pdo,$future,$now);nearCheck($futureLine[6]['EstimatedTurnAt']===$future.' 09:00:00','Future queue estimate starts at physician opening hours');
$approval=['DoctorName'=>'Ana Cruz','ClinicName'=>'Bayview Health Hub','AppointmentDate'=>$future,'AppointmentTime'=>null,'EstimatedTurnAt'=>$futureLine[6]['EstimatedTurnAt'],'ticket'=>'#6'];nearCheck(str_contains(appointmentApprovalSms($approval),'Estimated turn: 9:00 AM (approx.)'),'Approval SMS uses estimated turn for a queue booking');
$early=queueSmsEstimates($pdo,$date,new DateTimeImmutable($date.' 07:00:00'));nearCheck($early[4]['EstimatedWaitMinutes']>=180,'Before-opening estimates remain relative to the real current time');
$late=queueSmsEstimates($pdo,$date,new DateTimeImmutable($date.' 18:00:00'));nearCheck(!isset($late[3],$late[4],$late[5]),'No waiting turn time is invented after the clinic closes');
echo "Temporary fixtures only. No SMS or external requests sent.\n";
