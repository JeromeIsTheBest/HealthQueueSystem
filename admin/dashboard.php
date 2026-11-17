<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';

define('HQ_BASE_URL', '..');
requireRole(['Admin']);

$user = currentUser();
$pdo = getDbConnection();
$errors = [];
$flash = '';

require __DIR__ . '/../includes/admin-inquiry-review.php';

$stats = ['pending' => 0, 'clinics' => 0, 'patients' => 0, 'appointments' => 0, 'physicians' => 0, 'staff' => 0];
$commissionByPeriod = ['week_commission' => 0, 'week_count' => 0, 'month_commission' => 0, 'month_count' => 0, 'year_commission' => 0, 'year_count' => 0];
$inquiries = [];
$recentAppointments = [];
$reviewedInquiries = [];
if ($pdo) {
    try {
        $stats['pending'] = (int) $pdo->query("SELECT COUNT(*) FROM ClinicRegistrationInquiry WHERE Status = 'Pending'")->fetchColumn();
        $stats['clinics'] = (int) $pdo->query("SELECT COUNT(*) FROM Clinic WHERE archived = 0 AND Status = 'Active'")->fetchColumn();
        $stats['patients'] = (int) $pdo->query("SELECT COUNT(*) FROM Users u JOIN Roles r ON r.RoleID = u.RoleID WHERE r.RoleName = 'Patient' AND u.DeletedAt IS NULL")->fetchColumn();
        $stats['appointments'] = (int) $pdo->query("SELECT COUNT(*) FROM Appointments WHERE Status = 'Pending'")->fetchColumn();
        $stats['physicians'] = (int) $pdo->query("SELECT COUNT(*) FROM Users u JOIN Roles r ON r.RoleID = u.RoleID WHERE r.RoleName = 'Physician' AND u.DeletedAt IS NULL")->fetchColumn();
        $stats['staff'] = (int) $pdo->query("SELECT COUNT(*) FROM Users u JOIN Roles r ON r.RoleID = u.RoleID WHERE r.RoleName = 'Staff' AND u.DeletedAt IS NULL")->fetchColumn();
        // Same period bucketing as admin/revenue.php: YEARWEEK(..., 1) is Monday-start so "this week" lines up with a normal calendar week.
        $commissionByPeriod = $pdo->query(
            "SELECT
                COALESCE(SUM(CASE WHEN YEARWEEK(PaidAt, 1) = YEARWEEK(CURDATE(), 1) THEN PlatformCommission ELSE 0 END), 0) AS week_commission,
                COUNT(CASE WHEN YEARWEEK(PaidAt, 1) = YEARWEEK(CURDATE(), 1) THEN 1 END) AS week_count,
                COALESCE(SUM(CASE WHEN YEAR(PaidAt) = YEAR(CURDATE()) AND MONTH(PaidAt) = MONTH(CURDATE()) THEN PlatformCommission ELSE 0 END), 0) AS month_commission,
                COUNT(CASE WHEN YEAR(PaidAt) = YEAR(CURDATE()) AND MONTH(PaidAt) = MONTH(CURDATE()) THEN 1 END) AS month_count,
                COALESCE(SUM(CASE WHEN YEAR(PaidAt) = YEAR(CURDATE()) THEN PlatformCommission ELSE 0 END), 0) AS year_commission,
                COUNT(CASE WHEN YEAR(PaidAt) = YEAR(CURDATE()) THEN 1 END) AS year_count
             FROM AppointmentPayments
             WHERE PaymentStatus = 'Paid'"
        )->fetch();
        $inquiries = $pdo->query("SELECT InquiryID, ClinicName, ContactName, Email, Phone, City, PhysiciansCount, SubmittedAt FROM ClinicRegistrationInquiry WHERE Status = 'Pending' ORDER BY SubmittedAt ASC LIMIT 10")->fetchAll();
        $recentAppointments = $pdo->query("SELECT a.AppointmentDate, a.AppointmentTime, a.Status, c.ClinicName, u.FirstName, u.LastName FROM Appointments a JOIN Clinic c ON c.ClinicID = a.ClinicID JOIN Users u ON u.UserID = a.PatientID ORDER BY a.CreatedAt DESC LIMIT 10")->fetchAll();
        $reviewedInquiries = $pdo->query("SELECT ClinicName, Status, SubmittedAt FROM ClinicRegistrationInquiry WHERE Status <> 'Pending' ORDER BY SubmittedAt DESC LIMIT 5")->fetchAll();
    } catch (PDOException $e) {
        error_log('Admin dashboard read failed: ' . $e->getMessage());
        $errors[] = 'Some dashboard information is temporarily unavailable.';
    }
}
$pageTitle = 'Admin Dashboard — HealthQueue';
$isAdminDashboard = true;
require __DIR__ . '/../includes/header.php';
?>

<?php
$adPaths = [
 'clinic'=>'<path d="M4 21V5h16v16M2 21h20M9 21v-4h6v4M9 9h6M12 6v6M7 14h.01M17 14h.01"/>',
 'users'=>'<circle cx="9" cy="8" r="3"/><path d="M3 21v-3a6 6 0 0 1 12 0v3M16 5a3 3 0 0 1 0 6M21 21v-3a6 6 0 0 0-4-5"/>',
 'physician'=>'<path d="M6 3v6a5 5 0 0 0 10 0V3M4 3h4M14 3h4M11 14v2a4 4 0 0 0 8 0"/><circle cx="19" cy="13" r="2"/>',
 'inquiry'=>'<path d="M21 11a8 8 0 0 1-8 8H6l-4 3V11a9 9 0 0 1 19 0Z"/><path d="M8 10h8M8 14h5"/>',
 'calendar'=>'<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 2v6M17 2v6M3 11h18"/>',
 'activity'=>'<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 7h6M9 12h6M9 17h4"/>',
 'patient'=>'<circle cx="12" cy="7" r="4"/><path d="M4 22v-3a8 8 0 0 1 16 0v3"/>'
];
$adIcon = static fn(string $key): string => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.$adPaths[$key].'</svg>';
?>
<main class="ad-dashboard"><div class="container ad-container">
  <p class="ad-breadcrumb">Overview <span aria-hidden="true">/</span> <strong>Dashboard</strong></p>
  <header class="ad-heading">
    <div><h1>Welcome back, <?= htmlspecialchars($user['FirstName']) ?>.</h1><p>Manage your clinics, team, and registrations in one place.</p></div>
    <span class="ad-date"><?= $adIcon('calendar') ?><?= htmlspecialchars(date('j F Y')) ?></span>
  </header>
  <?php if ($flash): ?><p class="form-message success" role="status"><?= htmlspecialchars($flash) ?></p><?php endif; ?>
  <?php if ($errors): ?><div class="form-message error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <section class="ad-metrics" aria-label="Platform overview">
    <?php foreach ([['clinics','Active clinics','clinic','clinics.php'],['patients','Registered patients','users','patients.php'],['physicians','Physicians','physician','physicians.php'],['pending','Pending inquiries','inquiry','#registration-inquiries']] as [$key,$label,$icon,$url]): ?>
      <a class="ad-metric" href="<?= htmlspecialchars($url) ?>"><span class="ad-icon"><?= $adIcon($icon) ?></span><div><h2><?= $label ?></h2><div class="ad-metric-value"><strong><?= (int) $stats[$key] ?></strong><?php if ($key==='pending' && $stats[$key]>0): ?><span class="ad-badge ad-badge-pending">Needs review</span><?php endif; ?></div></div></a>
    <?php endforeach; ?>
  </section>
  <div class="ad-columns">
    <div class="ad-main-column">
      <section class="ad-panel" id="registration-inquiries">
        <header class="ad-panel-heading"><div><h2>Clinic registration inquiries <span class="ad-count"><?= (int) $stats['pending'] ?></span></h2><p>Review new clinics joining HealthQueue.</p></div></header>
        <?php if ($inquiries): ?><div class="ad-inquiries">
          <?php foreach ($inquiries as $inquiry): ?>
            <article class="ad-inquiry"><span class="ad-icon"><?= $adIcon('clinic') ?></span><div class="ad-inquiry-info"><h3><?= htmlspecialchars($inquiry['ClinicName']) ?></h3><p><?= htmlspecialchars($inquiry['City']) ?> &middot; <?= $inquiry['PhysiciansCount'] ? (int)$inquiry['PhysiciansCount'].' physicians' : 'Physician count not specified' ?></p><details class="ad-contact"><summary>Contact details</summary><p><?= htmlspecialchars($inquiry['ContactName']) ?><br><?= htmlspecialchars($inquiry['Email']) ?><br><?= htmlspecialchars($inquiry['Phone']) ?></p></details></div><form method="post" class="ad-inquiry-actions"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><input type="hidden" name="form_type" value="review_inquiry"><input type="hidden" name="inquiry_id" value="<?= (int)$inquiry['InquiryID'] ?>"><button class="btn btn-primary" name="decision" value="approve">Approve</button><button class="btn btn-outline" name="decision" value="reject">Decline</button></form></article>
          <?php endforeach; ?>
        </div><?php else: ?><div class="ad-empty"><h3>All caught up</h3><p>No clinic registration inquiries are waiting for review.</p></div><?php endif; ?>
        <a class="ad-text-link" href="clinics.php">Manage clinic directory</a>
      </section>
      <section class="ad-panel" id="appointment-overview">
        <header class="ad-panel-heading"><div><h2>Recent appointment requests</h2><p>Clinic staff manage booking approvals.</p></div></header>
        <?php if ($recentAppointments): ?><div class="ad-table-wrap"><table class="ad-table"><thead><tr><th>Patient</th><th>Clinic</th><th>Visit date</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($recentAppointments as $appointment): ?><tr><td><strong><?= htmlspecialchars($appointment['FirstName'].' '.$appointment['LastName']) ?></strong></td><td><?= htmlspecialchars($appointment['ClinicName']) ?></td><td><?= htmlspecialchars(date('j M Y',strtotime($appointment['AppointmentDate']))) ?><small><?= $appointment['AppointmentTime'] ? htmlspecialchars(date('g:i A',strtotime($appointment['AppointmentTime']))) : 'Queue booking' ?></small></td><td><span class="ad-badge ad-status-<?= htmlspecialchars(strtolower($appointment['Status'])) ?>"><?= htmlspecialchars($appointment['Status']) ?></span></td></tr><?php endforeach; ?>
        </tbody></table></div><?php else: ?><div class="ad-empty"><p>No appointment requests yet.</p></div><?php endif; ?>
      </section>
    </div>
    <aside class="ad-side-column">
      <section class="ad-panel ad-commission"><header class="ad-panel-heading"><h2>Platform commission</h2><span class="ad-count">10% share</span></header>
        <?php foreach (['week'=>'This week','month'=>'This month','year'=>'This year'] as $period=>$label): ?><div class="ad-revenue-row"><div><h3><?= $label ?></h3><p><?= (int)$commissionByPeriod[$period.'_count'] ?> paid bookings</p></div><strong>PHP <?= number_format((float)$commissionByPeriod[$period.'_commission'],2) ?></strong></div><?php endforeach; ?>
        <a href="revenue.php" class="ad-text-link">View revenue</a>
      </section>
      <section class="ad-panel"><header class="ad-panel-heading"><h2>Quick access</h2></header><div class="ad-shortcuts">
        <?php foreach ([['clinics.php','clinic','Manage clinics'],['physicians.php','users','Manage team'],['patients.php','patient','View patients'],['activity-log.php','activity','Activity log']] as [$url,$icon,$label]): ?><a href="<?= $url ?>"><?= $adIcon($icon) ?><span><?= $label ?></span></a><?php endforeach; ?>
      </div><div class="ad-extra-links"><a href="staff.php">Front-desk staff <span><?= (int)$stats['staff'] ?></span></a><a href="announcements.php">Post announcement</a></div></section>
    </aside>
  </div>
  <section class="ad-panel ad-reviewed"><header class="ad-panel-heading"><h2>Recently reviewed clinics</h2></header>
    <?php if ($reviewedInquiries): ?><div class="ad-table-wrap"><table class="ad-table"><thead><tr><th>Clinic</th><th>Review result</th><th>Submitted on</th></tr></thead><tbody><?php foreach ($reviewedInquiries as $inquiry): ?><tr><td><span class="ad-reviewed-name"><?= $adIcon('clinic') ?><strong><?= htmlspecialchars($inquiry['ClinicName']) ?></strong></span></td><td><span class="ad-badge ad-status-<?= htmlspecialchars(strtolower($inquiry['Status'])) ?>"><?= htmlspecialchars($inquiry['Status']) ?></span></td><td><?= htmlspecialchars(date('j M Y',strtotime($inquiry['SubmittedAt']))) ?></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><div class="ad-empty"><p>No reviewed registrations yet.</p></div><?php endif; ?>
  </section>
</div></main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
