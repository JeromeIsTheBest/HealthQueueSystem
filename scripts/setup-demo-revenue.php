<?php
if(PHP_SAPI!=='cli') {http_response_code(403);exit('CLI only.');}
if(!in_array('--apply',$argv,true))exit("Run with --apply to create demo settlement tables and recognize eligible completed wallet visits.\n");
require __DIR__ . '/../config/db.php';require __DIR__ . '/../includes/demo-revenue.php';
$pdo=getDbConnection();if(!$pdo)throw new RuntimeException('Database unavailable.');
$pdo->exec(file_get_contents(__DIR__.'/../database/migrations/20261117_demo_settlements.sql'));
$pdo->exec(file_get_contents(__DIR__.'/../database/migrations/20261117_physician_withdrawals.sql'));
$ids=$pdo->query("SELECT a.AppointmentID FROM Appointments a LEFT JOIN AppointmentPayments p ON p.AppointmentID=a.AppointmentID WHERE a.Status='Completed' AND a.BookingFeePaid=1 AND a.BookingPaymentMethod='wallet' AND p.PaymentID IS NULL ORDER BY a.AppointmentID")->fetchAll(PDO::FETCH_COLUMN);
$count=0;
foreach($ids as $id){try{$pdo->beginTransaction();if(recordCompletedDemoPayment($pdo,(int)$id))$count++;$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}}
echo 'Demo settlement tables ready. Eligible historical commissions recorded: '.$count.'. No settlements or bank transfers performed.'.PHP_EOL;
