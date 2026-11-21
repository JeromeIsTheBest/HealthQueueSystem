<?php
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../config/db.php';
if(!in_array('--apply',$argv,true)){echo "Use --apply to install the physician notification inbox.\n";exit;}
$pdo=getDbConnection();if(!$pdo)exit(1);
$pdo->exec(file_get_contents(__DIR__.'/../database/migrations/20261120_physician_notifications.sql'));
echo "Physician notification inbox installed. No historical alerts or external messages sent.\n";
