<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../config/db.php';require_once __DIR__.'/../includes/queue-email.php';
$p=getDbConnection();if(!$p)exit(1);
$p->exec('CREATE TEMPORARY TABLE Users (UserID INT PRIMARY KEY,Email VARCHAR(100),FirstName VARCHAR(50),Status VARCHAR(20),DeletedAt DATETIME NULL)');
$p->exec('CREATE TEMPORARY TABLE PatientProfileDetails (UserID INT PRIMARY KEY,Details TEXT)');
$p->exec('CREATE TEMPORARY TABLE Clinic (ClinicID INT PRIMARY KEY,ClinicName VARCHAR(60))');
$p->exec('CREATE TEMPORARY TABLE Appointments (AppointmentID INT PRIMARY KEY,PatientID INT,Status VARCHAR(20),AppointmentDate DATE,AppointmentTime TIME NULL)');
$p->exec('CREATE TEMPORARY TABLE Queue (QueueID INT PRIMARY KEY,ClinicID INT,AppointmentID INT,Status VARCHAR(20),QueueNumber INT,ScheduledNumber INT NULL,RegularNumber INT NULL,Position INT NULL,CreatedAt DATETIME)');
$p->exec("CREATE TEMPORARY TABLE QueueEmailOutbox (EmailID INT AUTO_INCREMENT PRIMARY KEY,QueueID INT,PatientID INT,AppointmentID INT,EventType VARCHAR(20),Status VARCHAR(20) DEFAULT 'pending',Attempts INT DEFAULT 0,LastError VARCHAR(200),CreatedAt DATETIME DEFAULT CURRENT_TIMESTAMP,UpdatedAt DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE(QueueID,EventType))");
$p->exec("INSERT INTO Clinic VALUES (1,'Test clinic')");
for($i=1;$i<=5;$i++){
$p->prepare("INSERT INTO Users VALUES (?,?,'Preview','Active',NULL)")->execute([$i,'preview'.$i.'@example.test']);
$p->prepare("INSERT INTO Appointments VALUES (?,?,'Confirmed',CURDATE(),NULL)")->execute([$i,$i]);
$p->prepare("INSERT INTO Queue VALUES (?,1,?,'Waiting',?,NULL,?,NULL,NOW())")->execute([$i,$i,$i,$i]);}
$check=static function($ok,$label){if(!$ok)throw new RuntimeException($label);echo 'PASS: '.$label.PHP_EOL;};
$attempts=[];$sender=static function($recipient,$ticket,$event)use(&$attempts){$attempts[]=[$ticket,$event];return $ticket!=='#3';};
$r=dispatchQueueEmails($p,$sender);
$check($r['sent']===2&&$r['failed']===1&&count($attempts)===3,'Worker sends near reminders for 0, 1 and 2 waiting ahead without a patient page');
$r=dispatchQueueEmails($p,$sender);$check(count($attempts)===3,'Successful reminders are not duplicated and failures wait before retry');
$p->exec("UPDATE QueueEmailOutbox SET UpdatedAt=DATE_SUB(NOW(),INTERVAL 6 MINUTE) WHERE Status='failed'");
$r=dispatchQueueEmails($p,static fn()=>true);$check($r['sent']===1,'Failed send retries independently of existing in-app notifications');
$p->exec("UPDATE Queue SET Status='Calling' WHERE QueueID=1");
$r=dispatchQueueEmails($p,static fn()=>true);$check($r['sent']===2,'Called email is separate and next eligible patient gets near reminder');
$p->exec("UPDATE Queue SET Status='Removed' WHERE QueueID=5");
$p->exec("INSERT INTO QueueEmailOutbox (QueueID,PatientID,AppointmentID,EventType) VALUES (5,5,5,'near')");
$r=dispatchQueueEmails($p,static fn()=>throw new RuntimeException('Should not send'));
$check($r['skipped']===1,'Removed queue entries are skipped');
$p->exec("INSERT INTO PatientProfileDetails(UserID,Details) VALUES(3,'{\"email_notifications\":false}')");
$p->exec("UPDATE Queue SET Status='Calling' WHERE QueueID=3");
$optOutCalls=0;$r=dispatchQueueEmails($p,static function()use(&$optOutCalls){$optOutCalls++;return true;});
$check($optOutCalls===0 && $r['skipped']>=1,'Email opt-out prevents delivery through the actual dispatcher');
echo "Temporary tables only. No real messages sent or patient records changed.".PHP_EOL;
