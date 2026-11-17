<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/demo-revenue.php';
require_once __DIR__ . '/../includes/physician-withdrawals.php';
define('HQ_BASE_URL','..');requireRole(['Admin']);
$user=currentUser();$pdo=getDbConnection();$errors=[];
$flash=(string)($_SESSION['demo_settlement_success']??'');unset($_SESSION['demo_settlement_success']);
if($_SERVER['REQUEST_METHOD']==='POST'){
 if(!verifyCsrfToken($_POST['csrf_token']??null))$errors[]='Your session expired. Please try again.';
 elseif(!$pdo)$errors[]='The database is temporarily unavailable.';
 elseif(($_POST['form_type']??'')==='simulate_settlement'){
  try{
   $result=simulateDemoSettlement($pdo,(int)$user['UserID'],(string)($_POST['request_key']??''));
   logActivity($pdo,(int)$user['UserID'],null,'Simulated demo settlement',$result['Reference'].' - PHP '.$result['GrossAmount'].'; no real money transferred.');
   $_SESSION['demo_settlement_success']='Demo settlement recorded. No real money was transferred.';
   unset($_SESSION['demo_settlement_key']);header('Location: '.HQ_BASE_URL.'/admin/revenue.php',true,303);exit;
  }catch(PDOException $e){error_log('Demo settlement failed: '.$e->getMessage());$errors[]='We could not record this simulation. Refresh and try again.';}
  catch(RuntimeException $e){$errors[]=$e->getMessage();}
 }
}
$_SESSION['demo_settlement_key']=$_SESSION['demo_settlement_key']??bin2hex(random_bytes(32));
$totals=['gross'=>0,'pending'=>0,'settled'=>0,'commission'=>0,'physician'=>0,'payments'=>0];$settlements=[];$reconciliationCount=0;$physicianPaid=0;$ready=false;
if($pdo){try{$totals=demoRevenueSummary($pdo);$reconciliationCount=(int)$pdo->query("SELECT COUNT(*) FROM (SELECT RelatedAppointmentID FROM WalletTransactions GROUP BY RelatedAppointmentID HAVING SUM(CASE WHEN Type='Refund' THEN Amount ELSE 0 END)>0 AND ABS(SUM(CASE WHEN Type='BookingPayment' THEN Amount ELSE 0 END)-SUM(CASE WHEN Type='Refund' THEN Amount ELSE 0 END))>0.005) x")->fetchColumn();$physicianPaid=(float)$pdo->query("SELECT COALESCE(SUM(Amount),0) FROM PhysicianWithdrawals WHERE Status='PaidSimulated'")->fetchColumn();$totals['physician']=max(0,(float)$totals['physician']-$physicianPaid);$settlements=$pdo->query('SELECT Reference,GrossAmount,CreatedAt FROM DemoSettlements ORDER BY SettlementID DESC LIMIT 30')->fetchAll();$ready=true;}
catch(PDOException $e){error_log('Demo revenue read failed: '.$e->getMessage());$errors[]='Demo revenue is temporarily unavailable. Check that the demo settlement migration has been applied.';}}
$isAdminRevenue=true;$pageTitle='Revenue & Commission - HealthQueue';require __DIR__ . '/../includes/header.php';
$paths=['wallet'=>'<rect x="3" y="6" width="18" height="15" rx="2"/><path d="m5 6 11-4v4M21 11h-6v5h6"/>','clock'=>'<circle cx="12" cy="12" r="9"/><path d="M12 6v6l4 3"/>','layers'=>'<path d="m12 3 10 5-10 5L2 8l10-5Zm-10 9 10 5 10-5M2 16l10 5 10-5"/>','percent'=>'<path d="m5 19 14-14"/><circle cx="6" cy="6" r="3"/><circle cx="18" cy="18" r="3"/>','person'=>'<circle cx="12" cy="7" r="4"/><path d="M4 22v-3a8 8 0 0 1 16 0v3"/>','bank'=>'<path d="m2 8 10-6 10 6H2Zm2 3v8m5-8v8m6-8v8m5-8v8M2 22h20"/>','info'=>'<circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7h.01"/>','flask'=>'<path d="M9 2h6M10 2v7L4 19a2 2 0 0 0 2 3h12a2 2 0 0 0 2-3L14 9V2M8 15h8"/>'];
$icon=static fn($key)=>'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.$paths[$key].'</svg>';
?>
<main class="admin-shell rv-page"><div class="container rv-container">
<p class="rv-breadcrumb">Administration <span>/</span> Revenue</p>
<header class="rv-heading"><div><h1>Revenue &amp; Commission</h1><p>Track demo payments, commission, and simulated settlements.</p></div><div class="rv-heading-actions"><a class="btn btn-primary" href="physician-withdrawals.php">Physician withdrawals</a><a class="btn btn-outline" href="revenue-export.php">Export report</a></div></header>
<?php if($flash): ?><p class="form-message success" role="status"><?= htmlspecialchars($flash) ?></p><?php endif; ?>
<?php if($errors): ?><div class="form-message error" role="alert"><?php foreach($errors as $error): ?><p><?= htmlspecialchars($error) ?></p><?php endforeach; ?></div><?php endif; ?>
<div class="rv-demo-banner"><?= $icon('flask') ?><strong>DEMO MODE &mdash; No real money transferred</strong></div>
<?php if($reconciliationCount): ?><p class="form-message warning"><?= $reconciliationCount ?> historical refund record(s) need reconciliation. <a href="reconciliation.php">Review exceptions</a>.</p><?php endif; ?>
<section class="rv-metrics" aria-label="Demo revenue totals">
<?php foreach([['gross','Demo payments','wallet'],['pending','Pending settlement','clock'],['settled','Simulated settled funds','layers'],['commission','Platform commission (10%)','percent'],['physician','Physician payable (90%)','person']] as [$key,$label,$symbol]): ?>
<article class="rv-metric rv-metric-<?= $key ?>"><span class="rv-icon"><?= $icon($symbol) ?></span><div><h2><?= $label ?></h2><strong>PHP <?= number_format((float)$totals[$key],2) ?></strong><?php if($key==='physician'): ?><p>Outstanding demo earnings</p><?php endif; ?></div></article>
<?php endforeach; ?></section>
<p class="rv-info"><?= $icon('info') ?><span>Commission is recorded after a paid consultation is confirmed complete. Only completed visits backed by demo wallet payments are included.</span></p>
<div class="rv-columns">
<section class="rv-panel"><h2>Simulated settlement history</h2>
<div class="rv-table-wrap"><table class="rv-table"><thead><tr><th>Reference</th><th>Date</th><th>Amount</th><th>Status</th></tr></thead><tbody>
<?php foreach($settlements as $settlement): ?><tr><td><strong><?= htmlspecialchars($settlement['Reference']) ?></strong></td><td><?= htmlspecialchars(date('j M Y',strtotime($settlement['CreatedAt']))) ?></td><td>PHP <?= number_format((float)$settlement['GrossAmount'],2) ?></td><td><span class="rv-status">Simulated settled</span></td></tr><?php endforeach; ?>
<?php if(!$settlements): ?><tr><td colspan="4" class="rv-empty">No simulated settlements yet.</td></tr><?php endif; ?></tbody></table></div>
<p class="rv-history-note">Demo records only. Settlement does not release physician earnings.</p>
</section>
<section class="rv-panel"><h2>Demo settlement destination</h2>
<div class="rv-destination"><span class="rv-icon"><?= $icon('bank') ?></span><div><h3>Demo Bank</h3><p>Account ending 1234</p></div><span class="rv-simulated">Simulated</span></div>
<div class="rv-available"><span>Available to simulate</span><strong>PHP <?= number_format((float)$totals['pending'],2) ?></strong></div>
<form method="post" class="rv-settlement-form"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><input type="hidden" name="form_type" value="simulate_settlement"><input type="hidden" name="request_key" value="<?= htmlspecialchars($_SESSION['demo_settlement_key']) ?>"><button type="submit" class="btn btn-primary btn-block" <?= !$ready || (float)$totals['pending']<=0?'disabled':'' ?>>Simulate settlement</button></form>
<p class="rv-destination-note">Demonstrates a gateway payout. No bank connection.</p>
</section></div>
<p class="rv-footnote">School demonstration ? Demo wallet records only ? No actual bank transfers</p>
</div></main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
