<?php
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../config/db.php';
if(!in_array('--apply',$argv,true)){echo "Use --apply to add arrival and grace metadata.\n";exit;}
$pdo=getDbConnection();if(!$pdo)exit(1);
$pdo->exec(file_get_contents(__DIR__.'/../database/migrations/20261119_scheduled_grace.sql'));
echo "Arrival and scheduled grace metadata installed. No appointment outcomes changed.\n";
