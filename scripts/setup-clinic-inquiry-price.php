<?php
if (PHP_SAPI !== 'cli') exit;
require __DIR__.'/../config/db.php';
if (!in_array('--apply',$argv,true)) {echo "Use --apply to add the inquiry consultation price column.\n";exit;}
$pdo=getDbConnection();if (!$pdo) exit(1);
$pdo->exec(file_get_contents(__DIR__.'/../database/migrations/20261118_clinic_inquiry_price.sql'));
echo "Inquiry consultation price column installed. Existing clinic fees preserved.\n";
