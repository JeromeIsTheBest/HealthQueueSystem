<?php
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../config/db.php';require __DIR__.'/../includes/clinic-inquiry.php';
function priceCheck(bool $ok,string $label):void{if(!$ok)throw new LogicException($label);echo "PASS: $label\n";}
foreach(['0','0.00','500','500.25','99999999.99'] as $value)priceCheck(validClinicInquiryPrice($value),'Accept price '.$value);
foreach(['','-1','1.001','100000000','1e3','NaN','500 pesos'] as $value)priceCheck(!validClinicInquiryPrice($value),'Reject invalid price '.$value);
$pdo=getDbConnection();foreach(['Clinic','ClinicRegistrationInquiry'] as $table){$ddl=$pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];$ddl=preg_replace('/^CREATE TABLE/','CREATE TEMPORARY TABLE',$ddl);$ddl=preg_replace('/^.*CONSTRAINT.*FOREIGN KEY.*\n/m','',$ddl);$ddl=preg_replace('/,\s*\)/',')',$ddl);$pdo->exec($ddl);}
$pdo->prepare('INSERT INTO ClinicRegistrationInquiry(ClinicName,ContactName,Email,Phone,City,StaffCount,BaseConsultationFee) VALUES(?,?,?,?,?,?,?)')->execute(['Fixture clinic','Fixture contact','fixture@example.test','09000000000','Fixture city',3,'650.75']);
$inquiry=$pdo->query('SELECT * FROM ClinicRegistrationInquiry')->fetch();priceCheck($inquiry['BaseConsultationFee']==='650.75','Inquiry preserves exact submitted price');
$pdo->beginTransaction();$id=clinicFromRegistrationInquiry($pdo,$inquiry);$pdo->commit();
priceCheck($pdo->query('SELECT BaseConsultationFee FROM Clinic WHERE ClinicID='.$id)->fetchColumn()==='650.75','Approval configures new clinic consultation price');
$inquiry['BaseConsultationFee']='900.00';$pdo->beginTransaction();$again=clinicFromRegistrationInquiry($pdo,$inquiry);$pdo->commit();
priceCheck($again===$id && $pdo->query('SELECT BaseConsultationFee FROM Clinic WHERE ClinicID='.$id)->fetchColumn()==='650.75','Existing clinic price is preserved on duplicate inquiry');
$inquiry['ClinicName']='Legacy fixture';$inquiry['BaseConsultationFee']=null;$pdo->beginTransaction();$legacy=clinicFromRegistrationInquiry($pdo,$inquiry);$pdo->commit();
priceCheck($pdo->query('SELECT BaseConsultationFee FROM Clinic WHERE ClinicID='.$legacy)->fetchColumn()==='0.00','Older inquiries remain approvable without an invented price');
echo "Temporary tables only; no actual clinics or inquiries created.\n";
