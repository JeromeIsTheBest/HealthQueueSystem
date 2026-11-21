<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/availability.php';
require_once __DIR__.'/../includes/input-validation.php';
define('HQ_BASE_URL','..');requireRole(['Patient']);$pdo=getDbConnection();
$id=filter_input(INPUT_GET,'physician',FILTER_VALIDATE_INT)?:0;$clinic=filter_input(INPUT_GET,'clinic',FILTER_VALIDATE_INT)?:0;
if(!$pdo){http_response_code(503);exit('Availability is temporarily unavailable.');}
$s=$pdo->prepare("SELECT u.UserID,u.FirstName,u.LastName,u.PhysicianSpecialty,c.ClinicName FROM Users u JOIN Roles r ON r.RoleID=u.RoleID JOIN Clinic c ON c.ClinicID=u.ClinicID WHERE u.UserID=? AND u.ClinicID=? AND r.RoleName='Physician' AND u.Status='Active' AND u.DeletedAt IS NULL AND c.Status='Active' AND c.archived=0");$s->execute([$id,$clinic]);$physician=$s->fetch();
if(!$physician){http_response_code(404);exit('Physician not available at this clinic.');}
$date=(string)($_GET['date']??date('Y-m-d'));$errors=[];
if(!hqValidCalendarValue($date)||$date<date('Y-m-d')){$date=date('Y-m-d');$errors[]='Choose today or a future date.';}
$times=bookableTimes($pdo,$clinic,$id,$date);$pageTitle='Physician availability - HealthQueue';require __DIR__.'/../includes/header.php';
?>
<main class="portal-shell"><div class="container" style="max-width:950px"><a class="text-link" href="clinic-detail.php?clinic_id=<?= $clinic ?>">&larr; Back to clinic</a><section class="cdp-card" style="margin-top:24px"><span class="section-kicker"><?= htmlspecialchars($physician['ClinicName']) ?></span><h1>Dr. <?= htmlspecialchars($physician['FirstName'].' '.$physician['LastName']) ?></h1><p><?= htmlspecialchars($physician['PhysicianSpecialty']?:'Physician') ?></p><h2>Available appointment times</h2><form method="get" class="form-stack" style="max-width:360px"><input type="hidden" name="clinic" value="<?= $clinic ?>"><input type="hidden" name="physician" value="<?= $id ?>"><label>Choose a date<input type="date" name="date" min="<?= date('Y-m-d') ?>" value="<?= htmlspecialchars($date) ?>" required></label><button class="btn btn-outline">Show availability</button></form>
<?php foreach($errors as $error): ?><p class="form-message error"><?= htmlspecialchars($error) ?></p><?php endforeach; ?>
<div class="physician-time-options" style="display:flex;flex-wrap:wrap;gap:12px;margin:28px 0"><?php foreach($times as $time): ?><a class="btn btn-outline" href="book-appointment.php?<?= htmlspecialchars(http_build_query(['clinic'=>$clinic,'physician'=>$id,'date'=>$date,'time'=>$time])) ?>"><?= htmlspecialchars(date('g:i A',strtotime($time))) ?></a><?php endforeach; ?><?php if(!$times): ?><p>No bookable times on this date. Try another date or choose a queue booking.</p><?php endif; ?></div><p class="cdp-muted">Times may change as other patients book. Your request will be checked again before it is saved.</p><a class="btn btn-primary" href="book-appointment.php?clinic=<?= $clinic ?>&amp;physician=<?= $id ?>&amp;date=<?= htmlspecialchars($date) ?>">Request a queue booking</a></section></div></main>
<?php require __DIR__.'/../includes/footer.php'; ?>
