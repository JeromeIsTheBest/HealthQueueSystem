<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/sms.php';
function checkSms($actual, $expected, string $label): void
{
    if ($actual !== $expected) throw new RuntimeException($label);
}
$confirmation = ['DoctorName'=>'Ana Cruz','ClinicName'=>'Bayview Health Hub','AppointmentDate'=>'2026-11-18','AppointmentTime'=>'10:30:00','ticket'=>'S-4'];
checkSms(appointmentApprovalSms($confirmation), "HealthQueue: Appointment confirmed.\nDoctor: Dr. Ana Cruz\nClinic: Bayview Health Hub\nDate: Nov 18, 2026\nTime: 10:30 AM\nQueue: S-4", 'Approval includes doctor, clinic, local date/time and ticket');
$confirmation['AppointmentTime']=null;$confirmation['EstimatedTurnAt']='2026-11-18 10:45:00';
checkSms(str_contains(appointmentApprovalSms($confirmation), 'Estimated turn: 10:45 AM (approx.)'), true, 'Queue approval does not invent an appointment time');
$confirmation['AppointmentTime']='00:00:00';
checkSms(str_contains(appointmentApprovalSms($confirmation), 'Estimated turn: 10:45 AM (approx.)'), true, 'Legacy empty time stays a queue booking');
foreach (['09171234567', '+63 917 123 4567', '639171234567', '(0917) 123-4567'] as $number) {
    checkSms(normalizeSmsNumber($number), '639171234567', 'Normalize Philippine number');
}
foreach (['', '0917123456', '091712345678', '+12025550123', '0917abc4567'] as $number) {
    checkSms(normalizeSmsNumber($number), null, 'Reject invalid number');
}
putenv('HQ_TEXTBEE_DEVICE_ID=device-preview');
checkSms(textbeePayload('09171234567', 'Example'), ['recipients'=>['+639171234567'], 'message'=>'Example', 'deviceId'=>'device-preview'], 'textbee payload and international number');
putenv('HQ_TEXTBEE_DEVICE_ID');
checkSms(isset(textbeePayload('09171234567', 'Example')['deviceId']), false, 'Default device supported');
checkSms(textbeeResponse(200, '{"data":{"success":true,"smsBatchId":"batch-test"}}')['status'], 'accepted', 'textbee acceptance');
checkSms(textbeeResponse(200, '{"data":{"success":false}}')['status'], 'unknown', 'No acceptance without success and batch ID');
checkSms(textbeeResponse(429, '{}')['status'], 'pending', 'Rate limit retry');
checkSms(textbeeResponse(401, '{}')['status'], 'failed', 'Bad credentials');
checkSms(textbeeResponse(400, '{}')['status'], 'failed', 'Invalid device or unverified email');
checkSms(textbeeResponse(0, false)['status'], 'unknown', 'Timeout must not resend');
checkSms(textbeeResponse(500, '{}')['status'], 'unknown', 'Server error must not resend');
checkSms(textbeeResponse(200, '<html>Error</html>')['status'], 'unknown', 'Malformed response must not resend');
putenv('HQ_SMS_ENABLED=1');putenv('HQ_TEXTBEE_API_KEY');
checkSms(smsEnabled(), false, 'Missing textbee key disables sending');
putenv('HQ_TEXTBEE_API_KEY=non-network-test');
checkSms(smsEnabled(), true, 'textbee needs no branded sender');

echo "SMS number and provider-response checks passed. No SMS sent.\n";

if (!in_array('--database', $argv, true)) exit;
require_once __DIR__ . '/../config/db.php';
$pdo = getDbConnection();
if (!$pdo) exit(1);
// Session-local tables shadow production tables; no real records are modified.
$pdo->exec('CREATE TEMPORARY TABLE Users (UserID INT PRIMARY KEY, ContactNumber VARCHAR(30), Status VARCHAR(20), DeletedAt DATETIME NULL, RoleID INT DEFAULT 1)');
$pdo->exec('CREATE TEMPORARY TABLE PatientProfileDetails (UserID INT PRIMARY KEY, Details TEXT)');
$sql = file_get_contents(__DIR__ . '/../database/migrations/20261112_create_sms_outbox.sql');
$pdo->exec(str_replace('CREATE TABLE IF NOT EXISTS', 'CREATE TEMPORARY TABLE', $sql));
$pdo->exec("CREATE TEMPORARY TABLE Roles(RoleID INT PRIMARY KEY,RoleName VARCHAR(20));INSERT INTO Roles VALUES(1,'Patient');CREATE TEMPORARY TABLE Appointments(AppointmentID INT PRIMARY KEY,PatientID INT);INSERT INTO Appointments VALUES(100,1);INSERT INTO Users(UserID,ContactNumber,Status,DeletedAt) VALUES (1, '09171234567', 'Active', NULL)");
checkSms(smsRecipient($pdo, 1), '639171234567', 'Default preference');
$pdo->exec("INSERT INTO PatientProfileDetails VALUES (1, '{\"sms_notifications\":false}')");
checkSms(smsRecipient($pdo, 1), null, 'Opt out');
$pdo->exec("UPDATE PatientProfileDetails SET Details = '{\"sms_notifications\":true}'");
putenv('HQ_SMS_ENABLED=1');
putenv('HQ_TEXTBEE_API_KEY=non-network-test');
queuePatientSms($pdo, 1, 100, 'confirmed:100', 'confirmed', 'HealthQueue: Appointment confirmed.');
queuePatientSms($pdo, 1, 100, 'confirmed:100', 'confirmed', 'HealthQueue: Appointment confirmed.');
checkSms((int) $pdo->query('SELECT COUNT(*) FROM SmsOutbox')->fetchColumn(), 1, 'Duplicate event suppressed');
$pdo->exec("UPDATE PatientProfileDetails SET Details = '{\"sms_notifications\":false}'");
queuePatientSms($pdo, 1, 100, 'near:100', 'near', 'HealthQueue: Your turn is near.');
checkSms((int) $pdo->query('SELECT COUNT(*) FROM SmsOutbox')->fetchColumn(), 1, 'Opt out prevents enqueue');
$pdo->exec("UPDATE Users SET Status = 'Inactive'");
checkSms(smsRecipient($pdo, 1), null, 'Inactive account');
echo "SMS database checks passed using temporary tables. No SMS sent.\n";
