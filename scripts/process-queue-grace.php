<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../config/db.php';require __DIR__.'/../includes/queue-actions.php';
$pdo=getDbConnection();if(!$pdo)exit(1);
try {$stats=processScheduledGrace($pdo,null,in_array('--check',$argv,true));echo (in_array('--check',$argv,true)?'Read-only grace check: ':'Queue grace worker: ').json_encode($stats).PHP_EOL;}
catch(Throwable $e){fwrite(STDERR,"Queue grace maintenance failed; inspect database setup.\n");error_log($e->getMessage());exit(1);}
