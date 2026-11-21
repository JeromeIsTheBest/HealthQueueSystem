<?php
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../config/db.php';$pdo=getDbConnection();if(!$pdo)exit(1);
$q=$pdo->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME");$q->execute([DB_NAME]);$tables=$q->fetchAll(PDO::FETCH_COLUMN);$out=__DIR__.'/../docs/diagrams/';
$header="-- HealthQueue database structure for dbdiagram.io (MySQL import)\n-- Exported ".date('Y-m-d H:i:s P')."\n-- Schema only: no INSERT statements, patient records, passwords, or API keys.\n-- Generated-column expressions are omitted only in this diagram-import file.\n-- Do not use this diagram file as a full database restore.\n\n";
$sql=$header;$raw="-- HealthQueue exact MariaDB table definitions; structure only.\nSET FOREIGN_KEY_CHECKS=0;\n\n";
foreach($tables as $table){
 $ddl=$pdo->query('SHOW CREATE TABLE `'.str_replace('`','``',$table).'`')->fetch(PDO::FETCH_NUM)[1];$ddl=preg_replace('/ AUTO_INCREMENT=\d+/','',$ddl);$raw.=$ddl.";\n\n";
 $ddl=preg_replace('/\) ENGINE=.*$/s',');',$ddl);
 // The generated column and its unique key stay visible to dbdiagram.
 $ddl=preg_replace('/(`[^`]+`\s+[^\n]+?)\s+GENERATED ALWAYS AS\s*\([^\n]+\)\s+(?:STORED|VIRTUAL)(,?)/i',"$1 COMMENT 'Generated column in live database; expression omitted for diagram import.'$2",$ddl);
 $sql.=$ddl."\n\n";
}
$raw.="SET FOREIGN_KEY_CHECKS=1;\n";
file_put_contents($out.'HealthQueue-dbdiagram.sql',rtrim($sql)."\n");file_put_contents($out.'HealthQueue-Exact-Schema.sql',$raw);
echo count($tables)." live table definitions exported without application records.\n";
