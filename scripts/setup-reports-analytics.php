<?php
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../config/db.php';
if(!in_array('--apply',$argv,true)){echo "Use --apply to install reports and reference-based transcript evaluations.\n";exit;}
$pdo=getDbConnection();if(!$pdo)exit(1);
$pdo->exec(file_get_contents(__DIR__.'/../database/migrations/20261121_reports_analytics.sql'));
echo "Reports metadata installed. No historical arrival/call times or AI accuracy results invented.\n";
