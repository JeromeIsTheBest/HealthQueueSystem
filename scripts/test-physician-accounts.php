<?php
// Connection-local synthetic fixtures only. No messages, AI, or transfers.
if(PHP_SAPI!=='cli'&&!(PHP_SAPI==='cgi-fcgi'&&getenv('HQ_ACCOUNT_RENDER')))exit;
if(PHP_SAPI==='cgi-fcgi')$argv=[__FILE__,'--render',getenv('HQ_ACCOUNT_RENDER')];
session_set_save_handler(static fn($p,$n)=>true,static fn()=>true,static fn($id)=>'',static fn($id,$data)=>true,static fn($id)=>true,static fn($n)=>0);
require __DIR__.'/../includes/auth.php';require __DIR__.'/../includes/physician-accounts.php';require __DIR__.'/../includes/physician-withdrawals.php';require __DIR__.'/../includes/availability.php';
$pdo=getDbConnection();if(!$pdo)throw new RuntimeException('Database unavailable.');
foreach($pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN) as $table){
 $sql=$pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];$sql=preg_replace('/^CREATE TABLE/','CREATE TEMPORARY TABLE',$sql);$sql=preg_replace('/^.*CONSTRAINT.*FOREIGN KEY.*\n/m','',$sql);$sql=preg_replace('/,\s*\)/',')',$sql);$pdo->exec($sql);
}
function accountCheck(bool $ok,string $label):void{if(!$ok)throw new LogicException($label);echo 'PASS: '.$label.PHP_EOL;}
function accountReject(callable $call,string $label):void{try{$call();}catch(RuntimeException $e){accountCheck(true,$label);return;}throw new LogicException($label);}
$pdo->exec("INSERT INTO Roles VALUES(1,'Patient'),(2,'Physician'),(3,'Staff'),(4,'Admin');INSERT INTO Clinic(ClinicID,ClinicName,Address,ContactNumber) VALUES(1,'Fixture Clinic A','Test','09123456789'),(2,'Fixture Clinic B','Test','09123456789')");
$s=$pdo->prepare('INSERT INTO Users(UserID,RoleID,ClinicID,FirstName,LastName,Email,PasswordHash,IDNumber) VALUES(?,?,?,?,?,?,?,?)');
foreach([[1,1,null],[10,2,1],[11,2,1],[12,2,2],[20,3,1],[30,4,null]] as [$id,$role,$clinic])$s->execute([$id,$role,$clinic,'Fixture','User','fixture'.$id.'@example.test',password_hash('fixture-password',PASSWORD_DEFAULT),str_pad((string)$id,10,'0',STR_PAD_LEFT)]);
$pdo->exec('INSERT INTO PhysicianAccountOwners(ClinicID,MainPhysicianID) VALUES(1,10),(2,12)');
if(($argv[1]??'')==='--render'){
 $mode=$argv[2]??'';if(str_starts_with($mode,'reports-')&&getenv('HQ_REPORT_VIEW'))$_GET['view']=getenv('HQ_REPORT_VIEW');$targets=['nearby-directory'=>[1,'patient/clinics.php'],'nearby-dashboard'=>[1,'patient/dashboard.php'],'nearby-detail'=>[1,'patient/clinic-detail.php'],'patient-visit-notification'=>[1,'patient/notifications.php'],'patient-record-notification'=>[1,'patient/notifications.php'],'reports-admin'=>[30,'admin/reports.php'],'reports-staff'=>[20,'staff/reports.php'],'reports-main'=>[10,'physician/reports.php'],'reports-sub'=>[11,'physician/reports.php'],'patient-completion'=>[1,'patient/my-appointments.php'],'staff-followup'=>[20,'staff/appointments.php'],'admin-revenue'=>[30,'admin/revenue.php'],'admin-withdrawals'=>[30,'admin/physician-withdrawals.php'],'physician-notifications'=>[10,'physician/notifications.php'],'admin-fees'=>[30,'admin/clinic-fees.php'],'physician-availability'=>[10,'physician/availability.php'],'admin-patients'=>[30,'admin/patients.php'],'admin-staff'=>[30,'admin/staff.php'],'manage-clinic'=>[10,'physician/manage-clinic.php'],'blocked-clinic'=>[11,'physician/manage-clinic.php'],'admin-clinics'=>[30,'admin/clinics.php'],'main'=>[10,'physician/dashboard.php'],'sub'=>[11,'physician/dashboard.php'],'management'=>[10,'physician/sub-accounts.php'],'admin'=>[30,'admin/physicians.php'],'patient'=>[1,'patient/clinic-detail.php'],'availability'=>[1,'patient/physician-availability.php'],'withdrawal'=>[10,'physician/withdrawals.php'],'blocked-withdrawal'=>[11,'physician/withdrawals.php'],'blocked-management'=>[11,'physician/sub-accounts.php'],'consultations'=>[11,'physician/consultations.php'],'staff-appointments'=>[20,'staff/appointments.php'],'staff-queue'=>[20,'staff/queue.php'],'staff-dashboard'=>[20,'staff/dashboard.php'],'reconciliation'=>[30,'admin/reconciliation.php'],'patient-dashboard'=>[1,'patient/dashboard.php'],'patient-appointments'=>[1,'patient/my-appointments.php'],'patient-queue'=>[1,'patient/queue-status.php'],'patient-notes'=>[1,'patient/consultation.php'],'physician-workspace'=>[10,'physician/consultation.php']];
 $pdo->exec("UPDATE Users SET ContactNumber='invalid-fixture' WHERE UserID=20;INSERT INTO Appointments(AppointmentID,PatientID,ClinicID,PhysicianID,AppointmentDate,AppointmentTime,Status) VALUES(10,1,1,10,DATE_SUB(CURDATE(),INTERVAL 1 DAY),NULL,'Confirmed'),(11,1,1,11,DATE_SUB(CURDATE(),INTERVAL 1 DAY),NULL,'Confirmed'),(12,1,1,10,CURDATE(),NULL,'Completed');INSERT INTO Consultations(ConsultationID,ClinicID,AppointmentID,PatientID,DoctorID) VALUES(10,1,10,1,10),(12,1,12,1,10);INSERT INTO ConsultationVersions(ConsultationID,EditedByPhysicianID,RevisionNumber,ClinicalNotes,Status) VALUES(10,10,1,'Synthetic reviewed note','Finalized'),(12,10,1,'Synthetic reviewed note','Finalized')");
 if(str_starts_with($mode,'nearby-'))$pdo->exec("UPDATE Clinic SET Latitude=10.3157,Longitude=123.8854 WHERE ClinicID=1;UPDATE Clinic SET Latitude=10.3557,Longitude=123.8854 WHERE ClinicID=2;INSERT INTO Clinic(ClinicID,ClinicName,Address,ContactNumber) VALUES(3,'Fixture Clinic C','Cebu City','09000000003')");
 if($mode==='patient-visit-notification'){require_once __DIR__.'/../includes/queue-actions.php';$pdo->beginTransaction();lockQueueClinic($pdo,1);requestVisitConfirmation($pdo,10);$pdo->commit();}
 if($mode==='patient-record-notification'){
  require_once __DIR__.'/../includes/medical-record-access.php';
  $pdo->exec("INSERT INTO Appointments(AppointmentID,PatientID,ClinicID,PhysicianID,AppointmentDate,Status) VALUES(17,1,1,10,CURDATE(),'Confirmed');INSERT INTO Queue(ClinicID,AppointmentID,PhysicianID,QueueNumber,Position,Status) VALUES(1,17,10,17,17,'Serving');INSERT INTO MedicalRecords(RecordID,PatientID,Title,Category,FilePath,FileType) VALUES(1,1,'Blood test results.pdf','Lab result','synthetic.pdf','pdf'),(2,1,'Chest X-ray.jpg','Imaging','synthetic.jpg','jpg')");requestMedicalRecordAccess($pdo,10,17);
 }
 if(str_starts_with($mode,'reports-'))$pdo->exec("INSERT INTO AITranscriptVersions(ConsultationID,TranscriptType,TranscriptText) VALUES(12,'Original_STT','Synthetic reference transcript');INSERT INTO Feedback(AppointmentID,PatientID,ClinicID,PhysicianID,Rating,Comment) VALUES(12,1,1,10,4,'Synthetic visit feedback')");
 if(in_array($mode,['patient-completion','staff-followup'],true)){
  require_once __DIR__.'/../includes/queue-actions.php';
  $pdo->exec("INSERT INTO Appointments(AppointmentID,PatientID,ClinicID,PhysicianID,AppointmentDate,Status) VALUES(16,1,1,11,CURDATE(),'Confirmed');INSERT INTO Consultations(ConsultationID,ClinicID,AppointmentID,PatientID,DoctorID) VALUES(16,1,16,1,11);INSERT INTO ConsultationVersions(ConsultationID,EditedByPhysicianID,RevisionNumber,ClinicalNotes,Status) VALUES(16,11,1,'Synthetic finalized note','Finalized')");
  foreach([10,16] as $visit){$pdo->beginTransaction();lockQueueClinic($pdo,1);requestVisitConfirmation($pdo,$visit);$pdo->commit();}
  respondToVisitCompletion($pdo,1,16,'issue','I need the clinic to review whether this visit took place.');
  if($mode==='staff-followup')$_GET['tab']='confirm';
 }
 if(in_array($mode,['withdrawal','admin-revenue','admin-withdrawals'],true)){$pdo->exec("UPDATE Appointments SET BookingFeePaid=1,BookingPaymentMethod='wallet' WHERE AppointmentID=12;INSERT INTO WalletTransactions(PatientID,Type,Amount,BalanceAfter,RelatedAppointmentID) VALUES(1,'BookingPayment',500,0,12)");$pdo->beginTransaction();recordCompletedDemoPayment($pdo,12);$pdo->commit();requestPhysicianWithdrawal($pdo,10,['request_key'=>str_repeat('e',64),'amount'=>'200','payout_method'=>'GCash','account_name'=>'Fixture Physician','phone'=>'09123456789','confirm_simulation'=>'1'],true);}
 if($mode==='physician-notifications'){$pdo->exec("INSERT INTO PhysicianNotifications(PhysicianID,Message) VALUES(10,'Your consultation fee change to PHP 600.00 has been approved, effective 25 November 2026.'),(12,'Other clinic private fixture')");}
 if($mode==='admin-fees'){$pdo->exec("INSERT INTO ClinicFeeRequests(ClinicID,RequestedBy,CurrentFee,ProposedFee,EffectiveDate,Reason) VALUES(1,10,500,600,DATE_ADD(CURDATE(),INTERVAL 1 DAY),'Updated consultation services')");}
 if($mode==='physician-availability') {$pdo->exec("INSERT INTO PhysicianDateAvailability(PhysicianID,AvailDate,StartTime,EndTime,PatientsPerHour) VALUES(10,CURDATE(),'09:00','13:00',5)");}
 if($mode==='staff-queue') {
  $pdo->exec("INSERT INTO Appointments(AppointmentID,PatientID,ClinicID,PhysicianID,AppointmentDate,AppointmentTime,Status,BookingFeePaid) VALUES(13,1,1,11,CURDATE(),NULL,'Confirmed',1),(14,1,1,11,CURDATE(),NULL,'Confirmed',1);INSERT INTO Queue(QueueID,ClinicID,AppointmentID,PhysicianID,QueueNumber,RegularNumber,Status,CreatedAt) VALUES(13,1,13,11,1,1,'Waiting',DATE_SUB(NOW(),INTERVAL 27 MINUTE)),(14,1,14,11,2,2,'Waiting',NOW())");
  $fixtureGraceClock=strtotime(date('Y-m-d').' 15:50:00');$pdo->exec('SET timestamp='.$fixtureGraceClock);
  $pdo->exec("INSERT INTO Appointments(AppointmentID,PatientID,ClinicID,PhysicianID,AppointmentDate,AppointmentTime,Status,BookingFeePaid) VALUES(15,1,1,11,CURDATE(),'15:30:00','Confirmed',1);INSERT INTO Queue(QueueID,ClinicID,AppointmentID,PhysicianID,QueueNumber,ScheduledNumber,Status,CreatedAt,GracePolicyEnabledAt) VALUES(15,1,15,11,15,1,'Waiting',CONCAT(CURDATE(),' 15:00:00'),CONCAT(CURDATE(),' 15:00:00'))");

 }
 if(in_array($mode,['patient-queue','patient-appointments','patient-dashboard'],true)){
  $fixtureGraceClock=strtotime(date('Y-m-d').' 15:50:00');$pdo->exec('SET timestamp='.$fixtureGraceClock);
  $pdo->exec("INSERT INTO Appointments(AppointmentID,PatientID,ClinicID,PhysicianID,AppointmentDate,AppointmentTime,Status,BookingFeePaid) VALUES(15,1,1,11,CURDATE(),'15:30:00','Confirmed',1);INSERT INTO Queue(QueueID,ClinicID,AppointmentID,PhysicianID,QueueNumber,ScheduledNumber,Status,CreatedAt,GracePolicyEnabledAt) VALUES(15,1,15,11,15,1,'Waiting',CONCAT(CURDATE(),' 15:00:00'),CONCAT(CURDATE(),' 15:00:00'))");
 }
 if(!isset($targets[$mode]))throw new LogicException('Unknown render mode');[$id,$file]=$targets[$mode];
 $s=$pdo->prepare('SELECT u.*,r.RoleName FROM Users u JOIN Roles r ON r.RoleID=u.RoleID WHERE u.UserID=?');$s->execute([$id]);loginUser($s->fetch());$_SERVER['REQUEST_METHOD']='GET';$_SERVER['SCRIPT_NAME']='/healthqueue/'.$file;$_GET=array_merge($_GET,['clinic_id'=>1,'clinic'=>1,'physician'=>11]);if(str_starts_with($mode,'reports-'))unset($_GET['physician']);
 set_error_handler(static function($severity,$message,$file,$line){if(error_reporting()&$severity)throw new ErrorException($message,0,$severity,$file,$line);return false;});
 ob_start();require __DIR__.'/../'.$file;$html=ob_get_clean();
 if(str_contains($html,'Fatal error')||str_contains($html,'Warning:'))throw new LogicException('Page contains PHP errors.');
 if($mode==='sub'&&(str_contains($html,'Recent earnings')||str_contains($html,'withdrawals.php')||str_contains($html,'sub-accounts.php')||str_contains($html,'PHP 0.00')))throw new LogicException('Financial access leaked into sub dashboard.');
 if($mode==='patient'&&(str_contains($html,'Main Account')||str_contains($html,'Sub Account')))throw new LogicException('Internal account labels leaked to patient.');
 if(getenv('HQ_ACCOUNT_RENDER_HTML')){echo $html;exit;}
 echo 'PASS: rendered '.$mode.' ('.strlen($html).' bytes)'.PHP_EOL;exit;
}
accountCheck(physicianAccount($pdo,10)['is_main']&&!physicianAccount($pdo,11)['is_main'],'Main/sub account classification');
accountReject(fn()=>physicianWithdrawalBalance($pdo,11),'Sub-account cannot read balances');
accountReject(fn()=>requestPhysicianWithdrawal($pdo,11,['request_key'=>str_repeat('a',64),'amount'=>'1','payout_method'=>'GCash','account_name'=>'Fixture User','phone'=>'09123456789']),'Sub-account cannot request withdrawals');
$input=['first_name'=>'ana','last_name'=>'cruz','email'=>'sub-fixture@example.test','contact_number'=>'09123456789','specialty'=>'Family Medicine','password'=>'fixture-password'];
$id=savePhysicianSubAccount($pdo,10,$input);$row=$pdo->query('SELECT * FROM Users WHERE UserID='.(int)$id)->fetch();accountCheck($row['FirstName']==='Ana'&&$row['LastName']==='Cruz'&&(int)$row['ClinicID']===1&&password_verify('fixture-password',$row['PasswordHash']),'Sub-account created with own login, clinic, specialty and capitalized names');
accountReject(fn()=>savePhysicianSubAccount($pdo,11,$input),'Sub-account cannot create other accounts');
$input['member_id']=12;accountReject(fn()=>savePhysicianSubAccount($pdo,10,$input),'Owner cannot edit another clinic physician');
$input['member_id']=10;accountReject(fn()=>savePhysicianSubAccount($pdo,10,$input),'Owner cannot overwrite main account through sub form');
$input['member_id']=$id;$input['contact_number']='08123456789';try{savePhysicianSubAccount($pdo,10,$input);throw new LogicException('Bad phone accepted');}catch(RuntimeException $e){accountCheck($e->getMessage()==='Enter a valid mobile number','Mobile validation uses exact required message');}
accountReject(fn()=>setPhysicianSubAccountStatus($pdo,10,12,'Inactive'),'Cross-clinic deactivation blocked');
accountReject(fn()=>setPhysicianSubAccountStatus($pdo,10,10,'Inactive'),'Owner cannot deactivate main through sub form');
setPhysicianSubAccountStatus($pdo,10,$id,'Inactive');accountCheck(!in_array($id,clinicPhysicianIds($pdo,1),true),'Inactive sub-account excluded from patient booking');
setPhysicianSubAccountStatus($pdo,10,$id,'Active');accountCheck(in_array($id,clinicPhysicianIds($pdo,1),true),'Active main and sub accounts eligible for patient booking');
$pdo->exec("INSERT INTO Appointments(AppointmentID,PatientID,ClinicID,PhysicianID,AppointmentDate,Status,BookingFeePaid,BookingPaymentMethod) VALUES(1,1,1,11,CURDATE(),'Completed',1,'wallet');INSERT INTO WalletTransactions(PatientID,Type,Amount,BalanceAfter,RelatedAppointmentID) VALUES(1,'BookingPayment',500,0,1)");
$pdo->beginTransaction();recordCompletedDemoPayment($pdo,1);$pdo->commit();accountCheck((int)$pdo->query('SELECT FinancialOwnerUserID FROM AppointmentPayments')->fetchColumn()===10&&(int)$pdo->query('SELECT PhysicianID FROM Appointments')->fetchColumn()===11,'Revenue snapshots owner while preserving performing physician');
$balance=physicianWithdrawalBalance($pdo,10);accountCheck($balance['available']===45000,'Main account receives settled 90% share of sub consultation');
requestPhysicianWithdrawal($pdo,10,['request_key'=>str_repeat('d',64),'amount'=>'300','payout_method'=>'GCash','account_name'=>'Fixture User','phone'=>'09123456789']);accountCheck(physicianWithdrawalBalance($pdo,10)['available']===15000,'Pooled pending withdrawals reserve funds');
accountCheck((float)$pdo->query('SELECT WalletBalance FROM Users WHERE UserID=1')->fetchColumn()===0.0,'Owner settlement and withdrawal leave patient wallet unchanged');
$pdo->exec("INSERT INTO PhysicianWithdrawals (Reference,RequestKey,PhysicianID,FinancialOwnerUserID,Amount,PayoutMethod,AccountName,AccountNumber,Status) VALUES('DEMO-WD-HISTORY','".str_repeat('e',64)."',11,10,100,'GCash','Fixture User','09123456789','Pending')");
accountCheck(physicianWithdrawalBalance($pdo,10)['available']===5000,'Historical sub-account reservations stay reserved against pooled owner');
$historyId=(int)$pdo->query("SELECT WithdrawalID FROM PhysicianWithdrawals WHERE Reference='DEMO-WD-HISTORY'")->fetchColumn();reviewPhysicianWithdrawal($pdo,$historyId,30,'reject');accountCheck(physicianWithdrawalBalance($pdo,10)['available']===15000,'Rejecting historical request releases funds to Main');
$pdo->exec("INSERT INTO PhysicianWithdrawals (Reference,RequestKey,PhysicianID,FinancialOwnerUserID,Amount,PayoutMethod,AccountName,AccountNumber,Status) VALUES('DEMO-WD-OLD-PAID','".str_repeat('f',64)."',11,10,50,'GCash','Fixture User','09123456789','PaidSimulated')");
accountCheck(physicianWithdrawalBalance($pdo,10)['available']===10000,'Historical simulated payouts cannot be withdrawn again');
echo "Synthetic temporary fixtures only. No external services called.\n";
