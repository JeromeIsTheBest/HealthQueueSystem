<?php
require_once __DIR__ . '/email.php';
require_once __DIR__ . '/notification-preferences.php';

/** Only due, confirmed patients participate in the clinic waiting order. */
function eligibleQueueEmails(PDO $pdo): array
{
    require_once __DIR__.'/queue-estimates.php';
    $rows=[];
    foreach($pdo->query("SELECT DISTINCT ClinicID FROM Queue WHERE DATE(CreatedAt)=CURDATE() AND Status IN ('Waiting','Calling','Serving')")->fetchAll(PDO::FETCH_COLUMN) as $clinic){
        foreach(clinicTimeQueue($pdo,(int)$clinic) as $row){
            if(!empty($row['GraceHeld'])||!in_array($row['Status'],['Waiting','Calling'],true))continue;
            if($row['AppointmentTime'] && $row['AppointmentTime']!=='00:00:00' && strtotime($row['AppointmentDate'].' '.$row['AppointmentTime'])>time())continue;
            $rows[]=$row;
        }
    }
    return $rows;
}

function enqueueQueueEmail(PDO $pdo, int $queueId, int $patientId, int $appointmentId, string $event): void
{
    if (!in_array($event, ['near','called'], true)) throw new InvalidArgumentException('Invalid queue reminder event.');
    $pdo->prepare('INSERT INTO QueueEmailOutbox (QueueID,PatientID,AppointmentID,EventType) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE QueueID=VALUES(QueueID)')
        ->execute([$queueId,$patientId,$appointmentId,$event]);
}

/** A sent reminder never depends on the in-app notification's read/write state. */
function collectQueueEmails(PDO $pdo): array
{
    $ahead=[];$eligible=[];
    foreach (eligibleQueueEmails($pdo) as $row) {
        $clinic=$row['ClinicID'].':'.($row['PhysicianID']??0);
        $event=$row['Status']==='Calling' ? 'called' : 'near';
        if ($event==='near' && ($ahead[$clinic]??0)>2) { $ahead[$clinic]++; continue; }
        if ($event==='near') $ahead[$clinic]=($ahead[$clinic]??0)+1;
        enqueueQueueEmail($pdo,(int)$row['QueueID'],(int)$row['PatientID'],(int)$row['AppointmentID'],$event);
        $eligible[$row['QueueID'].':'.$event]=true;
    }
    return $eligible;
}

/** Injectable sender enables tests without contacting Gmail or real recipients. */
function dispatchQueueEmails(PDO $pdo, callable $sender): array
{
    $stats=['sent'=>0,'failed'=>0,'skipped'=>0];
    $eligible=collectQueueEmails($pdo);
    // An interrupted SMTP handoff is ambiguous; never automatically duplicate it.
    $pdo->exec("UPDATE QueueEmailOutbox SET Status='unknown',LastError='Worker interrupted; check delivery before retrying' WHERE Status='sending' AND UpdatedAt<NOW()-INTERVAL 10 MINUTE");
    $rows=$pdo->query("SELECT * FROM QueueEmailOutbox WHERE Status IN ('pending','failed') AND Attempts<3 AND (Attempts=0 OR UpdatedAt<NOW()-INTERVAL 5 MINUTE) ORDER BY EmailID LIMIT 20")->fetchAll();
    foreach ($rows as $row) {
        $lookup=$pdo->prepare("SELECT u.Email,u.FirstName,q.QueueNumber,q.ScheduledNumber,q.RegularNumber,c.ClinicName
            FROM Queue q JOIN Appointments a ON a.AppointmentID=q.AppointmentID
            JOIN Users u ON u.UserID=a.PatientID JOIN Clinic c ON c.ClinicID=q.ClinicID
            WHERE q.QueueID=? AND a.PatientID=? AND a.AppointmentID=? AND u.Status='Active' AND u.DeletedAt IS NULL");
        $lookup->execute([$row['QueueID'],$row['PatientID'],$row['AppointmentID']]);$recipient=$lookup->fetch();
        if (!$recipient || !patientAllowsNotification($pdo,(int)$row['PatientID'],'email_notifications') || !filter_var($recipient['Email'],FILTER_VALIDATE_EMAIL) || !isset($eligible[$row['QueueID'].':'.$row['EventType']])) {
            $pdo->prepare("UPDATE QueueEmailOutbox SET Status='skipped',LastError='No longer eligible' WHERE EmailID=?")->execute([$row['EmailID']]);$stats['skipped']++;continue;
        }
        $pdo->prepare("UPDATE QueueEmailOutbox SET Status='sending',Attempts=Attempts+1 WHERE EmailID=?")->execute([$row['EmailID']]);
        $ticket=$recipient['ScheduledNumber']!==null ? 'S-'.(int)$recipient['ScheduledNumber'] : '#'.(int)($recipient['RegularNumber']??$recipient['QueueNumber']);
        try { $sent=$sender($recipient,$ticket,$row['EventType']); }
        catch (Throwable $e) { $sent=false;error_log('Queue email delivery failed.'); }
        $pdo->prepare('UPDATE QueueEmailOutbox SET Status=?,LastError=? WHERE EmailID=?')
            ->execute([$sent?'sent':'failed',$sent?null:'Email could not be sent; check SMTP configuration and mail logs',$row['EmailID']]);
        $stats[$sent?'sent':'failed']++;
    }
    return $stats;
}
