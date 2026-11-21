<?php
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../config/db.php';
if(!in_array('--apply',$argv,true)){echo "Use --apply to install fee requests and preserve existing booking prices.\n";exit;}
$pdo=getDbConnection();if(!$pdo)exit(1);
$pdo->exec(file_get_contents(__DIR__.'/../database/migrations/20261120_clinic_fee_requests.sql'));
echo "Fee request schema installed; existing booking prices preserved. No wallet balances changed.\n";
