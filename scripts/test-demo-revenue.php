<?php
// Uses connection-local temporary tables only; never changes application records.
require __DIR__ . '/../config/db.php';require __DIR__ . '/../includes/demo-revenue.php';
$pdo=getDbConnection();if(!$pdo)throw new RuntimeException('Database unavailable.');
$pdo->exec('CREATE TEMPORARY TABLE Roles (RoleID INT PRIMARY KEY,RoleName VARCHAR(20)) ENGINE=InnoDB');
$pdo->exec('CREATE TEMPORARY TABLE Clinic (ClinicID INT PRIMARY KEY,ClinicName VARCHAR(100)) ENGINE=InnoDB');
$pdo->exec('CREATE TEMPORARY TABLE Users (UserID INT PRIMARY KEY,RoleID INT,ClinicID INT,FirstName VARCHAR(50),LastName VARCHAR(50),Status VARCHAR(20),DeletedAt DATETIME NULL) ENGINE=InnoDB');
$pdo->exec('CREATE TEMPORARY TABLE PhysicianAccountOwners (ClinicID INT PRIMARY KEY,MainPhysicianID INT UNIQUE) ENGINE=InnoDB');
$pdo->exec("INSERT INTO Roles VALUES(1,'Admin'),(2,'Physician');INSERT INTO Clinic VALUES(1,'Fixture'),(2,'Fixture B');INSERT INTO Users VALUES(1,1,NULL,'Admin','Fixture','Active',NULL),(2,2,1,'Main','Fixture','Active',NULL),(4,2,2,'Other','Fixture','Active',NULL);INSERT INTO PhysicianAccountOwners VALUES(1,2),(2,4)");
$definitions=[
'PlatformDemoBankTransfers'=>"TransferID INT AUTO_INCREMENT PRIMARY KEY,PaymentID INT UNIQUE,Reference VARCHAR(50) UNIQUE,Amount DECIMAL(12,2),BankLabel VARCHAR(100) DEFAULT 'HealthQueue Demo Bank',AccountLastFour CHAR(4) DEFAULT '0000',Status VARCHAR(30) DEFAULT 'CompletedSimulated',CreatedAt DATETIME DEFAULT CURRENT_TIMESTAMP",
'Appointments'=>'AppointmentID INT PRIMARY KEY,PatientID INT,PhysicianID INT,Status VARCHAR(20),BookingFeePaid INT,BookingPaymentMethod VARCHAR(20),ClinicID INT DEFAULT 1',
'WalletTransactions'=>'TransactionID INT AUTO_INCREMENT PRIMARY KEY,RelatedAppointmentID INT,PatientID INT,Type VARCHAR(20),Amount DECIMAL(10,2)',
'AppointmentPayments'=>"PaymentID INT AUTO_INCREMENT PRIMARY KEY,AppointmentID INT UNIQUE,GrossAmount DECIMAL(10,2),PhysicianRevenue DECIMAL(10,2),PlatformCommission DECIMAL(10,2),PaymentStatus VARCHAR(20),PaidAt DATETIME DEFAULT CURRENT_TIMESTAMP,RefundedAt DATETIME NULL,FinancialOwnerUserID INT NULL",
'DemoSettlements'=>'SettlementID INT AUTO_INCREMENT PRIMARY KEY,RequestKey VARCHAR(64) UNIQUE,Reference VARCHAR(40) UNIQUE,GrossAmount DECIMAL(12,2),CreatedByUserID INT,CreatedAt DATETIME DEFAULT CURRENT_TIMESTAMP',
'DemoSettlementItems'=>'SettlementID INT,PaymentID INT UNIQUE,PRIMARY KEY(SettlementID,PaymentID)'];
foreach($definitions as $table=>$definition)$pdo->exec("CREATE TEMPORARY TABLE {$table} ({$definition}) ENGINE=InnoDB");
function checkDemo(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$pdo->exec("INSERT INTO Appointments(AppointmentID,PatientID,PhysicianID,Status,BookingFeePaid,BookingPaymentMethod) VALUES(1,1,2,'Completed',1,'wallet'),(2,1,2,'Confirmed',1,'wallet'),(3,1,2,'Completed',0,'wallet'),(4,1,2,'Completed',1,'wallet'),(5,1,2,'Completed',1,'card'),(6,1,2,'Completed',1,'wallet')");
$pdo->exec("INSERT INTO WalletTransactions(RelatedAppointmentID,PatientID,Type,Amount) VALUES(1,1,'BookingPayment',500),(2,1,'BookingPayment',500),(4,1,'BookingPayment',500),(4,1,'Refund',500),(6,1,'BookingPayment',100.01)");
$pdo->beginTransaction();
checkDemo(recordCompletedDemoPayment($pdo,1),'Paid completed visit not recognized');
checkDemo(!recordCompletedDemoPayment($pdo,1),'Duplicate commission');
foreach([2,3,4,5] as $id)checkDemo(!recordCompletedDemoPayment($pdo,$id),'Ineligible visit recognized');
checkDemo(recordCompletedDemoPayment($pdo,6),'Decimal payment not recognized');$pdo->commit();
$summary=demoRevenueSummary($pdo);checkDemo((string)$summary['gross']==='600.01' && (float)$summary['pending']===0.0 && (string)$summary['settled']==='600.01','Pending totals incorrect');
checkDemo((string)$summary['commission']==='60.00' && (string)$summary['physician']==='540.01','Split/rounding incorrect');
checkDemo((int)$pdo->query('SELECT COUNT(*) FROM DemoSettlementItems')->fetchColumn()===2,'Automatic releases missing');
// Retain legacy settlement API regression coverage using isolated fixture allocations only.
$pdo->exec('DELETE FROM DemoSettlementItems;DELETE FROM DemoSettlements');
$key=str_repeat('a',64);$result=simulateDemoSettlement($pdo,1,$key);$retry=simulateDemoSettlement($pdo,1,$key);
checkDemo($result['Reference']===$retry['Reference'],'Repeated request not idempotent');
$summary=demoRevenueSummary($pdo);checkDemo((float)$summary['pending']===0.0 && (string)$summary['settled']==='600.01','Settlement totals incorrect');
try{simulateDemoSettlement($pdo,1,str_repeat('b',64));throw new LogicException('Empty settlement allowed');}catch(RuntimeException $e){checkDemo(str_contains($e->getMessage(),'No completed demo payments'),'Unexpected failure');}
checkDemo((int)$pdo->query('SELECT COUNT(*) FROM DemoSettlements')->fetchColumn()===1,'Duplicate settlement record');
checkDemo((string)$pdo->query("SELECT SUM(Amount) FROM WalletTransactions WHERE Type='BookingPayment'")->fetchColumn()==='1600.01','Simulation changed wallet payments');
echo "PASS: eligible payments, 90/10 rounding, duplicate recognition, refund/unpaid exclusion, settlement totals, idempotent replay, and empty balance rejection. Temporary fixtures only.\n";
