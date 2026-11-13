<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/sms.php';
function checkSms($actual, $expected, string $label): void
{
    if ($actual !== $expected) throw new RuntimeException($label);
}
foreach (['09171234567', '+63 917 123 4567', '639171234567', '(0917) 123-4567'] as $number) {
    checkSms(normalizeSmsNumber($number), '639171234567', 'Normalize Philippine number');
}
foreach (['', '0917123456', '091712345678', '+12025550123', '0917abc4567'] as $number) {
    checkSms(normalizeSmsNumber($number), null, 'Reject invalid number');
}
checkSms(semaphoreResponse(200, '[{"message_id":123,"status":"Queued"}]')['status'], 'accepted', 'Provider acceptance');
checkSms(semaphoreResponse(200, '[{"message_id":123,"status":"Failed"}]')['status'], 'failed', 'Provider rejection');
checkSms(semaphoreResponse(429, '{}')['status'], 'pending', 'Rate limit retry');
checkSms(semaphoreResponse(401, '{}')['status'], 'failed', 'Bad credentials');
checkSms(semaphoreResponse(0, false)['status'], 'unknown', 'Timeout must not resend');
checkSms(semaphoreResponse(500, '{}')['status'], 'unknown', 'Server error must not resend');
checkSms(semaphoreResponse(200, '<html>Error</html>')['status'], 'unknown', 'Malformed response must not resend');
echo "SMS number and provider-response checks passed. No SMS sent.\n";

if (!in_array('--database', $argv, true)) exit;
require_once __DIR__ . '/../config/db.php';
$pdo = getDbConnection();
if (!$pdo) exit(1);
// Session-local tables shadow production tables; no real records are modified.
$pdo->exec('CREATE TEMPORARY TABLE Users (UserID INT PRIMARY KEY, ContactNumber VARCHAR(30), Status VARCHAR(20), DeletedAt DATETIME NULL)');
$pdo->exec('CREATE TEMPORARY TABLE PatientProfileDetails (UserID INT PRIMARY KEY, Details TEXT)');
$sql = file_get_contents(__DIR__ . '/../database/migrations/20261112_create_sms_outbox.sql');
$pdo->exec(str_replace('CREATE TABLE IF NOT EXISTS', 'CREATE TEMPORARY TABLE', $sql));
$pdo->exec("INSERT INTO Users VALUES (1, '09171234567', 'Active', NULL)");
checkSms(smsRecipient($pdo, 1), '639171234567', 'Default preference');
$pdo->exec("INSERT INTO PatientProfileDetails VALUES (1, '{\"sms_notifications\":false}')");
checkSms(smsRecipient($pdo, 1), null, 'Opt out');
$pdo->exec("UPDATE PatientProfileDetails SET Details = '{\"sms_notifications\":true}'");
putenv('HQ_SMS_ENABLED=1');
putenv('HQ_SEMAPHORE_API_KEY=non-network-test');
putenv('HQ_SMS_SENDER_NAME=HealthQueue');
queuePatientSms($pdo, 1, 100, 'confirmed:100', 'confirmed', 'HealthQueue: Appointment confirmed.');
queuePatientSms($pdo, 1, 100, 'confirmed:100', 'confirmed', 'HealthQueue: Appointment confirmed.');
checkSms((int) $pdo->query('SELECT COUNT(*) FROM SmsOutbox')->fetchColumn(), 1, 'Duplicate event suppressed');
$pdo->exec("UPDATE PatientProfileDetails SET Details = '{\"sms_notifications\":false}'");
queuePatientSms($pdo, 1, 100, 'near:100', 'near', 'HealthQueue: Your turn is near.');
checkSms((int) $pdo->query('SELECT COUNT(*) FROM SmsOutbox')->fetchColumn(), 1, 'Opt out prevents enqueue');
$pdo->exec("UPDATE Users SET Status = 'Inactive'");
checkSms(smsRecipient($pdo, 1), null, 'Inactive account');
echo "SMS database checks passed using temporary tables. No SMS sent.\n";
