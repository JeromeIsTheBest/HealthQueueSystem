<?php
/** Uses only connection-local shadow tables. HTTP mode exercises actual PHP multipart validation. */
if(!in_array(PHP_SAPI,['cli','cli-server'],true)){http_response_code(404);exit;}
require __DIR__.'/../config/db.php';require __DIR__.'/../includes/clinic-management.php';require __DIR__.'/../includes/availability.php';
$pdo=getDbConnection();foreach($pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN) as $table){$ddl=$pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];$ddl=preg_replace('/^CREATE TABLE/','CREATE TEMPORARY TABLE',$ddl);$ddl=preg_replace('/^.*CONSTRAINT.*FOREIGN KEY.*\n/m','',$ddl);$ddl=preg_replace('/,\s*\)/',')',$ddl);$pdo->exec($ddl);}
$pdo->exec("INSERT INTO Roles VALUES(1,'Patient'),(2,'Physician'),(3,'Staff'),(4,'Admin');INSERT INTO Clinic(ClinicID,ClinicName,Address,ContactNumber,BaseConsultationFee,Description) VALUES(1,'Fixture A','Test','09000000001',500,'Original'),(2,'Fixture B','Other','09000000002',600,'Other description')");
$s=$pdo->prepare("INSERT INTO Users(UserID,RoleID,ClinicID,FirstName,LastName,Email,PasswordHash) VALUES(?,?,?,'Fixture','User',?,'fixture')");foreach([[1,1,null],[10,2,1],[11,2,1],[12,2,2],[20,3,1],[30,4,null]] as [$id,$role,$clinic])$s->execute([$id,$role,$clinic,'fixture'.$id.'@example.test']);$pdo->exec('INSERT INTO PhysicianAccountOwners(ClinicID,MainPhysicianID) VALUES(1,10),(2,12)');
$settings=['open_days'=>[1,2,3,4,5,6,7],'open_time'=>'08:00','close_time'=>'18:00','description'=>'Updated clinic description'];
if(PHP_SAPI==='cli-server'){
 header('Content-Type: application/json');$created=[];
 try{saveManagedClinic($pdo,(int)($_GET['actor']??10),$_POST,$_FILES);$result=$pdo->query('SELECT OpenDays,OpenTime,CloseTime,Description,PhotoUrl FROM Clinic WHERE ClinicID=1')->fetch();$created=$pdo->query('SELECT Filename FROM ClinicPhoto')->fetchAll(PDO::FETCH_COLUMN);echo json_encode(['ok'=>true,'photos'=>count($created),'cover_set'=>$result['PhotoUrl']!==null]);}
 catch(Throwable $e){http_response_code(422);echo json_encode(['ok'=>false,'message'=>$e->getMessage()]);}
 finally{foreach($created as $name){if(!preg_match('/\Aclinic_[a-f0-9]{32}\.(jpg|png|webp)\z/',$name))throw new LogicException('Unsafe fixture photo filename');$fixturePath=realpath(__DIR__.'/../assets/uploads/clinics').DIRECTORY_SEPARATOR.$name;if(is_file($fixturePath))unlink($fixturePath);}}
 exit;
}
function managedCheck(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n";}
function managedReject(callable $fn,string $label):void{try{$fn();}catch(RuntimeException $e){if($e instanceof PDOException)throw $e;managedCheck(true,$label);return;}throw new RuntimeException($label);}
saveManagedClinic($pdo,10,$settings);$clinic=$pdo->query('SELECT * FROM Clinic WHERE ClinicID=1')->fetch();managedCheck($clinic['OpenTime']==='08:00:00'&&$clinic['CloseTime']==='18:00:00'&&$clinic['Description']===$settings['description'],'Owner updates own clinic opening schedule and description');
managedReject(fn()=>saveManagedClinic($pdo,11,$settings),'Sub-account cannot manage clinic');
managedReject(fn()=>saveManagedClinic($pdo,30,$settings),'Admin cannot use the physician clinic manager');
managedReject(fn()=>saveManagedClinic($pdo,10,$settings+['clinic_id'=>2]),'Owner cannot target another clinic');
managedReject(fn()=>saveManagedClinic($pdo,10,array_replace($settings,['close_time'=>'25:00'])),'Invalid clock time rejected server-side');
managedReject(fn()=>saveManagedClinic($pdo,10,array_replace($settings,['close_time'=>'07:00'])),'Reversed clinic hours rejected');
managedReject(fn()=>saveManagedClinic($pdo,10,array_replace($settings,['open_days'=>[8]])),'Invalid weekday rejected');
managedReject(fn()=>saveManagedClinic($pdo,10,array_replace($settings,['description'=>str_repeat('x',5001)])),'Description length limit enforced');
$tomorrow=date('Y-m-d',strtotime('+1 day'));$pdo->prepare("INSERT INTO PhysicianDateAvailability(PhysicianID,AvailDate,StartTime,EndTime) VALUES(10,?,'07:00','20:00')")->execute([$tomorrow]);
managedCheck(!isBookable($pdo,1,10,$tomorrow,'07:00')&&isBookable($pdo,1,10,$tomorrow,'08:00'),'Patient slots intersect clinic and physician schedules');
$pdo->prepare("INSERT INTO Appointments(AppointmentID,PatientID,ClinicID,PhysicianID,AppointmentDate,AppointmentTime,Status,BookingFeePaid) VALUES(1,1,1,10,?,'16:00','Confirmed',1)")->execute([$tomorrow]);
managedReject(fn()=>saveManagedClinic($pdo,10,array_replace($settings,['close_time'=>'15:00'])),'Shortened hours cannot strand an existing confirmed visit');
managedCheck($pdo->query('SELECT CloseTime FROM Clinic WHERE ClinicID=1')->fetchColumn()==='18:00:00','Rejected change rolls back hours');
$basic=['clinic_id'=>1,'clinic_name'=>'Fixture A updated','address'=>'Updated address','contact_number'=>'09000000001','base_consultation_fee'=>'550.00','specialties'=>'General'];
foreach(['description'=>'Forbidden','open_time'=>'09:00','close_time'=>'16:00','open_days'=>[1],'remove_photos'=>['other.jpg'],'photo_order'=>['other.jpg']] as $field=>$value)managedReject(fn()=>saveAdminClinicBasics($pdo,30,$basic+[$field=>$value]),'Admin crafted '.$field.' change rejected');
managedReject(fn()=>saveAdminClinicBasics($pdo,30,$basic,['photos'=>['name'=>['a.png']]]),'Admin crafted photo upload rejected');
managedReject(fn()=>saveAdminClinicBasics($pdo,10,$basic),'Physician cannot use admin basic-details writer');
managedReject(fn()=>saveAdminClinicBasics($pdo,30,$basic),'Admin cannot bypass fee request approval');$basic['base_consultation_fee']='500.00';saveAdminClinicBasics($pdo,30,$basic);$clinic=$pdo->query('SELECT * FROM Clinic WHERE ClinicID=1')->fetch();managedCheck($clinic['ClinicName']==='Fixture A updated'&&$clinic['BaseConsultationFee']==='500.00'&&$clinic['CloseTime']==='18:00:00'&&$clinic['Description']===$settings['description'],'Admin edits registration details while preserving owner settings');
$new=saveAdminClinicBasics($pdo,30,array_replace($basic,['clinic_id'=>0,'clinic_name'=>'New fixture']));$clinic=$pdo->query('SELECT * FROM Clinic WHERE ClinicID='.$new)->fetch();managedCheck($clinic['OpenDays']===''&&$clinic['OpenTime']==='00:00:00'&&$clinic['PhotoUrl']===null,'New admin clinic remains unconfigured until its owner sets hours');
managedCheck($pdo->query('SELECT Description FROM Clinic WHERE ClinicID=2')->fetchColumn()==='Other description','Other clinic is unchanged');
echo "Temporary tables only. No actual clinic records changed.\n";
