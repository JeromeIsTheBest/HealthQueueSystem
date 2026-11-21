<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';
define('HQ_BASE_URL', '..');
requireRole(['Admin']);
$user=currentUser();$pdo=getDbConnection();$errors=[];$flash='';
require __DIR__ . '/../includes/admin-inquiry-review.php';
$status=in_array($_GET['status']??'Pending',['Pending','Approved','Rejected','All'],true)?($_GET['status']??'Pending'):'Pending';
$search=trim((string)($_GET['q']??''));$page=max(1,(int)($_GET['page']??1));$perPage=10;$total=0;$requests=[];
if($pdo){try{
 $where=[];$params=[];
 if($status!=='All'){$where[]='Status = ?';$params[]=$status;}
 if($search!==''){$where[]='(ClinicName LIKE ? OR City LIKE ? OR ContactName LIKE ?)';array_push($params,'%'.$search.'%','%'.$search.'%','%'.$search.'%');}
 $condition=$where?' WHERE '.implode(' AND ',$where):'';
 $stmt=$pdo->prepare('SELECT COUNT(*) FROM ClinicRegistrationInquiry'.$condition);$stmt->execute($params);$total=(int)$stmt->fetchColumn();
 $pages=max(1,(int)ceil($total/$perPage));$page=min($page,$pages);$offset=($page-1)*$perPage;
 $stmt=$pdo->prepare('SELECT * FROM ClinicRegistrationInquiry'.$condition.' ORDER BY SubmittedAt DESC, InquiryID DESC LIMIT '.$perPage.' OFFSET '.$offset);$stmt->execute($params);$requests=$stmt->fetchAll();
}catch(PDOException $e){error_log('Clinic requests read failed: '.$e->getMessage());$errors[]='Clinic requests could not be loaded.';}}
$isAdminDirectory=true;$pageTitle='Clinic requests ? HealthQueue';
require __DIR__ . '/../includes/header.php';
?>
<main class="admin-shell dr-page"><div class="container dr-container">
<p class="dr-breadcrumb">Administration <span>/</span> Clinic requests</p>
<header class="dr-heading"><div><h1>Clinic requests</h1><p>Review registration inquiries and see previous decisions.</p></div><a class="btn btn-outline" href="clinics.php">Back to clinics</a></header>
<?php if($flash): ?><p class="form-message success" role="status"><?= htmlspecialchars($flash) ?></p><?php endif; ?>
<?php if($errors): ?><div class="form-message error" role="alert"><?php foreach($errors as $error): ?><p><?= htmlspecialchars($error) ?></p><?php endforeach; ?></div><?php endif; ?>
<section class="dr-panel">
<form method="get" class="dr-filters">
<label class="dr-search"><span class="sr-only">Search clinic requests</span><input type="search" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search clinic, location, or contact person"></label>
<label class="dr-filter-field"><span>Review status</span><select name="status"><?php foreach(['Pending'=>'Pending','Approved'=>'Approved','Rejected'=>'Declined','All'=>'All requests'] as $value=>$label): ?><option value="<?= $value ?>"<?= $status===$value?' selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></label>
<button class="btn btn-primary" type="submit">Apply filters</button>
</form>
<div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Clinic</th><th>Contact details</th><th>Staff</th><th>Submitted</th><th>Status</th><th>Actions</th></tr></thead><tbody>
<?php foreach($requests as $request): ?>
<tr><td><strong><?= htmlspecialchars($request['ClinicName']) ?></strong><br><?= htmlspecialchars($request['City']) ?></td>
<td><?= htmlspecialchars($request['ContactName']) ?><br><?= htmlspecialchars($request['Email']) ?><br><?= htmlspecialchars($request['Phone']) ?></td>
<td><?= $request['StaffCount']?(int)$request['StaffCount']:'Not specified' ?></td>
<td><?= htmlspecialchars(date('j M Y',strtotime($request['SubmittedAt']))) ?></td>
<td><span class="status-badge status-<?= $request['Status']==='Approved'?'active':'inactive' ?>"><?= $request['Status']==='Rejected'?'Declined':htmlspecialchars($request['Status']) ?></span></td>
<td><div class="dr-actions"><button type="button" class="btn btn-outline" data-directory-open="clinicRequest<?= (int)$request['InquiryID'] ?>">View Request</button></div></td></tr>
<?php endforeach; ?>
<?php if(!$requests): ?><tr><td colspan="6" class="admin-empty">No clinic requests match these filters.</td></tr><?php endif; ?>
</tbody></table></div>
<footer class="dr-list-footer"><span><?= $total ?> request(s)</span><nav aria-label="Request pages">
<?php for($n=max(1,$page-2);$n<=min($pages??1,$page+2);$n++): ?><a href="?<?= htmlspecialchars(http_build_query(['status'=>$status,'q'=>$search,'page'=>$n])) ?>"<?= $page===$n?' aria-current="page"':'' ?>><?= $n ?></a><?php endfor; ?>
</nav></footer>
</section></div></main>
<?php foreach($requests as $request): ?>
<dialog class="modal-box dr-dialog" id="clinicRequest<?= (int)$request['InquiryID'] ?>" aria-labelledby="clinicRequestTitle<?= (int)$request['InquiryID'] ?>">
<button type="button" class="modal-close" data-directory-close aria-label="Close registration information">&times;</button>
<div class="portal-heading"><h2 id="clinicRequestTitle<?= (int)$request['InquiryID'] ?>">Clinic registration info</h2></div>
<dl class="dr-account-details">
<?php foreach(['ClinicName'=>'Clinic name','ContactName'=>'Contact person','Email'=>'Email address','Phone'=>'Phone number','City'=>'City / location'] as $field=>$label): ?>
<div><dt><?= $label ?></dt><dd><?= htmlspecialchars($request[$field]) ?></dd></div>
<?php endforeach; ?>
<div><dt>Base price of consultation</dt><dd><?= $request['BaseConsultationFee'] !== null ? 'PHP '.number_format((float)$request['BaseConsultationFee'],2) : 'Not specified (older inquiry)' ?></dd></div>
<div><dt>Number of staff</dt><dd><?= $request['StaffCount']?(int)$request['StaffCount']:'Not specified' ?></dd></div>
<div><dt>Submitted</dt><dd><?= htmlspecialchars(date('j M Y, g:i A',strtotime($request['SubmittedAt']))) ?></dd></div>
<div><dt>Status</dt><dd><?= $request['Status']==='Rejected'?'Declined':htmlspecialchars($request['Status']) ?></dd></div>
</dl>
<?php if($request['Status']==='Pending'): ?>
<form method="post" class="dr-request-review"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><input type="hidden" name="form_type" value="review_inquiry"><input type="hidden" name="inquiry_id" value="<?= (int)$request['InquiryID'] ?>"><button class="btn btn-outline" name="decision" value="reject">Decline</button><button class="btn btn-primary" name="decision" value="approve">Approve clinic</button></form>
<?php else: ?><button type="button" class="btn btn-outline" data-directory-close>Close</button><?php endif; ?>
</dialog>
<?php endforeach; ?>
<script src="<?= HQ_BASE_URL ?>/assets/js/admin-directory.js?v=<?= filemtime(__DIR__ . '/../assets/js/admin-directory.js') ?>" defer></script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
