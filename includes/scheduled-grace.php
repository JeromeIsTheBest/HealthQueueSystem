<?php
const HQ_SCHEDULED_GRACE_SECONDS = 30 * 60;

/** Arrival is staff-confirmed. A consultation already in progress is never treated as absent. */
function scheduledGraceInfo(array $row,int $now):array
{
    $time=$row['AppointmentTime']??null;
    $scheduled=!empty($time)&&$time!=='00:00:00';
    $start=$scheduled?strtotime($row['AppointmentDate'].' '.$time):null;
    $arrived=!empty($row['ArrivedAt'])||($row['Status']??'')==='Serving';
    $deadline=$start!==null?$start+HQ_SCHEDULED_GRACE_SECONDS: null;
    if($deadline!==null&&!empty($row['GraceExtendedAt']))$deadline+=HQ_SCHEDULED_GRACE_SECONDS;
    $requested=!empty($row['GraceRescheduleRequestedAt']);
    $managed=!empty($row['GracePolicyEnabledAt']);
    $held=$managed&&$scheduled&&!$arrived&&$now>=$start;
    return ['managed'=>$managed,'scheduled'=>$scheduled,'start'=>$start,'arrived'=>$arrived,'held'=>$held,'deadline'=>$deadline,
        'extended'=>!empty($row['GraceExtendedAt']),'reschedule_requested'=>$requested,
        'can_act'=>$held&&$now<$deadline&&!$requested,
        'auto_no_show'=>$held&&$now>=$deadline&&!$requested&&empty($row['HasClinicalRecord'])];
}

/** May the patient request a reschedule during the special late-arrival grace period? */
function appointmentGraceRescheduleOpen(PDO $pdo,int $appointmentId,int $patientId):bool
{
    $s=$pdo->prepare("SELECT q.*,a.AppointmentDate,a.AppointmentTime FROM Queue q JOIN Appointments a ON a.AppointmentID=q.AppointmentID WHERE a.AppointmentID=? AND a.PatientID=? AND a.Status='Confirmed' AND q.Status IN ('Waiting','Calling') AND DATE(q.CreatedAt)=a.AppointmentDate ORDER BY q.QueueID DESC LIMIT 1");
    $s->execute([$appointmentId,$patientId]);$row=$s->fetch();
    if(!$row)return false;
    $now=strtotime((string)$pdo->query('SELECT NOW()')->fetchColumn());
    return scheduledGraceInfo($row,$now)['can_act'];
}
