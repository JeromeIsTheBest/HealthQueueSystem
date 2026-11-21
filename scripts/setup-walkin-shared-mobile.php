<?php
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../config/db.php';
if(!in_array('--apply',$argv,true)){echo "Use --apply to permit shared contact numbers only for walk-in-created patient records.\n";exit;}
$pdo=getDbConnection();if(!$pdo)exit(1);
$pdo->exec(file_get_contents(__DIR__.'/../database/migrations/20261120_walkin_shared_mobile.sql'));
echo "Walk-in mobile exception installed. Existing accounts remain unchanged; regular account mobile uniqueness remains enforced.\n";
