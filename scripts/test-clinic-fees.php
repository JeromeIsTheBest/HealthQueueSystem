<?php
/** Uses only connection-local shadow tables. HTTP mode exercises actual PHP multipart validation. */
if(!in_array(PHP_SAPI,['cli','cli-server'],true)){http_response_code(404);exit;}
require __DIR__.'/../config/db.php';require __DIR__.'/../includes/clinic-management.php';require __DIR__.'/../includes/wallet.php';require __DIR__.'/../includes/demo-revenue.php';
$pdo=getDbConnection();foreach($pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN) as $table){$ddl=$pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];$ddl=preg_replace('/^CREATE TABLE/','CREATE TEMPORARY TABLE',$ddl);$ddl=preg_replace('/^.*CONSTRAINT.*FOREIGN KEY.*\n/m','',$ddl);$ddl=preg_replace('/,\s*\)/',')',$ddl);$pdo->exec($ddl);}
$pdo->exec("INSERT INTO Roles VALUES(1,'Patient'),(2,'Physician'),(3,'Staff'),(4,'Admin');INSERT INTO Clinic(ClinicID,ClinicName,Address,ContactNumber,BaseConsultationFee,Description) VALUES(1,'Fixture A','Test','09000000001',500,'Original'),(2,'Fixture B','Other','09000000002',600,'Other description')");
$s=$pdo->prepare("INSERT INTO Users(UserID,RoleID,ClinicID,FirstName,LastName,Email,PasswordHash) VALUES(?,?,?,'Fixture','User',?,'fixture')");foreach([[1,1,null],[10,2,1],[11,2,1],[12,2,2],[20,3,1],[30,4,null]] as [$id,$role,$clinic])$s->execute([$id,$role,$clinic,'fixture'.$id.'@example.test']);$pdo->exec('INSERT INTO PhysicianAccountOwners(ClinicID,MainPhysicianID) VALUES(1,10),(2,12)');

function feeCheck(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n";}
function feeReject(callable $fn,string $label):void{try{$fn();}catch(RuntimeException $e){if($e instanceof PDOException)throw $e;feeCheck(true,$label);return;}throw new RuntimeException($label);}
require __DIR__.'/../includes/notification-read.php';
$today=(string)$pdo->query('SELECT CURDATE()')->fetchColumn();$tomorrow=date('Y-m-d',strtotime($today.' +1 day'));
feeCheck(currentClinicFee($pdo,1)==='500.00','Initial clinic fee');
feeReject(fn()=>requestClinicFee($pdo,11,'600.00',$tomorrow,'Request'),'Sub account cannot request fee change');
feeReject(fn()=>requestClinicFee($pdo,30,'600.00',$tomorrow,'Request'),'Admin cannot impersonate owner request');
feeReject(fn()=>requestClinicFee($pdo,10,'500.00',$tomorrow,'Request'),'Unchanged price rejected');
feeReject(fn()=>requestClinicFee($pdo,10,'600.001',$tomorrow,'Request'),'Fractional cent rejected');
$id=requestClinicFee($pdo,10,'600.00',$tomorrow,'Improved services');
feeCheck(currentClinicFee($pdo,1)==='500.00','Pending request does not change price');
feeReject(fn()=>requestClinicFee($pdo,10,'700.00',$tomorrow,'Second'),'Duplicate pending request rejected');
feeReject(fn()=>reviewClinicFee($pdo,10,$id,'Approved'),'Physician cannot approve own request');
feeReject(fn()=>reviewClinicFee($pdo,30,$id,'Invalid'),'Invalid review decision rejected');
reviewClinicFee($pdo,30,$id,'Approved');
$notice=$pdo->query('SELECT * FROM PhysicianNotifications')->fetch();feeCheck((int)$notice['PhysicianID']===10&&str_contains($notice['Message'],'PHP 600.00')&&str_contains($notice['Message'],date('j F Y',strtotime($tomorrow)))&&!(int)$notice['IsRead'],'Approval creates unread owner notice with amount and effective date');
feeCheck(currentClinicFee($pdo,1)==='500.00','Approved future fee waits for effective date');
feeReject(fn()=>requestClinicFee($pdo,10,'700.00',$tomorrow,'Second'),'Scheduled change blocks another request');
feeReject(fn()=>reviewClinicFee($pdo,30,$id,'Approved'),'Review cannot be replayed');
feeCheck((int)$pdo->query('SELECT COUNT(*) FROM PhysicianNotifications')->fetchColumn()===1,'Repeated review creates no duplicate notice');
feeCheck(hqMarkNotificationRead($pdo,'Physician',11,(int)$notice['NotificationID'])===null&&hqMarkNotificationRead($pdo,'Physician',12,(int)$notice['NotificationID'])===null,'Sub and other clinic cannot read owner notice');
feeCheck(hqMarkNotificationRead($pdo,'Physician',10,(int)$notice['NotificationID'])===0,'Opening own notice marks Read and clears unread count');
feeCheck((int)$pdo->query('SELECT IsRead FROM PhysicianNotifications')->fetchColumn()===1,'Read state persisted');
$pdo->exec('UPDATE Users SET WalletBalance=10000 WHERE UserID=1');
$pdo->prepare("INSERT INTO Appointments(AppointmentID,PatientID,ClinicID,PhysicianID,AppointmentDate,BookingFeeAmount) VALUES(1,1,1,10,?,500)")->execute([$tomorrow]);
$pdo->exec('SET timestamp='.strtotime($tomorrow.' 09:00:00'));
feeCheck(currentClinicFee($pdo,1)==='600.00','Approved fee effective automatically on date');
$pdo->beginTransaction();payBookingFromWallet($pdo,1,1);$pdo->commit();
feeCheck($pdo->query("SELECT Amount FROM WalletTransactions WHERE RelatedAppointmentID=1 AND Type='BookingPayment'")->fetchColumn()==='500.00','Existing unpaid booking keeps original price');
$pdo->prepare("INSERT INTO Appointments(AppointmentID,PatientID,ClinicID,PhysicianID,AppointmentDate,BookingFeeAmount) VALUES(2,1,1,10,?,?)")->execute([$tomorrow,currentClinicFee($pdo,1)]);
$pdo->beginTransaction();payBookingFromWallet($pdo,1,2);payBookingFromWallet($pdo,1,2);$pdo->commit();
feeCheck($pdo->query("SELECT SUM(Amount) FROM WalletTransactions WHERE RelatedAppointmentID=2 AND Type='BookingPayment'")->fetchColumn()==='600.00','New booking pays effective fee once');
$pdo->beginTransaction();cancelBooking($pdo,1,1,'Fixture','Fixture refund ');$pdo->commit();
feeCheck($pdo->query("SELECT Amount FROM WalletTransactions WHERE RelatedAppointmentID=1 AND Type='Refund'")->fetchColumn()==='500.00','Refund uses original payment');
$pdo->exec("UPDATE Appointments SET Status='Completed' WHERE AppointmentID=2");$pdo->beginTransaction();recordCompletedDemoPayment($pdo,2);$pdo->commit();
$payment=$pdo->query('SELECT * FROM AppointmentPayments WHERE AppointmentID=2')->fetch();
feeCheck((float)$payment['GrossAmount']===600.0&&(float)$payment['PhysicianRevenue']===540.0&&(float)$payment['PlatformCommission']===60.0,'90/10 split uses actual payment');
feeCheck(currentClinicFee($pdo,2)==='600.00','Other clinic fee unchanged');
$id2=requestClinicFee($pdo,10,'700.00',$tomorrow,'Another change');reviewClinicFee($pdo,30,$id2,'Declined');feeCheck(currentClinicFee($pdo,1)==='600.00','Declined request does not change fee');
feeCheck((int)$pdo->query('SELECT COUNT(*) FROM PhysicianNotifications')->fetchColumn()===2&&str_contains($pdo->query('SELECT Message FROM PhysicianNotifications ORDER BY NotificationID DESC LIMIT 1')->fetchColumn(),'declined'),'Decline creates one personal notification');
$old=requestClinicFee($pdo,10,'650.00',$tomorrow,'Expired pending');$pdo->exec('SET timestamp='.strtotime($tomorrow.' +1 day 09:00:00'));feeReject(fn()=>reviewClinicFee($pdo,30,$old,'Approved'),'Past effective date cannot be approved retroactively');
$pdo->prepare("INSERT INTO PhysicianNotifications(PhysicianID,FeeRequestID,Message) VALUES(10,?,'Fixture duplicate')")->execute([$old]);
try{reviewClinicFee($pdo,30,$old,'Declined');throw new RuntimeException('Expected notification failure');}catch(PDOException $e){}
feeCheck($pdo->query('SELECT Status FROM ClinicFeeRequests WHERE RequestID='.$old)->fetchColumn()==='Pending','Notification insert failure rolls back the review');
echo "Temporary fixtures only. No actual requests, payments, messages or payouts.\n";
