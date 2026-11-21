<?php
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../config/db.php';
if(!in_array('--apply',$argv,true)){echo "Use --apply to enforce unique account mobile numbers.\n";exit;}
$pdo=getDbConnection();if(!$pdo)exit(1);
$hasWalkIn=(bool)$pdo->query("SHOW COLUMNS FROM Users LIKE 'IsWalkInAccount'")->fetch();
$regularOnly=$hasWalkIn?' AND IsWalkInAccount=0':'';
$duplicates=(int)$pdo->query("SELECT COUNT(*) FROM (SELECT TRIM(ContactNumber) FROM Users WHERE ContactNumber IS NOT NULL AND TRIM(ContactNumber)<>'' {$regularOnly} GROUP BY TRIM(ContactNumber) HAVING COUNT(*)>1) d")->fetchColumn();
if($duplicates){fwrite(STDERR,"Existing duplicate mobile numbers must be corrected before installing this constraint. No account records changed.\n");exit(1);}
$pdo->exec(file_get_contents(__DIR__.'/../database/migrations/20261117_unique_account_mobile.sql'));
echo "Unique account mobile numbers enforced. No account records changed.\n";
