<?php
/** textbee SMS outbox. No network calls are made by web requests. */
function smsEnabled(): bool
{
    return getenv('HQ_SMS_ENABLED') === '1'
        && trim((string) getenv('HQ_TEXTBEE_API_KEY')) !== '';
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

/** Build the textbee request separately so it can be verified without sending SMS. */
function textbeePayload(string $number, string $message): array
{
    $normalized = normalizeSmsNumber($number);
    if (!$normalized) throw new InvalidArgumentException('Invalid Philippine mobile number.');
    $payload = ['recipients' => ['+' . $normalized], 'message' => $message];
    $deviceId = trim((string) getenv('HQ_TEXTBEE_DEVICE_ID'));
    if ($deviceId !== '') $payload['deviceId'] = $deviceId;
    return $payload;
}

/** API acceptance is not confirmed handset delivery. No automatic provider fallback. */
function textbeeSend(string $number, string $message): array
{
    $apiKey = trim((string) getenv('HQ_TEXTBEE_API_KEY'));
    if ($apiKey === '' || preg_match('/[\r\n]/', $apiKey)) {
        return ['status' => 'failed', 'id' => null, 'detail' => 'textbee API key missing or invalid'];
    }
    $ch = curl_init('https://api.textbee.dev/api/v1/gateway/send-sms');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-api-key: ' . $apiKey],
        CURLOPT_POSTFIELDS => json_encode(textbeePayload($number, $message), JSON_THROW_ON_ERROR)]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return textbeeResponse($code, $body);
}

function textbeeResponse(int $code, $body): array
{
    $data = is_string($body) ? json_decode($body, true) : null;
    $batch = is_array($data['data'] ?? null) ? $data['data'] : [];
    if ($code >= 200 && $code < 300 && ($batch['success'] ?? false) === true
        && is_string($batch['smsBatchId'] ?? null) && $batch['smsBatchId'] !== '') {
        return ['status' => 'accepted', 'id' => $batch['smsBatchId'], 'detail' => 'textbee: queued for phone; delivery not confirmed'];
    }
    $details = [400 => 'Check verified email, enabled phone and device ID',
        401 => 'API key rejected', 403 => 'Access denied', 404 => 'Device or endpoint not found',
        429 => 'Message limit reached; retry later'];
    // Do not retry uncertain outcomes automatically: the phone may already have received the request.
    return ['status' => $code === 429 ? 'pending' : ($code >= 400 && $code < 500 ? 'failed' : 'unknown'),
        'id' => null, 'detail' => 'textbee HTTP ' . $code . (isset($details[$code]) ? ': ' . $details[$code] : '')];
}
