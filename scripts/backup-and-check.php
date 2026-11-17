<?php
if(PHP_SAPI!=='cli'){http_response_code(403);exit('CLI only.');}
if(!in_array('--run',$argv,true))exit("Run with --run to make a private backup and verify it in a disposable database.\n");
require __DIR__.'/../config/db.php';$pdo=getDbConnection();if(!$pdo)throw new RuntimeException('Database unavailable.');
$root=realpath(__DIR__.'/..');$folder=$root.'/storage/backups';if(!is_dir($folder))mkdir($folder,0700,true);
$backup=$folder.'/healthqueue-'.date('Ymd-His').'-'.bin2hex(random_bytes(3)).'.sql';
$bin=dirname(dirname(PHP_BINARY)).'/mysql/bin/';
function backupCommand(array $command,?string $inputFile=null):void{
 $env=getenv();if(DB_PASS!=='')$env['MYSQL_PWD']=DB_PASS;
 $descriptors=[0=>$inputFile?['file',$inputFile,'r']:['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];
 $process=proc_open($command,$descriptors,$pipes,null,$env,['bypass_shell'=>true,'create_no_window'=>true]);if(!is_resource($process))throw new RuntimeException('Could not start database backup tool.');
 if(isset($pipes[0]))fclose($pipes[0]);$output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);
 if($code!==0)throw new RuntimeException('Database tool failed. Backup/restore was not verified. Check local database access.');
}
$tables=$pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);$counts=[];foreach($tables as $table)$counts[$table]=(int)$pdo->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn();
backupCommand([$bin.'mysqldump.exe','--host='.DB_HOST,'--user='.DB_USER,'--single-transaction','--skip-lock-tables','--hex-blob','--result-file='.$backup,DB_NAME]);
if(!is_file($backup)||filesize($backup)===0)throw new RuntimeException('Backup is empty.');
// A single-database dump must not redirect the importer to the source database.
$sql=file_get_contents($backup);if(preg_match('/^\s*(?:CREATE DATABASE|USE)\b/im',$sql))throw new RuntimeException('Unexpected database selection in backup; restore not attempted.');unset($sql);
$target='healthqueue_restore_check_'.bin2hex(random_bytes(6));
if(!preg_match('/\Ahealthqueue_restore_check_[a-f0-9]{12}\z/',$target)||$target===DB_NAME)throw new LogicException('Unsafe restore target.');
$created=false;
try{
 $pdo->exec('CREATE DATABASE `'.$target.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$created=true;
 backupCommand([$bin.'mysql.exe','--host='.DB_HOST,'--user='.DB_USER,'--default-character-set=utf8mb4','--database='.$target],$backup);
 $verified=0;
 foreach($counts as $table=>$count){$restored=(int)$pdo->query('SELECT COUNT(*) FROM `'.$target.'`.`'.$table.'`')->fetchColumn();if($restored!==$count)throw new RuntimeException('Row-count verification differs. Keep the backup and investigate concurrent database activity.');$verified++;}
 echo 'Private SQL backup created: storage/backups/'.basename($backup).PHP_EOL;
 echo 'Restore verified for '.$verified.' tables with matching row counts. No source records were changed.'.PHP_EOL;
}finally{
 if($created && preg_match('/\Ahealthqueue_restore_check_[a-f0-9]{12}\z/',$target) && $target!==DB_NAME)$pdo->exec('DROP DATABASE `'.$target.'`');
}
