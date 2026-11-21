<?php
require_once __DIR__.'/relationship-links.php';
/** textbee SMS outbox. No network calls are made by web requests. */
require_once __DIR__.'/queue-estimates.php';
function appointmentApprovalSms(array $appointment): string
{
    $singleLine = static fn(string $value): string => trim(preg_replace('/\s+/u', ' ', $value));
    $doctor = $singleLine((string) $appointment['DoctorName']);
    $clinic = $singleLine((string) $appointment['ClinicName']);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $appointment['AppointmentDate']);
    if (!$date || $doctor === '' || $clinic === '') throw new InvalidArgumentException('Appointment confirmation details are incomplete.');
    $time = (string) ($appointment['AppointmentTime'] ?? '');
    $scheduled=$time!==''&&$time!=='00:00:00';
    $timeLabel=$scheduled?date('g:i A',strtotime($time))
        : (!empty($appointment['EstimatedTurnAt'])?date('g:i A',strtotime($appointment['EstimatedTurnAt'])) . ' (approx.)':'To be confirmed by the clinic');
    return "HealthQueue: Appointment confirmed.\nDoctor: Dr. {$doctor}\nClinic: {$clinic}\nDate: "
        . $date->format('M j, Y') . ($scheduled?"\nTime: {$timeLabel}":"\nEstimated turn: {$timeLabel}") . "\nQueue: " . $singleLine((string) $appointment['ticket']);
}

function nearTurnSms(array $entry):string
{
    $clean=static fn($v)=>trim(preg_replace('/\s+/u',' ',(string)$v));
    $scheduled=!empty($entry['AppointmentTime'])&&$entry['AppointmentTime']!=='00:00:00';
    $time=$scheduled?'Appointment time: '.date('g:i A',strtotime($entry['AppointmentTime']))
        :'Estimated turn: '.date('g:i A',strtotime($entry['EstimatedTurnAt'])).' (approx.)';
    $wait=(int)$entry['EstimatedWaitMinutes'];
    $alert=$wait>0?'Your turn is near (about '.$wait.' min).':'You are next or due now.';
    return 'HealthQueue: '.$alert."\nDr. ".$clean($entry['DoctorName']).' at '.$clean($entry['ClinicName'])."\n".$time."\nQueue: ".smsQueueTicket($entry).'. Please be ready at the clinic.';
}

/** About one consultation ahead; timed bookings can be reminded before their scheduled start. */
function collectQueueSmsReminders(PDO $pdo,?DateTimeImmutable $now=null):array
{
    $now=$now??new DateTimeImmutable((string)$pdo->query('SELECT NOW()')->fetchColumn());$eligible=[];
    foreach(queueSmsEstimates($pdo,$now->format('Y-m-d'),$now) as $entry){
        if(!empty($entry['GraceHeld']))continue;
        if($entry['Status']==='Calling'){$event='called';$message='HealthQueue: You are being called now. Please proceed to the clinic counter.';}
        elseif($entry['Status']==='Waiting'&&(int)$entry['EstimatedWaitMinutes']<=HQ_NEAR_TURN_MINUTES){$event='near';$message=nearTurnSms($entry);}
        else continue;
        if(!smsEnabled()||!smsRecipient($pdo,(int)$entry['PatientID']))continue;
        $key=$event.':'.$entry['QueueID'];$eligible[$key]=$message;
        queuePatientSms($pdo,(int)$entry['PatientID'],(int)$entry['AppointmentID'],$key,$event,$message);
        // Only revive a never-attempted alert, not one accepted by textbee or with an uncertain result.
        $pdo->prepare("UPDATE SmsOutbox SET Status='pending',ProviderStatus=NULL,Message=? WHERE EventKey=? AND Status='skipped' AND Attempts=0")->execute([$message,$key]);
    }
    return $eligible;
}

/** Recheck the current line immediately before handing an alert to textbee. */
function currentQueueReminderMessage(PDO $pdo,array $outbox,?DateTimeImmutable $now=null):?string
{
    if(!preg_match('/\A(near|called):([1-9][0-9]*)\z/',(string)$outbox['EventKey'],$key)||$key[1]!==$outbox['EventType'])return null;
    $now=$now??new DateTimeImmutable((string)$pdo->query('SELECT NOW()')->fetchColumn());
    $s=$pdo->prepare('SELECT ClinicID,PhysicianID FROM Queue WHERE QueueID=?');$s->execute([(int)$key[2]]);$scope=$s->fetch();if(!$scope)return null;
    $timeline=queueSmsEstimates($pdo,$now->format('Y-m-d'),$now,(int)$scope['ClinicID'],(int)$scope['PhysicianID']);$entry=$timeline[(int)$key[2]]??null;
    if(!$entry||!empty($entry['GraceHeld'])||(int)$entry['AppointmentID']!==(int)$outbox['AppointmentID']||(int)$entry['PatientID']!==(int)$outbox['PatientID'])return null;
    if($key[1]==='called')return $entry['Status']==='Calling'?'HealthQueue: You are being called now. Please proceed to the clinic counter.':null;
    return $entry['Status']==='Waiting'&&(int)$entry['EstimatedWaitMinutes']<=HQ_NEAR_TURN_MINUTES?nearTurnSms($entry):null;
}

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
        insertReferencedSms($pdo,$patientId,$appointmentId,$eventKey,$event,$message);
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
