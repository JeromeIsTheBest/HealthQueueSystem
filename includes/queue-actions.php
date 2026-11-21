<?php
require_once __DIR__.'/appointment-workflows.php';
require_once __DIR__.'/notifications.php';require_once __DIR__.'/sms.php';require_once __DIR__.'/queue-email.php';require_once __DIR__.'/audit.php';
const HQ_QUEUE_ORDER_SQL="CASE WHEN a.AppointmentTime IS NOT NULL AND TIMESTAMP(a.AppointmentDate,a.AppointmentTime)<=NOW() THEN 0 ELSE 1 END, CASE WHEN a.AppointmentTime IS NOT NULL AND TIMESTAMP(a.AppointmentDate,a.AppointmentTime)<=NOW() THEN TIMESTAMP(a.AppointmentDate,a.AppointmentTime) END, COALESCE(q.Position,q.QueueNumber*10),q.QueueNumber,q.QueueID";
function assertClinicPhysician(PDO $pdo,int $clinicId,?int $physicianId):void
{
 if(!$physicianId)return;
 $stmt=$pdo->prepare("SELECT UserID FROM Users WHERE UserID=? AND ClinicID=? AND Status='Active' AND DeletedAt IS NULL AND RoleID=(SELECT RoleID FROM Roles WHERE RoleName='Physician')");$stmt->execute([$physicianId,$clinicId]);
 if(!$stmt->fetchColumn())throw new RuntimeException('Choose an active physician from your clinic.');
}
function lockQueueClinic(PDO $pdo,int $clinicId):void
{
 $stmt=$pdo->prepare('SELECT ClinicID FROM Clinic WHERE ClinicID=? FOR UPDATE');$stmt->execute([$clinicId]);if(!$stmt->fetchColumn())throw new RuntimeException('Clinic unavailable.');
}
/** SQL shared by queue calling and the call-next button eligibility. */
function queuePhysicianFreeSql(string $alias='q'):string
{
    return "{$alias}.PhysicianID IS NOT NULL AND NOT EXISTS (SELECT 1 FROM Queue busy JOIN Appointments busyA ON busyA.AppointmentID=busy.AppointmentID WHERE busy.ClinicID={$alias}.ClinicID AND busy.PhysicianID={$alias}.PhysicianID AND DATE(busy.CreatedAt)=CURDATE() AND busy.Status IN ('Calling','Serving') AND busyA.Status='Confirmed' AND (busy.Status='Serving' OR busy.GracePolicyEnabledAt IS NULL OR busy.ArrivedAt IS NOT NULL OR busyA.AppointmentTime IS NULL OR busyA.AppointmentTime='00:00:00' OR TIMESTAMP(busyA.AppointmentDate,busyA.AppointmentTime)>NOW()))";
}
function callNextClinicPatient(PDO $pdo,int $clinicId,int $staffId,?int $physicianId=null,?int $queueId=null):?string
{
 $pdo->beginTransaction();
 try{
  lockQueueClinic($pdo,$clinicId);
  if($physicianId)assertClinicPhysician($pdo,$clinicId,$physicianId);
  $entry=nextTimeQueuePatient($pdo,$clinicId,$physicianId);
  if(!$entry || ($queueId!==null && (int)$entry['QueueID']!==$queueId)){$pdo->commit();return null;}
  $guard=$pdo->prepare("SELECT q.QueueID FROM Queue q JOIN Appointments a ON a.AppointmentID=q.AppointmentID WHERE q.QueueID=? AND q.ClinicID=? AND q.Status='Waiting' AND a.Status='Confirmed' AND ".queuePhysicianFreeSql()." FOR UPDATE");
  $guard->execute([$entry['QueueID'],$clinicId]);
  if(!$guard->fetchColumn()){$pdo->commit();return null;}
  $pdo->prepare("UPDATE Queue SET Status='Calling',CalledAt=NOW(),FirstCalledAt=COALESCE(FirstCalledAt,NOW()) WHERE QueueID=? AND Status='Waiting'")->execute([$entry['QueueID']]);
  notifyPatient($pdo,(int)$entry['PatientID'],"You're being called now - please proceed to the counter.",(int)$entry['AppointmentID']);
  queuePatientSms($pdo,(int)$entry['PatientID'],(int)$entry['AppointmentID'],'called:'.$entry['QueueID'],'called','HealthQueue: You are being called now. Please proceed to the clinic counter.');
  enqueueQueueEmail($pdo,(int)$entry['QueueID'],(int)$entry['PatientID'],(int)$entry['AppointmentID'],'called');
  $ticket=$entry['ScheduledNumber']!==null?'S-'.(int)$entry['ScheduledNumber']:'#'.(int)($entry['RegularNumber']??$entry['QueueNumber']);
  logActivity($pdo,$staffId,$clinicId,'Updated queue status','Calling queue '.$ticket);$pdo->commit();return $ticket;
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function callQueueEntry(PDO $pdo,int $queueId,int $clinicId,int $staffId):?string
{
    return callNextClinicPatient($pdo,$clinicId,$staffId,null,$queueId);
}
/** Patient progress follows the assigned physician's due queue and the same order as calling. */
function patientQueueProgress(PDO $pdo,int $queueId):array
{
    $s=$pdo->prepare('SELECT ClinicID,PhysicianID FROM Queue WHERE QueueID=?');$s->execute([$queueId]);$mine=$s->fetch();$result=['Ahead'=>0,'NowServing'=>null,'EstimatedWaitMinutes'=>0,'EstimatedTurnAt'=>null,'GraceHeld'=>false,'GraceDeadline'=>null,'GraceExtended'=>false,'GraceRescheduleRequested'=>false];if(!$mine)return $result;
    $line=clinicTimeQueue($pdo,(int)$mine['ClinicID'],$mine['PhysicianID']?(int)$mine['PhysicianID']:null);$ahead=0;
    foreach($line as $row){
        if(empty($row['GraceHeld'])&&in_array($row['Status'],['Calling','Serving'],true))$result['NowServing']=(int)$row['QueueNumber'];
        if((int)$row['QueueID']===$queueId){$result['Ahead']=$ahead;$result['EstimatedWaitMinutes']=$row['EstimatedWaitMinutes'];$result['EstimatedTurnAt']=$row['EstimatedTurnAt'];$result['GraceHeld']=!empty($row['GraceHeld']);$result['GraceDeadline']=$row['GraceDeadline']??null;$result['GraceExtended']=!empty($row['GraceExtended']);$result['GraceRescheduleRequested']=!empty($row['GraceRescheduleRequested']);break;}
        if($row['Status']==='Waiting'&&empty($row['GraceHeld']))$ahead++;
    }
    return $result;
}

/** One late notice every ten minutes across all browsers; swap within one physician's line. */
function reportPatientRunningLate(PDO $pdo,int $queueId,int $patientId,?DateTimeImmutable $now=null):array
{
    $now=$now??new DateTimeImmutable((string)$pdo->query('SELECT NOW()')->fetchColumn());
    $lookup=$pdo->prepare('SELECT q.ClinicID FROM Queue q JOIN Appointments a ON a.AppointmentID=q.AppointmentID WHERE q.QueueID=? AND a.PatientID=?');$lookup->execute([$queueId,$patientId]);$clinic=(int)$lookup->fetchColumn();
    if(!$clinic)throw new RuntimeException('That queue entry is unavailable.');
    $pdo->beginTransaction();
    try {
        lockQueueClinic($pdo,$clinic);
        $s=$pdo->prepare("SELECT q.*,a.PatientID,a.AppointmentDate,a.AppointmentTime,CONCAT(u.FirstName,' ',u.LastName) AS PatientName FROM Queue q JOIN Appointments a ON a.AppointmentID=q.AppointmentID JOIN Users u ON u.UserID=a.PatientID WHERE q.QueueID=? AND a.PatientID=? AND q.Status IN ('Waiting','Calling') AND a.Status='Confirmed' AND a.AppointmentDate=? AND DATE(q.CreatedAt)=a.AppointmentDate FOR UPDATE");$s->execute([$queueId,$patientId,$now->format('Y-m-d')]);$row=$s->fetch();
        if(!$row)throw new RuntimeException('That queue entry is no longer waiting or being called.');
        $grace=scheduledGraceInfo($row,$now->getTimestamp());
        if($grace['scheduled']&&$grace['arrived'])throw new RuntimeException('The clinic has already confirmed your arrival. Contact the front desk for help.');
        if($grace['held']&&$now->getTimestamp()>=$grace['deadline'])throw new RuntimeException('The arrival grace period has ended. Contact the clinic.');
        if($grace['held']&&empty($row['GraceExtendedAt']))$pdo->prepare('UPDATE Queue SET GraceExtendedAt=? WHERE QueueID=?')->execute([$now->format('Y-m-d H:i:s'),$queueId]);
        if($row['LastLateNoticeAt'] && strtotime($row['LastLateNoticeAt'])>$now->getTimestamp()-600){$pdo->commit();return ['moved'=>false,'duplicate'=>true];}
        $line=clinicTimeQueue($pdo,$clinic,(int)$row['PhysicianID'],$now);$next=null;$found=$row['Status']==='Calling'&&!$grace['held'];
        foreach($line as $candidate){
            if((int)$candidate['QueueID']===$queueId){$found=true;continue;}
            if($found && empty($candidate['GraceHeld']) && $candidate['Status']==='Waiting'){$next=$candidate;break;}
        }
        $after=$next?(int)$next['QueueID']:null;
        $pdo->prepare("UPDATE Queue SET Status='Waiting',CalledAt=NULL,LateAfterQueueID=?,LastLateNoticeAt=? WHERE QueueID=?")->execute([$after,$now->format('Y-m-d H:i:s'),$queueId]);
        if($next){
            $ids=[];foreach($line as $candidate)if($candidate['Status']==='Waiting'||(int)$candidate['QueueID']===$queueId)$ids[]=(int)$candidate['QueueID'];
            $index=array_search($queueId,$ids,true);$other=array_search($after,$ids,true);
            if($index!==false && $other!==false){[$ids[$index],$ids[$other]]=[$ids[$other],$ids[$index]];$set=$pdo->prepare('UPDATE Queue SET Position=? WHERE QueueID=? AND ClinicID=?');foreach($ids as $i=>$id)$set->execute([($i+1)*10,$id,$clinic]);}
            $pdo->prepare('UPDATE Queue SET LateAfterQueueID=NULL WHERE QueueID=? AND LateAfterQueueID=?')->execute([$after,$queueId]);
        }
        notifyClinic($pdo,$clinic,$row['PatientName'].' (queue '.smsQueueTicket($row).') is running late.'.($next?' Moved behind '.smsQueueTicket($next).'.':' No following patient is waiting.'),(int)$row['AppointmentID']);
        logActivity($pdo,$patientId,$clinic,'Reported running late','Queue #'.$queueId.($after?'; yields to queue #'.$after:'; no successor'));
        $pdo->commit();return ['moved'=>$after!==null,'duplicate'=>false,'grace_extended'=>$grace['held'],'deadline'=>$grace['held']?date('Y-m-d H:i:s',$grace['start']+3600):null];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function approveClinicAppointment(PDO $pdo,int $appointmentId,int $clinicId,?int $physicianId,int $staffId):array
{
 if(!lockClinicBooking($pdo,$clinicId))throw new RuntimeException('The clinic is busy. Please try again.');
 try{
  $pdo->beginTransaction();lockQueueClinic($pdo,$clinicId);
  $stmt=$pdo->prepare('SELECT * FROM Appointments WHERE AppointmentID=? AND ClinicID=? FOR UPDATE');$stmt->execute([$appointmentId,$clinicId]);$a=$stmt->fetch();
  if(!$a||$a['Status']!=='Pending'||!(int)$a['BookingFeePaid'])throw new RuntimeException('This paid request is no longer awaiting approval.');
  $physicianId=$physicianId?:($a['PhysicianID']?(int)$a['PhysicianID']:null);if(!$physicianId)throw new RuntimeException('Assign a physician before accepting this request.');assertClinicPhysician($pdo,$clinicId,$physicianId);
  assertVisitAvailability($pdo,$clinicId,$physicianId,$a['AppointmentDate'],$a['AppointmentTime'], $appointmentId);
  $stmt=$pdo->prepare("SELECT COUNT(*) FROM Queue WHERE AppointmentID=? AND Status IN ('Waiting','Calling','Serving')");$stmt->execute([$appointmentId]);if((int)$stmt->fetchColumn())throw new RuntimeException('This appointment already has an active queue entry.');
  $pdo->prepare("UPDATE Appointments SET Status='Confirmed',PhysicianID=? WHERE AppointmentID=? AND ClinicID=? AND Status='Pending'")->execute([$physicianId,$appointmentId,$clinicId]);
  $a['PhysicianID']=$physicianId;$a['ticket']=createClinicQueueTicket($pdo,$a,$staffId);
  $details=$pdo->prepare('SELECT c.ClinicName,u.FirstName,u.LastName FROM Clinic c JOIN Users u ON u.ClinicID=c.ClinicID WHERE c.ClinicID=? AND u.UserID=?');$details->execute([$clinicId,$physicianId]);$person=$details->fetch();
  if(!$person)throw new RuntimeException('Appointment confirmation details are unavailable.');
  $a['ClinicName']=$person['ClinicName'];$a['DoctorName']=trim($person['FirstName'].' '.$person['LastName']);
  if(empty($a['AppointmentTime'])||$a['AppointmentTime']==='00:00:00'){
      $timeline=queueSmsEstimates($pdo,$a['AppointmentDate'],null,$clinicId,$physicianId);
      foreach($timeline as $entry)if((int)$entry['AppointmentID']===$appointmentId){$a['EstimatedTurnAt']=$entry['EstimatedTurnAt'];break;}
  }
  $pdo->commit();return $a;
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
 finally{unlockClinicBooking($pdo,$clinicId);}
}

/** Staff ordering stays within the selected patient's physician and is serialized with calling. */
function reorderTimeQueue(PDO $pdo,int $clinicId,int $queueId,string $action):void
{
    if(!in_array($action,['move_up','move_down','move_last'],true))throw new RuntimeException('Invalid queue action.');
    $pdo->beginTransaction();
    try {
        lockQueueClinic($pdo,$clinicId);
        $s=$pdo->prepare("SELECT PhysicianID FROM Queue WHERE QueueID=? AND ClinicID=? AND Status='Waiting' FOR UPDATE");$s->execute([$queueId,$clinicId]);$doctor=$s->fetchColumn();
        if(!$doctor)throw new RuntimeException('That patient is no longer waiting.');
        $line=array_values(array_filter(clinicTimeQueue($pdo,$clinicId,(int)$doctor),static fn($r)=>$r['Status']==='Waiting'));
        $ids=array_map(static fn($r)=>(int)$r['QueueID'],$line);$i=array_search($queueId,$ids,true);
        if($i===false)throw new RuntimeException('That patient is no longer waiting.');
        if($action==='move_last'){array_splice($ids,$i,1);$ids[]=$queueId;}
        else {$j=$action==='move_up'?$i-1:$i+1;if(isset($ids[$j]))[$ids[$i],$ids[$j]]=[$ids[$j],$ids[$i]];}
        $s=$pdo->prepare('UPDATE Queue SET Position=?,LateAfterQueueID=NULL WHERE QueueID=? AND ClinicID=?');
        foreach($ids as $position=>$id)$s->execute([($position+1)*10,$id,$clinicId]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

/** Staff confirms physical arrival without starting the physician's consultation. */
function confirmQueueArrival(PDO $pdo,int $queueId,int $clinicId,int $staffId,?DateTimeImmutable $now=null):bool
{
    $now=$now??new DateTimeImmutable((string)$pdo->query('SELECT NOW()')->fetchColumn());
    $pdo->beginTransaction();
    try {
        lockQueueClinic($pdo,$clinicId);
        $s=$pdo->prepare("SELECT q.*,a.PatientID,a.AppointmentDate,a.AppointmentTime FROM Queue q JOIN Appointments a ON a.AppointmentID=q.AppointmentID WHERE q.QueueID=? AND q.ClinicID=? AND a.Status='Confirmed' AND q.Status IN ('Waiting','Calling','Serving') AND a.AppointmentDate=? FOR UPDATE");$s->execute([$queueId,$clinicId,$now->format('Y-m-d')]);$row=$s->fetch();
        if(!$row)throw new RuntimeException('That visit is no longer available for arrival confirmation.');
        if($row['ArrivedAt']){$pdo->commit();return false;}
        $grace=scheduledGraceInfo($row,$now->getTimestamp());
        if($grace['auto_no_show'])throw new RuntimeException('The arrival grace period has ended. Review this visit with the clinic before proceeding.');
        $pdo->prepare('UPDATE Queue SET ArrivedAt=? WHERE QueueID=?')->execute([$now->format('Y-m-d H:i:s'),$queueId]);
        notifyPatient($pdo,(int)$row['PatientID'],'The clinic has confirmed your arrival. Please watch your queue for your turn.',(int)$row['AppointmentID']);
        logActivity($pdo,$staffId,$clinicId,'Confirmed patient arrival','Queue #'.$queueId);
        $pdo->commit();return true;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

/** Background maintenance: never infer absence from a consultation with existing clinical work. */
function processScheduledGrace(PDO $pdo,?DateTimeImmutable $now=null,bool $dryRun=false):array
{
    $now=$now??new DateTimeImmutable((string)$pdo->query('SELECT NOW()')->fetchColumn());$stats=['no_show'=>0,'released'=>0,'review'=>0];
    $s=$pdo->prepare("SELECT DISTINCT q.ClinicID FROM Queue q JOIN Appointments a ON a.AppointmentID=q.AppointmentID WHERE a.Status='Confirmed' AND a.AppointmentTime IS NOT NULL AND a.AppointmentTime<>'00:00:00' AND a.AppointmentDate BETWEEN ? AND ? AND q.Status IN ('Waiting','Calling')");$s->execute([$now->modify('-1 day')->format('Y-m-d'),$now->format('Y-m-d')]);
    foreach($s->fetchAll(PDO::FETCH_COLUMN) as $clinicId){
        $pdo->beginTransaction();
        try {
            lockQueueClinic($pdo,(int)$clinicId);
            $s=$pdo->prepare("SELECT q.*,a.PatientID,a.AppointmentDate,a.AppointmentTime,EXISTS(SELECT 1 FROM Consultations c LEFT JOIN ConsultationVersions v ON v.ConsultationID=c.ConsultationID WHERE c.AppointmentID=a.AppointmentID AND (v.VersionID IS NOT NULL OR c.AudioFilePath IS NOT NULL)) AS HasClinicalRecord FROM Queue q JOIN Appointments a ON a.AppointmentID=q.AppointmentID WHERE q.ClinicID=? AND a.Status='Confirmed' AND a.AppointmentTime IS NOT NULL AND a.AppointmentTime<>'00:00:00' AND a.AppointmentDate BETWEEN ? AND ? AND q.Status IN ('Waiting','Calling') FOR UPDATE");
            $s->execute([$clinicId,$now->modify('-1 day')->format('Y-m-d'),$now->format('Y-m-d')]);
            foreach($s->fetchAll() as $row){
                $grace=scheduledGraceInfo($row,$now->getTimestamp());
                // Old visits belong to staff review; include cross-midnight grace windows only.
                if($grace['deadline']<strtotime($now->format('Y-m-d').' 00:00:00'))continue;
                if(!$grace['held'])continue;
                if($row['Status']==='Calling'){$stats['released']++;if(!$dryRun)$pdo->prepare("UPDATE Queue SET Status='Waiting',CalledAt=NULL WHERE QueueID=?")->execute([$row['QueueID']]);}
                if(!$grace['auto_no_show']){if($now->getTimestamp()>=$grace['deadline'])$stats['review']++;continue;}
                $stats['no_show']++;
                if($dryRun)continue;
                // Both statuses, pending requests, patient feedback and audit commit together.
                $expiryUpdate=$pdo->prepare("UPDATE Appointments SET Status='NoShow' WHERE AppointmentID=? AND Status='Confirmed'");$expiryUpdate->execute([$row['AppointmentID']]);
                if(!$expiryUpdate->rowCount()){$stats['no_show']--;continue;}
                $pdo->prepare("UPDATE Queue SET Status='Forfeited_Late' WHERE AppointmentID=? AND Status IN ('Waiting','Calling')")->execute([$row['AppointmentID']]);
                $pdo->prepare("UPDATE AppointmentRescheduleRequests SET Status='Withdrawn',ReviewedAt=NOW() WHERE AppointmentID=? AND Status='Pending'")->execute([$row['AppointmentID']]);
                notifyPatient($pdo,(int)$row['PatientID'],'Your scheduled appointment was marked as no-show after the arrival grace period ended. Any paid booking fee is retained. Contact the clinic if this status is incorrect.',(int)$row['AppointmentID']);
                notifyClinic($pdo,(int)$clinicId,'Appointment #'.$row['AppointmentID'].' was automatically marked as no-show after its arrival grace period.',(int)$row['AppointmentID']);
                logActivity($pdo,null,(int)$clinicId,'Automatic scheduled no-show','Appointment #'.$row['AppointmentID'].'; deadline '.date('Y-m-d H:i:s',$grace['deadline']).'; no automatic refund or completion commission.');
            }
            if($dryRun)$pdo->rollBack();else $pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    return $stats;
}
