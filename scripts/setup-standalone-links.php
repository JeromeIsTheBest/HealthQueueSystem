<?php
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../config/db.php';require __DIR__.'/standalone-link-migration.php';$p=getDbConnection();if(!$p)exit(1);
preflightStandaloneLinks($p);
if(!in_array('--apply',$argv,true))exit("Preflight passed. Use --apply to back up the database and install five relationships.\n");
$dir=__DIR__.'/../storage/backups';if(!is_dir($dir))mkdir($dir,0700,true);$name='healthqueue-before-table-links-'.date('Ymd-His').'-'.bin2hex(random_bytes(4)).'.sql';$partial=$dir.'/'.$name.'.partial';$handle=fopen($partial,'xb');if(!$handle)throw new RuntimeException('Private backup could not be created.');
try{
 fwrite($handle,"-- Private HealthQueue snapshot before table-link migration.\n-- Restore into a separate empty database. Contains confidential application records.\nSET FOREIGN_KEY_CHECKS=0;\n\n");$p->beginTransaction();
 $tables=$p->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
 foreach($tables as $table){
  if(!preg_match('/^[A-Za-z0-9_]+$/D',$table))throw new RuntimeException('Unsupported table identifier.');
  $ddl=$p->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];fwrite($handle,$ddl.";\n");
  $columns=[];foreach($p->query('SHOW FULL COLUMNS FROM `'.$table.'`')->fetchAll() as $column)if(!str_contains(strtoupper($column['Extra']),'GENERATED'))$columns[]=$column['Field'];
  $list=implode(',',array_map(fn($v)=>'`'.str_replace('`','``',$v).'`',$columns));$q=$p->query('SELECT '.$list.' FROM `'.$table.'`');
  while($row=$q->fetch(PDO::FETCH_NUM)){$values=implode(',',array_map(fn($v)=>$v===null?'NULL':$p->quote((string)$v),$row));fwrite($handle,'INSERT INTO `'.$table.'`('.$list.') VALUES('.$values.");\n");}fwrite($handle,"\n");
 }
 $p->commit();fwrite($handle,"SET FOREIGN_KEY_CHECKS=1;\n");fclose($handle);if(!rename($partial,$dir.'/'.$name))throw new RuntimeException('Backup finalization failed.');
 applyStandaloneLinks($p);echo "Preflight passed; private backup: storage/backups/{$name}\nFive nullable relationships installed. No historical links guessed; no messages or transfers initiated.\n";
}catch(Throwable $e){if($p->inTransaction())$p->rollBack();if(is_resource($handle))fclose($handle);fwrite(STDERR,'Table-link setup stopped: '.$e->getMessage()."\n");exit(1);}
