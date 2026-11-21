<?php
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../config/db.php';require __DIR__.'/../includes/demo-revenue.php';
if(!in_array('--apply',$argv,true)){echo "Use --apply to release existing eligible demo earnings. No payouts are initiated.\n";exit;}
$pdo=getDbConnection();if(!$pdo)exit(1);$count=0;
foreach($pdo->query('SELECT PaymentID FROM AppointmentPayments ORDER BY PaymentID')->fetchAll(PDO::FETCH_COLUMN) as $id){$pdo->beginTransaction();try{$count+=releaseDemoPayment($pdo,(int)$id)?1:0;$pdo->commit();}catch(Throwable $e){$pdo->rollBack();throw $e;}}
echo "Released eligible demo payment records: $count. Existing withdrawals preserved. No wallet balances, real payments or transfers changed.\n";
