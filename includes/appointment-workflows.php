<?php
require_once __DIR__.'/availability.php';
require_once __DIR__.'/input-validation.php';
require_once __DIR__.'/demo-revenue.php';
require_once __DIR__.'/notifications.php';
require_once __DIR__.'/audit.php';
require_once __DIR__.'/visit-completion.php';

/** Latest revision, rather than any older finalized revision. */
function latestVisitFinalized(PDO $pdo,int $appointmentId): bool
{
    $stmt=$pdo->prepare('SELECT v.Status FROM ConsultationVersions v JOIN Consultations c ON c.ConsultationID=v.ConsultationID WHERE c.AppointmentID=? ORDER BY v.RevisionNumber DESC,v.VersionID DESC LIMIT 1');
    $stmt->execute([$appointmentId]);return $stmt->fetchColumn()==='Finalized';
}

/** Caller holds the clinic and appointment locks. */
function createClinicQueueTicket(PDO $pdo,array $a,int $staffId):string
{
    $scheduled=!empty($a['AppointmentTime'])&&$a['AppointmentTime']!=='00:00:00';
    $column=$scheduled?'ScheduledNumber':'RegularNumber';
    $stmt=$pdo->prepare("SELECT COALESCE(MAX(QueueNumber),0)+1 AS Number,COALESCE(MAX({$column}),0)+1 AS DisplayNumber FROM Queue WHERE ClinicID=? AND DATE(CreatedAt)=?");
    $stmt->execute([$a['ClinicID'],$a['AppointmentDate']]);$numbers=$stmt->fetch();
    $pdo->prepare("INSERT INTO Queue(ClinicID,AppointmentID,PhysicianID,QueueNumber,ScheduledNumber,RegularNumber,Status,CreatedByStaffID,CreatedAt) VALUES(?,?,?,?,?,?,'Waiting',?,GREATEST(NOW(),TIMESTAMP(?,COALESCE(?,'00:00:00'))))")
        ->execute([$a['ClinicID'],$a['AppointmentID'],$a['PhysicianID'],$numbers['Number'],$scheduled?$numbers['DisplayNumber']:null,$scheduled?null:$numbers['DisplayNumber'],$staffId,$a['AppointmentDate'],$scheduled?$a['AppointmentTime']:null]);
    $pdo->prepare('UPDATE Queue SET GracePolicyEnabledAt=NOW() WHERE QueueID=?')->execute([(int)$pdo->lastInsertId()]);
    return $scheduled?'S-'.$numbers['DisplayNumber']:'#'.$numbers['DisplayNumber'];
}

/** Validate the date for queue visits, and full capacity for scheduled visits. */
function assertVisitAvailability(PDO $pdo,int $clinicId,int $physicianId,string $date,?string $time,int $excludeId):void
{
    $scheduled=$time!==null&&$time!==''&&$time!=='00:00:00';
    if(!hqValidCalendarValue($date)||$date<date('Y-m-d'))throw new RuntimeException('Choose today or a future date.');
    if($scheduled){
        if(!preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9](?::00)?\z/',$time)||!isBookable($pdo,$clinicId,$physicianId,$date,$time,$excludeId))throw new RuntimeException('The selected physician has no available slot at that date and time.');
    }elseif(!physicianWorksOnDate($pdo,$clinicId,$physicianId,$date)){
        throw new RuntimeException('The clinic or selected physician is unavailable on this date.');
    }
}

/** Used by both staff completion routes. Owns its transaction. */
function completeClinicVisit(PDO $pdo,int $appointmentId,int $clinicId,int $staffId,bool $attendanceVerified=false,string $resolution=''):bool
{
 if(!$attendanceVerified)throw new RuntimeException('Verify that this consultation took place before confirming completion.');
 $pdo->beginTransaction();try{
  lockQueueClinic($pdo,$clinicId);
  $s=$pdo->prepare("SELECT 1 FROM Users u JOIN Roles r ON r.RoleID=u.RoleID WHERE u.UserID=? AND u.ClinicID=? AND r.RoleName='Staff' AND u.Status='Active' AND u.DeletedAt IS NULL");$s->execute([$staffId,$clinicId]);if(!$s->fetchColumn())throw new RuntimeException('Staff access to this clinic is required.');
  $s=$pdo->prepare('SELECT * FROM Appointments WHERE AppointmentID=? AND ClinicID=? FOR UPDATE');$s->execute([$appointmentId,$clinicId]);$a=$s->fetch();if(!$a)throw new RuntimeException('Consultation not found in your clinic.');
  if($a['Status']==='Completed'){$pdo->commit();return false;}
  if($a['Status']!=='Confirmed')throw new RuntimeException('Only a confirmed consultation can be completed.');
  finishVerifiedVisit($pdo,$a,$staffId,'Staff',trim($resolution));$pdo->commit();return true;
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

/** Queue no-shows retain the paid fee; they never earn completed-visit commission. */
function markClinicNoShow(PDO $pdo,int $appointmentId,int $clinicId,int $actorId,?int $physicianScope=null,?int $queueId=null):bool
{
    $pdo->beginTransaction();
    try{
        lockQueueClinic($pdo,$clinicId);
        $stmt=$pdo->prepare('SELECT PatientID,PhysicianID,Status,AppointmentDate,AppointmentTime,BookingFeePaid FROM Appointments WHERE AppointmentID=? AND ClinicID=? FOR UPDATE');$stmt->execute([$appointmentId,$clinicId]);$a=$stmt->fetch();
        if(!$a||($physicianScope!==null&&(int)$a['PhysicianID']!==$physicianScope))throw new RuntimeException('Appointment not found.');
        if($a['Status']==='NoShow'){$pdo->commit();return false;}
        if($a['Status']!=='Confirmed')throw new RuntimeException('Only confirmed visits can be marked as no-show.');
        if($a['AppointmentDate']>date('Y-m-d')||(!empty($a['AppointmentTime'])&&strtotime($a['AppointmentDate'].' '.$a['AppointmentTime'])>time()))throw new RuntimeException('This appointment has not started yet.');
        $s=$pdo->prepare("SELECT COUNT(*) FROM ConsultationVersions v JOIN Consultations c ON c.ConsultationID=v.ConsultationID WHERE c.AppointmentID=? AND v.Status='Finalized'");$s->execute([$appointmentId]);
        if((int)$s->fetchColumn())throw new RuntimeException('This visit has finalized notes. Review it for completion instead.');
        if($queueId!==null){$s=$pdo->prepare("SELECT QueueID FROM Queue WHERE QueueID=? AND AppointmentID=? AND ClinicID=? AND Status IN ('Calling','Serving') FOR UPDATE");$s->execute([$queueId,$appointmentId,$clinicId]);if(!$s->fetchColumn())throw new RuntimeException('This queue entry is no longer being called or served.');}
        $pdo->prepare("UPDATE Appointments SET Status='NoShow' WHERE AppointmentID=?")->execute([$appointmentId]);
        $pdo->prepare("UPDATE Queue SET Status='Forfeited_Late' WHERE AppointmentID=? AND Status IN ('Waiting','Calling','Serving')")->execute([$appointmentId]);
        $pdo->prepare("UPDATE AppointmentRescheduleRequests SET Status='Withdrawn',ReviewedAt=NOW() WHERE AppointmentID=? AND Status='Pending'")->execute([$appointmentId]);
        notifyPatient($pdo,(int)$a['PatientID'],'Your appointment was marked as no-show. You can book another visit. Any paid booking fee is retained; contact the clinic if this status is incorrect.',$appointmentId);
        logActivity($pdo,$actorId,$clinicId,'Marked appointment no-show','Appointment #'.$appointmentId.'; booking fee retained, no automatic refund.');
        $pdo->commit();return true;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

/** All staff reschedule routes share validation, locking and queue replacement. */
function rescheduleClinicVisit(PDO $pdo,int $appointmentId,int $clinicId,int $staffId,string $date,string $time,?int $requestId=null,?int $queueId=null):void
{
    if(!lockClinicBooking($pdo,$clinicId))throw new RuntimeException('The clinic is busy. Please try again.');
    try{
        $pdo->beginTransaction();lockQueueClinic($pdo,$clinicId);
        $s=$pdo->prepare('SELECT * FROM Appointments WHERE AppointmentID=? AND ClinicID=? FOR UPDATE');$s->execute([$appointmentId,$clinicId]);$a=$s->fetch();
        if(!$a||!in_array($a['Status'],['Pending','Confirmed'],true))throw new RuntimeException('Only active appointments can be rescheduled.');
        if($requestId!==null){$s=$pdo->prepare("SELECT RequestedDate,RequestedTime FROM AppointmentRescheduleRequests WHERE RequestID=? AND AppointmentID=? AND ClinicID=? AND PatientID=? AND Status='Pending' FOR UPDATE");$s->execute([$requestId,$appointmentId,$clinicId,$a['PatientID']]);$r=$s->fetch();if(!$r)throw new RuntimeException('This reschedule request is no longer pending.');$date=$r['RequestedDate'];$time=$r['RequestedTime'];}
        if(!hqValidCalendarValue($date)||!preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9](?::00)?\z/',$time)||strtotime($date.' '.$time)<=time())throw new RuntimeException('Choose a valid future date and time.');
        $s=$pdo->prepare("SELECT COUNT(*) FROM ConsultationVersions v JOIN Consultations c ON c.ConsultationID=v.ConsultationID WHERE c.AppointmentID=? AND v.Status='Finalized'");$s->execute([$appointmentId]);if((int)$s->fetchColumn())throw new RuntimeException('A visit with finalized notes cannot be rescheduled.');
        $s=$pdo->prepare("SELECT QueueID,Status FROM Queue WHERE AppointmentID=? AND Status IN ('Waiting','Calling','Serving') FOR UPDATE");$s->execute([$appointmentId]);$queues=$s->fetchAll();
        foreach($queues as $q)if($q['Status']==='Serving')throw new RuntimeException('A consultation in progress cannot be rescheduled.');
        if($queueId!==null&&!in_array($queueId,array_map('intval',array_column($queues,'QueueID')),true))throw new RuntimeException('The queue entry does not belong to this active appointment.');
        if($a['PhysicianID']){assertClinicPhysician($pdo,$clinicId,(int)$a['PhysicianID']);assertVisitAvailability($pdo,$clinicId,(int)$a['PhysicianID'],$date,$time,$appointmentId);}
        elseif(!isBookable($pdo,$clinicId,null,$date,$time,$appointmentId))throw new RuntimeException('No physician is available at that date and time.');
        $pdo->prepare('UPDATE Appointments SET AppointmentDate=?,AppointmentTime=? WHERE AppointmentID=?')->execute([$date,$time,$appointmentId]);
        $pdo->prepare("UPDATE Queue SET Status='Removed' WHERE AppointmentID=? AND Status IN ('Waiting','Calling','Serving')")->execute([$appointmentId]);
        if($a['Status']==='Confirmed'){$a['AppointmentDate']=$date;$a['AppointmentTime']=$time;createClinicQueueTicket($pdo,$a,$staffId);}
        $pdo->prepare("UPDATE AppointmentRescheduleRequests SET Status=CASE WHEN RequestID=? THEN 'Approved' ELSE 'Withdrawn' END,ReviewedBy=?,ReviewedAt=NOW() WHERE AppointmentID=? AND Status='Pending'")->execute([$requestId??0,$staffId,$appointmentId]);
        notifyPatient($pdo,(int)$a['PatientID'],'Your appointment is now '.$date.' at '.date('g:i A',strtotime($time)).'.',$appointmentId);
        logActivity($pdo,$staffId,$clinicId,'Rescheduled appointment','Appointment #'.$appointmentId.' to '.$date.' '.$time);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    finally{unlockClinicBooking($pdo,$clinicId);}
}
