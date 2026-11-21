<?php
/** Metadata-only preflight plus additive DDL. Never disables FK checking. */
function standaloneLinkDefinitions():array {return [
 ['SmsOutbox','PatientID','Users','UserID','fk_sms_patient'],
 ['SmsOutbox','AppointmentID','Appointments','AppointmentID','fk_sms_appointment'],
 ['ClinicRegistrationInquiry','ApprovedClinicID','Clinic','ClinicID','fk_inquiry_approved_clinic'],
 ['SignupEmailVerifications','UserID','Users','UserID','fk_signup_verification_user'],
 ['RequestRateLimits','UserID','Users','UserID','fk_rate_limit_user']
];}
function standaloneLinkColumn(PDO $p,string $table,string $column):?array {
 $q=$p->prepare('SELECT COLUMN_TYPE,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND LOWER(TABLE_NAME)=LOWER(?) AND COLUMN_NAME=?');$q->execute([$table,$column]);return $q->fetch()?:null;
}
function preflightStandaloneLinks(PDO $p):array {
 $report=[];foreach(standaloneLinkDefinitions() as [$table,$column,$parent,$key,$name]){
  $pc=standaloneLinkColumn($p,$parent,$key);$cc=standaloneLinkColumn($p,$table,$column);if(!$pc||($cc&&$pc['COLUMN_TYPE']!==$cc['COLUMN_TYPE']))throw new RuntimeException('Relationship column types do not match: '.$table.'.'.$column);
  $orphans=$cc?(int)$p->query("SELECT COUNT(*) FROM `$table` c LEFT JOIN `$parent` p ON p.`$key`=c.`$column` WHERE c.`$column` IS NOT NULL AND p.`$key` IS NULL")->fetchColumn():0;
  if($orphans)throw new RuntimeException("Resolve {$orphans} invalid links in {$table}.{$column} before migration. No records changed.");
  $report[$table.'.'.$column]=['orphans'=>$orphans,'column_exists'=>$cc!==null];
 }
 $bad=(int)$p->query('SELECT COUNT(*) FROM SmsOutbox s JOIN Appointments a ON a.AppointmentID=s.AppointmentID WHERE s.PatientID IS NOT NULL AND s.PatientID<>a.PatientID')->fetchColumn();if($bad)throw new RuntimeException('SMS records have mismatched appointment ownership. Investigate before migration.');return $report;
}
function applyStandaloneLinks(PDO $p):void {
 if($p->inTransaction())throw new LogicException('Schema changes cannot run inside an application transaction.');preflightStandaloneLinks($p);
 foreach(standaloneLinkDefinitions() as [$table,$column,$parent,$key,$name]){
  $cc=standaloneLinkColumn($p,$table,$column);$type=standaloneLinkColumn($p,$parent,$key)['COLUMN_TYPE'];
  if(!$cc)$p->exec("ALTER TABLE `$table` ADD COLUMN `$column` {$type} NULL DEFAULT NULL");
  elseif($cc['IS_NULLABLE']!=='YES')$p->exec("ALTER TABLE `$table` MODIFY COLUMN `$column` {$type} NULL DEFAULT NULL");
  $q=$p->prepare('SELECT k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND LOWER(k.TABLE_NAME)=LOWER(?) AND k.COLUMN_NAME=? AND k.REFERENCED_TABLE_NAME IS NOT NULL');$q->execute([$table,$column]);$fk=$q->fetch();
  if($fk){if(strcasecmp($fk['REFERENCED_TABLE_NAME'],$parent)!==0||$fk['REFERENCED_COLUMN_NAME']!==$key||$fk['DELETE_RULE']!=='SET NULL')throw new RuntimeException('Existing relationship has different rules: '.$table.'.'.$column);continue;}
  $p->exec("ALTER TABLE `$table` ADD CONSTRAINT `$name` FOREIGN KEY (`$column`) REFERENCES `$parent` (`$key`) ON DELETE SET NULL ON UPDATE RESTRICT");
 }
}
