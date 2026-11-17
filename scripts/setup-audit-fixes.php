<?php
if(PHP_SAPI!=='cli'){http_response_code(403);exit('CLI only.');}
if(!in_array('--apply',$argv,true))exit("Run with --apply to install the audit protections.\n");
require __DIR__.'/../config/db.php';require __DIR__.'/../includes/input-validation.php';require __DIR__.'/../includes/sms.php';$pdo=getDbConnection();if(!$pdo)throw new RuntimeException('Database unavailable.');
$pdo->exec(file_get_contents(__DIR__.'/../database/migrations/20261117_audit_fixes.sql'));
$count=0;$rows=$pdo->query('SELECT UserID,FirstName,LastName FROM Users')->fetchAll();
$update=$pdo->prepare('UPDATE Users SET FirstName=?,LastName=? WHERE UserID=?');
foreach($rows as $row){$first=hqCapitalizeName($row['FirstName']);$last=hqCapitalizeName($row['LastName']);if($first!==$row['FirstName']||$last!==$row['LastName']){$update->execute([$first,$last,$row['UserID']]);$count++;}}
$phoneCount=0;$updatePhone=$pdo->prepare('UPDATE Users SET ContactNumber=? WHERE UserID=?');
foreach($pdo->query("SELECT UserID,ContactNumber FROM Users WHERE ContactNumber IS NOT NULL AND ContactNumber<>''")->fetchAll() as $row){
 if(!hqValidMobile($row['ContactNumber'])){$normalized=normalizeSmsNumber($row['ContactNumber']);if($normalized){$local='0'.substr($normalized,2);if(hqValidMobile($local)){$updatePhone->execute([$local,$row['UserID']]);$phoneCount++;}}}
}
echo 'Audit schema installed. Names normalized: '.$count.'. Recognized international/formatted mobile numbers normalized: '.$phoneCount.'. Unrecognized phone values and historical wallet entries were preserved.'.PHP_EOL;
