<?php
require_once __DIR__.'/../config/db.php';require_once __DIR__.'/../includes/auth.php';require_once __DIR__.'/../includes/audit.php';require_once __DIR__.'/../includes/physician-withdrawals.php';
define('HQ_BASE_URL','..');requireRole(['Physician']);$user=currentUser();$pdo=getDbConnection();$errors=[];$flash=(string)($_SESSION['withdrawal_success']??'');unset($_SESSION['withdrawal_success']);
$values=['amount'=>'','payout_method'=>'GCash','account_name'=>$user['FirstName'].' '.$user['LastName'],'phone'=>'','bank_name'=>'','bank_account_number'=>''];
if($_SERVER['REQUEST_METHOD']==='POST'){
 foreach($values as $key=>$value)$values[$key]=trim((string)($_POST[$key]??''));
 if(!verifyCsrfToken($_POST['csrf_token']??null))$errors[]='Your session expired. Please try again.';
 elseif(!$pdo)$errors[]='The database is temporarily unavailable.';
 else{try{$ref=requestPhysicianWithdrawal($pdo,(int)$user['UserID'],$_POST);logActivity($pdo,(int)$user['UserID'],$user['ClinicID']?(int)$user['ClinicID']:null,'Requested simulated withdrawal',$ref);$_SESSION['withdrawal_success']='Demo withdrawal requested. The amount is reserved pending admin review.';unset($_SESSION['withdrawal_key']);header('Location: '.HQ_BASE_URL.'/physician/withdrawals.php',true,303);exit;}
 catch(PDOException $e){error_log('Withdrawal request failed: '.$e->getMessage());$errors[]='We could not save your request. Please try again.';}catch(RuntimeException $e){$errors[]=$e->getMessage();}}
}
$_SESSION['withdrawal_key']=$_SESSION['withdrawal_key']??bin2hex(random_bytes(32));
$balance=['earned'=>0,'available'=>0,'pending'=>0,'paid'=>0,'awaiting'=>0];$rows=[];$ready=false;
if($pdo){try{$balance=physicianWithdrawalBalance($pdo,(int)$user['UserID']);$stmt=$pdo->prepare('SELECT * FROM PhysicianWithdrawals WHERE PhysicianID=? ORDER BY WithdrawalID DESC LIMIT 50');$stmt->execute([$user['UserID']]);$rows=$stmt->fetchAll();$ready=true;}catch(PDOException $e){error_log('Physician withdrawals read failed: '.$e->getMessage());$errors[]='Withdrawal information is temporarily unavailable.';}}
$isWithdrawalPage=true;$pageTitle='Earnings & Withdrawals - HealthQueue';require __DIR__.'/../includes/header.php';
?>
<main class="admin-shell pw-page"><div class="container pw-container">
<p class="pw-breadcrumb">Physician <span>/</span> Earnings</p>
<header class="pw-heading"><h1>Earnings &amp; Withdrawals</h1><p>Track your earnings and request a demo payout.</p></header>
<?php if($flash): ?><p class="form-message success" role="status"><?= htmlspecialchars($flash) ?></p><?php endif; ?>
<?php if($errors): ?><div class="form-message error" role="alert"><?php foreach($errors as $error): ?><p><?= htmlspecialchars($error) ?></p><?php endforeach; ?></div><?php endif; ?>
<div class="pw-demo">DEMO MODE &mdash; No real money transferred.</div>
<section class="pw-metrics" aria-label="Demo physician balances">
<?php foreach(['earned'=>'Total demo earnings','available'=>'Available to withdraw','pending'=>'Pending withdrawal','paid'=>'Paid out - simulated'] as $key=>$label): ?><article class="pw-metric pw-metric-<?= $key ?>"><span class="pw-metric-icon" aria-hidden="true"><?= ['earned'=>'&#8369;','available'=>'&#8599;','pending'=>'&#9719;','paid'=>'&#10003;'][$key] ?></span><div><h2><?= $label ?></h2><strong>PHP <?= number_format($balance[$key]/100,2) ?></strong></div></article><?php endforeach; ?>
</section>
<section class="pw-panel"><header class="pw-panel-heading"><h2>Withdrawal history</h2><button class="btn btn-primary" type="button" data-pw-open="withdrawalModal" <?= !$ready||$balance['available']<=0?'disabled':'' ?>>Withdraw earnings</button></header>
<div class="pw-table-wrap"><table class="pw-table"><thead><tr><th>Withdrawal ID</th><th>Date requested</th><th>Payout method</th><th>Amount</th><th>Status</th></tr></thead><tbody>
<?php foreach($rows as $row): ?><tr><td><strong><?= htmlspecialchars($row['Reference']) ?></strong></td><td><?= htmlspecialchars(date('j M Y',strtotime($row['CreatedAt']))) ?></td><td><?= htmlspecialchars(withdrawalMasked($row)) ?></td><td>PHP <?= number_format((float)$row['Amount'],2) ?></td><td><span class="pw-status pw-status-<?= htmlspecialchars($row['Status']) ?>"><?= withdrawalStatus($row['Status']) ?></span><?php if($row['ReviewedAt']): ?><small class="pw-reviewed"><?= htmlspecialchars(date('j M Y',strtotime($row['ReviewedAt']))) ?></small><?php endif; ?></td></tr><?php endforeach; ?>
<?php if(!$rows): ?><tr><td colspan="5" class="pw-empty">No withdrawal requests yet.</td></tr><?php endif; ?></tbody></table></div>
</section>
<p class="pw-info">Physician share: 90% of eligible completed demo wallet payments. Withdrawals require settled demo funds. Awaiting settlement: <strong>PHP <?= number_format($balance['awaiting']/100,2) ?></strong>.</p>
</div></main>
<dialog class="pw-dialog" id="withdrawalModal" aria-labelledby="withdrawalTitle" data-pw-auto="<?= $errors&&$_SERVER['REQUEST_METHOD']==='POST'?'1':'0' ?>">
<button type="button" class="pw-close" data-pw-close aria-label="Close withdrawal form">&times;</button><h2 id="withdrawalTitle">Withdraw earnings</h2>
<p class="pw-dialog-subtitle">Available balance: <strong>PHP <?= number_format($balance['available']/100,2) ?></strong></p>
<form method="post" class="pw-form"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><input type="hidden" name="request_key" value="<?= htmlspecialchars($_SESSION['withdrawal_key']) ?>">
<?php if($errors): ?><div class="form-message error" role="alert"><?php foreach($errors as $error): ?><p><?= htmlspecialchars($error) ?></p><?php endforeach; ?></div><?php endif; ?>
<label>Amount<input type="number" name="amount" min="0.01" max="<?= number_format($balance['available']/100,2,'.','') ?>" step="0.01" value="<?= htmlspecialchars($values['amount']) ?>" placeholder="0.00" required></label>
<label>Payout method<select name="payout_method" id="withdrawalMethod"><?php foreach(['GCash'=>'GCash','Maya'=>'Maya','Bank'=>'Bank account'] as $key=>$label): ?><option value="<?= $key ?>" <?= $values['payout_method']===$key?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></label>
<label>Account holder<input name="account_name" maxlength="150" value="<?= htmlspecialchars($values['account_name']) ?>" required></label>
<label data-pw-mobile>Mobile number<input type="tel" name="phone" inputmode="numeric" maxlength="11" pattern="09[0-9]{9}" title="Enter a valid mobile number" value="<?= htmlspecialchars($values['phone']) ?>" required></label>
<div data-pw-bank hidden><label>Bank name<input name="bank_name" maxlength="100" value="<?= htmlspecialchars($values['bank_name']) ?>" disabled required></label><label>Account number<input name="bank_account_number" inputmode="numeric" pattern="[0-9]{6,34}" maxlength="34" value="<?= htmlspecialchars($values['bank_account_number']) ?>" disabled required></label></div>
<p class="pw-info">The requested amount is reserved while admin reviews your request. Use demo destination details for your presentation.</p>
<div class="pw-form-actions"><button class="btn btn-outline" type="button" data-pw-close>Cancel</button><button class="btn btn-primary" type="submit">Submit request</button></div>
</form><p class="pw-dialog-note">School demo only. Payouts are simulated.</p></dialog>
<script src="<?= HQ_BASE_URL ?>/assets/js/physician-withdrawals.js?v=<?= filemtime(__DIR__.'/../assets/js/physician-withdrawals.js') ?>" defer></script>
<?php require __DIR__.'/../includes/footer.php'; ?>
