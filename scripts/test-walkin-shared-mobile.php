<?php
/** Uses only connection-local shadow tables. HTTP mode exercises actual PHP multipart validation. */
if(!in_array(PHP_SAPI,['cli','cli-server'],true)){http_response_code(404);exit;}
session_set_save_handler(static fn($p,$n)=>true,static fn()=>true,static fn($id)=>'',static fn($id,$data)=>true,static fn($id)=>true,static fn($n)=>0);
require __DIR__.'/../config/db.php';require __DIR__.'/../includes/clinic-management.php';require __DIR__.'/../includes/walk-in-patient.php';
$pdo=getDbConnection();foreach($pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN) as $table){$ddl=$pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];$ddl=preg_replace('/^CREATE TABLE/','CREATE TEMPORARY TABLE',$ddl);$ddl=preg_replace('/^.*CONSTRAINT.*FOREIGN KEY.*\n/m','',$ddl);$ddl=preg_replace('/,\s*\)/',')',$ddl);$pdo->exec($ddl);}
$pdo->exec("INSERT INTO Roles VALUES(1,'Patient'),(2,'Physician'),(3,'Staff'),(4,'Admin');INSERT INTO Clinic(ClinicID,ClinicName,Address,ContactNumber,BaseConsultationFee,Description) VALUES(1,'Fixture A','Test','09000000001',500,'Original'),(2,'Fixture B','Other','09000000002',600,'Other description')");
$s=$pdo->prepare("INSERT INTO Users(UserID,RoleID,ClinicID,FirstName,LastName,Email,PasswordHash) VALUES(?,?,?,'Fixture','User',?,'fixture')");foreach([[1,1,null],[10,2,1],[11,2,1],[12,2,2],[20,3,1],[30,4,null]] as [$id,$role,$clinic])$s->execute([$id,$role,$clinic,'fixture'.$id.'@example.test']);$pdo->exec('INSERT INTO PhysicianAccountOwners(ClinicID,MainPhysicianID) VALUES(1,10),(2,12)');

function walkInCheck(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n";}
$pdo->exec("UPDATE Users SET ContactNumber='09123456789' WHERE UserID=1");
$values=['first_name'=>'test','last_name'=>'sample','contact_number'=>'09123456789','email'=>''];
$pdo->beginTransaction();$first=createWalkInPatient($pdo,$values);$second=createWalkInPatient($pdo,array_replace($values,['first_name'=>'Other']));$pdo->commit();
walkInCheck($first!==$second&&$first!==1&&$second!==1,'Shared contact creates separate walk-in identities');
walkInCheck((int)$pdo->query("SELECT COUNT(*) FROM Users WHERE ContactNumber='09123456789'")->fetchColumn()===3,'Walk-ins can share an existing registered mobile');
walkInCheck((int)$pdo->query('SELECT COUNT(*) FROM Users WHERE IsWalkInAccount=1 AND AccountMobileNumber IS NULL')->fetchColumn()===2,'Only walk-in-created records receive the exception');
walkInCheck($pdo->query('SELECT FirstName FROM Users WHERE UserID='.$first)->fetchColumn()==='Test','Walk-in names still capitalize');
walkInCheck(hqMobileInUse($pdo,'09123456789'),'Other forms still detect duplicate mobile');
try{$pdo->exec("INSERT INTO Users(RoleID,FirstName,LastName,Email,PasswordHash,ContactNumber) VALUES(2,'Regular','Fixture','regular@example.test','fixture','09123456789')");throw new RuntimeException('Expected duplicate protection');}catch(PDOException $e){walkInCheck(hqDuplicateMobileError($e),'Database still rejects duplicate regular accounts');}
$pdo->beginTransaction();$invalid=false;try{createWalkInPatient($pdo,array_replace($values,['contact_number'=>'08123456789']));}catch(RuntimeException $e){$invalid=$e->getMessage()==='Enter a valid mobile number';}$pdo->rollBack();walkInCheck($invalid,'Walk-in mobile format validation preserved');
$pdo->beginTransaction();$duplicateEmail=false;try{createWalkInPatient($pdo,array_replace($values,['email'=>'fixture1@example.test']));}catch(PDOException $e){$duplicateEmail=true;}$pdo->rollBack();walkInCheck($duplicateEmail,'Existing email is never silently assigned to a new patient');
walkInCheck((int)$pdo->query('SELECT IsWalkInAccount FROM Users WHERE UserID=1')->fetchColumn()===0,'Existing account unchanged');
$pdo->beginTransaction();$rolled=createWalkInPatient($pdo,array_replace($values,['first_name'=>'Rollback']));$pdo->rollBack();walkInCheck((int)$pdo->query('SELECT COUNT(*) FROM Users WHERE UserID='.$rolled)->fetchColumn()===0,'Failed queue transaction rolls back new walk-in');
echo "Temporary fixtures only. No actual patient accounts, messages or queue entries created.\n";
