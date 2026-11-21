<?php
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../config/db.php';require __DIR__.'/../includes/demo-revenue.php';
if(!in_array('--apply',$argv,true)){echo "Use --apply to install automatic demo-bank recording for platform commission.\n";exit;}
$pdo=getDbConnection();if(!$pdo)exit(1);
$pdo->exec(file_get_contents(__DIR__.'/../database/migrations/20261120_platform_demo_bank.sql'));
$count=0;foreach($pdo->query('SELECT PaymentID FROM AppointmentPayments ORDER BY PaymentID')->fetchAll(PDO::FETCH_COLUMN) as $id){$pdo->beginTransaction();try{$count+=recordPlatformDemoBankTransfer($pdo,(int)$id)?1:0;$pdo->commit();}catch(Throwable $e){$pdo->rollBack();throw $e;}}
echo "Automatic platform demo-bank records created: $count. No real transfers, physician payouts, or wallet balance changes.\n";
