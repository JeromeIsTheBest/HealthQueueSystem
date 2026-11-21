<?php
require_once __DIR__.'/availability.php';
require_once __DIR__.'/scheduled-grace.php';

const HQ_ESTIMATED_VISIT_SECONDS = 15 * 60;
const HQ_NEAR_TURN_MINUTES = 15;
const HQ_QUEUE_EARLY_FILL_SECONDS = 10 * 60;

/** First instant within published clinic/physician hours; never invent tomorrow's slot. */
function nextQueueWorkingTime(int $at,array $windows):?int
{
    foreach($windows as [$start,$end]){if($at<$start)$at=$start;if($at<$end)return $at;}
    return null;
}

/** Predict one physician's line, honoring due scheduled visits and adjusted queue positions. */
function estimatePhysicianQueue(array $rows,array $windows,int $now):array
{
    $result=[];$waiting=[];$active=[];$held=[];
    foreach($rows as $row){$grace=scheduledGraceInfo($row,$now);if($grace['held']){$held[]=$row+['GraceHeld'=>true,'GraceDeadline'=>date('Y-m-d H:i:s',$grace['deadline']),'GraceExtended'=>$grace['extended'],'GraceRescheduleRequested'=>$grace['reschedule_requested'],'EstimatedTurnAt'=>null,'EstimatedWaitMinutes'=>null];continue;}if($row['Status']==='Waiting')$waiting[]=$row;elseif(in_array($row['Status'],['Calling','Serving'],true))$active[]=$row;}
    $position=static fn(array $r):int=>$r['Position']!==null?(int)$r['Position']:(int)$r['QueueNumber']*10;
    $byPosition=static fn(array $a,array $b):int=>[$position($a),(int)$a['QueueNumber'],(int)$a['QueueID']]<=>[$position($b),(int)$b['QueueNumber'],(int)$b['QueueID']];
    usort($waiting,$byPosition);
    usort($active,static fn($a,$b)=>[($a['Status']==='Serving'?0:1),(int)$a['QueueID']]<=>[($b['Status']==='Serving'?0:1),(int)$b['QueueID']]);
    $cursor=$now;
    foreach($active as $row){
        $result[(int)$row['QueueID']]=$row+['EstimatedTurnAt'=>date('Y-m-d H:i:s',$now),'EstimatedWaitMinutes'=>0];
        $elapsed=$row['Status']==='Serving'&&!empty($row['ServedAt'])?max(0,$now-strtotime($row['ServedAt'])):0;
        $cursor+=max(0,HQ_ESTIMATED_VISIT_SECONDS-$elapsed);
    }
    while($waiting){
        $aligned=nextQueueWorkingTime($cursor,$windows);if($aligned===null)break;$cursor=$aligned;
        $due=[];$regular=[];$future=[];
        $waitingIds=array_column($waiting,'QueueID');
        $unblocked=array_filter($waiting,static fn($r)=>empty($r['LateAfterQueueID']) || !in_array($r['LateAfterQueueID'],$waitingIds));
        if (!$unblocked) $unblocked=$waiting; // Defensive fallback for corrupt legacy cycles.
        foreach($unblocked as $i=>$row){
            $time=$row['AppointmentTime']??null;
            if(!$time||$time==='00:00:00'){$regular[]=$i;continue;}
            $start=strtotime($row['AppointmentDate'].' '.$time);
            if($start<=$cursor)$due[]=$i;else $future[$i]=$start;
        }
        if($due){usort($due,static fn($i,$j)=>[$waiting[$i]['AppointmentTime'],$position($waiting[$i]),(int)$waiting[$i]['QueueID']]<=>[$waiting[$j]['AppointmentTime'],$position($waiting[$j]),(int)$waiting[$j]['QueueID']]);$index=$due[0];}
        elseif($regular){
            $nextTimed=$future?min($future):null;
            $gap=$nextTimed!==null?$nextTimed-$cursor:PHP_INT_MAX;
            // Reserve full estimated visits in the projected line. If the doctor is
            // actually idle now, staff may fill a real gap of at least ten minutes.
            $idleNow=!$active && !$result && $cursor===$now;
            if ($nextTimed!==null && $gap<HQ_ESTIMATED_VISIT_SECONDS && !($idleNow && $gap>=HQ_QUEUE_EARLY_FILL_SECONDS)) {$cursor=$nextTimed;continue;}
            $index=$regular[0];
        }
        else{$cursor=min($future);continue;}
        $row=$waiting[$index];unset($waiting[$index]);
        $result[(int)$row['QueueID']]=$row+['EstimatedTurnAt'=>date('Y-m-d H:i:s',$cursor),'EstimatedWaitMinutes'=>(int)ceil(max(0,$cursor-$now)/60)];
        $cursor+=HQ_ESTIMATED_VISIT_SECONDS;
    }
    foreach($held as $row)$result[(int)$row['QueueID']]=$row;
    return $result;
}

/** Current queue snapshot. Estimates are advisory, not guaranteed appointment times. */
function queueSmsEstimates(PDO $pdo,string $date,?DateTimeImmutable $now=null,?int $clinicId=null,?int $physicianId=null,bool $respectPublishedHours=true):array
{
    $now=$now??new DateTimeImmutable((string)$pdo->query('SELECT NOW()')->fetchColumn());
    $sql="SELECT q.QueueID,q.ClinicID,q.PhysicianID,q.AppointmentID,q.QueueNumber,q.ScheduledNumber,q.RegularNumber,q.Position,q.LateAfterQueueID,q.ArrivedAt,q.GracePolicyEnabledAt,q.GraceExtendedAt,q.GraceRescheduleRequestedAt,q.Status,q.CalledAt,q.ServedAt,a.PatientID,a.AppointmentDate,a.AppointmentTime,c.ClinicName,CONCAT(doctor.FirstName,' ',doctor.LastName) AS DoctorName FROM Queue q JOIN Appointments a ON a.AppointmentID=q.AppointmentID JOIN Clinic c ON c.ClinicID=q.ClinicID JOIN Users doctor ON doctor.UserID=q.PhysicianID WHERE a.AppointmentDate=? AND DATE(q.CreatedAt)=a.AppointmentDate AND a.Status='Confirmed' AND q.Status IN ('Waiting','Calling','Serving') AND doctor.Status='Active' AND doctor.DeletedAt IS NULL";
    $args=[$date];if($clinicId!==null){$sql.=' AND q.ClinicID=?';$args[]=$clinicId;}if($physicianId!==null){$sql.=' AND q.PhysicianID=?';$args[]=$physicianId;}
    $s=$pdo->prepare($sql.' ORDER BY q.ClinicID,q.PhysicianID,q.QueueID');$s->execute($args);$groups=[];
    foreach($s->fetchAll() as $row)$groups[$row['ClinicID'].':'.$row['PhysicianID']][]=$row;
    $result=[];
    foreach($groups as $rows){
        $first=$rows[0];
        if (!$respectPublishedHours) {$windows=[[strtotime($date.' 00:00:00'),strtotime($date.' 23:59:59')]];$result+=estimatePhysicianQueue($rows,$windows,$now->getTimestamp());continue;}
        $clinic=clinicOperatingWindow($pdo,(int)$first['ClinicID'],$date);if(!$clinic)continue;
        $s=$pdo->prepare('SELECT StartTime,EndTime FROM PhysicianDateAvailability v WHERE PhysicianID=? AND AvailDate=? AND IsDayOff=0 AND NOT EXISTS(SELECT 1 FROM PhysicianDateAvailability offDay WHERE offDay.PhysicianID=v.PhysicianID AND offDay.AvailDate=v.AvailDate AND offDay.IsDayOff=1) ORDER BY StartTime,EndTime');$s->execute([$first['PhysicianID'],$date]);$windows=[];
        foreach($s->fetchAll() as $block){$start=strtotime($date.' '.max($clinic['OpenTime'],$block['StartTime']));$end=strtotime($date.' '.min($clinic['CloseTime'],$block['EndTime']));if($start<$end)$windows[]=[$start,$end];}
        $result+=estimatePhysicianQueue($rows,$windows,$now->getTimestamp());
    }
    return $result;
}

function smsQueueTicket(array $row):string
{
    return $row['ScheduledNumber']!==null?'S-'.(int)$row['ScheduledNumber']:'#'.(int)($row['RegularNumber']??$row['QueueNumber']);
}

/** The displayed live line includes future timed visits at their estimated positions. */
function clinicTimeQueue(PDO $pdo,int $clinicId,?int $physicianId=null,?DateTimeImmutable $now=null):array
{
    $now=$now??new DateTimeImmutable((string)$pdo->query('SELECT NOW()')->fetchColumn());
    $rows=array_values(queueSmsEstimates($pdo,$now->format('Y-m-d'),$now,$clinicId,$physicianId,false));
    usort($rows,static fn($a,$b)=>[in_array($a['Status'],['Calling','Serving'],true)?0:1,!empty($a['GraceHeld'])?1:0,$a['EstimatedTurnAt']??'9999',(int)$a['QueueNumber'],(int)$a['QueueID']]<=>[in_array($b['Status'],['Calling','Serving'],true)?0:1,!empty($b['GraceHeld'])?1:0,$b['EstimatedTurnAt']??'9999',(int)$b['QueueNumber'],(int)$b['QueueID']]);
    return $rows;
}

/** First ready patient per idle physician; never call a timed visit before its booked time. */
function nextTimeQueuePatient(PDO $pdo,int $clinicId,?int $physicianId=null,?DateTimeImmutable $now=null):?array
{
    $now=$now??new DateTimeImmutable((string)$pdo->query('SELECT NOW()')->fetchColumn());
    $line=clinicTimeQueue($pdo,$clinicId,$physicianId,$now);$busy=[];$seen=[];
    foreach($line as $row)if(empty($row['GraceHeld'])&&in_array($row['Status'],['Calling','Serving'],true))$busy[(int)$row['PhysicianID']]=true;
    foreach($line as $row){
        $doctor=(int)$row['PhysicianID'];
        if(!empty($row['GraceHeld'])||$row['Status']!=='Waiting'||isset($busy[$doctor])||isset($seen[$doctor]))continue;
        $seen[$doctor]=true;
        if(strtotime($row['EstimatedTurnAt'])<=$now->getTimestamp())return $row;
    }
    return null;
}
