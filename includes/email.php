<?php
/** Gmail SMTP mail helpers. Configure credentials with environment variables. */

function sendHealthQueueEmail(string $to, string $subject, string $htmlBody, string $textBody = ''): bool
{
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!is_file($autoload)) {
        error_log('Email not sent: Composer dependencies are not installed.');
        return false;
    }

    require_once $autoload;
    $host = getenv('HQ_SMTP_HOST') ?: 'smtp.gmail.com';
    $username = getenv('HQ_SMTP_USERNAME') ?: '';
    $password = getenv('HQ_SMTP_PASSWORD') ?: '';
    $from = getenv('HQ_MAIL_FROM') ?: $username;
    $fromName = getenv('HQ_MAIL_FROM_NAME') ?: 'HealthQueue';
    if ($username === '' || $password === '' || $from === '') {
        error_log('Email not sent: configure HQ_SMTP_USERNAME, HQ_SMTP_PASSWORD, and HQ_MAIL_FROM.');
        return false;
    }

    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $host;
        $mail->SMTPAuth = true;
        $mail->Username = $username;
        $mail->Password = $password;
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = (int) (getenv('HQ_SMTP_PORT') ?: 587);
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($from, $fromName);
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = $textBody !== '' ? $textBody : trim(strip_tags($htmlBody));
        $mail->send();
        return true;
    } catch (Throwable $e) {
        error_log('HealthQueue email send failed: ' . $e->getMessage());
        return false;
    }
}

function sendPasswordResetEmail(string $email, string $firstName, string $resetUrl): bool
{
    $name = htmlspecialchars($firstName !== '' ? $firstName : 'there', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $url = htmlspecialchars($resetUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $body = '<p>Hello ' . $name . ',</p><p>We received a request to reset your HealthQueue password. Use the link below within 30 minutes:</p>'
        . '<p><a href="' . $url . '">Reset your password</a></p><p>If you did not request this, you can ignore this email.</p>';
    return sendHealthQueueEmail($email, 'Reset your HealthQueue password', $body, "Hello {$firstName},\n\nReset your HealthQueue password within 30 minutes: {$resetUrl}\n\nIf you did not request this, ignore this email.");
}

function sendQueueNearEmail(string $email, string $firstName, string $queueNumber, string $clinicName): bool
{
    $name = htmlspecialchars($firstName !== '' ? $firstName : 'there', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $number = htmlspecialchars($queueNumber, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $clinic = htmlspecialchars($clinicName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $body = '<p>Hello ' . $name . ',</p><p>Your queue number <strong>' . $number . '</strong> at ' . $clinic
        . ' is coming up soon. Please head to the waiting area and watch for your turn.</p><p>— HealthQueue</p>';
    return sendHealthQueueEmail($email, 'Your HealthQueue turn is coming up', $body, "Hello {$firstName},\n\nYour queue number {$queueNumber} at {$clinicName} is coming up soon. Please head to the waiting area and watch for your turn.");
}
