<?php
// Reuse the isolated demo payment/settlement fixtures, never real records.
require __DIR__.'/test-demo-revenue.php';require __DIR__.'/../includes/physician-withdrawals.php';
$pdo->exec('CREATE TEMPORARY TABLE Users (UserID INT PRIMARY KEY,RoleID INT,Status VARCHAR(20)) ENGINE=InnoDB');
$pdo->exec('CREATE TEMPORARY TABLE Roles (RoleID INT PRIMARY KEY,RoleName VARCHAR(20)) ENGINE=InnoDB');
$pdo->exec("INSERT INTO Roles VALUES(1,'Admin'),(2,'Physician')");$pdo->exec("INSERT INTO Users VALUES(1,1,'Active'),(2,2,'Active'),(4,2,'Active')");
$fixtureSql=file_get_contents(__DIR__.'/../database/migrations/20261117_physician_withdrawals.sql');
$fixtureSql=str_replace('CREATE TABLE IF NOT EXISTS','CREATE TEMPORARY TABLE',$fixtureSql);
$fixtureSql=preg_replace('/^.*CONSTRAINT.*\n/m','',$fixtureSql);
$fixtureSql=preg_replace('/,\s*\)/',')',$fixtureSql);
$pdo->exec($fixtureSql);
function withdrawalInput(string $key,string $amount='100.00'):array{return ['request_key'=>str_repeat($key,64),'amount'=>$amount,'payout_method'=>'GCash','account_name'=>'sofia reyes','phone'=>'09123456789'];}
$balance=physicianWithdrawalBalance($pdo,2);checkDemo($balance['available']===54001,'Incorrect initial physician share');
$first=requestPhysicianWithdrawal($pdo,2,withdrawalInput('c'));checkDemo(physicianWithdrawalBalance($pdo,2)['available']===44001,'Pending amount not reserved');
checkDemo(requestPhysicianWithdrawal($pdo,2,withdrawalInput('c'))===$first,'Duplicate request not idempotent');
try{requestPhysicianWithdrawal($pdo,2,withdrawalInput('d','500.00'));throw new LogicException('Overspend allowed');}catch(RuntimeException $e){checkDemo(str_contains($e->getMessage(),'exceeds'),'Wrong overspend response');}
try{requestPhysicianWithdrawal($pdo,4,withdrawalInput('c'));throw new LogicException('Foreign request key accepted');}catch(RuntimeException $e){checkDemo(str_contains($e->getMessage(),'Invalid withdrawal'),'Foreign request not rejected');}
$id=(int)$pdo->query('SELECT WithdrawalID FROM PhysicianWithdrawals LIMIT 1')->fetchColumn();checkDemo(reviewPhysicianWithdrawal($pdo,$id,1,'reject'),'Reject failed');checkDemo(physicianWithdrawalBalance($pdo,2)['available']===54001,'Rejected funds not released');checkDemo(!reviewPhysicianWithdrawal($pdo,$id,1,'pay'),'Rejected request paid later');
$second=requestPhysicianWithdrawal($pdo,2,withdrawalInput('e','200.00'));$stmt=$pdo->prepare('SELECT WithdrawalID FROM PhysicianWithdrawals WHERE Reference=?');$stmt->execute([$second]);$secondId=(int)$stmt->fetchColumn();
checkDemo(reviewPhysicianWithdrawal($pdo,$secondId,1,'pay'),'Simulated payout failed');checkDemo(!reviewPhysicianWithdrawal($pdo,$secondId,1,'pay'),'Double payout allowed');
$balance=physicianWithdrawalBalance($pdo,2);checkDemo($balance['available']===34001&&$balance['pending']===0&&$balance['paid']===20000,'Paid balances incorrect');
$pdo->exec("UPDATE Appointments SET Status='Completed' WHERE AppointmentID=2");$pdo->beginTransaction();recordCompletedDemoPayment($pdo,2);$pdo->commit();$balance=physicianWithdrawalBalance($pdo,2);checkDemo($balance['available']===34001&&$balance['awaiting']===45000,'Unsettled earnings became withdrawable');
$bank=withdrawalInput('f','340.01');$bank['payout_method']='Bank';$bank['bank_name']='Demo Bank';$bank['bank_account_number']='0012345678';requestPhysicianWithdrawal($pdo,2,$bank);checkDemo(physicianWithdrawalBalance($pdo,2)['available']===0,'Full balance request failed');
$bad=withdrawalInput('a','1.00');$bad['phone']='08123456789';try{requestPhysicianWithdrawal($pdo,2,$bad);throw new LogicException('Invalid phone accepted');}catch(RuntimeException $e){checkDemo($e->getMessage()==='Enter a valid mobile number','Wrong phone validation');}
foreach(['1e2','-10','0.001'] as $amount){try{withdrawalCents($amount);throw new LogicException('Invalid amount accepted');}catch(RuntimeException $e){checkDemo(str_contains($e->getMessage(),'valid amount'),'Wrong amount validation');}}
checkDemo(physicianWithdrawalBalance($pdo,4)['earned']===0,'Physician ownership leaked earnings');
echo "PASS: reservation, rejection release, simulated payout, duplicate requests/reviews, overspend prevention, unsettled funds, bank destination, validation, and physician isolation. Temporary fixtures only.\n";
