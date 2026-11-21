<?php
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../config/db.php';
if(!in_array('--apply',$argv,true)){echo "Use --apply to add queue late-notice metadata.\n";exit;}
$pdo=getDbConnection();if(!$pdo)exit(1);
$pdo->exec(file_get_contents(__DIR__.'/../database/migrations/20261119_time_based_queue.sql'));
echo "Time-based queue metadata installed; existing queue positions and bookings preserved.\n";
