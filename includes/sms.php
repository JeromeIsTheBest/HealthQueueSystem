<?php
/** Semaphore SMS outbox. No network calls are made by web requests. */
function smsEnabled(): bool
{
    return getenv('HQ_SMS_ENABLED') === '1'
        && (string) getenv('HQ_SEMAPHORE_API_KEY') !== ''
        && (string) getenv('HQ_SMS_SENDER_NAME') !== '';
}

function normalizeSmsNumber(string $number): ?string
{
    $number = preg_replace('/[\s()+.-]/', '', $number);
    if (preg_match('/^09\d{9}$/', $number)) $number = '63' . substr($number, 1);
    return preg_match('/^639\d{9}$/', $number) ? $number : null;
}

function smsRecipient(PDO $pdo, int $patientId): ?string
{
    $stmt = $pdo->prepare('SELECT u.ContactNumber, d.Details FROM Users u LEFT JOIN PatientProfileDetails d ON d.UserID = u.UserID WHERE u.UserID = ? AND u.DeletedAt IS NULL AND u.Status = ?');
    $stmt->execute([$patientId, 'Active']);
    $row = $stmt->fetch();
    if (!$row) return null;
    $details = json_decode($row['Details'] ?? '{}', true);
    if (isset($details['sms_notifications']) && !$details['sms_notifications']) return null;
    return normalizeSmsNumber((string) $row['ContactNumber']);
}

function queuePatientSms(PDO $pdo, int $patientId, int $appointmentId, string $eventKey, string $event, string $message): void
{
    if (!smsEnabled()) return;
    try {
        if (!smsRecipient($pdo, $patientId)) return;
        $stmt = $pdo->prepare('INSERT INTO SmsOutbox (PatientID, AppointmentID, EventKey, EventType, Message) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE EventKey = EventKey');
        $stmt->execute([$patientId, $appointmentId, $eventKey, $event, $message]);
    } catch (Throwable $e) {
        error_log('HealthQueue SMS enqueue failed; check SMS migrations.');
    }
}

/** Return provider acceptance, not a claim of handset delivery. */
function semaphoreSend(string $number, string $message): array
{
    $ch = curl_init('https://api.semaphore.co/api/v4/messages');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 25,
        CURLOPT_POSTFIELDS => http_build_query(['apikey' => getenv('HQ_SEMAPHORE_API_KEY'),
            'sendername' => getenv('HQ_SMS_SENDER_NAME'), 'number' => $number, 'message' => $message])]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return semaphoreResponse($code, $body);
}

function semaphoreResponse(int $code, $body): array
{
    $data = is_string($body) ? json_decode($body, true) : null;
    $item = $data[0] ?? [];
    if ($code >= 200 && $code < 300 && !empty($item['message_id'])) {
        $providerStatus = (string) ($item['status'] ?? 'Queued');
        if (in_array(strtolower($providerStatus), ['failed', 'refunded'], true)) {
            return ['status' => 'failed', 'id' => (string) $item['message_id'], 'detail' => $providerStatus];
        }
        return ['status' => 'accepted', 'id' => (string) $item['message_id'], 'detail' => $providerStatus];
    }
    // Only an explicit rate limit can be retried safely. A timeout may have
    // occurred after acceptance, so automatically resending could charge twice.
    return ['status' => $code === 429 ? 'pending' : ($code >= 400 && $code < 500 ? 'failed' : 'unknown'),
        'id' => null, 'detail' => 'HTTP ' . $code];
}
