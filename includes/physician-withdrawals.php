<?php
require_once __DIR__.'/demo-revenue.php';
require_once __DIR__.'/input-validation.php';
require_once __DIR__.'/audit.php';
function withdrawalCents(string $amount): int
{
 if(!preg_match('/\A[0-9]{1,8}(?:\.[0-9]{1,2})?\z/',$amount))throw new RuntimeException('Enter a valid amount with up to two decimal places.');
 $parts=explode('.',$amount);return (int)$parts[0]*100+(int)str_pad($parts[1]??'',2,'0');
}
function physicianWithdrawalBalance(PDO $pdo,int $physicianId):array
{
 requireMainPhysician($pdo,$physicianId);
 $where=demoRevenueEligibility();
 $stmt=$pdo->prepare("SELECT COALESCE(SUM(p.PhysicianRevenue),0) AS earned,COALESCE(SUM(CASE WHEN i.PaymentID IS NOT NULL THEN p.PhysicianRevenue ELSE 0 END),0) AS settled FROM AppointmentPayments p JOIN Appointments a ON a.AppointmentID=p.AppointmentID LEFT JOIN DemoSettlementItems i ON i.PaymentID=p.PaymentID WHERE p.FinancialOwnerUserID=? AND {$where}");$stmt->execute([$physicianId]);$row=$stmt->fetch();
 $stmt=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN Status='Pending' THEN Amount ELSE 0 END),0) AS pending,COALESCE(SUM(CASE WHEN Status='PaidSimulated' THEN Amount ELSE 0 END),0) AS paid FROM PhysicianWithdrawals WHERE FinancialOwnerUserID=?");$stmt->execute([$physicianId]);$row=array_merge($row,$stmt->fetch());
 foreach($row as $key=>$value)$row[$key]=(int)round((float)$value*100);
 $row['available']=max(0,$row['settled']-$row['pending']-$row['paid']);$row['awaiting']=max(0,$row['earned']-$row['settled']);return $row;
}
function requestPhysicianWithdrawal(PDO $pdo,int $physicianId,array $input,bool $completeImmediately=false):string
{
 if($completeImmediately&&($input['confirm_simulation']??'')!=='1')throw new RuntimeException('Confirm that this is a simulated withdrawal.');
 $key=(string)($input['request_key']??'');if(!preg_match('/\A[a-f0-9]{64}\z/',$key))throw new RuntimeException('Refresh the page and try again.');
 $cents=withdrawalCents(trim((string)($input['amount']??'')));if($cents<=0)throw new RuntimeException('Enter an amount greater than zero.');
 $method=(string)($input['payout_method']??'');if(!in_array($method,['GCash','Maya','Bank'],true))throw new RuntimeException('Choose a payout method.');
 $name=hqCapitalizeName((string)($input['account_name']??''));if($name===''||mb_strlen($name)>150)throw new RuntimeException('Enter a valid account holder name.');
 $number=trim((string)($input[$method==='Bank'?'bank_account_number':'phone']??''));$bank=trim((string)($input['bank_name']??''));
 if($method==='Bank') {if(!preg_match('/\A[0-9]{6,34}\z/',$number)||$bank===''||mb_strlen($bank)>100)throw new RuntimeException('Enter a bank name and a valid numeric account number (6-34 digits).');}
 elseif($number===''||!hqValidMobile($number))throw new RuntimeException('Enter a valid mobile number');
 requireMainPhysician($pdo,$physicianId);
 $pdo->beginTransaction();
 try{
  // Serialize all reservations for one physician to prevent overspending.
  $stmt=$pdo->prepare("SELECT u.UserID FROM Users u JOIN Roles r ON r.RoleID=u.RoleID WHERE u.UserID=? AND r.RoleName='Physician' AND u.Status='Active' FOR UPDATE");$stmt->execute([$physicianId]);if(!$stmt->fetchColumn())throw new RuntimeException('This physician account is unavailable.');
  requireMainPhysician($pdo,$physicianId);
  $stmt=$pdo->prepare('SELECT PhysicianID,Reference,Status FROM PhysicianWithdrawals WHERE RequestKey=? FOR UPDATE');$stmt->execute([$key]);
  if($existing=$stmt->fetch()){if((int)$existing['PhysicianID']!==$physicianId)throw new RuntimeException('Invalid withdrawal request.');if($completeImmediately&&$existing['Status']!=='PaidSimulated')throw new RuntimeException('This earlier request must be confirmed from withdrawal history.');$pdo->commit();return $existing['Reference'];}
  $balance=physicianWithdrawalBalance($pdo,$physicianId);if($cents>$balance['available'])throw new RuntimeException('The amount exceeds your available settled demo earnings.');
  $ref='DEMO-WD-'.date('Ymd').'-'.strtoupper(bin2hex(random_bytes(4)));
  $pdo->prepare('INSERT INTO PhysicianWithdrawals (Reference,RequestKey,PhysicianID,Amount,PayoutMethod,AccountName,AccountNumber,BankName,FinancialOwnerUserID) VALUES (?,?,?,?,?,?,?,?,?)')->execute([$ref,$key,$physicianId,number_format($cents/100,2,'.',''),$method,$name,$number,$method==='Bank'?$bank:null,$physicianId]);
  if($completeImmediately){$id=(int)$pdo->lastInsertId();$pdo->prepare("UPDATE PhysicianWithdrawals SET Status='PaidSimulated',ReviewedAt=NOW(),ReviewedByUserID=? WHERE WithdrawalID=?")->execute([$physicianId,$id]);logActivity($pdo,$physicianId,(int)requireMainPhysician($pdo,$physicianId)['ClinicID'],'Completed self-service demo withdrawal',$ref.'; no real transfer.');}
  $pdo->commit();return $ref;
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function reviewPhysicianWithdrawal(PDO $pdo,int $withdrawalId,int $adminId,string $decision):bool
{
 if(!in_array($decision,['pay','reject'],true))throw new RuntimeException('Choose a valid review action.');
 $pdo->beginTransaction();
 try{
  $stmt=$pdo->prepare('SELECT COALESCE(FinancialOwnerUserID,PhysicianID) FROM PhysicianWithdrawals WHERE WithdrawalID=?');$stmt->execute([$withdrawalId]);$physicianId=$stmt->fetchColumn();if(!$physicianId)throw new RuntimeException('Withdrawal request not found.');
  $stmt=$pdo->prepare('SELECT UserID FROM Users WHERE UserID=? FOR UPDATE');$stmt->execute([$physicianId]);
  $stmt=$pdo->prepare('SELECT Status FROM PhysicianWithdrawals WHERE WithdrawalID=? FOR UPDATE');$stmt->execute([$withdrawalId]);
  if($stmt->fetchColumn()!=='Pending'){$pdo->commit();return false;}
  $status=$decision==='pay'?'PaidSimulated':'Rejected';
  $pdo->prepare('UPDATE PhysicianWithdrawals SET Status=?,ReviewedAt=NOW(),ReviewedByUserID=? WHERE WithdrawalID=?')->execute([$status,$adminId,$withdrawalId]);$pdo->commit();return true;
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function withdrawalStatus(string $status):string{return ['Pending'=>'Pending','PaidSimulated'=>'Completed - simulated','Rejected'=>'Rejected'][$status]??$status;}
function withdrawalMasked(array $row):string{return ($row['PayoutMethod']==='Bank'?$row['BankName']:$row['PayoutMethod']).' - '.str_repeat('*',4).substr($row['AccountNumber'],-4);}

/** Confirm a pre-upgrade pending reservation without paying it twice or releasing another owner's money. */
function completeOwnDemoWithdrawal(PDO $pdo,int $physicianId,int $withdrawalId):string
{
 requireMainPhysician($pdo,$physicianId);$pdo->beginTransaction();try{
  $pdo->prepare('SELECT UserID FROM Users WHERE UserID=? FOR UPDATE')->execute([$physicianId]);requireMainPhysician($pdo,$physicianId);
  $s=$pdo->prepare('SELECT * FROM PhysicianWithdrawals WHERE WithdrawalID=? AND FinancialOwnerUserID=? FOR UPDATE');$s->execute([$withdrawalId,$physicianId]);$row=$s->fetch();if(!$row)throw new RuntimeException('Withdrawal unavailable.');
  if($row['Status']==='PaidSimulated'){$pdo->commit();return $row['Reference'];}
  if($row['Status']!=='Pending')throw new RuntimeException('Only pending withdrawals can be confirmed.');
  $balance=physicianWithdrawalBalance($pdo,$physicianId);$amount=(int)round((float)$row['Amount']*100);if($amount>max(0,$balance['settled']-$balance['paid']-($balance['pending']-$amount)))throw new RuntimeException('These reserved earnings are no longer eligible. Contact the administrator.');
  $pdo->prepare("UPDATE PhysicianWithdrawals SET Status='PaidSimulated',ReviewedAt=NOW(),ReviewedByUserID=? WHERE WithdrawalID=?")->execute([$physicianId,$withdrawalId]);logActivity($pdo,$physicianId,(int)requireMainPhysician($pdo,$physicianId)['ClinicID'],'Confirmed earlier demo withdrawal',$row['Reference'].'; no real transfer.');$pdo->commit();return $row['Reference'];
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
