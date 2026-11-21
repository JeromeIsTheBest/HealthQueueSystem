<?php
/** Simulation ledger only. No bank API, payment provider, or patient wallet mutation. */
function recordPlatformDemoBankTransfer(PDO $pdo,int $paymentId):bool
{
 if(!$pdo->inTransaction())throw new LogicException('Platform demo bank transfer requires a transaction.');
 $where=demoRevenueEligibility();
 $s=$pdo->prepare("SELECT p.PaymentID,p.PlatformCommission FROM AppointmentPayments p JOIN Appointments a ON a.AppointmentID=p.AppointmentID JOIN DemoSettlementItems i ON i.PaymentID=p.PaymentID WHERE p.PaymentID=? AND {$where} FOR UPDATE");$s->execute([$paymentId]);$payment=$s->fetch();if(!$payment||(float)$payment['PlatformCommission']<=0)return false;
 $s=$pdo->prepare('SELECT TransferID FROM PlatformDemoBankTransfers WHERE PaymentID=? FOR UPDATE');$s->execute([$paymentId]);if($s->fetchColumn())return false;
 $reference='DEMO-BANK-'.$paymentId.'-'.strtoupper(bin2hex(random_bytes(4)));
 $pdo->prepare('INSERT INTO PlatformDemoBankTransfers(PaymentID,Reference,Amount) VALUES(?,?,?)')->execute([$paymentId,$reference,$payment['PlatformCommission']]);return true;
}
