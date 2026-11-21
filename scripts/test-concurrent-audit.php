<?php
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require __DIR__.'/../config/db.php';require __DIR__.'/../includes/wallet.php';require __DIR__.'/../includes/queue-actions.php';require __DIR__.'/../includes/patient-booking-lock.php';require __DIR__.'/../includes/physician-withdrawals.php';
require __DIR__.'/../includes/clinic-fees.php';
putenv('HQ_SMS_ENABLED=0');
function testDatabase(string $name):PDO{if(!preg_match('/\Ahealthqueue_test_[a-f0-9]{12}\z/',$name))throw new LogicException('Unsafe test database.');return new PDO('mysql:host='.DB_HOST.';dbname='.$name.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);}
if(($argv[1]??'')==='--worker'){
 $p=testDatabase($argv[2]??'');$mode=$argv[3]??'';
 try{
  if(str_starts_with($mode,'fee-request-')){requestClinicFee($p,10,'600.00',(string)$p->query('SELECT DATE_ADD(CURDATE(),INTERVAL 1 DAY)')->fetchColumn(),'Concurrent fee fixture');echo 'ok';}
  elseif(str_starts_with($mode,'fee-review-')){reviewClinicFee($p,30,(int)$p->query('SELECT MAX(RequestID) FROM ClinicFeeRequests')->fetchColumn(),'Approved');echo 'ok';}
  elseif($mode==='approve'){$r=approveClinicAppointment($p,1,1,10,20);echo 'ok';}
  elseif($mode==='topup'){$p->beginTransaction();walletTopUp($p,1,100,'Concurrent fixture',str_repeat('b',64));$p->commit();echo 'ok';}
  elseif(str_starts_with($mode,'mobile-')){$id=$mode==='mobile-a'?51:52;$p->prepare("INSERT INTO Users(UserID,RoleID,FirstName,LastName,Email,PasswordHash,ContactNumber) VALUES(?,1,'Fixture','Mobile',?,'fixture','09000000051')")->execute([$id,'mobile'.$id.'@example.test']);echo 'ok';}
  elseif(str_starts_with($mode,'visit-')){$id=(int)substr($mode,strrpos($mode,'-')+1);if(str_starts_with($mode,'visit-staff-'))$changed=completeClinicVisit($p,$id,2,21,true);else $changed=respondToVisitCompletion($p,3,$id,str_starts_with($mode,'visit-issue-')?'issue':'confirm','Please review whether my consultation was completed.');echo $changed?'changed':'already';}
  elseif(str_starts_with($mode,'self-service')){requestPhysicianWithdrawal($p,12,['request_key'=>hash('sha256',$mode),'amount'=>$mode==='self-service-replay'?'100':'300','payout_method'=>'GCash','account_name'=>'Fixture Owner','phone'=>'09123456789','confirm_simulation'=>'1'],true);echo 'ok';}
  elseif(str_starts_with($mode,'withdraw')){requestPhysicianWithdrawal($p,10,['request_key'=>hash('sha256',$mode),'amount'=>'300','payout_method'=>'GCash','account_name'=>'Fixture Physician','phone'=>'09123456789']);echo 'ok';}
  elseif(str_starts_with($mode,'complete-')){echo completeClinicVisit($p,6,1,20,true)?'ok':'already';}
  elseif(str_starts_with($mode,'reschedule-')){$id=$mode==='reschedule-a'?7:8;$date=$p->query('SELECT DATE_ADD(CURDATE(),INTERVAL 1 DAY)')->fetchColumn();rescheduleClinicVisit($p,$id,1,20,$date,'10:00');echo 'ok';}
  elseif(str_starts_with($mode,'late-')){$qid=(int)$p->query('SELECT QueueID FROM Queue WHERE AppointmentID=1')->fetchColumn();$r=reportPatientRunningLate($p,$qid,1);echo $r['duplicate']?'duplicate':'moved';}
  elseif(str_starts_with($mode,'queue-call-')){echo callNextClinicPatient($p,1,20,10)!==null?'called':'blocked';}
  elseif(in_array($mode,['grace-arrival','grace-expiry','grace-late-boundary'],true)){
   $day=(string)$p->query('SELECT CURDATE()')->fetchColumn();$clock=new DateTimeImmutable($day.' '.($mode==='grace-expiry'?'16:30:00':'16:29:59'));$p->exec('SET timestamp='.$clock->getTimestamp());
   $qid=(int)$p->query('SELECT QueueID FROM Queue WHERE AppointmentID=11')->fetchColumn();
   if($mode==='grace-arrival'){confirmQueueArrival($p,$qid,1,20,$clock);echo 'arrived';}
   elseif($mode==='grace-late-boundary'){reportPatientRunningLate($p,$qid,3,$clock);echo 'extended';}
   else{processScheduledGrace($p,$clock);echo 'checked';}
  }
  elseif($mode==='pay'){echo reviewPhysicianWithdrawal($p,(int)$p->query('SELECT MIN(WithdrawalID) FROM PhysicianWithdrawals')->fetchColumn(),30,'pay')?'ok':'already';}
  elseif($mode==='booking'){$p->beginTransaction();lockPatientBooking($p,3);$p->exec("INSERT INTO Appointments(PatientID,ClinicID,AppointmentDate) VALUES(3,1,CURDATE())");$p->commit();echo 'ok';}
  else throw new LogicException('Unknown fixture operation');
 }catch(RuntimeException $e){if($p->inTransaction())$p->rollBack();echo hqDuplicateMobileError($e)?'duplicate-mobile':($e instanceof PDOException?'database-error':'rejected');}exit;
}
function runPair(string $db,string $first,string $second):array{
 $jobs=[];foreach([$first,$second] as $mode){$proc=proc_open([PHP_BINARY,__FILE__,'--worker',$db,$mode],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$jobs[]=[$proc,$pipes];}
 $results=[];foreach($jobs as [$proc,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($proc);if($exit!==0||$err!=='')throw new RuntimeException('Concurrent fixture process failed.');$results[]=$out;}return $results;
}
function concurrentCheck(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo 'PASS: '.$label.PHP_EOL;}
$source=getDbConnection();$name='healthqueue_test_'.bin2hex(random_bytes(6));$created=false;
try{
 if(!preg_match('/\Ahealthqueue_test_[a-f0-9]{12}\z/',$name)||$name===DB_NAME)throw new LogicException('Unsafe test target');
 $source->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$created=true;$p=testDatabase($name);
 foreach($source->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN) as $table){$row=$source->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM);$sql=preg_replace('/^.*CONSTRAINT.*FOREIGN KEY.*\n/m','',$row[1]);$sql=preg_replace('/,\s*\)/',')',$sql);$p->exec($sql);}
 $p->exec("INSERT INTO Roles VALUES(1,'Patient'),(2,'Physician'),(3,'Staff'),(4,'Admin');INSERT INTO Clinic(ClinicID,ClinicName,Address,ContactNumber,BaseConsultationFee) VALUES(1,'Fixture','Test','09123456789',500)");
 $stmt=$p->prepare('INSERT INTO Users(UserID,RoleID,ClinicID,FirstName,LastName,Email,PasswordHash,WalletBalance) VALUES(?,?,?,?,?,?,?,?)');foreach([[1,1,null],[3,1,null],[10,2,1],[20,3,1],[30,4,null]] as [$id,$role,$clinic])$stmt->execute([$id,$role,$clinic,'Fixture','User','fixture'.$id.'@example.test',password_hash('fixture-password',PASSWORD_DEFAULT),$id===1?1000:0]);
 $p->exec('INSERT INTO PhysicianAccountOwners(ClinicID,MainPhysicianID) VALUES(1,10)');
$p->exec("UPDATE Clinic SET OpenDays='1,2,3,4,5,6,7',OpenTime='00:00',CloseTime='23:59';INSERT INTO PhysicianDateAvailability(PhysicianID,AvailDate,StartTime,EndTime,PatientsPerHour) VALUES(10,CURDATE(),'00:00','23:59',12)");
 $p->exec("INSERT INTO Appointments(AppointmentID,PatientID,ClinicID,PhysicianID,AppointmentDate,Status,BookingFeePaid,BookingPaymentMethod) VALUES(1,1,1,10,CURDATE(),'Pending',1,'wallet'),(2,1,1,10,CURDATE(),'Completed',1,'wallet');INSERT INTO WalletTransactions(PatientID,Type,Amount,BalanceAfter,RelatedAppointmentID) VALUES(1,'BookingPayment',500,1000,2)");
 $p->beginTransaction();recordCompletedDemoPayment($p,2);$p->commit();
 $r=runPair($name,'approve','approve');concurrentCheck(count(array_filter($r,fn($x)=>$x==='ok'))===1&&(int)$p->query('SELECT COUNT(*) FROM Queue WHERE AppointmentID=1')->fetchColumn()===1,'Concurrent approvals produce one queue entry');
 $r=runPair($name,'topup','topup');concurrentCheck($r===['ok','ok']&&(float)$p->query('SELECT WalletBalance FROM Users WHERE UserID=1')->fetchColumn()===1100.0,'Concurrent duplicate top-ups credit once');
 $r=runPair($name,'withdraw-a','withdraw-b');concurrentCheck(count(array_filter($r,fn($x)=>$x==='ok'))===1&&(int)$p->query('SELECT COUNT(*) FROM PhysicianWithdrawals')->fetchColumn()===1,'Concurrent withdrawals cannot overspend 450 settled earnings');
 $r=runPair($name,'pay','pay');concurrentCheck(count(array_filter($r,fn($x)=>$x==='ok'))===1,'Concurrent review pays a request once: '.json_encode($r));
 $r=runPair($name,'booking','booking');concurrentCheck(count(array_filter($r,fn($x)=>$x==='ok'))===1&&(int)$p->query('SELECT COUNT(*) FROM Appointments WHERE PatientID=3')->fetchColumn()===1,'Concurrent patient bookings create one active request: '.json_encode($r));
 $r=runPair($name,'mobile-a','mobile-b');concurrentCheck(count(array_filter($r,fn($x)=>$x==='ok'))===1&&count(array_filter($r,fn($x)=>$x==='duplicate-mobile'))===1,'Concurrent accounts cannot claim the same mobile number');
 $p->exec("INSERT INTO Appointments(AppointmentID,PatientID,ClinicID,PhysicianID,AppointmentDate,Status,BookingFeePaid,BookingPaymentMethod) VALUES(6,1,1,10,CURDATE(),'Confirmed',1,'wallet');INSERT INTO WalletTransactions(PatientID,Type,Amount,BalanceAfter,RelatedAppointmentID) VALUES(1,'BookingPayment',500,1100,6);INSERT INTO Consultations(ConsultationID,ClinicID,AppointmentID,PatientID,DoctorID) VALUES(6,1,6,1,10);INSERT INTO ConsultationVersions(ConsultationID,EditedByPhysicianID,RevisionNumber,ClinicalNotes,Status) VALUES(6,10,1,'Synthetic reviewed note','Finalized')");
 $r=runPair($name,'complete-a','complete-b');concurrentCheck(count(array_filter($r,fn($x)=>$x==='ok'))===1&&count(array_filter($r,fn($x)=>$x==='already'))===1&&(int)$p->query('SELECT COUNT(*) FROM AppointmentPayments WHERE AppointmentID=6')->fetchColumn()===1,'Concurrent staff completion records revenue once');
 $p->exec("INSERT INTO PhysicianDateAvailability(PhysicianID,AvailDate,StartTime,EndTime,PatientsPerHour) VALUES(10,DATE_ADD(CURDATE(),INTERVAL 1 DAY),'10:00','11:00',1);INSERT INTO Appointments(AppointmentID,PatientID,ClinicID,PhysicianID,AppointmentDate,AppointmentTime,Status) VALUES(7,1,1,10,DATE_ADD(CURDATE(),INTERVAL 2 DAY),'12:00','Pending'),(8,3,1,10,DATE_ADD(CURDATE(),INTERVAL 2 DAY),'13:00','Pending')");
 $r=runPair($name,'reschedule-a','reschedule-b');concurrentCheck(count(array_filter($r,fn($x)=>$x==='ok'))===1&&count(array_filter($r,fn($x)=>$x==='rejected'))===1,'Concurrent rescheduling cannot take the same final capacity');
 $p->exec("INSERT INTO Appointments(AppointmentID,PatientID,ClinicID,PhysicianID,AppointmentDate,Status) VALUES(9,3,1,10,CURDATE(),'Confirmed');INSERT INTO Queue(ClinicID,AppointmentID,PhysicianID,QueueNumber,RegularNumber,Position,Status) VALUES(1,9,10,9,9,90,'Waiting')");
 $r=runPair($name,'late-a','late-b');concurrentCheck(count(array_filter($r,fn($x)=>$x==='moved'))===1&&count(array_filter($r,fn($x)=>$x==='duplicate'))===1,'Concurrent late notices move the patient only once');
 $r=runPair($name,'queue-call-a','queue-call-b');concurrentCheck(count(array_filter($r,fn($x)=>$x==='called'))===1&&count(array_filter($r,fn($x)=>$x==='blocked'))===1,'Concurrent time-based calls select only one patient for a physician');
 $p->exec("INSERT INTO Appointments(AppointmentID,PatientID,ClinicID,PhysicianID,AppointmentDate,AppointmentTime,Status) VALUES(11,3,1,10,CURDATE(),'16:00:00','Confirmed');INSERT INTO Queue(ClinicID,AppointmentID,PhysicianID,QueueNumber,ScheduledNumber,Status,CreatedAt,GracePolicyEnabledAt) VALUES(1,11,10,11,11,'Waiting',CONCAT(CURDATE(),' 15:00:00'),CONCAT(CURDATE(),' 15:00:00'))");
 $r=runPair($name,'grace-arrival','grace-expiry');$state=$p->query('SELECT a.Status,q.ArrivedAt FROM Appointments a JOIN Queue q ON q.AppointmentID=a.AppointmentID WHERE a.AppointmentID=11')->fetch();concurrentCheck(($state['Status']==='Confirmed'&&$state['ArrivedAt']!==null)||($state['Status']==='NoShow'&&$state['ArrivedAt']===null),'Arrival and expiry serialize without a contradictory no-show');
 $p->exec("UPDATE Appointments SET Status='Confirmed' WHERE AppointmentID=11;UPDATE Queue SET Status='Waiting',ArrivedAt=NULL,GraceExtendedAt=NULL,LastLateNoticeAt=NULL WHERE AppointmentID=11");
 $r=runPair($name,'grace-late-boundary','grace-expiry');$state=$p->query('SELECT a.Status,q.GraceExtendedAt FROM Appointments a JOIN Queue q ON q.AppointmentID=a.AppointmentID WHERE a.AppointmentID=11')->fetch();concurrentCheck(($state['Status']==='Confirmed'&&$state['GraceExtendedAt']!==null)||($state['Status']==='NoShow'&&$state['GraceExtendedAt']===null),'Timely extension and expiry serialize without losing an accepted extension');
 $r=runPair($name,'fee-request-a','fee-request-b');concurrentCheck(count(array_filter($r,fn($x)=>$x==='ok'))===1&&(int)$p->query("SELECT COUNT(*) FROM ClinicFeeRequests WHERE Status='Pending'")->fetchColumn()===1,'Concurrent fee requests create only one pending change');
 $r=runPair($name,'fee-review-a','fee-review-b');concurrentCheck(count(array_filter($r,fn($x)=>$x==='ok'))===1&&(int)$p->query("SELECT COUNT(*) FROM ClinicFeeRequests WHERE Status='Approved'")->fetchColumn()===1,'Concurrent fee approval is applied only once');
 concurrentCheck((int)$p->query('SELECT COUNT(*) FROM PhysicianNotifications')->fetchColumn()===1,'Concurrent approval creates only one physician notification');
 $p->exec("INSERT INTO Clinic(ClinicID,ClinicName,Address,ContactNumber) VALUES(2,'Second fixture','Test','09123456789');INSERT INTO Users(UserID,RoleID,ClinicID,FirstName,LastName,Email,PasswordHash) VALUES(12,2,2,'Second','Owner','second-owner@example.test','fixture');INSERT INTO PhysicianAccountOwners(ClinicID,MainPhysicianID) VALUES(2,12);INSERT INTO Appointments(AppointmentID,PatientID,ClinicID,PhysicianID,AppointmentDate,Status,BookingFeePaid,BookingPaymentMethod) VALUES(30,3,2,12,CURDATE(),'Completed',1,'wallet');INSERT INTO WalletTransactions(PatientID,Type,Amount,BalanceAfter,RelatedAppointmentID) VALUES(3,'BookingPayment',500,0,30)");
 $p->beginTransaction();recordCompletedDemoPayment($p,30);$p->commit();
 $r=runPair($name,'self-service-a','self-service-b');concurrentCheck(count(array_filter($r,fn($x)=>$x==='ok'))===1&&physicianWithdrawalBalance($p,12)['available']===15000,'Concurrent self-service withdrawals cannot overspend automatically released earnings');
 $r=runPair($name,'self-service-replay','self-service-replay');concurrentCheck($r===['ok','ok']&&physicianWithdrawalBalance($p,12)['available']===5000&&(int)$p->query('SELECT COUNT(*) FROM PhysicianWithdrawals WHERE FinancialOwnerUserID=12')->fetchColumn()===2,'Concurrent replay of one confirmation pays it exactly once');
 $p->exec("INSERT INTO Users(UserID,RoleID,ClinicID,FirstName,LastName,Email,PasswordHash) VALUES(21,3,2,'Second','Staff','second-staff@example.test','fixture')");
 foreach([31,32,33] as $visit){
  $p->prepare("INSERT INTO Appointments(AppointmentID,PatientID,ClinicID,PhysicianID,AppointmentDate,Status,BookingFeePaid,BookingPaymentMethod) VALUES(?,3,2,12,CURDATE(),'Confirmed',1,'wallet')")->execute([$visit]);
  $p->prepare('INSERT INTO Consultations(ConsultationID,ClinicID,AppointmentID,PatientID,DoctorID) VALUES(?,2,?,3,12)')->execute([$visit,$visit]);
  $p->prepare("INSERT INTO ConsultationVersions(ConsultationID,EditedByPhysicianID,RevisionNumber,ClinicalNotes,Status) VALUES(?,12,1,'Synthetic finalized note','Finalized')")->execute([$visit]);
  $p->prepare("INSERT INTO WalletTransactions(PatientID,Type,Amount,BalanceAfter,RelatedAppointmentID) VALUES(3,'BookingPayment',500,0,?)")->execute([$visit]);
  $p->beginTransaction();lockQueueClinic($p,2);requestVisitConfirmation($p,$visit);$p->commit();
 }
 $r=runPair($name,'visit-patient-31','visit-staff-31');concurrentCheck((int)$p->query('SELECT COUNT(*) FROM AppointmentPayments WHERE AppointmentID=31')->fetchColumn()===1&&$p->query('SELECT Status FROM Appointments WHERE AppointmentID=31')->fetchColumn()==='Completed','Patient and Staff confirmations release earnings once');
 foreach([[32,'visit-issue-32','visit-staff-32'],[33,'visit-issue-33','visit-patient-33']] as [$visit,$left,$right]){
  runPair($name,$left,$right);$state=$p->query('SELECT a.Status,r.Status ReviewStatus FROM Appointments a JOIN VisitCompletionReviews r ON r.AppointmentID=a.AppointmentID WHERE a.AppointmentID='.$visit)->fetch();$records=(int)$p->query('SELECT COUNT(*) FROM AppointmentPayments WHERE AppointmentID='.$visit)->fetchColumn();
  concurrentCheck(($state['Status']==='Completed'&&$state['ReviewStatus']!=='IssueReported'&&$records===1)||($state['Status']==='Confirmed'&&$state['ReviewStatus']==='IssueReported'&&$records===0),'Issue and completion race preserves accepted issue hold for visit '.$visit);
 }
 echo 'Synthetic isolated database only. No external messages, inference, or transfers.'.PHP_EOL;
}finally{if($created&&preg_match('/\Ahealthqueue_test_[a-f0-9]{12}\z/',$name)&&$name!==DB_NAME)$source->exec('DROP DATABASE `'.$name.'`');}
