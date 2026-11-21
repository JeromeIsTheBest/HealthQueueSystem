<?php
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../config/db.php';
if(!in_array('--apply',$argv,true))exit("Use --apply to install patient-controlled record sharing.\n");
getDbConnection()->exec(file_get_contents(__DIR__.'/../database/migrations/20261121_medical_record_access.sql'));
echo "Record sharing tables installed. No access granted and no messages sent.\n";
