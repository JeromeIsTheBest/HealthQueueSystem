<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/sms.php';
if (!smsEnabled()) { echo "textbee SMS disabled or HQ_TEXTBEE_API_KEY missing.\n"; exit; }
if (!function_exists('curl_init')) { fwrite(STDERR, "PHP cURL is required.\n"); exit(1); }
if (in_array('--check', $argv, true)) { echo "textbee SMS configuration ready. No SMS sent.\n"; exit; }
$pdo = getDbConnection();
if (!$pdo) exit(1);
// Serialize scheduled workers, including network calls, without holding a transaction.
if (!(int) $pdo->query("SELECT GET_LOCK('healthqueue_sms_worker', 0)")->fetchColumn()) exit;
try {
    // Uncertain outcomes are retained for manual provider reconciliation.
    $pdo->exec("UPDATE SmsOutbox SET Status = 'unknown', ProviderStatus = 'Worker interrupted' WHERE Status = 'sending' AND UpdatedAt < NOW() - INTERVAL 5 MINUTE");
    collectQueueSmsReminders($pdo);
    $rows = $pdo->query("SELECT * FROM SmsOutbox WHERE Status = 'pending' AND Attempts < 3 AND (Attempts = 0 OR UpdatedAt < NOW() - INTERVAL 5 MINUTE) ORDER BY CASE EventType WHEN 'called' THEN 0 WHEN 'near' THEN 1 ELSE 2 END,SmsID LIMIT 20")->fetchAll();
    foreach ($rows as $row) {
        $number = smsRecipient($pdo, (int) $row['PatientID']);
        $valid = $pdo->prepare("SELECT COUNT(*) FROM Appointments WHERE AppointmentID = ? AND PatientID = ? AND Status = 'Confirmed' AND AppointmentDate >= CURDATE()");
        $valid->execute([$row['AppointmentID'], $row['PatientID']]);
        $stale = !$number || !$valid->fetchColumn();
        if ($row['EventType'] !== 'confirmed') {
            $currentMessage=currentQueueReminderMessage($pdo,$row);
            $stale=$stale||$currentMessage===null;
            if(!$stale)$row['Message']=$currentMessage;
        }
        if ($stale) {
            $pdo->prepare("UPDATE SmsOutbox SET Status = 'skipped', ProviderStatus = 'No longer eligible' WHERE SmsID = ?")->execute([$row['SmsID']]);
            continue;
        }
        $pdo->prepare("UPDATE SmsOutbox SET Status = 'sending', Attempts = Attempts + 1,Message=? WHERE SmsID = ?")->execute([$row['Message'],$row['SmsID']]);
        $result = textbeeSend($number, $row['Message']);
        if ($result['status'] === 'pending' && (int) $row['Attempts'] >= 2) $result['status'] = 'failed';
        $pdo->prepare('UPDATE SmsOutbox SET Status = ?, ProviderMessageID = ?, ProviderStatus = ? WHERE SmsID = ?')
            ->execute([$result['status'], $result['id'], $result['detail'], $row['SmsID']]);
        if ($result['status'] === 'pending') break;
    }
    echo "SMS worker completed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "SMS worker failed. Check database migrations and server configuration.\n");
    exit(1);
} finally {
    $pdo->query("SELECT RELEASE_LOCK('healthqueue_sms_worker')");
}
