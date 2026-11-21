<?php
require_once __DIR__.'/physician-accounts.php';
require_once __DIR__.'/input-validation.php';
require_once __DIR__.'/clinic-inquiry.php';
require_once __DIR__.'/audit.php';
/** Approved fees take effect by booking creation date, without a background worker. */
function currentClinicFee(PDO $pdo,int $clinicId):string
{
 $s=$pdo->prepare("SELECT COALESCE((SELECT ProposedFee FROM ClinicFeeRequests WHERE ClinicID=c.ClinicID AND Status='Approved' AND EffectiveDate<=CURDATE() ORDER BY EffectiveDate DESC,RequestID DESC LIMIT 1),c.BaseConsultationFee) FROM Clinic c WHERE c.ClinicID=?");$s->execute([$clinicId]);$fee=$s->fetchColumn();if($fee===false)throw new RuntimeException('Clinic unavailable.');return (string)$fee;
}
function clinicFeeRows(PDO $pdo,array $rows):array {foreach($rows as &$row)$row['BaseConsultationFee']=currentClinicFee($pdo,(int)$row['ClinicID']);unset($row);return $rows;}
function requestClinicFee(PDO $pdo,int $physicianId,string $fee,string $date,string $reason):int
{
 $owner=requireMainPhysician($pdo,$physicianId);$clinicId=(int)$owner['ClinicID'];$reason=trim($reason);
 if(!validClinicInquiryPrice($fee)||!hqValidCalendarValue($date)||$reason===''||mb_strlen($reason)>1000)throw new RuntimeException('Enter a valid fee, effective date and reason up to 1,000 characters.');
 $pdo->beginTransaction();try{
  $s=$pdo->prepare("SELECT ClinicID FROM Clinic WHERE ClinicID=? AND archived=0 AND Status='Active' FOR UPDATE");$s->execute([$clinicId]);if(!$s->fetchColumn())throw new RuntimeException('Clinic unavailable.');requireMainPhysician($pdo,$physicianId);
  $today=(string)$pdo->query('SELECT CURDATE()')->fetchColumn();if($date<$today)throw new RuntimeException('Choose today or a future effective date.');
  $s=$pdo->prepare("SELECT RequestID FROM ClinicFeeRequests WHERE ClinicID=? AND (Status='Pending' OR (Status='Approved' AND EffectiveDate>CURDATE())) LIMIT 1");$s->execute([$clinicId]);if($s->fetchColumn())throw new RuntimeException('Your clinic already has a pending or scheduled fee change.');
  $current=currentClinicFee($pdo,$clinicId);if((int)round((float)$fee*100)===(int)round((float)$current*100))throw new RuntimeException('Enter a fee different from the current consultation fee.');
  $pdo->prepare('INSERT INTO ClinicFeeRequests(ClinicID,RequestedBy,CurrentFee,ProposedFee,EffectiveDate,Reason) VALUES(?,?,?,?,?,?)')->execute([$clinicId,$physicianId,$current,$fee,$date,$reason]);$id=(int)$pdo->lastInsertId();
  logActivity($pdo,$physicianId,$clinicId,'Requested consultation fee change','Request #'.$id.'; '.$current.' to '.$fee.'; effective '.$date);$pdo->commit();return $id;
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function reviewClinicFee(PDO $pdo,int $adminId,int $requestId,string $decision):void
{
 if(!in_array($decision,['Approved','Declined'],true))throw new RuntimeException('Choose approve or decline.');
 $s=$pdo->prepare("SELECT 1 FROM Users u JOIN Roles r ON r.RoleID=u.RoleID WHERE u.UserID=? AND r.RoleName='Admin' AND u.Status='Active' AND u.DeletedAt IS NULL");$s->execute([$adminId]);if(!$s->fetchColumn())throw new RuntimeException('Administrator access required.');
 $s=$pdo->prepare('SELECT ClinicID FROM ClinicFeeRequests WHERE RequestID=?');$s->execute([$requestId]);$clinicId=(int)$s->fetchColumn();if(!$clinicId)throw new RuntimeException('Fee request unavailable.');
 $pdo->beginTransaction();try{
  $s=$pdo->prepare("SELECT ClinicID FROM Clinic WHERE ClinicID=? AND archived=0 AND Status='Active' FOR UPDATE");$s->execute([$clinicId]);if(!$s->fetchColumn())throw new RuntimeException('Clinic unavailable.');
  $s=$pdo->prepare('SELECT * FROM ClinicFeeRequests WHERE RequestID=? FOR UPDATE');$s->execute([$requestId]);$r=$s->fetch();if($r['Status']!=='Pending')throw new RuntimeException('This fee request has already been reviewed.');
  $today=(string)$pdo->query('SELECT CURDATE()')->fetchColumn();if($decision==='Approved'&&$r['EffectiveDate']<$today)throw new RuntimeException('The requested effective date has passed. Decline this request and ask for a new effective date.');
  $pdo->prepare('UPDATE ClinicFeeRequests SET Status=?,ReviewedBy=?,ReviewedAt=NOW() WHERE RequestID=?')->execute([$decision,$adminId,$requestId]);
  $amount='PHP '.number_format((float)$r['ProposedFee'],2);$effective=date('j F Y',strtotime($r['EffectiveDate']));
  $message=$decision==='Approved'?'Your consultation fee change to '.$amount.' has been approved, effective '.$effective.'. New bookings will use this fee from the effective date. Existing bookings keep their original fee.':'Your consultation fee change request to '.$amount.' for '.$effective.' has been declined. Your current clinic fee remains unchanged. You can submit a new request in Manage Clinic.';
  $pdo->prepare('INSERT INTO PhysicianNotifications(PhysicianID,FeeRequestID,Message) VALUES(?,?,?)')->execute([(int)$r['RequestedBy'],$requestId,$message]);
  logActivity($pdo,$adminId,$clinicId,$decision.' consultation fee request','Request #'.$requestId);$pdo->commit();
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
