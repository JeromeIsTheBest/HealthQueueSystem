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

function healthQueueUrl(string $path): string
{
    $base = rtrim(getenv('HQ_APP_URL') ?: '', '/');
    if ($base === '') {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scriptDir = str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/')));
        $appPath = $scriptDir === '/' || $scriptDir === '.' ? '' : rtrim($scriptDir, '/');
        $base = ($https ? 'https://' : 'http://') . $host . $appPath;
    }
    return $base . '/' . ltrim($path, '/');
}

function sendEmailVerificationEmail(string $email, string $firstName, string $verificationUrl): bool
{
    $name = htmlspecialchars($firstName !== '' ? $firstName : 'there', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $url = htmlspecialchars($verificationUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $body = '<p>Hello ' . $name . ',</p><p>Thanks for creating a HealthQueue account. Verify your email address within 24 hours:</p>'
        . '<p><a href="' . $url . '">Verify email address</a></p><p>If you did not create this account, you can ignore this email.</p>';
    return sendHealthQueueEmail($email, 'Verify your HealthQueue email address', $body, "Hello {$firstName},\n\nVerify your HealthQueue email within 24 hours: {$verificationUrl}\n\nIf you did not create this account, ignore this email.");
}

function sendSignupEmailVerificationEmail(string $email, string $verificationUrl): bool
{
    $url = htmlspecialchars($verificationUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $body = '<p>Use the link below to verify this email address for a HealthQueue account. The link expires in 24 hours:</p>'
        . '<p><a href="' . $url . '">Verify email address</a></p><p>If you did not request this, you can ignore this email.</p>';
    return sendHealthQueueEmail($email, 'Verify your email for HealthQueue signup', $body, "Verify this email for HealthQueue signup within 24 hours: {$verificationUrl}\n\nIf you did not request this, ignore this email.");
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
