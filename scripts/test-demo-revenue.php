<?php
// Uses connection-local temporary tables only; never changes application records.
require __DIR__ . '/../config/db.php';require __DIR__ . '/../includes/demo-revenue.php';
$pdo=getDbConnection();if(!$pdo)throw new RuntimeException('Database unavailable.');
$definitions=[
'Appointments'=>'AppointmentID INT PRIMARY KEY,PatientID INT,PhysicianID INT,Status VARCHAR(20),BookingFeePaid INT,BookingPaymentMethod VARCHAR(20)',
'WalletTransactions'=>'TransactionID INT AUTO_INCREMENT PRIMARY KEY,RelatedAppointmentID INT,PatientID INT,Type VARCHAR(20),Amount DECIMAL(10,2)',
'AppointmentPayments'=>"PaymentID INT AUTO_INCREMENT PRIMARY KEY,AppointmentID INT UNIQUE,GrossAmount DECIMAL(10,2),PhysicianRevenue DECIMAL(10,2),PlatformCommission DECIMAL(10,2),PaymentStatus VARCHAR(20),PaidAt DATETIME DEFAULT CURRENT_TIMESTAMP,RefundedAt DATETIME NULL",
'DemoSettlements'=>'SettlementID INT AUTO_INCREMENT PRIMARY KEY,RequestKey VARCHAR(64) UNIQUE,Reference VARCHAR(40) UNIQUE,GrossAmount DECIMAL(12,2),CreatedByUserID INT,CreatedAt DATETIME DEFAULT CURRENT_TIMESTAMP',
'DemoSettlementItems'=>'SettlementID INT,PaymentID INT UNIQUE,PRIMARY KEY(SettlementID,PaymentID)'];
foreach($definitions as $table=>$definition)$pdo->exec("CREATE TEMPORARY TABLE {$table} ({$definition}) ENGINE=InnoDB");
function checkDemo(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$pdo->exec("INSERT INTO Appointments VALUES(1,1,2,'Completed',1,'wallet'),(2,1,2,'Confirmed',1,'wallet'),(3,1,2,'Completed',0,'wallet'),(4,1,2,'Completed',1,'wallet'),(5,1,2,'Completed',1,'card'),(6,1,2,'Completed',1,'wallet')");
$pdo->exec("INSERT INTO WalletTransactions(RelatedAppointmentID,PatientID,Type,Amount) VALUES(1,1,'BookingPayment',500),(2,1,'BookingPayment',500),(4,1,'BookingPayment',500),(4,1,'Refund',500),(6,1,'BookingPayment',100.01)");
$pdo->beginTransaction();
checkDemo(recordCompletedDemoPayment($pdo,1),'Paid completed visit not recognized');
checkDemo(!recordCompletedDemoPayment($pdo,1),'Duplicate commission');
foreach([2,3,4,5] as $id)checkDemo(!recordCompletedDemoPayment($pdo,$id),'Ineligible visit recognized');
checkDemo(recordCompletedDemoPayment($pdo,6),'Decimal payment not recognized');$pdo->commit();
$summary=demoRevenueSummary($pdo);checkDemo((string)$summary['gross']==='600.01' && (string)$summary['pending']==='600.01','Pending totals incorrect');
checkDemo((string)$summary['commission']==='60.00' && (string)$summary['physician']==='540.01','Split/rounding incorrect');
$key=str_repeat('a',64);$result=simulateDemoSettlement($pdo,1,$key);$retry=simulateDemoSettlement($pdo,1,$key);
checkDemo($result['Reference']===$retry['Reference'],'Repeated request not idempotent');
$summary=demoRevenueSummary($pdo);checkDemo((float)$summary['pending']===0.0 && (string)$summary['settled']==='600.01','Settlement totals incorrect');
try{simulateDemoSettlement($pdo,1,str_repeat('b',64));throw new LogicException('Empty settlement allowed');}catch(RuntimeException $e){checkDemo(str_contains($e->getMessage(),'No completed demo payments'),'Unexpected failure');}
checkDemo((int)$pdo->query('SELECT COUNT(*) FROM DemoSettlements')->fetchColumn()===1,'Duplicate settlement record');
checkDemo((string)$pdo->query("SELECT SUM(Amount) FROM WalletTransactions WHERE Type='BookingPayment'")->fetchColumn()==='1600.01','Simulation changed wallet payments');
echo "PASS: eligible payments, 90/10 rounding, duplicate recognition, refund/unpaid exclusion, settlement totals, idempotent replay, and empty balance rejection. Temporary fixtures only.\n";
