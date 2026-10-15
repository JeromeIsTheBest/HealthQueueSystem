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

$stmt = $pdo->prepare(
    'SELECT m.FilePath, m.FileType, m.Title FROM MedicalRecords m
     JOIN Appointments a ON a.PatientID = m.PatientID
     WHERE a.AppointmentID = ? AND a.PhysicianID = ? AND m.RecordID = ? LIMIT 1'
);
$stmt->execute([$appointmentId, $user['UserID'], $recordId]);
$record = $stmt->fetch();
if (!$record) { http_response_code(404); exit; }
$path = __DIR__ . '/../storage/medical-records/' . basename($record['FilePath']);
if (!is_file($path)) { http_response_code(404); exit; }
$mime = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png'][$record['FileType']] ?? 'application/octet-stream';
$name = trim(preg_replace('/[^A-Za-z0-9 _.-]+/', '', $record['Title'])) ?: 'medical-record';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . $name . '.' . $record['FileType'] . '"');
header('Cache-Control: private, no-store');
readfile($path);
