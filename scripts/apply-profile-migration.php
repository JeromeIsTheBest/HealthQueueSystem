<?php
require __DIR__ . '/../config/db.php';
$pdo = getDbConnection();
if (!$pdo) exit(1);
$pdo->exec(file_get_contents(__DIR__ . '/../database/migrations/20261105_patient_profile_details.sql'));
echo "Profile migration applied\n";
