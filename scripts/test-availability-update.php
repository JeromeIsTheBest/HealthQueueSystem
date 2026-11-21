<?php
/** Uses only connection-local shadow tables. HTTP mode exercises actual PHP multipart validation. */
if(!in_array(PHP_SAPI,['cli','cli-server'],true)){http_response_code(404);exit;}
require __DIR__.'/../config/db.php';require __DIR__.'/../includes/clinic-management.php';require __DIR__.'/../includes/physician-availability.php';
$pdo=getDbConnection();foreach($pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN) as $table){$ddl=$pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];$ddl=preg_replace('/^CREATE TABLE/','CREATE TEMPORARY TABLE',$ddl);$ddl=preg_replace('/^.*CONSTRAINT.*FOREIGN KEY.*\n/m','',$ddl);$ddl=preg_replace('/,\s*\)/',')',$ddl);$pdo->exec($ddl);}
$pdo->exec("INSERT INTO Roles VALUES(1,'Patient'),(2,'Physician'),(3,'Staff'),(4,'Admin');INSERT INTO Clinic(ClinicID,ClinicName,Address,ContactNumber,BaseConsultationFee,Description) VALUES(1,'Fixture A','Test','09000000001',500,'Original'),(2,'Fixture B','Other','09000000002',600,'Other description')");
$s=$pdo->prepare("INSERT INTO Users(UserID,RoleID,ClinicID,FirstName,LastName,Email,PasswordHash) VALUES(?,?,?,'Fixture','User',?,'fixture')");foreach([[1,1,null],[10,2,1],[11,2,1],[12,2,2],[20,3,1],[30,4,null]] as [$id,$role,$clinic])$s->execute([$id,$role,$clinic,'fixture'.$id.'@example.test']);$pdo->exec('INSERT INTO PhysicianAccountOwners(ClinicID,MainPhysicianID) VALUES(1,10),(2,12)');

function checkUpdate(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n";}
function rejectUpdate(callable $fn,string $label):void{try{$fn();}catch(RuntimeException $e){if($e instanceof PDOException)throw $e;checkUpdate(true,$label);return;}throw new RuntimeException($label);}
$date=(string)$pdo->query('SELECT CURDATE()')->fetchColumn();
$pdo->prepare("INSERT INTO PhysicianDateAvailability(SlotID,PhysicianID,AvailDate,StartTime,EndTime) VALUES(1,10,?,'08:00','12:00'),(2,10,?,'14:00','17:00'),(3,11,?,'08:00','12:00')")->execute([$date,$date,$date]);
updatePhysicianAvailability($pdo,10,$date,1,'09:00','13:00',5);
checkUpdate($pdo->query('SELECT StartTime FROM PhysicianDateAvailability WHERE SlotID=1')->fetchColumn()==='09:00:00','Saved block updated');
checkUpdate($pdo->query('SELECT COUNT(*) FROM PhysicianDateAvailability')->fetchColumn()==3,'Update does not add or remove other blocks');
rejectUpdate(fn()=>updatePhysicianAvailability($pdo,11,$date,1,'09:00','13:00',4),'Cannot edit another physician schedule');
rejectUpdate(fn()=>updatePhysicianAvailability($pdo,10,$date,1,'09:00','15:00',4),'Overlapping update rejected');
$pdo->prepare("INSERT INTO Appointments(PatientID,ClinicID,PhysicianID,AppointmentDate,AppointmentTime,Status,BookingFeePaid) VALUES(1,1,10,?,'10:00','Confirmed',1)")->execute([$date]);
rejectUpdate(fn()=>updatePhysicianAvailability($pdo,10,$date,1,'11:00','13:00',4),'Existing appointment cannot be excluded');
checkUpdate($pdo->query('SELECT StartTime FROM PhysicianDateAvailability WHERE SlotID=1')->fetchColumn()==='09:00:00','Rejected update rolls back');
rejectUpdate(fn()=>updatePhysicianAvailability($pdo,10,$date,1,'09:00','08:00',4),'Invalid times rejected');
updatePhysicianAvailability($pdo,11,$date,3,'01:00','05:30',4);
checkUpdate($pdo->query('SELECT StartTime FROM PhysicianDateAvailability WHERE SlotID=3')->fetchColumn()==='01:00:00','Early morning schedule updates accepted');
rejectUpdate(fn()=>updatePhysicianAvailability($pdo,11,$date,3,'00:30','05:30',4),'Times outside dropdown range rejected');
echo "Temporary fixtures only. No live schedules changed.\n";
