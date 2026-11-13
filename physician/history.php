<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

define('HQ_BASE_URL', '..');
requireRole(['Physician']);

$user = currentUser();
$pdo = getDbConnection();
$consultations = [];
$dataError = null;

$search = mb_substr(trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : ''), 0, 100);
$range = in_array($_GET['range'] ?? '', ['week', 'month'], true) ? $_GET['range'] : 'all';
$today = new DateTimeImmutable('today');

if ($pdo) {
    try {
        $sql = "SELECT a.AppointmentID, a.AppointmentDate, a.AppointmentTime, c.ClinicName,
                       pat.FirstName, pat.LastName, cons.CompletedAt
                FROM Appointments a
                JOIN Clinic c ON c.ClinicID = a.ClinicID
                JOIN Users pat ON pat.UserID = a.PatientID
                LEFT JOIN Consultations cons ON cons.AppointmentID = a.AppointmentID
                WHERE a.PhysicianID = ? AND a.Status = 'Completed'";
        $params = [$user['UserID']];
        if ($search !== '') {
            $like = '%' . str_replace(['=', '%', '_'], ['==', '=%', '=_'], $search) . '%';
            $sql .= " AND (CONCAT(pat.FirstName, ' ', pat.LastName) LIKE ? ESCAPE '=' OR c.ClinicName LIKE ? ESCAPE '=')";
            $params[] = $like; $params[] = $like;
        }
        if ($range !== 'all') {
            $start = $range === 'week' ? $today->modify('monday this week') : $today->modify('first day of this month');
            $sql .= ' AND a.AppointmentDate BETWEEN ? AND ?';
            $params[] = $start->format('Y-m-d'); $params[] = $today->format('Y-m-d');
        }
        $sql .= ' ORDER BY a.AppointmentDate DESC, a.AppointmentTime DESC, a.AppointmentID DESC LIMIT 50';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $consultations = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Consultation history load failed: ' . $e->getMessage());
        $dataError = 'Your consultation history is temporarily unavailable.';
    }
} else {
    $dataError = 'Your consultation history is temporarily unavailable.';
}

$historyGroups = [];
foreach ($consultations as $item) $historyGroups[$item['AppointmentDate']][] = $item;
$isPhysicianHistory = true;
$pageTitle = 'Consultation History — HealthQueue';
require __DIR__ . '/../includes/header.php';
?>

<?php
$historyIcon = static function (string $name): string {
    $paths = [
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'notes' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M8 13h8M8 17h6"/>',
        'microphone' => '<rect x="9" y="2" width="6" height="12" rx="3"/><path d="M5 10v1a7 7 0 0 0 14 0v-1M12 18v4M9 22h6"/>',
        'check' => '<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>',
        'search' => '<circle cx="10.5" cy="10.5" r="7"/><path d="m16 16 5 5"/>',
        'reset' => '<path d="M3 11a9 9 0 1 1 2 7M3 4v7h7"/>',
    ];
    return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
};
?>
<main class="portal-shell physician-history">
  <div class="container ph-history-container">
    <header class="ph-history-heading">
      <div><span class="ph-history-eyebrow">Records</span><h1>Consultation history</h1></div>
      <time class="ph-history-today" datetime="<?= $today->format('Y-m-d') ?>"><?= $historyIcon('calendar') ?><?= $today->format('j F Y') ?></time>
    </header>

    <form method="get" class="ph-history-toolbar" role="search" aria-label="Filter consultation history">
      <input type="hidden" name="range" value="<?= htmlspecialchars($range) ?>">
      <label class="ph-history-search"><span class="ph-history-sr">Search patient or clinic</span><?= $historyIcon('search') ?><input type="search" name="q" value="<?= htmlspecialchars($search) ?>" maxlength="100" placeholder="Search patient or clinic"><button type="submit" class="ph-history-search-submit" aria-label="Search history"><?= $historyIcon('search') ?></button></label>
      <fieldset class="ph-history-periods"><legend class="ph-history-sr">Visit date range</legend>
        <?php foreach (['all' => 'All visits', 'week' => 'This week', 'month' => 'This month'] as $key => $label): ?>
          <button type="submit" name="range" value="<?= $key ?>" class="<?= $range === $key ? 'is-active' : '' ?>" aria-pressed="<?= $range === $key ? 'true' : 'false' ?>"><?= $label ?></button>
        <?php endforeach; ?>
      </fieldset>
      <a class="ph-history-reset" href="<?= HQ_BASE_URL ?>/physician/history.php"><?= $historyIcon('reset') ?>Reset</a>
    </form>

    <?php if ($dataError): ?><p class="form-message error" role="alert"><?= htmlspecialchars($dataError) ?></p><?php endif; ?>

    <section class="ph-history-panel" aria-labelledby="historyRecordsHeading">
      <div class="ph-history-panel-heading"><h2 id="historyRecordsHeading">Completed visits <span class="ph-history-count"><?= count($consultations) ?></span></h2><span>Latest 50<?= $search !== '' || $range !== 'all' ? ' matching' : '' ?> visits</span></div>
      <?php if ($historyGroups): ?>
        <?php foreach ($historyGroups as $date => $visits): ?>
          <?php
            $visitDate = new DateTimeImmutable($date);
            $dayLabel = $date === $today->format('Y-m-d') ? 'Today' : ($date === $today->modify('-1 day')->format('Y-m-d') ? 'Yesterday' : 'Earlier');
          ?>
          <section class="ph-history-group" aria-label="<?= htmlspecialchars($visitDate->format('j F Y')) ?>">
            <h3 class="ph-history-date"><?= $dayLabel ?> &middot; <?= $visitDate->format('j F Y') ?></h3>
            <div class="ph-history-rows">
              <?php foreach ($visits as $item): ?>
                <article class="ph-history-row">
                  <div class="ph-history-patient"><span class="ph-history-avatar" aria-hidden="true"><?= htmlspecialchars(strtoupper(mb_substr($item['FirstName'],0,1) . mb_substr($item['LastName'],0,1))) ?></span><div><h4><?= htmlspecialchars($item['FirstName'] . ' ' . $item['LastName']) ?></h4><p><?= htmlspecialchars($item['ClinicName']) ?></p></div></div>
                  <time class="ph-history-visit-date" datetime="<?= htmlspecialchars($date) ?>"><?= $historyIcon('calendar') ?><?= $visitDate->format('j M Y') ?></time>
                  <span class="ph-history-visit-time"><?= $historyIcon('clock') ?><?= $item['AppointmentTime'] && $item['AppointmentTime'] !== '00:00:00' ? htmlspecialchars(date('g:i A',strtotime($item['AppointmentTime']))) : 'Queue visit' ?></span>
                  <span class="ph-history-completed"><?= $historyIcon('check') ?>Completed</span>
                  <div class="ph-history-actions"><a class="btn btn-outline" href="<?= HQ_BASE_URL ?>/physician/consultation.php?appointment_id=<?= (int) $item['AppointmentID'] ?>#ai-transcriber"><?= $historyIcon('microphone') ?>Transcript</a><a class="btn btn-primary" href="<?= HQ_BASE_URL ?>/physician/consultation.php?appointment_id=<?= (int) $item['AppointmentID'] ?>"><?= $historyIcon('notes') ?>View notes</a></div>
                </article>
              <?php endforeach; ?>
            </div>
          </section>
        <?php endforeach; ?>
      <?php elseif (!$dataError): ?>
        <div class="ph-history-empty"><?= $historyIcon('notes') ?><h3><?= $search !== '' || $range !== 'all' ? 'No matching consultations' : 'No completed consultations yet' ?></h3><p><?= $search !== '' || $range !== 'all' ? 'Try a different patient name, clinic, or date range.' : 'Completed visits will appear here with their clinical notes.' ?></p><?php if ($search !== '' || $range !== 'all'): ?><a class="btn btn-outline" href="history.php">Clear filters</a><?php endif; ?></div>
      <?php endif; ?>
      <footer class="ph-history-panel-footer"><span><?= count($consultations) ?> completed consultation<?= count($consultations) === 1 ? '' : 's' ?></span><a href="<?= HQ_BASE_URL ?>/physician/consultations.php">Back to consultations</a></footer>
    </section>
  </div>
</main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
