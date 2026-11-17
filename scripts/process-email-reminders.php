<?php
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
require_once __DIR__.'/../config/db.php';
require_once __DIR__.'/../includes/queue-email.php';
if (!getenv('HQ_SMTP_USERNAME') || !getenv('HQ_SMTP_PASSWORD')) {fwrite(STDERR,"Email reminders disabled: SMTP credentials missing.\n");exit(1);}
$pdo=getDbConnection();if(!$pdo)exit(1);
if(in_array('--check',$argv,true)) { $pdo->query('SELECT EmailID FROM QueueEmailOutbox LIMIT 0'); echo "Email reminder configuration and database ready. No email sent.\n"; exit; }
if (!(int)$pdo->query("SELECT GET_LOCK('healthqueue_email_worker',0)")->fetchColumn())exit;
try {
    $pdo->exec("UPDATE QueueEmailOutbox SET Status='unknown',LastError='Worker interrupted; check mail delivery before retrying' WHERE Status='sending' AND UpdatedAt < NOW() - INTERVAL 10 MINUTE");
    $stats=dispatchQueueEmails($pdo,static function(array $recipient,string $ticket,string $event):bool {
        if ($event==='near') return sendQueueNearEmail($recipient['Email'],$recipient['FirstName'],$ticket,$recipient['ClinicName']);
        $name=htmlspecialchars($recipient['FirstName'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $clinic=htmlspecialchars($recipient['ClinicName'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $number=htmlspecialchars($ticket,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        return sendHealthQueueEmail($recipient['Email'],'HealthQueue: You are being called now',"<p>Hello {$name},</p><p>Your queue number <strong>{$number}</strong> at {$clinic} is being called now. Please proceed to the clinic counter.</p>");
    });
    echo 'Email reminders: '.json_encode($stats).PHP_EOL;
} catch(Throwable $e) {error_log('Queue email worker failed: '.$e->getMessage());fwrite(STDERR,"Email reminder worker failed. Check database migration and server configuration.\n");exit(1);}
finally {$pdo->query("SELECT RELEASE_LOCK('healthqueue_email_worker')");}
