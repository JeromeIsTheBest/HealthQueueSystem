<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

define('HQ_BASE_URL', '..');
requireRole(['Patient']);
header('Content-Type: application/json');
$pdo = getDbConnection();
$clinicId = filter_input(INPUT_GET, 'clinic_id', FILTER_VALIDATE_INT);
$date = trim((string) ($_GET['appointment_date'] ?? ''));
if (!$pdo || !$clinicId || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(422);
    echo json_encode(['ok' => false]);
    exit;
}

$stmt = $pdo->prepare(
    "SELECT c.OpenTime, c.CloseTime,
            (SELECT COUNT(*) FROM Appointments a
             WHERE a.ClinicID = c.ClinicID AND a.AppointmentDate = ?
               AND a.AppointmentTime IS NULL
               AND (a.Status = 'Confirmed' OR (a.Status = 'Pending' AND a.BookingFeePaid = 1))) AS Population,
            NOW() AS NowAt
     FROM Clinic c WHERE c.ClinicID = ? AND c.Status = 'Active' AND c.archived = 0"
);
$stmt->execute([$date, $clinicId]);
$clinic = $stmt->fetch();
if (!$clinic) {
    http_response_code(404);
    echo json_encode(['ok' => false]);
    exit;
}

$population = (int) $clinic['Population'];
$anchor = max(strtotime($date . ' ' . $clinic['OpenTime']), strtotime($clinic['NowAt']));
$estimate = date('g:i A', $anchor + $population * 15 * 60);
echo json_encode(['ok' => true, 'estimate' => $estimate, 'wait_minutes' => $population * 15]);
