<?php
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../config/db.php';
if(!in_array('--apply',$argv,true)){echo "Use --apply to install summary source tracking.\n";exit;}
$pdo=getDbConnection();if(!$pdo)exit(1);
$pdo->exec(file_get_contents(__DIR__.'/../database/migrations/20261117_workflow_fixes.sql'));
echo "Summary source tracking installed. Existing transcripts, phone numbers, refunds and visit outcomes preserved.\n";
