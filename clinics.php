<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/clinic-photos.php';

define('HQ_BASE_URL', '/healthqueue');

$pdo = getDbConnection();
$search = trim((string) ($_GET['q'] ?? ''));
$clinics = [];
$service = trim((string) ($_GET['service'] ?? ''));
$fee = (string) ($_GET['fee'] ?? '');
$services = [];
$totalClinics = 0;
$totalLocations = 0;
$dataError = null;

if ($pdo) {
    try {
        $directory = $pdo->query("SELECT Address, Specialties FROM Clinic WHERE archived = 0 AND Status = 'Active'")->fetchAll();
        $totalClinics = count($directory);
        $totalLocations = count(array_unique(array_column($directory, 'Address')));
        foreach ($directory as $entry) {
            foreach (explode(',', (string) $entry['Specialties']) as $specialty) {
                $specialty = trim($specialty);
                if ($specialty !== '') $services[$specialty] = $specialty;
            }
        }
        natcasesort($services);
        $sql = "SELECT ClinicID, ClinicName, Address, BaseConsultationFee, PhotoUrl FROM Clinic WHERE archived = 0 AND Status = 'Active'";
        $params = [];
        if ($search !== '') {
            $sql .= ' AND (ClinicName LIKE ? OR Address LIKE ?)';
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }
        if ($service !== '' && isset($services[$service])) {
            $sql .= " AND FIND_IN_SET(?, REPLACE(Specialties, ', ', ',')) > 0";
            $params[] = $service;
        }
        if (in_array($fee, ['300', '500', '1000'], true)) {
            $sql .= ' AND BaseConsultationFee <= ?';
            $params[] = (int) $fee;
        }
        $sql .= ' ORDER BY ClinicName';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $clinics = $stmt->fetchAll();

        foreach ($clinics as &$clinic) {
            $ratingStmt = $pdo->prepare('SELECT COALESCE(AVG(Rating), 0) AS avg_rating, COUNT(*) AS review_count FROM Feedback WHERE ClinicID = ?');
            $ratingStmt->execute([$clinic['ClinicID']]);
            $rating = $ratingStmt->fetch();
            $clinic['AvgRating'] = (float) $rating['avg_rating'];
            $clinic['ReviewCount'] = (int) $rating['review_count'];
        }
        unset($clinic);
    } catch (PDOException $e) {
        error_log('Public clinics browse failed: ' . $e->getMessage());
        $dataError = 'Clinics are temporarily unavailable.';
    }
} else {
    $dataError = 'Clinics are temporarily unavailable.';
}

$pageTitle = 'All Clinics — HealthQueue';
require __DIR__ . '/includes/header.php';
?>

<main class="portal-shell public-clinic-directory"><div class="container">
  <a href="<?= HQ_BASE_URL ?>/index.php#clinics" class="back-link">&larr; Back to home</a>

  <div class="directory-stats" aria-label="Clinic directory totals">
    <span><span aria-hidden="true">&#10010;</span><span class="directory-stat-label">Total clinics</span><strong><?= $totalClinics ?></strong></span>
    <span><span class="directory-stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 11-8 11S4 16 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/></svg></span><span class="directory-stat-label">Clinic locations</span><strong><?= $totalLocations ?></strong></span>
    <span><span class="directory-stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="7" width="18" height="14" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M12 11v6M9 14h6"/></svg></span><span class="directory-stat-label">Services offered</span><strong><?= count($services) ?></strong></span>
  </div>
  <section class="directory-banner">
    <div class="portal-hero"><div><h1>All Clinics</h1><p>Explore every partner clinic currently active on HealthQueue.</p></div></div>

  </section>

  <?php if ($dataError): ?><p class="form-message error" role="alert"><?= htmlspecialchars($dataError) ?></p><?php endif; ?>

  <section class="portal-section">
    <form method="get" class="directory-filters">
      <label class="directory-search">Search clinics<input type="search" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Clinic name or address..."></label>
      <label>Service type<select name="service"><option value="">All services</option><?php foreach ($services as $option): ?><option value="<?= htmlspecialchars($option) ?>"<?= $service === $option ? ' selected' : '' ?>><?= htmlspecialchars($option) ?></option><?php endforeach; ?></select></label>
      <label>Consultation fee<select name="fee"><option value="">Any price</option><?php foreach ([300,500,1000] as $limit): ?><option value="<?= $limit ?>"<?= $fee === (string) $limit ? ' selected' : '' ?>>Up to PHP <?= number_format($limit) ?></option><?php endforeach; ?></select></label>
      <div class="directory-filter-actions">
        <button type="submit" class="btn btn-primary">Search</button>
        <?php if ($search !== '' || $service !== '' || $fee !== ''): ?><a href="<?= HQ_BASE_URL ?>/clinics.php" class="text-link">Clear</a><?php endif; ?>
      </div>
    </form>
    <p class="directory-results" aria-live="polite"><?= count($clinics) ?> clinic<?= count($clinics) === 1 ? '' : 's' ?> found</p>

    <?php if ($clinics): ?>
      <div class="portal-clinics">
        <?php foreach ($clinics as $clinic): ?>
          <article class="portal-clinic-card">
            <?php $photo = clinicCoverPhoto($pdo, $clinic) ?? ''; ?>
            <?php if ($photo !== '' && is_file(__DIR__ . '/assets/uploads/clinics/' . $photo)): ?>
              <img src="<?= HQ_BASE_URL ?>/assets/uploads/clinics/<?= rawurlencode($photo) ?>" alt="" class="portal-clinic-photo">
            <?php else: ?>
              <div class="portal-clinic-mark">+</div>
            <?php endif; ?>
            <h3><?= htmlspecialchars($clinic['ClinicName']) ?></h3>
            <p><?= htmlspecialchars($clinic['Address']) ?></p>
            <?php if ($clinic['ReviewCount'] > 0): ?>
              <p style="color:#f59e0b;font-weight:700;margin:2px 0 8px;font-size:13px;">★ <?= number_format($clinic['AvgRating'], 1) ?> <span style="color:var(--slate-400);font-weight:400;">(<?= $clinic['ReviewCount'] ?>)</span></p>
            <?php endif; ?>
            <div>
              <span>From PHP <?= number_format((float) $clinic['BaseConsultationFee'], 2) ?></span>
              <a href="<?= HQ_BASE_URL ?>/clinic-profile.php?clinic_id=<?= (int) $clinic['ClinicID'] ?>">View details</a>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p class="admin-empty">No clinics match your search.</p>
    <?php endif; ?>
  </section>
</div></main>

<?php require __DIR__ . '/includes/footer.php'; ?>
