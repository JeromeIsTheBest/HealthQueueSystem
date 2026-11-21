<?php
require_once __DIR__.'/availability.php';
require_once __DIR__.'/audit.php';
/** Update one owned hour block, retaining other blocks and existing bookings. */
function updatePhysicianAvailability(PDO $pdo,int $physicianId,string $date,int $slotId,string $start,string $end,?int $perHour):void
{
    if(!hqValidCalendarValue($date)||!preg_match('/\A(?:0[1-9]|1[0-9]|2[01]):(?:00|30)\z|\A22:00\z/',$start)||!preg_match('/\A(?:0[1-9]|1[0-9]|2[01]):(?:00|30)\z|\A22:00\z/',$end)||$start>=$end||($perHour!==null&&($perHour<1||$perHour>12)))throw new RuntimeException('Choose valid hours and patients per hour.');
    $s=$pdo->prepare("SELECT u.ClinicID FROM Users u JOIN Roles r ON r.RoleID=u.RoleID WHERE u.UserID=? AND r.RoleName='Physician' AND u.Status='Active' AND u.DeletedAt IS NULL");$s->execute([$physicianId]);$clinicId=(int)$s->fetchColumn();
    if(!$clinicId)throw new RuntimeException('Physician account unavailable.');
    if(!lockClinicBooking($pdo,$clinicId))throw new RuntimeException('Another booking is being processed. Please try again.');
    try{
        $pdo->beginTransaction();
        $pdo->prepare('SELECT ClinicID FROM Clinic WHERE ClinicID=? FOR UPDATE')->execute([$clinicId]);
        $today=(string)$pdo->query('SELECT CURDATE()')->fetchColumn();if($date<$today)throw new RuntimeException('Only today or future schedules can be updated.');
        $s=$pdo->prepare('SELECT * FROM PhysicianDateAvailability WHERE PhysicianID=? AND AvailDate=? FOR UPDATE');$s->execute([$physicianId,$date]);$blocks=$s->fetchAll();$target=null;$remaining=[];
        foreach($blocks as $block){if((int)$block['SlotID']===$slotId&&!$block['IsDayOff'])$target=$block;elseif(!$block['IsDayOff'])$remaining[]=$block;}
        if(!$target)throw new RuntimeException('This schedule is unavailable. Refresh and select it again.');
        foreach($remaining as $block)if($block['StartTime']<$end.':00'&&$block['EndTime']>$start.':00')throw new RuntimeException('These hours overlap another schedule on this date.');
        $remaining[]=['StartTime'=>$start.':00','EndTime'=>$end.':00','PatientsPerHour'=>$perHour];
        $s=$pdo->prepare('SELECT AppointmentTime FROM Appointments WHERE ClinicID=? AND PhysicianID=? AND AppointmentDate=? AND '.occupyingAppointmentSql());$s->execute([$clinicId,$physicianId,$date]);$bookings=$s->fetchAll(PDO::FETCH_COLUMN);$counts=[];
        foreach($bookings as $time){if(!$time||$time==='00:00:00')continue;$covered=false;foreach($remaining as $block)if($time>=$block['StartTime']&&strtotime($time)+BOOKING_SLOT_MINUTES*60<=strtotime($block['EndTime']))$covered=true;if(!$covered)throw new RuntimeException('These hours exclude an existing appointment. Ask Staff to reschedule the affected visit first.');$hour=substr($time,0,2);$counts[$hour]=($counts[$hour]??0)+1;}
        for($t=strtotime($start);$t<strtotime($end);$t+=1800)if(($counts[date('H',$t)]??0)>($perHour??DEFAULT_PATIENTS_PER_HOUR))throw new RuntimeException('Patients per hour cannot be lower than existing bookings.');
        $pdo->prepare('UPDATE PhysicianDateAvailability SET StartTime=?,EndTime=?,PatientsPerHour=? WHERE SlotID=? AND PhysicianID=?')->execute([$start,$end,$perHour,$slotId,$physicianId]);
        logActivity($pdo,$physicianId,$clinicId,'Updated availability',$date.' '.$start.'-'.$end);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}finally{unlockClinicBooking($pdo,$clinicId);}
}
