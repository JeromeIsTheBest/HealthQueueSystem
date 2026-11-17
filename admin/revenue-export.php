<?php
require_once __DIR__.'/../includes/csv.php';
require_once __DIR__ . '/../config/db.php';require_once __DIR__ . '/../includes/auth.php';require_once __DIR__ . '/../includes/demo-revenue.php';
requireRole(['Admin']);$pdo=getDbConnection();
try{if(!$pdo)throw new RuntimeException();$summary=demoRevenueSummary($pdo);$summary['physician']=max(0,(float)$summary['physician']-(float)$pdo->query("SELECT COALESCE(SUM(Amount),0) FROM PhysicianWithdrawals WHERE Status='PaidSimulated'")->fetchColumn());$rows=$pdo->query('SELECT Reference,CreatedAt,GrossAmount FROM DemoSettlements ORDER BY SettlementID DESC')->fetchAll();}
catch(Throwable $e){http_response_code(503);exit('Demo revenue export is temporarily unavailable.');}
header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="healthqueue-demo-revenue-'.date('Y-m-d').'.csv"');
$out=fopen('php://output','w');safeCsvRow($out,['DEMO MODE - No real money transferred']);safeCsvRow($out,['Metric','Amount (PHP)']);
foreach(['gross'=>'Demo payments','pending'=>'Pending settlement','settled'=>'Simulated settled funds','commission'=>'Platform commission','physician'=>'Physician payable'] as $key=>$label)safeCsvRow($out,[$label,number_format((float)$summary[$key],2,'.','')]);
safeCsvRow($out,[]);safeCsvRow($out,['Reference','Simulated on','Amount (PHP)','Status']);
foreach($rows as $row)safeCsvRow($out,[$row['Reference'],$row['CreatedAt'],$row['GrossAmount'],'Simulated settled']);fclose($out);
