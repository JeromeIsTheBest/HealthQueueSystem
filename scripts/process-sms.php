<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/sms.php';
if (!smsEnabled()) { echo "SMS disabled or credentials missing.\n"; exit; }
if (!function_exists('curl_init')) { fwrite(STDERR, "PHP cURL is required.\n"); exit(1); }
$pdo = getDbConnection();
if (!$pdo) exit(1);
// Serialize scheduled workers, including network calls, without holding a transaction.
if (!(int) $pdo->query("SELECT GET_LOCK('healthqueue_sms_worker', 0)")->fetchColumn()) exit;
try {
    // Uncertain outcomes are retained for manual provider reconciliation.
    $pdo->exec("UPDATE SmsOutbox SET Status = 'unknown', ProviderStatus = 'Worker interrupted' WHERE Status = 'sending' AND UpdatedAt < NOW() - INTERVAL 5 MINUTE");
    $entries = $pdo->query("SELECT q.*, a.PatientID FROM Queue q JOIN Appointments a ON a.AppointmentID = q.AppointmentID
        WHERE DATE(q.CreatedAt) = CURDATE() AND a.Status = 'Confirmed' AND q.Status IN ('Waiting', 'Calling')
        AND (a.AppointmentTime IS NULL OR TIMESTAMP(a.AppointmentDate, a.AppointmentTime) <= NOW())
        ORDER BY q.ClinicID, CASE WHEN a.AppointmentTime IS NOT NULL THEN 0 ELSE 1 END,
        TIMESTAMP(a.AppointmentDate, a.AppointmentTime), COALESCE(q.Position, q.QueueNumber * 10), q.QueueNumber, q.QueueID")->fetchAll();
    $ahead = [];
    foreach ($entries as $entry) {
        $clinic = $entry['ClinicID'];
        $event = $entry['Status'] === 'Calling' ? 'called' : 'near';
        if ($event === 'near' && ($ahead[$clinic] ?? 0) > 2) { $ahead[$clinic]++; continue; }
        if ($event === 'near') $ahead[$clinic] = ($ahead[$clinic] ?? 0) + 1;
        queuePatientSms($pdo, (int) $entry['PatientID'], (int) $entry['AppointmentID'],
            $event . ':' . $entry['QueueID'], $event, $event === 'called'
                ? 'HealthQueue: You are being called now. Please proceed to the clinic counter.'
                : 'HealthQueue: Your turn is approaching. Please return to the clinic waiting area.');
    }
    $rows = $pdo->query("SELECT * FROM SmsOutbox WHERE Status = 'pending' AND Attempts < 3 AND (Attempts = 0 OR UpdatedAt < NOW() - INTERVAL 5 MINUTE) ORDER BY SmsID LIMIT 20")->fetchAll();
    foreach ($rows as $row) {
        $number = smsRecipient($pdo, (int) $row['PatientID']);
        $valid = $pdo->prepare("SELECT COUNT(*) FROM Appointments WHERE AppointmentID = ? AND PatientID = ? AND Status = 'Confirmed' AND AppointmentDate >= CURDATE()");
        $valid->execute([$row['AppointmentID'], $row['PatientID']]);
        $stale = !$number || !$valid->fetchColumn();
        if ($row['EventType'] !== 'confirmed') {
            $check = $pdo->prepare('SELECT COUNT(*) FROM Queue WHERE QueueID = ? AND DATE(CreatedAt) = CURDATE() AND Status = ?');
            $check->execute([(int) substr($row['EventKey'], strpos($row['EventKey'], ':') + 1), $row['EventType'] === 'near' ? 'Waiting' : 'Calling']);
            $stale = $stale || !$check->fetchColumn() || strtotime($row['CreatedAt']) < time() - 600;
        }
        if ($stale) {
            $pdo->prepare("UPDATE SmsOutbox SET Status = 'skipped', ProviderStatus = 'No longer eligible' WHERE SmsID = ?")->execute([$row['SmsID']]);
            continue;
        }
        $pdo->prepare("UPDATE SmsOutbox SET Status = 'sending', Attempts = Attempts + 1 WHERE SmsID = ?")->execute([$row['SmsID']]);
        $result = semaphoreSend($number, $row['Message']);
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
