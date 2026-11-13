<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/clinic-photos.php';

define('HQ_BASE_URL', '/healthqueue');

$pdo = getDbConnection();
$clinicId = filter_input(INPUT_GET, 'clinic_id', FILTER_VALIDATE_INT);

if (!$clinicId || !$pdo) {
    header('Location: ' . HQ_BASE_URL . '/index.php#clinics');
    exit;
}

$stmt = $pdo->prepare("SELECT ClinicID, ClinicName, Address, ContactNumber, BaseConsultationFee, PhotoUrl, Description FROM Clinic WHERE ClinicID = ? AND archived = 0 AND Status = 'Active'");
$stmt->execute([$clinicId]);
$clinic = $stmt->fetch();

if (!$clinic) {
    header('Location: ' . HQ_BASE_URL . '/index.php#clinics');
    exit;
}

$physicians = [];
$ratingSummary = ['avg_rating' => 0, 'review_count' => 0];
$reviews = [];

try {
    $physStmt = $pdo->prepare(
        "SELECT UserID, FirstName, LastName, AvailabilityStatus, ProfilePhoto FROM Users
         WHERE ClinicID = ? AND RoleID = (SELECT RoleID FROM Roles WHERE RoleName = 'Physician') AND Status = 'Active'
         ORDER BY LastName"
    );
    $physStmt->execute([$clinicId]);
    $physicians = $physStmt->fetchAll();

    $ratingStmt = $pdo->prepare('SELECT COALESCE(AVG(Rating), 0) AS avg_rating, COUNT(*) AS review_count FROM Feedback WHERE ClinicID = ?');
    $ratingStmt->execute([$clinicId]);
    $ratingSummary = $ratingStmt->fetch();

    $reviewStmt = $pdo->prepare(
        "SELECT f.Rating, f.Comment, f.CreatedAt, p.FirstName
         FROM Feedback f
         JOIN Users p ON p.UserID = f.PatientID
         WHERE f.ClinicID = ? AND f.Comment IS NOT NULL AND TRIM(f.Comment) <> ''
         ORDER BY f.CreatedAt DESC
         LIMIT 30"
    );
    $reviewStmt->execute([$clinicId]);
    $reviews = $reviewStmt->fetchAll();
} catch (PDOException $e) {
    error_log('Public clinic profile load failed: ' . $e->getMessage());
}

$hqStatusDot = ['Available' => '#22c55e', 'On Break' => '#0077b3', 'Unavailable' => '#94a3b8'];
$currentUserData = currentUser();
$isPatient = $currentUserData && $currentUserData['RoleName'] === 'Patient';

$photoName = basename((string) ($clinic['PhotoUrl'] ?? ''));
$hasClinicPhoto = $photoName !== '' && is_file(__DIR__ . '/assets/uploads/clinics/' . $photoName);
$galleryPhotos = clinicPhotos($pdo, $clinic);
$pageTitle = $clinic['ClinicName'] . ' — HealthQueue';
require __DIR__ . '/includes/header.php';
?>

<main class="portal-shell public-clinic-profile"><div class="container">
  <a href="<?= HQ_BASE_URL ?>/clinics.php" class="back-link">&larr; Back to clinics</a>

  <div class="clinic-gallery<?= count($galleryPhotos) === 1 ? ' clinic-gallery-single' : '' ?>" tabindex="0" role="region" aria-label="Clinic photos. Swipe or use the arrow keys to browse.">
    <?php if ($galleryPhotos): ?>
      <?php foreach ($galleryPhotos as $photo): ?>
        <div class="clinic-gallery-main"><img src="<?= HQ_BASE_URL ?>/assets/uploads/clinics/<?= rawurlencode($photo) ?>" alt="<?= htmlspecialchars($clinic['ClinicName']) ?> clinic photo"></div>
      <?php endforeach; ?>
    <?php else: ?>
    <div class="clinic-gallery-main">
      <?php if ($hasClinicPhoto): ?>
        <img src="<?= HQ_BASE_URL ?>/assets/uploads/clinics/<?= rawurlencode($photoName) ?>" alt="<?= htmlspecialchars($clinic['ClinicName']) ?>">
      <?php else: ?>
        <div class="clinic-photo-placeholder"><span aria-hidden="true">&#10010;</span><strong><?= htmlspecialchars($clinic['ClinicName']) ?></strong><small>Clinic photo coming soon</small></div>
      <?php endif; ?>
    </div>
    <div class="clinic-gallery-consultation"><img src="<?= HQ_BASE_URL ?>/assets/img/landing/doctor-consultation.webp" alt="Illustrative consultation"><span>Patient care · illustrative photo</span></div>
    <div class="clinic-gallery-info"><span aria-hidden="true">&#10010;</span><strong>Your next visit starts here</strong><p>Appointments, queue updates, and patient care.</p></div>
    <?php endif; ?>
  </div>

  <section class="portal-hero">
    <div>
      <h1><?= htmlspecialchars($clinic['ClinicName']) ?></h1>
      <p><?= htmlspecialchars($clinic['Address']) ?> &middot; <?= htmlspecialchars($clinic['ContactNumber'] ?: 'No contact number listed') ?></p>
      <?php if ((int) $ratingSummary['review_count'] > 0): ?>
        <p style="color:#fbbf24;font-weight:700;margin-top:6px;">★ <?= number_format((float) $ratingSummary['avg_rating'], 1) ?> <span style="color:rgba(255,255,255,.7);font-weight:400;">(<?= (int) $ratingSummary['review_count'] ?> review<?= (int) $ratingSummary['review_count'] === 1 ? '' : 's' ?>)</span></p>
      <?php else: ?>
        <p style="color:rgba(255,255,255,.7);margin-top:6px;">No reviews yet.</p>
      <?php endif; ?>
    </div>
    <a class="clinic-map-link" href="https://www.google.com/maps/search/?api=1&amp;query=<?= rawurlencode($clinic['ClinicName'] . ' ' . $clinic['Address']) ?>" target="_blank" rel="noopener noreferrer"><span aria-hidden="true">&#128205;</span><strong>Find this clinic</strong><small>Open in Google Maps ↗</small></a>
  </section>

  <?php if (!empty($clinic['Description'])): ?>
    <section class="portal-section">
      <div class="portal-heading"><div><span class="section-kicker">About</span><h2>About this clinic</h2></div></div>
      <p style="margin:0;color:var(--slate-600);font-size:14.5px;white-space:pre-line;"><?= htmlspecialchars($clinic['Description']) ?></p>
    </section>
  <?php endif; ?>

  <section class="portal-section">
    <div class="portal-heading clinic-pricing-heading">
      <div><span class="section-kicker">Pricing</span><h2>Base consultation fee</h2></div>
      <div class="clinic-book-action">
        <?php if ($isPatient): ?>
          <button type="button" class="btn btn-primary" data-book-clinic="<?= (int) $clinic['ClinicID'] ?>">Book Now</button>
        <?php elseif (!$currentUserData): ?>
          <a href="<?= HQ_BASE_URL ?>/auth/register.php" class="btn btn-primary">Sign up to book</a>
        <?php endif; ?>
      </div>
    </div>
    <p class="admin-empty" style="text-align:left;">PHP <?= number_format((float) $clinic['BaseConsultationFee'], 2) ?> — the exact fee is confirmed at checkout after your request is submitted.</p>
  </section>

  <section class="portal-section clinic-team-section">
    <div class="portal-heading"><div><span class="section-kicker">Team</span><h2>Physicians</h2></div></div>
    <?php if ($physicians): ?>
      <div class="compact-list clinic-physician-grid">
        <?php foreach ($physicians as $physician): ?>
          <article>
            <?php $avatar = basename((string) ($physician['ProfilePhoto'] ?? '')); ?>
            <span class="clinic-physician-avatar">
              <?php if ($avatar !== '' && is_file(__DIR__ . '/assets/uploads/avatars/' . $avatar)): ?>
                <img src="<?= HQ_BASE_URL ?>/assets/uploads/avatars/<?= rawurlencode($avatar) ?>" alt="">
              <?php else: ?>
                <?= htmlspecialchars(strtoupper(mb_substr($physician['FirstName'], 0, 1) . mb_substr($physician['LastName'], 0, 1))) ?>
              <?php endif; ?>
            </span>
            <div class="clinic-physician-info">
            <strong>Dr. <?= htmlspecialchars($physician['FirstName'] . ' ' . $physician['LastName']) ?></strong>
            <span style="display:inline-flex;align-items:center;gap:8px;">
              <span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:<?= $hqStatusDot[$physician['AvailabilityStatus']] ?? '#94a3b8' ?>;"></span>
              <?= htmlspecialchars($physician['AvailabilityStatus']) ?>
            </span>
            <details><summary>Learn more</summary><p>Dr. <?= htmlspecialchars($physician['LastName']) ?> practices at <?= htmlspecialchars($clinic['ClinicName']) ?>. Published appointment times are shown when booking.</p></details>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p class="admin-empty">No physicians are listed for this clinic yet.</p>
    <?php endif; ?>
  </section>

  <section class="portal-section">
    <div class="portal-heading"><div><span class="section-kicker">Reviews</span><h2>What patients say</h2></div></div>
    <?php if ($reviews): ?>
      <div class="compact-list clinic-review-grid">
        <?php foreach ($reviews as $reviewIndex => $review): ?>
          <article <?= $reviewIndex >= 3 ? 'class="clinic-extra-review" hidden' : '' ?> style="align-items:flex-start;flex-direction:column;gap:6px;">
            <strong style="color:#f59e0b;"><?= str_repeat('★', (int) $review['Rating']) . str_repeat('☆', 5 - (int) $review['Rating']) ?></strong>
            <p style="margin:0;color:var(--slate-600);font-size:14px;white-space:pre-line;"><?= htmlspecialchars($review['Comment']) ?></p>
            <span style="color:var(--slate-400);font-size:12px;"><?= htmlspecialchars($review['FirstName']) ?> &middot; <?= htmlspecialchars(date('M j, Y', strtotime($review['CreatedAt']))) ?></span>
          </article>
        <?php endforeach; ?>
      </div>
      <?php if (count($reviews) > 3): ?>
        <button type="button" class="btn btn-outline clinic-reviews-toggle" aria-expanded="false">View all <?= count($reviews) ?> written reviews</button>
      <?php endif; ?>
    <?php else: ?>
      <p class="admin-empty">No written reviews yet.</p>
    <?php endif; ?>
  </section>
</div></main>
<script src="<?= HQ_BASE_URL ?>/assets/js/clinic-gallery.js?v=<?= filemtime(__DIR__ . '/assets/js/clinic-gallery.js') ?>" defer></script>
<script>
document.querySelector('.clinic-reviews-toggle')?.addEventListener('click', function () {
  const expanded = this.getAttribute('aria-expanded') === 'true';
  document.querySelectorAll('.clinic-extra-review').forEach(review => { review.hidden = expanded; });
  this.setAttribute('aria-expanded', String(!expanded));
  this.textContent = expanded ? 'View all <?= count($reviews) ?> written reviews' : 'Show fewer reviews';
});
</script>

<?php if ($isPatient): ?>
<?php // Shared booking modal: only times inside physicians' published hours are offered. ?>
<?php require __DIR__ . '/includes/booking-modal.php'; ?>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
