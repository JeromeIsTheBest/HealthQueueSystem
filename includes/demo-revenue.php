<?php
require_once __DIR__.'/physician-accounts.php';
require_once __DIR__.'/platform-bank-demo.php';
/** School demo only: recognize wallet-backed completed visits, never send money. */
function demoRevenueEligibility(): string
{
    return "p.PaymentStatus = 'Paid' AND p.RefundedAt IS NULL AND a.Status = 'Completed'
      AND a.BookingFeePaid = 1 AND a.BookingPaymentMethod = 'wallet' AND a.PhysicianID IS NOT NULL
      AND EXISTS (SELECT 1 FROM WalletTransactions w WHERE w.RelatedAppointmentID = a.AppointmentID
          AND w.PatientID = a.PatientID AND w.Type = 'BookingPayment' AND w.Amount = p.GrossAmount)
      AND NOT EXISTS (SELECT 1 FROM WalletTransactions r WHERE r.RelatedAppointmentID = a.AppointmentID AND r.Type = 'Refund')";
}

/** Caller owns transaction. Appointment lock makes repeat completion idempotent. */
function recordCompletedDemoPayment(PDO $pdo, int $appointmentId): bool
{
    if (!$pdo->inTransaction()) throw new LogicException('Commission recognition requires a transaction.');
    $stmt = $pdo->prepare('SELECT PatientID, PhysicianID, ClinicID, Status, BookingFeePaid, BookingPaymentMethod FROM Appointments WHERE AppointmentID = ? FOR UPDATE');
    $stmt->execute([$appointmentId]);$a=$stmt->fetch();
    if (!$a || $a['Status'] !== 'Completed' || !$a['PhysicianID'] || !(int)$a['BookingFeePaid'] || $a['BookingPaymentMethod'] !== 'wallet') return false;
    $stmt=$pdo->prepare('SELECT PaymentID FROM AppointmentPayments WHERE AppointmentID=?');$stmt->execute([$appointmentId]);
    if($existing=$stmt->fetchColumn()){releaseDemoPayment($pdo,(int)$existing);return false;}
    $stmt=$pdo->prepare("SELECT Amount FROM WalletTransactions WHERE RelatedAppointmentID=? AND PatientID=? AND Type='BookingPayment' ORDER BY TransactionID LIMIT 1 FOR UPDATE");
    $stmt->execute([$appointmentId,$a['PatientID']]);$amount=$stmt->fetchColumn();
    $stmt=$pdo->prepare("SELECT COUNT(*) FROM WalletTransactions WHERE RelatedAppointmentID=? AND Type='Refund'");$stmt->execute([$appointmentId]);
    if($amount===false || (int)$stmt->fetchColumn()) return false;
    $cents=(int)round((float)$amount*100);if($cents<=0)return false;
    $physician=(int)round($cents*0.9);$commission=$cents-$physician;
    $ownerStmt=$pdo->prepare('SELECT MainPhysicianID FROM PhysicianAccountOwners WHERE ClinicID=?');$ownerStmt->execute([$a['ClinicID']]);$financialOwnerId=$ownerStmt->fetchColumn();
    if(!$financialOwnerId)throw new RuntimeException('This clinic needs a Main Physician before revenue can be recorded.');
    $pdo->prepare("INSERT INTO AppointmentPayments (AppointmentID,GrossAmount,PhysicianRevenue,PlatformCommission,PaymentStatus,FinancialOwnerUserID) VALUES (?,?,?,?,'Paid',?)")
      ->execute([$appointmentId,number_format($cents/100,2,'.',''),number_format($physician/100,2,'.',''),number_format($commission/100,2,'.',''),$financialOwnerId]);
    releaseDemoPayment($pdo,(int)$pdo->lastInsertId());
    return true;
}

function demoRevenueSummary(PDO $pdo): array
{
    $where=demoRevenueEligibility();
    return $pdo->query("SELECT COALESCE(SUM(p.GrossAmount),0) AS gross,
      COALESCE(SUM(CASE WHEN i.PaymentID IS NULL THEN p.GrossAmount ELSE 0 END),0) AS pending,
      COALESCE(SUM(CASE WHEN i.PaymentID IS NOT NULL THEN p.GrossAmount ELSE 0 END),0) AS settled,
      COALESCE(SUM(p.PlatformCommission),0) AS commission, COALESCE(SUM(p.PhysicianRevenue),0) AS physician,
      COUNT(*) AS payments FROM AppointmentPayments p JOIN Appointments a ON a.AppointmentID=p.AppointmentID
      LEFT JOIN DemoSettlementItems i ON i.PaymentID=p.PaymentID WHERE {$where}")->fetch();
}

/** Atomic, idempotent allocation of eligible demo payments; never calls a gateway. */
function simulateDemoSettlement(PDO $pdo, int $adminId, string $requestKey): array
{
    if (!preg_match('/\A[a-f0-9]{64}\z/',$requestKey)) throw new RuntimeException('Invalid settlement request. Refresh and try again.');
    if($pdo->inTransaction())throw new LogicException('Settlement owns its transaction.');
    $pdo->beginTransaction();
    try {
        $stmt=$pdo->prepare('SELECT Reference,GrossAmount FROM DemoSettlements WHERE RequestKey=?');$stmt->execute([$requestKey]);
        if($existing=$stmt->fetch()){$pdo->commit();return $existing;}
        $where=demoRevenueEligibility();
        $payments=$pdo->query("SELECT p.PaymentID,p.GrossAmount FROM AppointmentPayments p JOIN Appointments a ON a.AppointmentID=p.AppointmentID WHERE {$where} ORDER BY p.PaymentID FOR UPDATE")->fetchAll();
        $stmt=$pdo->prepare('SELECT Reference,GrossAmount FROM DemoSettlements WHERE RequestKey=? FOR UPDATE');$stmt->execute([$requestKey]);
        if($existing=$stmt->fetch()){$pdo->commit();return $existing;}
        // Read allocations after acquiring payment locks so concurrent submissions see committed allocations.
        $allocated=array_flip(array_map('intval',$pdo->query('SELECT PaymentID FROM DemoSettlementItems FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN)));
        $ids=[];$cents=0;
        foreach($payments as $payment)if(!isset($allocated[(int)$payment['PaymentID']])){$ids[]=(int)$payment['PaymentID'];$cents+=(int)round((float)$payment['GrossAmount']*100);}
        if(!$ids || $cents<=0)throw new RuntimeException('No completed demo payments are available to settle.');
        $reference='DEMO-SET-'.date('Ymd').'-'.strtoupper(bin2hex(random_bytes(4)));
        $amount=number_format($cents/100,2,'.','');
        $pdo->prepare('INSERT INTO DemoSettlements (RequestKey,Reference,GrossAmount,CreatedByUserID) VALUES (?,?,?,?)')->execute([$requestKey,$reference,$amount,$adminId]);
        $id=(int)$pdo->lastInsertId();$stmt=$pdo->prepare('INSERT INTO DemoSettlementItems (SettlementID,PaymentID) VALUES (?,?)');
        foreach($ids as $paymentId)$stmt->execute([$id,$paymentId]);
        $pdo->commit();return ['Reference'=>$reference,'GrossAmount'=>$amount];
    } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

/** Automatically release one eligible payment. Caller owns the completion transaction. */
function releaseDemoPayment(PDO $pdo,int $paymentId):bool
{
    if(!$pdo->inTransaction())throw new LogicException('Automatic release requires a transaction.');
    $where=demoRevenueEligibility();
    $s=$pdo->prepare("SELECT p.PaymentID,p.GrossAmount,p.FinancialOwnerUserID FROM AppointmentPayments p JOIN Appointments a ON a.AppointmentID=p.AppointmentID WHERE p.PaymentID=? AND {$where} FOR UPDATE");$s->execute([$paymentId]);$payment=$s->fetch();if(!$payment)return false;
    $s=$pdo->prepare('SELECT SettlementID FROM DemoSettlementItems WHERE PaymentID=? FOR UPDATE');$s->execute([$paymentId]);if($s->fetchColumn()){recordPlatformDemoBankTransfer($pdo,$paymentId);return false;}
    if(!$payment['FinancialOwnerUserID'])throw new RuntimeException('Payment needs a financial owner before release.');
    $key=hash('sha256','healthqueue-auto-release-v1-'.$paymentId);$reference='DEMO-AUTO-'.$paymentId.'-'.strtoupper(bin2hex(random_bytes(4)));
    $pdo->prepare('INSERT INTO DemoSettlements(RequestKey,Reference,GrossAmount,CreatedByUserID) VALUES(?,?,?,?)')->execute([$key,$reference,$payment['GrossAmount'],$payment['FinancialOwnerUserID']]);
    $pdo->prepare('INSERT INTO DemoSettlementItems(SettlementID,PaymentID) VALUES(?,?)')->execute([(int)$pdo->lastInsertId(),$paymentId]);recordPlatformDemoBankTransfer($pdo,$paymentId);return true;
}
