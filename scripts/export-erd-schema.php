<?php
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../config/db.php';$p=getDbConnection();
$q=$p->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME");$q->execute([DB_NAME]);$tables=$q->fetchAll(PDO::FETCH_COLUMN);
$queries=[
'columns'=>"SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_KEY,ORDINAL_POSITION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? ORDER BY TABLE_NAME,ORDINAL_POSITION",
'foreign_keys'=>"SELECT k.TABLE_NAME,k.CONSTRAINT_NAME,k.COLUMN_NAME,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,k.ORDINAL_POSITION,r.UPDATE_RULE,r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME WHERE k.TABLE_SCHEMA=? AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.TABLE_NAME,k.CONSTRAINT_NAME,k.ORDINAL_POSITION",
'indexes'=>"SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,COLUMN_NAME,SEQ_IN_INDEX FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX"];
$data=['database'=>DB_NAME,'generated_at'=>date('c'),'tables'=>$tables];foreach($queries as $key=>$sql){$q=$p->prepare($sql);$q->execute([DB_NAME]);$data[$key]=$q->fetchAll();}
file_put_contents(__DIR__.'/../docs/diagrams/healthqueue-current-schema.json',json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));echo count($tables).' tables; '.count($data['foreign_keys'])." foreign-key columns inspected. Metadata only; no application records read.\n";
