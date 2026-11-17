<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/ai-transcription.php';
require_once __DIR__ . '/../includes/wallet.php';
require_once __DIR__ . '/../includes/notifications.php';

define('HQ_BASE_URL', '..');
requireRole(['Physician']);

$user = currentUser();
$pdo = getDbConnection();
$errors = [];
$flash = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'update_appointment_status') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!$pdo) {
        $errors[] = 'The database is temporarily unavailable.';
    } else {
        $appointmentId = filter_input(INPUT_POST, 'appointment_id', FILTER_VALIDATE_INT);
        $newStatus = $_POST['status'] ?? '';
        if (!$appointmentId || !in_array($newStatus, ['Cancelled', 'NoShow'], true)) {
            $errors[] = 'Invalid appointment update.';
        } else {
            try {
                // Scoped to this physician's own UserID so one physician can never touch another's appointment.
                $pdo->beginTransaction();
                $patientStmt = $pdo->prepare('SELECT PatientID FROM Appointments WHERE AppointmentID = ? AND PhysicianID = ? FOR UPDATE');
                $patientStmt->execute([$appointmentId, $user['UserID']]);
                $patientId = $patientStmt->fetchColumn();
                $changed = $newStatus === 'Cancelled' && $patientId
                    ? cancelBooking($pdo, (int) $appointmentId, (int) $patientId, 'Cancelled by physician', 'Refund for physician-cancelled booking at ')
                    : false;
                if ($newStatus === 'NoShow' && $patientId) {
                    $stmt = $pdo->prepare("UPDATE Appointments SET Status = 'NoShow' WHERE AppointmentID = ? AND PhysicianID = ? AND Status = 'Confirmed'");
                    $stmt->execute([$appointmentId, $user['UserID']]);
                    $changed = (bool) $stmt->rowCount();
                }
                if ($changed) {
                    $pdo->commit();
                    logActivity($pdo, $user['UserID'], $user['ClinicID'], 'Updated appointment status', "Appointment #{$appointmentId} set to {$newStatus}.");
                    if ($newStatus === 'Cancelled') notifyPatient($pdo, (int) $patientId, 'Your appointment was cancelled by the physician. Any eligible booking fee refund has been applied to your wallet.', (int) $appointmentId);
                    $flash = 'Appointment updated.';
                } else {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $errors[] = 'That appointment could not be found.';
                }
            } catch (PDOException $e) {
                error_log('Appointment status update failed: ' . $e->getMessage());
                $errors[] = 'We could not update this appointment.';
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'toggle_availability') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!in_array($_POST['availability_status'] ?? '', ['Available', 'On Break', 'Unavailable'], true)) {
        $errors[] = 'Choose a valid availability status.';
    } elseif ($pdo) {
        try {
            $newAvailability = $_POST['availability_status'];
            $pdo->prepare('UPDATE Users SET AvailabilityStatus = ? WHERE UserID = ?')->execute([$newAvailability, $user['UserID']]);
            logActivity($pdo, $user['UserID'], $user['ClinicID'], 'Updated availability status', "Set to {$newAvailability}.");
            $flash = "You're now marked as {$newAvailability}.";
        } catch (PDOException $e) {
            error_log('Availability toggle failed: ' . $e->getMessage());
            $errors[] = 'We could not update your availability status.';
        }
    } else {
        $errors[] = 'The database is temporarily unavailable.';
    }
}

$stats = ['earnings' => 0, 'today_earnings' => 0, 'paid_count' => 0, 'upcoming' => 0, 'total' => 0];
$upcoming = [];
$scheduledAppointments = [];
$recentEarnings = [];
$nextPatient = null;
$availabilityStatus = 'Available';
$dataError = null;

if ($pdo) {
    try {
        $availabilityStmt = $pdo->prepare('SELECT AvailabilityStatus FROM Users WHERE UserID = ?');
        $availabilityStmt->execute([$user['UserID']]);
        $availabilityStatus = $availabilityStmt->fetchColumn() ?: 'Available';

        $earningsStmt = $pdo->prepare(
            "SELECT COALESCE(SUM(p.PhysicianRevenue), 0) AS total, COUNT(*) AS paid_count
             FROM AppointmentPayments p
             JOIN Appointments a ON a.AppointmentID = p.AppointmentID
             WHERE a.PhysicianID = ? AND p.PaymentStatus = 'Paid'"
        );
        $earningsStmt->execute([$user['UserID']]);
        $earningsRow = $earningsStmt->fetch();
        $stats['earnings'] = (float) $earningsRow['total'];
        $stats['paid_count'] = (int) $earningsRow['paid_count'];

        $todayEarningsStmt = $pdo->prepare(
            "SELECT COALESCE(SUM(p.PhysicianRevenue), 0)
             FROM AppointmentPayments p
             JOIN Appointments a ON a.AppointmentID = p.AppointmentID
             WHERE a.PhysicianID = ? AND p.PaymentStatus = 'Paid' AND DATE(p.PaidAt) = CURDATE()"
        );
        $todayEarningsStmt->execute([$user['UserID']]);
        $stats['today_earnings'] = (float) $todayEarningsStmt->fetchColumn();

        $upcomingCountStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM Appointments
             WHERE PhysicianID = ? AND Status IN ('Pending', 'Confirmed')
               AND (AppointmentDate > CURDATE()
                    OR (AppointmentDate = CURDATE() AND (AppointmentTime IS NULL OR AppointmentTime = '00:00:00' OR AppointmentTime > CURTIME())))"
        );
        $upcomingCountStmt->execute([$user['UserID']]);
        $stats['upcoming'] = (int) $upcomingCountStmt->fetchColumn();

        $totalStmt = $pdo->prepare('SELECT COUNT(*) FROM Appointments WHERE PhysicianID = ?');
        $totalStmt->execute([$user['UserID']]);
        $stats['total'] = (int) $totalStmt->fetchColumn();

        $scheduledStmt = $pdo->prepare(
            "SELECT a.AppointmentID, a.AppointmentDate, a.AppointmentTime, a.Status, a.Concern,
                    c.ClinicName, pat.FirstName, pat.LastName
             FROM Appointments a
             JOIN Clinic c ON c.ClinicID = a.ClinicID
             JOIN Users pat ON pat.UserID = a.PatientID
             WHERE a.ClinicID = ?
               AND (a.PhysicianID = ? OR a.PhysicianID IS NULL)
               AND TIMESTAMP(a.AppointmentDate, a.AppointmentTime) > NOW()
               AND a.AppointmentTime IS NOT NULL AND a.AppointmentTime <> '00:00:00'
               AND a.BookingFeePaid = 1
               AND a.Status IN ('Pending', 'Confirmed')
             ORDER BY a.AppointmentDate, a.AppointmentTime"
        );
        // Include clinic bookings that have no physician preference even when
        // the clinic has multiple physicians. These are visible ahead of time
        // here and remain out of the live queue until their scheduled time.
        $scheduledStmt->execute([$user['ClinicID'], $user['UserID']]);
        $scheduledAppointments = $scheduledStmt->fetchAll();

        $upcomingStmt = $pdo->prepare(
            "SELECT a.AppointmentID, a.AppointmentDate, a.AppointmentTime, a.Status, a.Concern,
                    c.ClinicName, pat.FirstName, pat.LastName
             FROM Appointments a
             JOIN Clinic c ON c.ClinicID = a.ClinicID
             JOIN Users pat ON pat.UserID = a.PatientID
             WHERE a.PhysicianID = ? AND a.AppointmentDate >= CURDATE()
               AND (a.AppointmentTime IS NULL OR a.AppointmentTime = '00:00:00')
               AND a.Status IN ('Pending', 'Confirmed')
             ORDER BY a.AppointmentDate
             LIMIT 8"
        );
        $upcomingStmt->execute([$user['UserID']]);
        $upcoming = $upcomingStmt->fetchAll();

        // The physician follows the same clinic queue the front desk calls from.
        $nextStmt = $pdo->prepare(
            "SELECT a.AppointmentID, a.AppointmentDate, a.AppointmentTime, a.Status, a.Concern, q.Status AS QueueStatus,
                    c.ClinicName, pat.FirstName, pat.LastName
             FROM Queue q JOIN Appointments a ON a.AppointmentID = q.AppointmentID
             JOIN Clinic c ON c.ClinicID = a.ClinicID JOIN Users pat ON pat.UserID = a.PatientID
             WHERE q.PhysicianID = ? AND DATE(q.CreatedAt) = CURDATE()
               AND q.Status IN ('Calling', 'Serving')
               AND a.Status = 'Confirmed'
             ORDER BY FIELD(q.Status, 'Calling', 'Serving'), q.CalledAt, q.CreatedAt LIMIT 1"
        );
        $nextStmt->execute([$user['UserID']]);
        $nextPatient = $nextStmt->fetch() ?: null;

        $earningsListStmt = $pdo->prepare(
            "SELECT p.PhysicianRevenue, p.PaidAt, c.ClinicName, pat.FirstName, pat.LastName
             FROM AppointmentPayments p
             JOIN Appointments a ON a.AppointmentID = p.AppointmentID
             JOIN Clinic c ON c.ClinicID = a.ClinicID
             JOIN Users pat ON pat.UserID = a.PatientID
             WHERE a.PhysicianID = ? AND p.PaymentStatus = 'Paid'
             ORDER BY p.PaidAt DESC
             LIMIT 8"
        );
        $earningsListStmt->execute([$user['UserID']]);
        $recentEarnings = $earningsListStmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Physician dashboard failed: ' . $e->getMessage());
        $dataError = 'Your appointment and earnings information is temporarily unavailable.';
    }
} else {
    $dataError = 'Your appointment and earnings information is temporarily unavailable.';
}

$pageTitle = 'Physician Dashboard — HealthQueue';
$isPhysicianDashboard = true;
require __DIR__ . '/../includes/header.php';
?>

<?php
$pdInitials = static function (array $person): string {
    return htmlspecialchars(strtoupper(substr($person['FirstName'], 0, 1) . substr($person['LastName'], 0, 1)));
};
$pdCalendar = '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18"/>';
$pdMoney = '<ellipse cx="10" cy="5" rx="7" ry="3"/><path d="M3 5v5c0 4 14 4 14 0V5M3 10v5c0 3 8 4 12 2M18 12c4 0 4 7 0 7M18 10v11"/>';
$pdMetrics = [
    ["Today's earnings", 'PHP ' . number_format($stats['today_earnings'], 2), '', $pdMoney],
    ['Total earnings', 'PHP ' . number_format($stats['earnings'], 2), '90% physician share', $pdMoney],
    ['Paid consultations', (string) $stats['paid_count'], '', '<rect x="5" y="4" width="14" height="18" rx="2"/><path d="M9 2h6v4H9zM8 14l3 3 5-6"/>'],
    ['Upcoming appointments', (string) $stats['upcoming'], '', $pdCalendar],
    ['Total assigned', (string) $stats['total'], '', '<circle cx="9" cy="7" r="3"/><path d="M2 21v-3a7 7 0 0 1 14 0v3M17 4a3 3 0 0 1 0 6M18 14a5 5 0 0 1 4 5v2"/>'],
];
?>
<main class="portal-shell physician-dashboard">
  <div class="container pd-container">
    <header class="pd-heading">
      <div>
        <h1>Welcome back, Dr. <?= htmlspecialchars($user['LastName']) ?>.</h1>
      </div>
      <div class="pd-heading-tools">
        <details class="pd-status-dropdown">
          <summary class="pd-availability" aria-label="Change your availability">
            <span class="pd-availability-dot <?= $availabilityStatus === 'Available' ? 'is-available' : ($availabilityStatus === 'On Break' ? 'is-break' : '') ?>" aria-hidden="true"></span>
            <?= htmlspecialchars($availabilityStatus) ?>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
          </summary>
          <form method="post" class="pd-status-list" aria-label="Availability options">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
            <input type="hidden" name="form_type" value="toggle_availability">
            <?php foreach (['Available', 'On Break', 'Unavailable'] as $option): ?>
              <button type="submit" name="availability_status" value="<?= htmlspecialchars($option) ?>" class="pd-status-option<?= $availabilityStatus === $option ? ' is-selected' : '' ?>" <?= $availabilityStatus === $option ? 'aria-current="true"' : '' ?>>
                <span class="pd-availability-dot <?= $option === 'Available' ? 'is-available' : ($option === 'On Break' ? 'is-break' : '') ?>" aria-hidden="true"></span>
                <span><?= htmlspecialchars($option) ?></span>
                <?php if ($availabilityStatus === $option): ?><span class="pd-status-check" aria-hidden="true">&#10003;</span><?php endif; ?>
              </button>
            <?php endforeach; ?>
          </form>
        </details>
        <time class="pd-today" datetime="<?= date('Y-m-d') ?>"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><?= $pdCalendar ?></svg><?= date('l, j F Y') ?></time>
      </div>
    </header>

    <?php if ($flash): ?><p class="form-message success" role="status"><?= htmlspecialchars($flash) ?></p><?php endif; ?>
    <?php if ($errors): ?><div class="form-message error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <?php if ($dataError): ?><p class="form-message error" role="alert"><?= htmlspecialchars($dataError) ?></p><?php endif; ?>

    <section class="pd-next" aria-labelledby="pdNextHeading">
      <h2 id="pdNextHeading">Next patient</h2>
      <?php if ($nextPatient): ?>
        <div class="pd-next-body">
          <span class="pd-avatar pd-avatar-large" aria-hidden="true"><?= $pdInitials($nextPatient) ?></span>
          <div class="pd-next-info">
            <h3><?= htmlspecialchars($nextPatient['FirstName'] . ' ' . $nextPatient['LastName']) ?></h3>
            <p><?= htmlspecialchars($nextPatient['ClinicName']) ?></p>
          </div>
          <span class="pd-queue-badge"><?= ($nextPatient['QueueStatus'] ?? '') === 'Serving' ? 'Consultation in progress' : 'Called by front desk' ?></span>
          <a class="btn btn-primary pd-next-action" href="<?= HQ_BASE_URL ?>/physician/consultation.php?appointment_id=<?= (int) $nextPatient['AppointmentID'] ?>">Open consultation <span aria-hidden="true">&rarr;</span></a>
        </div>
      <?php else: ?>
        <div class="pd-next-body pd-next-empty">
          <div><h3>No patient is being called</h3><p>Your next patient will appear here when called by the front desk.</p></div>
          <a class="btn btn-outline pd-next-action" href="<?= HQ_BASE_URL ?>/physician/consultations.php">View consultations</a>
        </div>
      <?php endif; ?>
    </section>

    <section class="pd-metrics" aria-label="Earnings and appointment overview">
      <?php foreach ($pdMetrics as [$label, $value, $note, $icon]): ?>
        <article class="pd-metric">
          <div class="pd-metric-label"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $icon ?></svg><span><?= htmlspecialchars($label) ?></span></div>
          <strong><?= htmlspecialchars($value) ?></strong>
          <?php if ($note): ?><small><?= htmlspecialchars($note) ?></small><?php endif; ?>
        </article>
      <?php endforeach; ?>
    </section>

    <div class="pd-grid">
      <div class="pd-main-column">
        <section class="pd-panel" aria-labelledby="pdScheduledHeading">
          <div class="pd-panel-heading">
            <div><h2 id="pdScheduledHeading">Scheduled appointments</h2><p>Your upcoming visits with a set time</p></div>
            <a class="pd-text-link" href="<?= HQ_BASE_URL ?>/physician/consultations.php">View all</a>
          </div>
          <?php if ($scheduledAppointments): ?>
            <div class="pd-table-scroll" tabindex="0" role="region" aria-label="Scheduled appointments table">
              <table class="pd-table">
                <thead><tr><th scope="col">Patient</th><th scope="col">Date &amp; time</th><th scope="col">Concern</th><th scope="col">Status</th><th scope="col">Action</th></tr></thead>
                <tbody>
                <?php foreach ($scheduledAppointments as $appointment): ?>
                  <tr>
                    <td><div class="pd-patient"><span class="pd-avatar" aria-hidden="true"><?= $pdInitials($appointment) ?></span><div><strong><?= htmlspecialchars($appointment['FirstName'] . ' ' . $appointment['LastName']) ?></strong><small><?= htmlspecialchars($appointment['ClinicName']) ?></small></div></div></td>
                    <td><time datetime="<?= htmlspecialchars($appointment['AppointmentDate'] . 'T' . $appointment['AppointmentTime']) ?>"><?= date('M j, g:i A', strtotime($appointment['AppointmentDate'] . ' ' . $appointment['AppointmentTime'])) ?></time></td>
                    <td><span class="pd-concern-preview"><?= htmlspecialchars($appointment['Concern'] ?: 'Not provided') ?></span></td>
                    <td><span class="status-badge status-<?= strtolower(htmlspecialchars($appointment['Status'])) ?>"><?= htmlspecialchars($appointment['Status']) ?></span></td>
                    <td><div class="pd-row-actions">
                      <?php if ($appointment['Concern']): ?><button type="button" class="pd-text-button view-concern-btn" data-target="scheduled-concern-<?= (int) $appointment['AppointmentID'] ?>" aria-expanded="false" aria-controls="scheduled-concern-<?= (int) $appointment['AppointmentID'] ?>">View concern</button><?php endif; ?>
                      <?php if ($appointment['Status'] === 'Confirmed'): ?>
                        <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><input type="hidden" name="form_type" value="update_appointment_status"><input type="hidden" name="appointment_id" value="<?= (int) $appointment['AppointmentID'] ?>"><input type="hidden" name="status" value="Cancelled"><button class="pd-text-button pd-cancel" onclick="return confirm('Cancel this appointment?');">Cancel</button></form>
                      <?php endif; ?>
                    </div></td>
                  </tr>
                  <?php if ($appointment['Concern']): ?>
                    <tr id="scheduled-concern-<?= (int) $appointment['AppointmentID'] ?>" hidden><td colspan="5"><div class="pd-concern-detail"><strong>Concern summary</strong><p><?= htmlspecialchars(summarizeTranscript($appointment['Concern'])) ?></p></div></td></tr>
                  <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <div class="pd-empty"><h3>No scheduled appointments</h3><p>Appointments with a specific time will appear here.</p></div>
          <?php endif; ?>
        </section>

        <section class="pd-panel" aria-labelledby="pdQueueHeading">
          <div class="pd-panel-heading"><div><h2 id="pdQueueHeading">Queue appointments</h2><p>Bookings without a set time</p></div></div>
          <?php if ($upcoming): ?>
            <div class="pd-queue-list">
            <?php foreach ($upcoming as $appointment): ?>
              <article class="pd-queue-row">
                <div class="pd-patient"><span class="pd-avatar" aria-hidden="true"><?= $pdInitials($appointment) ?></span><div><h3><?= htmlspecialchars($appointment['FirstName'] . ' ' . $appointment['LastName']) ?></h3><small><?= date('M j, Y', strtotime($appointment['AppointmentDate'])) ?> &middot; <?= htmlspecialchars($appointment['ClinicName']) ?></small></div></div>
                <span class="status-badge status-<?= strtolower(htmlspecialchars($appointment['Status'])) ?>"><?= htmlspecialchars($appointment['Status']) ?></span>
                <div class="pd-row-actions pd-queue-actions">
                  <?php if ($appointment['Status'] === 'Confirmed'): ?>
                    <a href="<?= HQ_BASE_URL ?>/physician/consultation.php?appointment_id=<?= (int) $appointment['AppointmentID'] ?>" class="btn btn-primary btn-sm">Open consultation</a>
                    <a href="<?= HQ_BASE_URL ?>/physician/consultation.php?appointment_id=<?= (int) $appointment['AppointmentID'] ?>#ai-transcriber" class="btn btn-outline btn-sm">AI transcriber</a>
                    <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><input type="hidden" name="form_type" value="update_appointment_status"><input type="hidden" name="appointment_id" value="<?= (int) $appointment['AppointmentID'] ?>"><input type="hidden" name="status" value="NoShow"><button class="pd-text-button" onclick="return confirm('Mark this patient as a no-show?');">Mark no-show</button></form>
                  <?php else: ?><span class="pd-awaiting">Awaiting front-desk confirmation</span><?php endif; ?>
                  <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><input type="hidden" name="form_type" value="update_appointment_status"><input type="hidden" name="appointment_id" value="<?= (int) $appointment['AppointmentID'] ?>"><input type="hidden" name="status" value="Cancelled"><button class="pd-text-button pd-cancel" onclick="return confirm('Cancel this appointment?');">Cancel</button></form>
                </div>
                <?php if ($appointment['Concern']): ?>
                  <details class="pd-queue-concern"><summary>View concern summary</summary><strong>Concern summary</strong><p><?= htmlspecialchars(summarizeTranscript($appointment['Concern'])) ?></p></details>
                <?php endif; ?>
              </article>
            <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="pd-empty"><h3>No queue appointments</h3><p>Appointments without a specific time will appear here.</p></div>
          <?php endif; ?>
        </section>
      </div>

      <aside class="pd-side-column" aria-label="Earnings and availability">
        <section class="pd-panel" aria-labelledby="pdEarningsHeading">
          <div class="pd-panel-heading"><div><h2 id="pdEarningsHeading">Recent earnings</h2><p>Physician share from paid bookings</p></div><a class="text-link" href="<?= HQ_BASE_URL ?>/physician/withdrawals.php">View withdrawals</a></div>
          <?php if ($recentEarnings): ?>
            <ul class="pd-earnings-list">
            <?php foreach ($recentEarnings as $earning): ?>
              <li><div><strong><?= htmlspecialchars($earning['FirstName'] . ' ' . $earning['LastName']) ?></strong><small><?= htmlspecialchars($earning['ClinicName']) ?></small></div><time datetime="<?= date('Y-m-d', strtotime($earning['PaidAt'])) ?>"><?= date('M j, Y', strtotime($earning['PaidAt'])) ?></time><strong class="pd-earning-amount">PHP <?= number_format((float) $earning['PhysicianRevenue'], 2) ?></strong></li>
            <?php endforeach; ?>
            </ul>
          <?php else: ?><div class="pd-empty"><h3>No earnings yet</h3><p>Paid consultations will appear here.</p></div><?php endif; ?>
        </section>
        <section class="pd-availability-card" aria-labelledby="pdAvailabilityHeading">
          <span class="pd-availability-icon"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><?= $pdCalendar ?></svg></span>
          <div><h2 id="pdAvailabilityHeading">Manage your availability</h2><p>Keep your clinic schedule up to date.</p><a class="pd-text-link" href="<?= HQ_BASE_URL ?>/physician/availability.php">Update availability <span aria-hidden="true">&rarr;</span></a></div>
        </section>
      </aside>
    </div>
  </div>
</main>
<script>
const availabilityDropdown = document.querySelector('.pd-status-dropdown');
if (availabilityDropdown) {
  document.addEventListener('click', event => {
    if (!availabilityDropdown.contains(event.target)) availabilityDropdown.open = false;
  });
  availabilityDropdown.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
      availabilityDropdown.open = false;
      availabilityDropdown.querySelector('summary').focus();
    }
  });
  availabilityDropdown.addEventListener('focusout', event => {
    if (!availabilityDropdown.contains(event.relatedTarget)) availabilityDropdown.open = false;
  });
}
document.querySelectorAll('.physician-dashboard .view-concern-btn').forEach(button => {
  button.addEventListener('click', () => {
    const panel = document.getElementById(button.dataset.target);
    if (!panel) return;
    panel.hidden = !panel.hidden;
    button.setAttribute('aria-expanded', String(!panel.hidden));
  });
});
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
