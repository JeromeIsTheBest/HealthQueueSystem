<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/notifications.php';

define('HQ_BASE_URL', '..');
requireRole(['Physician']);

const LONG_WAIT_MINUTES = 30;

$user = currentUser();
$pdo = getDbConnection();
$physicianId = (int) $user['UserID'];
$errors = [];
$flash = '';
if (!empty($_SESSION['consult_flash'])) {
    $flash = $_SESSION['consult_flash'];
    unset($_SESSION['consult_flash']);
}

// "Start consultation": the patient moves to Serving and the workspace opens.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'start_consultation') {
    $appointmentId = filter_input(INPUT_POST, 'appointment_id', FILTER_VALIDATE_INT);
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif ($pdo && $appointmentId) {
        try {
            $check = $pdo->prepare("SELECT a.AppointmentID, a.ClinicID, a.PatientID FROM Appointments a WHERE a.AppointmentID = ? AND a.PhysicianID = ? AND a.Status = 'Confirmed' AND (a.AppointmentTime IS NULL OR a.AppointmentTime = '00:00:00' OR TIMESTAMP(a.AppointmentDate, a.AppointmentTime) <= NOW())");
            $check->execute([$appointmentId, $physicianId]);
            if ($appt = $check->fetch()) {
                $pdo->prepare(
                    "UPDATE Queue SET Status = 'Serving', CalledAt = COALESCE(CalledAt, NOW()), ServedAt = NOW()
                     WHERE AppointmentID = ? AND DATE(CreatedAt) = CURDATE() AND Status IN ('Waiting', 'Calling')"
                )->execute([$appointmentId]);
                $exists = $pdo->prepare('SELECT 1 FROM Consultations WHERE AppointmentID = ?');
                $exists->execute([$appointmentId]);
                if (!$exists->fetchColumn()) {
                    $pdo->prepare('INSERT INTO Consultations (ClinicID, AppointmentID, PatientID, DoctorID) VALUES (?, ?, ?, ?)')
                        ->execute([$appt['ClinicID'], $appointmentId, $appt['PatientID'], $physicianId]);
                }
                logActivity($pdo, $physicianId, (int) $appt['ClinicID'], 'Started consultation', "Appointment #{$appointmentId}");
                header('Location: ' . HQ_BASE_URL . '/physician/consultation.php?appointment_id=' . $appointmentId);
                exit;
            }
            $errors[] = 'That appointment is no longer available.';
        } catch (PDOException $e) {
            error_log('Start consultation failed: ' . $e->getMessage());
            $errors[] = 'We could not start this consultation.';
        }
    }
}

$inProgress = [];
$upNext = [];
$awaiting = [];
$scheduled = [];
$scheduledAppointments = [];
$stats = ['waiting' => 0, 'seen' => 0];
$clinicName = '';
$dataError = null;

if ($pdo) {
    try {
        $clinicStmt = $pdo->prepare('SELECT ClinicName FROM Clinic WHERE ClinicID = ?');
        $clinicStmt->execute([(int) $user['ClinicID']]);
        $clinicName = (string) $clinicStmt->fetchColumn();

        // Every confirmed appointment of mine, with today's queue entry and note state.
        $stmt = $pdo->prepare(
            "SELECT a.AppointmentID, a.AppointmentDate, a.AppointmentTime, a.Concern, a.BookingFeePaid,
                    pat.FirstName, pat.LastName,
                    q.QueueNumber, q.ScheduledNumber, q.RegularNumber, q.Status AS QueueStatus,
                    TIMESTAMPDIFF(MINUTE, q.CreatedAt, NOW()) AS WaitingMinutes,
                    TIMESTAMPDIFF(SECOND, q.ServedAt, NOW()) AS ServingSeconds,
                    (SELECT MAX(v.UpdatedAt) FROM ConsultationVersions v JOIN Consultations c ON c.ConsultationID = v.ConsultationID
                     WHERE c.AppointmentID = a.AppointmentID AND v.Status = 'Finalized') AS FinalizedAt,
                    (SELECT COUNT(*) FROM ConsultationVersions v JOIN Consultations c ON c.ConsultationID = v.ConsultationID
                     WHERE c.AppointmentID = a.AppointmentID AND v.Status = 'Draft') AS DraftCount,
                    (SELECT c.AudioFilePath FROM Consultations c WHERE c.AppointmentID = a.AppointmentID) AS AudioFilePath
             FROM Appointments a
             JOIN Users pat ON pat.UserID = a.PatientID
             LEFT JOIN Queue q ON q.QueueID = (
                 SELECT q2.QueueID FROM Queue q2 WHERE q2.AppointmentID = a.AppointmentID AND DATE(q2.CreatedAt) = CURDATE()
                 ORDER BY q2.CreatedAt DESC LIMIT 1
             )
             WHERE a.PhysicianID = ? AND a.Status = 'Confirmed'
               AND (a.AppointmentTime IS NULL OR a.AppointmentTime = '00:00:00'
                    OR TIMESTAMP(a.AppointmentDate, a.AppointmentTime) <= NOW())
             ORDER BY COALESCE(q.Position, q.QueueNumber * 10), a.AppointmentDate, a.AppointmentTime"
        );
        $stmt->execute([$physicianId]);
        foreach ($stmt->fetchAll() as $row) {
            if ($row['FinalizedAt']) {
                $awaiting[] = $row;
            } elseif ($row['QueueStatus'] === 'Serving') {
                $inProgress[] = $row;
            } elseif (in_array($row['QueueStatus'], ['Waiting', 'Calling'], true)) {
                $upNext[] = $row;
            } else {
                $scheduled[] = $row;
            }
        }
        $stats['waiting'] = count($upNext);

        // Show future timed visits here, separate from today's live queue.
        // The physician can see the schedule early, but cannot start it until
        // its appointment time makes it eligible for the queue.
        $scheduledStmt = $pdo->prepare(
            "SELECT a.AppointmentID, a.AppointmentDate, a.AppointmentTime, a.Concern,
                    pat.FirstName, pat.LastName
             FROM Appointments a
             JOIN Users pat ON pat.UserID = a.PatientID
             WHERE a.ClinicID = ?
               AND (a.PhysicianID = ? OR a.PhysicianID IS NULL)
               AND a.Status IN ('Pending', 'Confirmed')
               AND a.AppointmentTime IS NOT NULL AND a.AppointmentTime <> '00:00:00'
               AND a.BookingFeePaid = 1
               AND TIMESTAMP(a.AppointmentDate, a.AppointmentTime) > NOW()
             ORDER BY a.AppointmentDate, a.AppointmentTime"
        );
        $scheduledStmt->execute([(int) $user['ClinicID'], $physicianId]);
        $scheduledAppointments = $scheduledStmt->fetchAll();

        $seenStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM Queue WHERE PhysicianID = ? AND DATE(CreatedAt) = CURDATE() AND Status = 'Completed'"
        );
        $seenStmt->execute([$physicianId]);
        $stats['seen'] = (int) $seenStmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('Consultations list load failed: ' . $e->getMessage());
        $dataError = 'Your consultations list is temporarily unavailable.';
    }
}

$source = static fn(array $r): string => !$r['BookingFeePaid']
    ? 'Walk-in'
    : (empty($r['AppointmentTime']) || $r['AppointmentTime'] === '00:00:00'
        ? 'Regular queue'
        : 'Appointment ' . date('g:i A', strtotime($r['AppointmentTime'])));
$concern = static fn(array $r): string => trim((string) $r['Concern']) !== '' ? '"' . mb_strimwidth(trim($r['Concern']), 0, 60, '…') . '"' : 'No stated concern';
$waitLabel = static fn(int $m): string => $m < 60 ? "waiting {$m} min" : 'waiting ' . intdiv($m, 60) . ' h ' . ($m % 60) . ' min';
$csrf = htmlspecialchars(csrfToken());

$pageTitle = 'Consultations — HealthQueue';
$isPhysicianConsultations = true;
require __DIR__ . '/../includes/header.php';
?>

<?php
$pcInitials = static fn(array $person): string => htmlspecialchars(strtoupper(substr($person['FirstName'], 0, 1) . substr($person['LastName'], 0, 1)));
$pcTicket = static fn(array $row): string => $row['ScheduledNumber'] !== null ? 'S-' . (int) $row['ScheduledNumber'] : '#' . (int) ($row['RegularNumber'] ?? $row['QueueNumber']);
$pcCalendar = '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18"/>';
?>
<main class="portal-shell physician-consultations">
  <div class="container pc-container">
    <header class="pc-heading">
      <div>
        <span class="pc-eyebrow">Active visits</span>
        <h1>Consultations</h1>
        <p><?= $clinicName ? htmlspecialchars($clinicName) . ' &middot; ' : '' ?><time datetime="<?= date('Y-m-d') ?>"><?= date('l, j F Y') ?></time></p>
      </div>
      <div class="pc-stats" aria-label="Today's consultations">
        <article><span class="pc-stat-icon" aria-hidden="true"><svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="9" cy="7" r="3"/><path d="M2 21v-3a7 7 0 0 1 14 0v3M17 4a3 3 0 0 1 0 6M18 14a5 5 0 0 1 4 5v2"/></svg></span><div><strong><?= (int) $stats['waiting'] ?></strong><span>Waiting</span></div></article>
        <article><span class="pc-stat-icon" aria-hidden="true"><svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/></svg></span><div><strong><?= (int) $stats['seen'] ?></strong><span>Seen today</span></div></article>
      </div>
    </header>

    <?php if ($flash): ?><p class="form-message success" role="status"><?= htmlspecialchars($flash) ?></p><?php endif; ?>
    <?php if ($errors): ?><div class="form-message error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <?php if ($dataError): ?><p class="form-message error" role="alert"><?= htmlspecialchars($dataError) ?></p><?php endif; ?>

    <section class="pc-active" aria-labelledby="pcActiveHeading">
      <div class="pc-active-header"><h2 id="pcActiveHeading">In progress</h2></div>
      <?php if ($inProgress): ?>
        <?php foreach ($inProgress as $row): ?>
          <?php $secs = max(0, (int) $row['ServingSeconds']); ?>
          <article class="pc-active-row">
            <span class="pc-ticket pc-ticket-large"><?= $pcTicket($row) ?></span>
            <div class="pc-patient pc-active-patient"><span class="pc-avatar" aria-hidden="true"><?= $pcInitials($row) ?></span><div><h3><?= htmlspecialchars($row['FirstName'] . ' ' . $row['LastName']) ?></h3><p><?= htmlspecialchars($source($row)) ?> &middot; <?= htmlspecialchars($concern($row)) ?></p></div></div>
            <span class="pc-serving"><span aria-hidden="true">&#9679;</span> In consultation &middot; <?= sprintf('%02d:%02d', intdiv($secs, 60), $secs % 60) ?></span>
            <?php if ((int) $row['DraftCount']): ?><span class="pc-draft">Draft saved</span><?php endif; ?>
            <a class="btn btn-primary pc-continue" href="<?= HQ_BASE_URL ?>/physician/consultation.php?appointment_id=<?= (int) $row['AppointmentID'] ?>">Continue consultation <span aria-hidden="true">&rarr;</span></a>
          </article>
        <?php endforeach; ?>
      <?php else: ?><div class="pc-empty pc-active-empty"><h3>No consultation in progress</h3><p>Start the next eligible patient from your queue below.</p></div><?php endif; ?>
    </section>

    <div class="pc-grid">
      <div class="pc-main-column">
        <section class="pc-panel" aria-labelledby="pcQueueHeading">
          <div class="pc-panel-heading"><h2 id="pcQueueHeading">Up next <span class="pc-count"><?= count($upNext) ?></span></h2><p>Queue order managed by front desk</p></div>
          <?php if ($upNext): ?>
            <div class="pc-table-scroll" tabindex="0" role="region" aria-label="Upcoming patient queue">
              <table class="pc-table">
                <thead><tr><th scope="col">Queue</th><th scope="col">Patient</th><th scope="col">Concern</th><th scope="col">Waiting</th><?php if (!$inProgress): ?><th scope="col"><span class="pc-sr-only">Action</span></th><?php endif; ?></tr></thead>
                <tbody>
                <?php foreach ($upNext as $i => $row): ?>
                  <?php $mins = max(0, (int) $row['WaitingMinutes']); ?>
                  <tr>
                    <td><span class="pc-ticket"><?= $pcTicket($row) ?></span></td>
                    <td><div class="pc-patient"><span class="pc-avatar" aria-hidden="true"><?= $pcInitials($row) ?></span><div><strong><?= htmlspecialchars($row['FirstName'] . ' ' . $row['LastName']) ?></strong><small><?= htmlspecialchars($source($row)) ?><?= $row['QueueStatus'] === 'Calling' ? ' ? being called' : '' ?></small></div></div></td>
                    <td><span class="pc-concern" title="<?= htmlspecialchars($row['Concern'] ?? '') ?>"><?= htmlspecialchars($concern($row)) ?></span></td>
                    <td><span class="pc-wait<?= $mins >= LONG_WAIT_MINUTES ? ' is-long' : '' ?>"><?= htmlspecialchars($waitLabel($mins)) ?></span></td>
                    <?php if (!$inProgress): ?><td>
                      <?php if ($i === 0): ?>
                        <form method="post"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="form_type" value="start_consultation"><input type="hidden" name="appointment_id" value="<?= (int) $row['AppointmentID'] ?>"><button type="submit" class="btn btn-primary pc-start">Start consultation</button></form>
                      <?php endif; ?>
                    </td><?php endif; ?>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php if ($inProgress): ?><p class="pc-info"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7v1"/></svg>Finish the current consultation before starting the next patient.</p><?php endif; ?>
          <?php else: ?><div class="pc-empty"><h3>No patients waiting</h3><p>Patients in your live queue will appear here.</p></div><?php endif; ?>
        </section>

        <?php if ($scheduled): ?>
          <details class="pc-other">
            <summary><span>Other confirmed appointments <span class="pc-count"><?= count($scheduled) ?></span></span><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></summary>
            <ul>
              <?php foreach ($scheduled as $row): ?>
                <li><div><strong><?= htmlspecialchars($row['FirstName'] . ' ' . $row['LastName']) ?></strong><small><?= date('M j, g:i A', strtotime($row['AppointmentDate'] . ' ' . $row['AppointmentTime'])) ?></small></div><a class="pc-text-link" href="<?= HQ_BASE_URL ?>/physician/consultation.php?appointment_id=<?= (int) $row['AppointmentID'] ?>">Open consultation</a></li>
              <?php endforeach; ?>
            </ul>
          </details>
        <?php endif; ?>
      </div>

      <div class="pc-side-column">
        <section class="pc-panel" aria-labelledby="pcScheduledHeading">
          <div class="pc-panel-heading"><h2 id="pcScheduledHeading">Scheduled appointments <span class="pc-count"><?= count($scheduledAppointments) ?></span></h2><p>Not yet due</p></div>
          <?php if ($scheduledAppointments): ?>
            <ul class="pc-schedule-list">
              <?php foreach ($scheduledAppointments as $row): ?>
                <li><span class="pc-calendar-icon" aria-hidden="true"><svg width="23" height="23" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><?= $pcCalendar ?></svg></span><div><h3><?= htmlspecialchars($row['FirstName'] . ' ' . $row['LastName']) ?></h3><time datetime="<?= htmlspecialchars($row['AppointmentDate'] . 'T' . $row['AppointmentTime']) ?>"><?= date('M j, g:i A', strtotime($row['AppointmentDate'] . ' ' . $row['AppointmentTime'])) ?></time><p><?= htmlspecialchars($concern($row)) ?></p></div><span class="pc-not-due">Not yet due</span></li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?><div class="pc-empty"><h3>No upcoming timed appointments</h3><p>Future scheduled visits will appear here.</p></div><?php endif; ?>
        </section>

        <section class="pc-panel" aria-labelledby="pcAwaitingHeading">
          <div class="pc-panel-heading"><h2 id="pcAwaitingHeading">Awaiting staff confirmation <span class="pc-count"><?= count($awaiting) ?></span></h2></div>
          <?php if ($awaiting): ?>
            <ul class="pc-awaiting-list">
              <?php foreach ($awaiting as $row): ?>
                <li><div class="pc-patient"><span class="pc-avatar" aria-hidden="true"><?= $pcInitials($row) ?></span><div><h3><?= htmlspecialchars($row['FirstName'] . ' ' . $row['LastName']) ?></h3><small>Finalized <?= date('M j, g:i A', strtotime($row['FinalizedAt'])) ?></small></div></div><span class="pc-pending">Awaiting confirmation</span><a class="pc-text-link" href="<?= HQ_BASE_URL ?>/physician/consultation.php?appointment_id=<?= (int) $row['AppointmentID'] ?>">View consultation <span aria-hidden="true">&rarr;</span></a></li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?><div class="pc-empty"><h3>All caught up</h3><p>Nothing is waiting on the front desk.</p></div><?php endif; ?>
        </section>
      </div>
    </div>
  </div>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
