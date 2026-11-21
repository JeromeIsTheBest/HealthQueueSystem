<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

define('HQ_BASE_URL', '..');
requireRole(['Physician']);
$user = currentUser();
$pdo = getDbConnection();
$appointmentId = filter_input(INPUT_GET, 'appointment_id', FILTER_VALIDATE_INT);
$recordId = filter_input(INPUT_GET, 'record_id', FILTER_VALIDATE_INT);
if (!$pdo || !$appointmentId || !$recordId) { http_response_code(404); exit; }

require_once __DIR__.'/../includes/medical-record-access.php';
try { $record=authorizedPhysicianRecord($pdo,(int)$user['UserID'],(int)$appointmentId,(int)$recordId); }
catch(RuntimeException $e){http_response_code(403);header('Cache-Control: no-store');exit('Patient approval is required to view this record during an active consultation.');}
$path = __DIR__ . '/../storage/medical-records/' . basename($record['FilePath']);
if (!is_file($path)) { http_response_code(404); exit; }
$mime = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png'][$record['FileType']] ?? 'application/octet-stream';
$name = trim(preg_replace('/[^A-Za-z0-9 _.-]+/', '', $record['Title'])) ?: 'medical-record';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . $name . '.' . $record['FileType'] . '"');
header('Cache-Control: private, no-store');
readfile($path);
