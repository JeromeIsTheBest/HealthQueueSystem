<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/sms.php';
require_once __DIR__ . '/../includes/email.php';
require_once __DIR__ . '/../includes/clinic-hours.php';

define('HQ_BASE_URL', '..');
requireRole(['Staff']);

// Same rough per-patient estimate the patient pages use.
const MINUTES_PER_PATIENT = 15;

$user = currentUser();
$pdo = getDbConnection();
$clinicId = (int) $user['ClinicID'];
$flash = null;

// Flash handed back by staff/appointments.php after Approve/Decline.
if (!empty($_SESSION['dashboard_flash'])) {
    $flash = $_SESSION['dashboard_flash'];
    unset($_SESSION['dashboard_flash']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formType = $_POST['form_type'] ?? '';
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $flash = ['error', 'Your session expired. Please try again.'];
    } elseif (!$pdo || !$clinicId) {
        $flash = ['error', 'The database is temporarily unavailable.'];
    } elseif ($formType === 'call_next') {
        try {
            // Next in line = lowest waiting queue number today.
            $next = $pdo->prepare(
                "SELECT q.QueueID, q.QueueNumber, q.ScheduledNumber, q.RegularNumber, a.PatientID, a.AppointmentID, u.Email, u.FirstName, c.ClinicName FROM Queue q
                 JOIN Appointments a ON a.AppointmentID = q.AppointmentID
                 JOIN Users u ON u.UserID = a.PatientID JOIN Clinic c ON c.ClinicID = q.ClinicID
                 WHERE q.ClinicID = ? AND DATE(q.CreatedAt) = CURDATE() AND q.Status = 'Waiting' AND (a.AppointmentTime IS NULL OR TIMESTAMP(a.AppointmentDate, a.AppointmentTime) <= NOW()) AND NOT EXISTS (SELECT 1 FROM Queue activeQ WHERE activeQ.ClinicID = q.ClinicID AND activeQ.Status IN ('Calling', 'Serving'))
                 ORDER BY CASE WHEN a.AppointmentTime IS NOT NULL AND TIMESTAMP(a.AppointmentDate, a.AppointmentTime) <= NOW() THEN 0 ELSE 1 END, CASE WHEN a.AppointmentTime IS NOT NULL AND TIMESTAMP(a.AppointmentDate, a.AppointmentTime) <= NOW() THEN TIMESTAMP(a.AppointmentDate, a.AppointmentTime) END, q.QueueNumber LIMIT 1"
            );
            $next->execute([$clinicId]);
            $entry = $next->fetch();
            if (!$entry) {
                $flash = ['error', 'No one is waiting in the queue right now.'];
            } else {
                $pdo->prepare("UPDATE Queue SET Status = 'Calling', CalledAt = NOW() WHERE QueueID = ? AND Status = 'Waiting'")->execute([$entry['QueueID']]);
                notifyPatient($pdo, (int) $entry['PatientID'], "You're being called now — please proceed to the counter.", (int) $entry['AppointmentID']);
                $ticketNumber = $entry['ScheduledNumber'] !== null ? 'S-' . (int) $entry['ScheduledNumber'] : '#' . (int) ($entry['RegularNumber'] ?? $entry['QueueNumber']);
                queuePatientSms($pdo, (int) $entry['PatientID'], (int) $entry['AppointmentID'],
                    'called:' . $entry['QueueID'], 'called', 'HealthQueue: You are being called now. Please proceed to the clinic counter.');
                sendQueueNearEmail((string) $entry['Email'], (string) $entry['FirstName'], $ticketNumber, (string) $entry['ClinicName']);
                logActivity($pdo, $user['UserID'], $clinicId, 'Updated queue status', "Queue #{$entry['QueueNumber']} set to Calling");
                $flash = ['success', "Calling queue #{$entry['QueueNumber']}."];
            }
        } catch (PDOException $e) {
            error_log('Call next failed: ' . $e->getMessage());
            $flash = ['error', 'We could not call the next patient.'];
        }
    }
}

$clinic = null;
$now = null;
$stats = ['pending' => 0, 'pending_new' => 0, 'today' => 0, 'today_done' => 0, 'in_queue' => 0, 'avg_wait' => null, 'avg_wait_yesterday' => null];
$nowServing = null;
$upNext = [];
$pendingRequests = [];
$physicians = [];
$dataError = null;

if ($pdo && $clinicId) {
    try {
        $now = clinicNow($pdo);
        $clinicStmt = $pdo->prepare('SELECT ClinicName, OpenDays, OpenTime, CloseTime FROM Clinic WHERE ClinicID = ?');
        $clinicStmt->execute([$clinicId]);
        $clinic = $clinicStmt->fetch() ?: null;

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS Total, COALESCE(SUM(DATE(CreatedAt) = CURDATE()), 0) AS NewToday
             FROM Appointments WHERE ClinicID = ? AND Status = 'Pending' AND BookingFeePaid = 1"
        );
        $stmt->execute([$clinicId]);
        $row = $stmt->fetch();
        $stats['pending'] = (int) $row['Total'];
        $stats['pending_new'] = (int) $row['NewToday'];

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS Total, COALESCE(SUM(Status = 'Completed'), 0) AS Done
             FROM Appointments WHERE ClinicID = ? AND AppointmentDate = CURDATE() AND Status IN ('Confirmed', 'Completed')"
        );
        $stmt->execute([$clinicId]);
        $row = $stmt->fetch();
        $stats['today'] = (int) $row['Total'];
        $stats['today_done'] = (int) $row['Done'];

        // Average time from joining the queue to being called, today vs yesterday.
        $waitStmt = $pdo->prepare(
            "SELECT AVG(TIMESTAMPDIFF(MINUTE, CreatedAt, CalledAt)) FROM Queue
             WHERE ClinicID = ? AND CalledAt IS NOT NULL AND DATE(CreatedAt) = CURDATE() - INTERVAL ? DAY"
        );
        $waitStmt->execute([$clinicId, 0]);
        $stats['avg_wait'] = ($v = $waitStmt->fetchColumn()) !== null ? (int) round((float) $v) : null;
        $waitStmt->execute([$clinicId, 1]);
        $stats['avg_wait_yesterday'] = ($v = $waitStmt->fetchColumn()) !== null ? (int) round((float) $v) : null;

        $queueStmt = $pdo->prepare(
            "SELECT q.QueueID, q.QueueNumber, q.ScheduledNumber, q.RegularNumber, q.Status, a.AppointmentDate, a.AppointmentTime, a.BookingFeePaid,
                    pat.FirstName, pat.LastName, phy.LastName AS PhyLastName
             FROM Queue q
             JOIN Appointments a ON a.AppointmentID = q.AppointmentID
             JOIN Users pat ON pat.UserID = a.PatientID
             LEFT JOIN Users phy ON phy.UserID = q.PhysicianID
             WHERE q.ClinicID = ? AND DATE(q.CreatedAt) = CURDATE() AND q.Status IN ('Waiting', 'Calling', 'Serving')
             ORDER BY FIELD(q.Status, 'Serving', 'Calling', 'Waiting'), CASE WHEN a.AppointmentTime IS NOT NULL AND TIMESTAMP(a.AppointmentDate, a.AppointmentTime) <= NOW() THEN 0 ELSE 1 END, CASE WHEN a.AppointmentTime IS NOT NULL AND TIMESTAMP(a.AppointmentDate, a.AppointmentTime) <= NOW() THEN TIMESTAMP(a.AppointmentDate, a.AppointmentTime) END, q.QueueNumber"
        );
        $queueStmt->execute([$clinicId]);
        $active = $queueStmt->fetchAll();
        $stats['in_queue'] = count($active);
        foreach ($active as $entry) {
            if ($entry['Status'] !== 'Waiting' && !$nowServing) {
                $nowServing = $entry;
            } elseif ($entry['Status'] === 'Waiting') {
                $upNext[] = $entry;
            }
        }

        $reqStmt = $pdo->prepare(
            "SELECT a.AppointmentID, a.AppointmentDate, a.AppointmentTime, a.Concern, a.PhysicianID, pat.FirstName, pat.LastName
             FROM Appointments a JOIN Users pat ON pat.UserID = a.PatientID
             WHERE a.ClinicID = ? AND a.Status = 'Pending' AND a.BookingFeePaid = 1
             ORDER BY a.AppointmentDate, a.AppointmentTime LIMIT 2"
        );
        $reqStmt->execute([$clinicId]);
        $pendingRequests = $reqStmt->fetchAll();

        $physStmt = $pdo->prepare(
            "SELECT u.UserID, u.FirstName, u.LastName, u.AvailabilityStatus,
                    (SELECT COUNT(*) FROM Queue q WHERE q.PhysicianID = u.UserID AND DATE(q.CreatedAt) = CURDATE() AND q.Status IN ('Waiting', 'Calling', 'Serving')) AS InQueue
             FROM Users u
             WHERE u.ClinicID = ? AND u.Status = 'Active' AND u.RoleID = (SELECT RoleID FROM Roles WHERE RoleName = 'Physician')
             ORDER BY u.LastName"
        );
        $physStmt->execute([$clinicId]);
        $physicians = $physStmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Staff dashboard failed: ' . $e->getMessage());
        $dataError = 'Some dashboard information is temporarily unavailable.';
    }
}

if (!$pdo || !$clinicId) $dataError = 'Your clinic dashboard is temporarily unavailable. Contact your administrator if no clinic is assigned.';

$hour = $now ? (int) $now->format('G') : (int) date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$hours = $clinic && $now ? clinicOpenStatus($clinic, $now) : null;
$hoursText = $hours ? ($hours['open'] ? 'Clinic open until ' . clinicFormatTime($clinic['CloseTime']) : $hours['label']) : '';

$availableCount = count(array_filter($physicians, static fn($p) => $p['AvailabilityStatus'] === 'Available'));
$breakCount = count(array_filter($physicians, static fn($p) => $p['AvailabilityStatus'] === 'On Break'));
$availabilityDots = ['Available' => 'sd-dot-green', 'On Break' => 'sd-dot-amber', 'Unavailable' => 'sd-dot-slate'];
$waitDelta = ($stats['avg_wait'] !== null && $stats['avg_wait_yesterday'] !== null) ? $stats['avg_wait_yesterday'] - $stats['avg_wait'] : null;

$pageTitle = 'Staff Dashboard — HealthQueue';
$isStaffDashboard = true;
require __DIR__ . '/../includes/header.php';
?>

<?php
$fdTicket = static fn(array $entry): string => $entry['ScheduledNumber'] !== null ? 'S-' . (int) $entry['ScheduledNumber'] : '#' . (int) ($entry['RegularNumber'] ?? $entry['QueueNumber']);
$fdSource = static fn(array $entry): string => !$entry['BookingFeePaid'] ? 'Walk-in' : (!empty($entry['AppointmentTime']) && $entry['AppointmentTime'] !== '00:00:00' ? 'Scheduled visit' : 'Queue booking');
$fdInitials = static fn(array $person): string => htmlspecialchars(mb_strtoupper(mb_substr($person['FirstName'], 0, 1) . mb_substr($person['LastName'], 0, 1)));
$fdIcons = [
    'person' => '<circle cx="9" cy="7" r="4"/><path d="M2 21v-2a7 7 0 0 1 14 0v2M19 8v6M16 11h6"/>',
    'call' => '<path d="m3 10 17-5v14L3 14v-4zM7 15l2 6h4l-2-5M21 2l2-1M22 11h2"/>',
    'requests' => '<rect x="5" y="4" width="14" height="18" rx="2"/><path d="M9 2h6v4H9zM8 11h8M8 15h6"/>',
    'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18"/>',
    'people' => '<circle cx="9" cy="7" r="3"/><path d="M2 21v-3a7 7 0 0 1 14 0v3M17 4a3 3 0 0 1 0 6M18 14a5 5 0 0 1 4 5v2"/>',
    'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
];
$fdIcon = static fn(string $name): string => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($fdIcons[$name] ?? '') . '</svg>';
$nextInLine = null;
$fdNow = $now ? $now->getTimestamp() : time();
foreach ($upNext as $entry) {
    if ($entry['AppointmentTime'] === null || strtotime($entry['AppointmentDate'] . ' ' . $entry['AppointmentTime']) <= $fdNow) { $nextInLine = $entry; break; }
}
?>
<main class="portal-shell staff-dashboard">
  <div class="container fd-container">
    <header class="fd-heading">
      <div><span class="fd-eyebrow"><?= htmlspecialchars($clinic['ClinicName'] ?? 'Front desk') ?></span><h1>Your front desk, organized.</h1><p><?= htmlspecialchars(($now ?? new DateTimeImmutable())->format('l, j F Y')) ?><?= $hoursText ? ' ? ' . htmlspecialchars($hoursText) : '' ?></p></div>
      <div class="fd-heading-actions"><a class="btn btn-outline" href="<?= HQ_BASE_URL ?>/staff/queue.php#walk-in"><?= $fdIcon('person') ?>Register walk-in</a><a class="fd-text-link" href="<?= HQ_BASE_URL ?>/staff/queue.php">Open queue</a></div>
    </header>

    <?php if ($flash): ?><p class="form-message <?= $flash[0] === 'error' ? 'error' : 'success' ?>" role="<?= $flash[0] === 'error' ? 'alert' : 'status' ?>"><?= htmlspecialchars($flash[1]) ?></p><?php endif; ?>
    <?php if ($dataError): ?><p class="form-message error" role="alert"><?= htmlspecialchars($dataError) ?></p><?php endif; ?>

    <section class="fd-command" aria-label="Current patient and next queue action">
      <div class="fd-command-cell">
        <h2><?= $nowServing && $nowServing['Status'] === 'Calling' ? 'Now calling' : 'Now serving' ?></h2>
        <?php if ($nowServing): ?>
          <strong class="fd-big-ticket"><?= $fdTicket($nowServing) ?></strong>
          <p class="fd-current-name"><?= htmlspecialchars($nowServing['FirstName'] . ' ' . $nowServing['LastName']) ?></p><p class="fd-command-meta"><?= $nowServing['PhyLastName'] ? 'Dr. ' . htmlspecialchars($nowServing['PhyLastName']) : 'No physician assigned' ?></p>
          <span class="fd-serving-badge"><?= $nowServing['Status'] === 'Serving' ? 'In consultation' : 'Being called' ?></span>
        <?php else: ?><strong class="fd-idle">Ready for the next visit</strong><p class="fd-command-meta">No patient is currently being served.</p><?php endif; ?>
      </div>
      <div class="fd-command-cell">
        <h2>Next in line</h2>
        <?php if ($nextInLine): ?>
          <strong class="fd-big-ticket"><?= $fdTicket($nextInLine) ?></strong><p class="fd-current-name"><?= htmlspecialchars($nextInLine['FirstName'] . ' ' . $nextInLine['LastName']) ?></p><p class="fd-command-meta"><?= $fdSource($nextInLine) ?></p>
        <?php else: ?><strong class="fd-idle">No patient ready</strong><p class="fd-command-meta">Eligible patients will appear here when they join the queue.</p><?php endif; ?>
      </div>
      <div class="fd-command-cell fd-call-cell">
        <span class="fd-call-icon"><?= $fdIcon('call') ?></span>
        <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><input type="hidden" name="form_type" value="call_next"><button type="submit" class="btn btn-primary" <?= $nextInLine && !$nowServing ? '' : 'disabled' ?>>Call next patient</button></form>
        <p><?= $nowServing ? 'Available when the current visit ends.' : ($nextInLine ? 'Call the next eligible patient to the counter.' : 'Available when a patient is ready in the queue.') ?></p>
      </div>
    </section>

    <section class="fd-metrics" aria-label="Clinic activity overview">
      <a href="<?= HQ_BASE_URL ?>/staff/appointments.php"><span class="fd-metric-icon"><?= $fdIcon('requests') ?></span><div><h2>Pending requests</h2><strong><?= (int) $stats['pending'] ?></strong><small><?= (int) $stats['pending_new'] ?> new today</small></div></a>
      <a href="<?= HQ_BASE_URL ?>/staff/appointments.php"><span class="fd-metric-icon"><?= $fdIcon('calendar') ?></span><div><h2>Today's appointments</h2><strong><?= (int) $stats['today'] ?></strong><small><?= (int) $stats['today_done'] ?> completed</small></div></a>
      <a href="<?= HQ_BASE_URL ?>/staff/queue.php"><span class="fd-metric-icon"><?= $fdIcon('people') ?></span><div><h2>Waiting</h2><strong><?= count($upNext) ?></strong><small><?= $nowServing ? ($nowServing['Status'] === 'Serving' ? '1 being served' : '1 being called') : 'No active visit' ?></small></div></a>
      <div><span class="fd-metric-icon"><?= $fdIcon('clock') ?></span><div><h2>Average wait</h2><strong><?= $stats['avg_wait'] !== null ? (int) $stats['avg_wait'] . '<em> min</em>' : '?' ?></strong><small><?= $stats['avg_wait'] !== null ? 'Queue entry to first call' : 'No calls yet today' ?></small></div></div>
    </section>

    <div class="fd-grid">
      <section class="fd-panel" aria-labelledby="fdWaitingHeading">
        <div class="fd-panel-heading"><h2 id="fdWaitingHeading">Waiting queue <span class="fd-count"><?= count($upNext) ?></span></h2></div>
        <?php if ($upNext): ?>
          <ul class="fd-queue-list">
          <?php foreach (array_slice($upNext, 0, 5) as $i => $entry): ?>
            <li><span class="fd-ticket"><?= $fdTicket($entry) ?></span><div><h3><?= htmlspecialchars($entry['FirstName'] . ' ' . $entry['LastName']) ?></h3><p><?= $fdSource($entry) ?></p></div><span class="fd-estimate">~<?= ($i + 1) * MINUTES_PER_PATIENT ?> min</span></li>
          <?php endforeach; ?>
          </ul>
          <?php if (count($upNext) > 5): ?><a class="fd-text-link fd-more" href="<?= HQ_BASE_URL ?>/staff/queue.php">+<?= count($upNext) - 5 ?> more waiting</a><?php endif; ?>
          <p class="fd-panel-note">Estimates may change.</p>
        <?php else: ?><div class="fd-empty"><h3>No patients waiting</h3><p>New queue entries will appear here.</p></div><?php endif; ?>
      </section>

      <section class="fd-panel" aria-labelledby="fdRequestsHeading">
        <div class="fd-panel-heading"><h2 id="fdRequestsHeading">Needs approval <span class="fd-count"><?= (int) $stats['pending'] ?></span></h2></div>
        <?php if ($pendingRequests): ?>
          <ul class="fd-request-list">
          <?php foreach ($pendingRequests as $req): ?>
            <li><div class="fd-request-patient"><span class="fd-avatar" aria-hidden="true"><?= $fdInitials($req) ?></span><div><h3><?= htmlspecialchars($req['FirstName'] . ' ' . $req['LastName']) ?></h3><p><?= htmlspecialchars(date('M j', strtotime($req['AppointmentDate']))) ?> ? <?= $req['AppointmentTime'] && $req['AppointmentTime'] !== '00:00:00' ? htmlspecialchars(date('g:i A', strtotime($req['AppointmentTime']))) : 'Queue booking' ?></p></div></div>
              <form method="post" action="<?= HQ_BASE_URL ?>/staff/appointments.php" class="fd-request-actions"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><input type="hidden" name="appointment_id" value="<?= (int) $req['AppointmentID'] ?>"><input type="hidden" name="return_to" value="dashboard">
                <?php if ($req['PhysicianID']): ?><button type="submit" name="form_type" value="accept_request" class="btn btn-primary">Approve</button><?php else: ?><a href="<?= HQ_BASE_URL ?>/staff/appointments.php" class="btn btn-outline">Assign physician</a><?php endif; ?>
                <button type="submit" name="form_type" value="reject_request" class="fd-decline" onclick="return confirm('Decline this appointment request?');">Decline</button>
              </form>
            </li>
          <?php endforeach; ?>
          </ul>
          <a class="fd-text-link fd-more" href="<?= HQ_BASE_URL ?>/staff/appointments.php">View all requests<?= $stats['pending'] > count($pendingRequests) ? ' (+' . ($stats['pending'] - count($pendingRequests)) . ' more)' : '' ?></a>
        <?php else: ?><div class="fd-empty"><h3>All caught up</h3><p>No requests are waiting for approval.</p></div><?php endif; ?>
      </section>

      <section class="fd-panel" aria-labelledby="fdTeamHeading">
        <div class="fd-panel-heading"><h2 id="fdTeamHeading">Clinic team</h2><p>Physician availability</p></div>
        <?php if ($physicians): ?>
          <ul class="fd-team-list">
          <?php foreach ($physicians as $phy): ?>
            <?php $stateClass = ['Available'=>'is-available','On Break'=>'is-break','Unavailable'=>'is-unavailable'][$phy['AvailabilityStatus']] ?? 'is-unavailable'; ?>
            <li><div><h3>Dr. <?= htmlspecialchars($phy['LastName']) ?></h3><span class="fd-availability <?= $stateClass ?>"><span aria-hidden="true"></span><?= htmlspecialchars($phy['AvailabilityStatus']) ?></span></div><span class="fd-team-queue">Queue: <?= (int) $phy['InQueue'] ?></span></li>
          <?php endforeach; ?>
          </ul>
        <?php else: ?><div class="fd-empty"><h3>No physicians assigned</h3><p>Your clinic's physicians will appear here.</p></div><?php endif; ?>
        <p class="fd-panel-note">Updated by each physician.</p>
      </section>
    </div>
  </div>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
