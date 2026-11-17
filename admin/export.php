<?php
require_once __DIR__.'/../includes/csv.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

define('HQ_BASE_URL', '..');
requireRole(['Admin']);

$pdo = getDbConnection();
$type = $_GET['type'] ?? '';

if (!$pdo || !in_array($type, ['clinics', 'payments', 'patients'], true)) {
    http_response_code(400);
    echo 'Invalid or unavailable export requested.';
    exit;
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="healthqueue-' . $type . '-' . date('Y-m-d') . '.csv"');

$out = fopen('php://output', 'w');

if ($type === 'patients') {
    safeCsvRow($out, ['Patient ID','Name','Email','Contact','Status','Joined']);
    $rows=$pdo->query("SELECT u.IDNumber,u.FirstName,u.LastName,u.Email,u.ContactNumber,u.Status,u.CreatedAt FROM Users u JOIN Roles r ON r.RoleID=u.RoleID WHERE r.RoleName='Patient' AND u.DeletedAt IS NULL ORDER BY u.CreatedAt DESC");
    foreach ($rows as $row) {
        $cells=[$row['IDNumber'],$row['FirstName'].' '.$row['LastName'],$row['Email'],$row['ContactNumber'],$row['Status'],$row['CreatedAt']];
        $cells=array_map(static function($value) { $text=(string)$value; return preg_match('/^[=+@\t\r-]/',$text) ? "'".$text : $text; },$cells);
        safeCsvRow($out,$cells);
    }
} elseif ($type === 'clinics') {
    safeCsvRow($out, ['Clinic Name', 'Address', 'Contact Number', 'Consultation Fee (PHP)', 'Status', 'Archived', 'Registered On']);
    $stmt = $pdo->query('SELECT ClinicName, Address, ContactNumber, BaseConsultationFee, Status, archived, RegistrationDate FROM Clinic ORDER BY ClinicName');
    foreach ($stmt as $row) {
        safeCsvRow($out, [
            $row['ClinicName'], $row['Address'], $row['ContactNumber'],
            number_format((float) $row['BaseConsultationFee'], 2, '.', ''),
            $row['Status'], $row['archived'] ? 'Yes' : 'No', $row['RegistrationDate'],
        ]);
    }
} else {
    safeCsvRow($out, ['Paid On', 'Clinic', 'Physician', 'Patient', 'Gross Amount (PHP)', 'Physician Revenue (PHP)', 'Platform Commission (PHP)']);
    $stmt = $pdo->query(
        "SELECT p.PaidAt, c.ClinicName, phys.FirstName AS PhysFirst, phys.LastName AS PhysLast,
                pat.FirstName AS PatFirst, pat.LastName AS PatLast,
                p.GrossAmount, p.PhysicianRevenue, p.PlatformCommission
         FROM AppointmentPayments p
         JOIN Appointments a ON a.AppointmentID = p.AppointmentID
         JOIN Clinic c ON c.ClinicID = a.ClinicID
         JOIN Users phys ON phys.UserID = a.PhysicianID
         JOIN Users pat ON pat.UserID = a.PatientID
         WHERE p.PaymentStatus = 'Paid'
         ORDER BY p.PaidAt DESC"
    );
    foreach ($stmt as $row) {
        safeCsvRow($out, [
            $row['PaidAt'], $row['ClinicName'], 'Dr. ' . $row['PhysFirst'] . ' ' . $row['PhysLast'],
            $row['PatFirst'] . ' ' . $row['PatLast'],
            number_format((float) $row['GrossAmount'], 2, '.', ''),
            number_format((float) $row['PhysicianRevenue'], 2, '.', ''),
            number_format((float) $row['PlatformCommission'], 2, '.', ''),
        ]);
    }
}

fclose($out);
