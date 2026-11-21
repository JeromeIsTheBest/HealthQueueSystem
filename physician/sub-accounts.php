<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/physician-accounts.php';
require_once __DIR__.'/../includes/physician-withdrawals.php';
require_once __DIR__.'/../includes/audit.php';
define('HQ_BASE_URL','..');requireRole(['Physician']);
$user=currentUser();$pdo=getDbConnection();
try{if(!$pdo)throw new RuntimeException('Database unavailable.');$account=requireMainPhysician($pdo,(int)$user['UserID']);}
catch(RuntimeException $e){http_response_code(403);exit('Only the Main Physician can manage sub-accounts.');}
$errors=[];$flash=(string)($_SESSION['sub_account_success']??'');unset($_SESSION['sub_account_success']);
$values=['first_name'=>'','last_name'=>'','email'=>'','contact_number'=>'','specialty'=>''];$editId=(int)($_GET['edit']??0);
if($_SERVER['REQUEST_METHOD']==='POST'){
 if(!verifyCsrfToken($_POST['csrf_token']??null))$errors[]='Your session expired. Please try again.';
 else try{
  if(($_POST['form_type']??'')==='save_sub'){$id=savePhysicianSubAccount($pdo,(int)$user['UserID'],$_POST);$message='Sub-account saved.';}
  elseif(($_POST['form_type']??'')==='status_sub'){$id=(int)($_POST['member_id']??0);setPhysicianSubAccountStatus($pdo,(int)$user['UserID'],$id,(string)($_POST['status']??''));$message='Sub-account status updated.';}
  else throw new RuntimeException('Invalid action.');
  logActivity($pdo,(int)$user['UserID'],(int)$account['ClinicID'],$message,'Physician #'.$id);
  $_SESSION['sub_account_success']=$message;header('Location: sub-accounts.php',true,303);exit;
 }catch(PDOException $e){error_log('Sub-account save failed: '.$e->getMessage());$errors[]=hqDuplicateMobileError($e)?'This mobile number is already registered to another account.':($e->getCode()==='23000'?'That email address is already in use.':'We could not save this sub-account.');}
 catch(RuntimeException $e){$errors[]=$e->getMessage();}
 if(($_POST['form_type']??'')==='save_sub'){foreach($values as $key=>$v)$values[$key]=trim((string)($_POST[$key]??''));$editId=(int)($_POST['member_id']??0);}
}
$s=$pdo->prepare("SELECT u.UserID,u.FirstName,u.LastName,u.Email,u.ContactNumber,u.PhysicianSpecialty,u.Status,u.CreatedAt,(SELECT COUNT(*) FROM Appointments a WHERE a.PhysicianID=u.UserID AND a.Status IN ('Pending','Confirmed')) AS AssignedCount FROM Users u JOIN Roles r ON r.RoleID=u.RoleID WHERE r.RoleName='Physician' AND u.ClinicID=? AND u.UserID<>? AND u.DeletedAt IS NULL ORDER BY u.LastName,u.UserID");$s->execute([$account['ClinicID'],$user['UserID']]);$members=$s->fetchAll();
$editing=null;foreach($members as $member)if((int)$member['UserID']===$editId)$editing=$member;
if($editId&&!$editing){http_response_code(404);exit('Sub-account not found.');}
if($editing&&$_SERVER['REQUEST_METHOD']!=='POST')$values=['first_name'=>$editing['FirstName'],'last_name'=>$editing['LastName'],'email'=>$editing['Email'],'contact_number'=>$editing['ContactNumber'],'specialty'=>$editing['PhysicianSpecialty']??''];
$balance=physicianWithdrawalBalance($pdo,(int)$user['UserID']);$isAdminDirectory=true;$pageTitle='Manage Sub Accounts - HealthQueue';require __DIR__.'/../includes/header.php';
?>
<main class="portal-shell dr-page"><div class="container dr-container">
<header class="dr-heading"><div><h1>Manage Sub Accounts</h1><p>Create and manage physicians in your clinic.</p></div><a class="btn btn-primary" href="sub-accounts.php?new=1">+ Add physician</a></header>
<section class="sa-owner"><div><strong>Dr. <?= htmlspecialchars($user['FirstName'].' '.$user['LastName']) ?></strong> <span class="status-badge status-confirmed">Main Account (Owner)</span><p><?= htmlspecialchars($account['ClinicName']) ?></p></div></section>
<?php if($flash): ?><p class="form-message success" role="status"><?= htmlspecialchars($flash) ?></p><?php endif; ?>
<?php if($errors): ?><div class="form-message error" role="alert"><?php foreach($errors as $error): ?><p><?= htmlspecialchars($error) ?></p><?php endforeach; ?></div><?php endif; ?>
<section class="dr-stats sa-stats"><article><div><h2>Sub accounts</h2><strong><?= count($members) ?></strong></div></article><article><div><h2>Active</h2><strong><?= count(array_filter($members,fn($m)=>$m['Status']==='Active')) ?></strong></div></article><article><div><h2>Financial access</h2><strong style="font-size:20px">Main account only</strong></div></article></section>
<section class="admin-section dr-panel"><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Physician</th><th>Account type</th><th>Assigned consultations</th><th>Status</th><th>Actions</th></tr></thead><tbody>
<?php foreach($members as $member): ?><tr><td><strong>Dr. <?= htmlspecialchars($member['FirstName'].' '.$member['LastName']) ?></strong><br><small><?= htmlspecialchars($member['PhysicianSpecialty']?:$account['ClinicName']) ?></small></td><td>Sub Account</td><td><?= (int)$member['AssignedCount'] ?></td><td><span class="status-badge status-<?= strtolower($member['Status']) ?>"><?= htmlspecialchars($member['Status']) ?></span></td><td><div class="dr-actions"><a class="btn btn-outline btn-sm" href="?edit=<?= (int)$member['UserID'] ?>">Edit</a><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><input type="hidden" name="form_type" value="status_sub"><input type="hidden" name="member_id" value="<?= (int)$member['UserID'] ?>"><button class="btn btn-outline btn-sm" name="status" value="<?= $member['Status']==='Active'?'Inactive':'Active' ?>"><?= $member['Status']==='Active'?'Deactivate':'Activate' ?></button></form></div></td></tr><?php endforeach; ?>
<?php if(!$members): ?><tr><td colspan="5" class="admin-empty">No sub-accounts yet. Add a physician to get started.</td></tr><?php endif; ?></tbody></table></div></section>
<p class="sa-notice">Sub accounts cannot view earnings or request withdrawals. Clinical records remain accessible only to the assigned physician.</p>
<section class="sa-owner"><div><h2>Clinic Earnings</h2><p>Available balance: <strong>PHP <?= number_format($balance['available']/100,2) ?></strong> <span class="status-badge">Demo mode</span></p></div><a class="btn btn-primary" href="withdrawals.php">View earnings</a></section>
</div></main>
<dialog class="modal-box dr-dialog" id="subAccountModal" aria-labelledby="subTitle" data-open-on-load="<?= $editing||isset($_GET['new'])||($errors&&($_POST['form_type']??'')==='save_sub')?'1':'0' ?>"><button type="button" class="modal-close" data-directory-close aria-label="Close">&times;</button><h2 id="subTitle"><?= $editing?'Edit sub-account':'Add physician' ?></h2><p><?= htmlspecialchars($account['ClinicName']) ?> · Sub Account</p><form method="post" class="registration-form"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><input type="hidden" name="form_type" value="save_sub"><input type="hidden" name="member_id" value="<?= $editId ?>"><div class="form-stack form-cols-2"><label>First name<input name="first_name" maxlength="50" value="<?= htmlspecialchars($values['first_name']) ?>" required></label><label>Last name<input name="last_name" maxlength="50" value="<?= htmlspecialchars($values['last_name']) ?>" required></label></div><div class="form-stack"><label>Email address<input type="email" name="email" maxlength="100" value="<?= htmlspecialchars($values['email']) ?>" required></label><label>Mobile number<input type="tel" name="contact_number" inputmode="numeric" maxlength="11" pattern="09[0-9]{9}" title="Enter a valid mobile number" value="<?= htmlspecialchars($values['contact_number']) ?>" required></label><label>Specialty <span class="optional">(optional)</span><input name="specialty" maxlength="100" value="<?= htmlspecialchars($values['specialty']) ?>"></label><label><?= $editing?'New password (leave blank to keep current)':'Temporary password' ?><input type="password" name="password" minlength="8" <?= $editing?'':'required' ?> autocomplete="new-password"></label></div><button class="btn btn-primary" type="submit">Save sub-account</button></form></dialog>
<script src="../assets/js/admin-directory.js" defer></script>
<?php require __DIR__.'/../includes/footer.php'; ?>
