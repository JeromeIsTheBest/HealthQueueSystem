<?php
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require __DIR__.'/../config/db.php';require __DIR__.'/../includes/wallet.php';require __DIR__.'/../includes/queue-actions.php';require __DIR__.'/../includes/patient-booking-lock.php';require __DIR__.'/../includes/physician-withdrawals.php';
putenv('HQ_SMS_ENABLED=0');
function testDatabase(string $name):PDO{if(!preg_match('/\Ahealthqueue_test_[a-f0-9]{12}\z/',$name))throw new LogicException('Unsafe test database.');return new PDO('mysql:host='.DB_HOST.';dbname='.$name.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);}
if(($argv[1]??'')==='--worker'){
 $p=testDatabase($argv[2]??'');$mode=$argv[3]??'';
 try{
  if($mode==='approve'){$r=approveClinicAppointment($p,1,1,10,20);echo 'ok';}
  elseif($mode==='topup'){$p->beginTransaction();walletTopUp($p,1,100,'Concurrent fixture',str_repeat('b',64));$p->commit();echo 'ok';}
  elseif(str_starts_with($mode,'withdraw')){requestPhysicianWithdrawal($p,10,['request_key'=>hash('sha256',$mode),'amount'=>'300','payout_method'=>'GCash','account_name'=>'Fixture Physician','phone'=>'09123456789']);echo 'ok';}
  elseif($mode==='pay'){echo reviewPhysicianWithdrawal($p,(int)$p->query('SELECT MIN(WithdrawalID) FROM PhysicianWithdrawals')->fetchColumn(),30,'pay')?'ok':'already';}
  elseif($mode==='booking'){$p->beginTransaction();lockPatientBooking($p,3);$p->exec("INSERT INTO Appointments(PatientID,ClinicID,AppointmentDate) VALUES(3,1,CURDATE())");$p->commit();echo 'ok';}
  else throw new LogicException('Unknown fixture operation');
 }catch(RuntimeException $e){if($p->inTransaction())$p->rollBack();echo $e instanceof PDOException?'database-error':'rejected';}exit;
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
 $p->exec("INSERT INTO Appointments(AppointmentID,PatientID,ClinicID,PhysicianID,AppointmentDate,Status,BookingFeePaid,BookingPaymentMethod) VALUES(1,1,1,10,CURDATE(),'Pending',1,'wallet'),(2,1,1,10,CURDATE(),'Completed',1,'wallet');INSERT INTO WalletTransactions(PatientID,Type,Amount,BalanceAfter,RelatedAppointmentID) VALUES(1,'BookingPayment',500,1000,2)");
 $p->beginTransaction();recordCompletedDemoPayment($p,2);$p->commit();simulateDemoSettlement($p,30,str_repeat('a',64));
 $r=runPair($name,'approve','approve');concurrentCheck(count(array_filter($r,fn($x)=>$x==='ok'))===1&&(int)$p->query('SELECT COUNT(*) FROM Queue WHERE AppointmentID=1')->fetchColumn()===1,'Concurrent approvals produce one queue entry');
 $r=runPair($name,'topup','topup');concurrentCheck($r===['ok','ok']&&(float)$p->query('SELECT WalletBalance FROM Users WHERE UserID=1')->fetchColumn()===1100.0,'Concurrent duplicate top-ups credit once');
 $r=runPair($name,'withdraw-a','withdraw-b');concurrentCheck(count(array_filter($r,fn($x)=>$x==='ok'))===1&&(int)$p->query('SELECT COUNT(*) FROM PhysicianWithdrawals')->fetchColumn()===1,'Concurrent withdrawals cannot overspend 450 settled earnings');
 $r=runPair($name,'pay','pay');concurrentCheck(count(array_filter($r,fn($x)=>$x==='ok'))===1,'Concurrent review pays a request once: '.json_encode($r));
 $r=runPair($name,'booking','booking');concurrentCheck(count(array_filter($r,fn($x)=>$x==='ok'))===1&&(int)$p->query('SELECT COUNT(*) FROM Appointments WHERE PatientID=3')->fetchColumn()===1,'Concurrent patient bookings create one active request: '.json_encode($r));
 echo 'Synthetic isolated database only. No external messages, inference, or transfers.'.PHP_EOL;
}finally{if($created&&preg_match('/\Ahealthqueue_test_[a-f0-9]{12}\z/',$name)&&$name!==DB_NAME)$source->exec('DROP DATABASE `'.$name.'`');}
