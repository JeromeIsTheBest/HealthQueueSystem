<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/announcements.php';

define('HQ_BASE_URL', '..');
requireRole(['Patient']);

$user = currentUser();
$pdo = getDbConnection();
$appointments = [];
$clinics = [];
$records = [];
$recordCount = 0;
$recentNotifications = [];
$unreadCount = 0;
$unpaidCount = 0;
$queueEntries = [];
$announcements = [];
$minutesPerPatient = 15;
$dataError = null;

if ($pdo) {
    try {
        $stmt = $pdo->prepare("SELECT a.AppointmentID, a.AppointmentDate, a.AppointmentTime, a.Status, a.BookingFeePaid, c.ClinicName, c.Address, phy.FirstName AS PhyFirstName, phy.LastName AS PhyLastName, phy.ProfilePhoto AS PhyProfilePhoto FROM Appointments a JOIN Clinic c ON c.ClinicID = a.ClinicID LEFT JOIN Users phy ON phy.UserID = a.PhysicianID WHERE a.PatientID = ? AND a.AppointmentDate >= CURDATE() AND a.Status IN ('Pending', 'Confirmed') ORDER BY a.AppointmentDate, a.AppointmentTime LIMIT 5");
        $stmt->execute([$user['UserID']]);
        $appointments = $stmt->fetchAll();
        $appointmentCountStmt = $pdo->prepare("SELECT COUNT(*) FROM Appointments WHERE PatientID = ? AND AppointmentDate >= CURDATE() AND Status IN ('Pending', 'Confirmed')");
        $appointmentCountStmt->execute([$user['UserID']]);
        $appointmentCount = (int) $appointmentCountStmt->fetchColumn();

        $clinics = $pdo->query("SELECT ClinicID, ClinicName, Address, BaseConsultationFee, PhotoUrl FROM Clinic WHERE archived = 0 AND Status = 'Active' ORDER BY ClinicName LIMIT 6")->fetchAll();
        foreach ($clinics as &$clinicRow) {
            $photoName = basename((string) ($clinicRow['PhotoUrl'] ?? ''));
            $clinicRow['PhotoUrl'] = $photoName !== '' && is_file(__DIR__ . '/../assets/uploads/clinics/' . $photoName) ? $photoName : null;
        }
        unset($clinicRow);

        $unpaidStmt = $pdo->prepare("SELECT COUNT(*) FROM Appointments WHERE PatientID = ? AND Status = 'Pending' AND BookingFeePaid = 0");
        $unpaidStmt->execute([$user['UserID']]);
        $unpaidCount = (int) $unpaidStmt->fetchColumn();

        $recordsStmt = $pdo->prepare(
            "SELECT a.AppointmentID, a.AppointmentDate, c.ClinicName, u.FirstName, u.LastName
             FROM Appointments a
             JOIN Clinic c ON c.ClinicID = a.ClinicID
             JOIN Users u ON u.UserID = a.PhysicianID
             WHERE a.PatientID = ? AND a.Status = 'Completed'
             ORDER BY a.AppointmentDate DESC LIMIT 5"
        );
        $recordsStmt->execute([$user['UserID']]);
        $records = $recordsStmt->fetchAll();

        $recordCountStmt = $pdo->prepare("SELECT COUNT(*) FROM Appointments WHERE PatientID = ? AND Status = 'Completed'");
        $recordCountStmt->execute([$user['UserID']]);
        $recordCount = (int) $recordCountStmt->fetchColumn();

        $notificationsStmt = $pdo->prepare('SELECT NotificationID, Message, IsRead, CreatedAt FROM PatientNotifications WHERE PatientID = ? ORDER BY CreatedAt DESC LIMIT 4');
        $notificationsStmt->execute([$user['UserID']]);
        $recentNotifications = $notificationsStmt->fetchAll();
        $unreadStmt = $pdo->prepare('SELECT COUNT(*) FROM PatientNotifications WHERE PatientID = ? AND IsRead = 0');
        $unreadStmt->execute([$user['UserID']]);
        $unreadCount = (int) $unreadStmt->fetchColumn();

        $queueStmt = $pdo->prepare(
            "SELECT q.QueueID, q.QueueNumber, q.ScheduledNumber, q.RegularNumber, q.Status, q.ClinicID, c.ClinicName, c.Address,
                    a.AppointmentID, a.AppointmentDate, a.AppointmentTime, a.Concern,
                    phy.FirstName AS PhyFirstName, phy.LastName AS PhyLastName,
                    (SELECT MAX(nowq.QueueNumber) FROM Queue nowq WHERE nowq.ClinicID = q.ClinicID AND DATE(nowq.CreatedAt) = CURDATE() AND nowq.Status IN ('Calling', 'Serving')) AS NowServing
             FROM Queue q
             JOIN Appointments a ON a.AppointmentID = q.AppointmentID
             JOIN Clinic c ON c.ClinicID = q.ClinicID
             LEFT JOIN Users phy ON phy.UserID = q.PhysicianID
             WHERE a.PatientID = ? AND DATE(q.CreatedAt) = CURDATE() AND q.Status IN ('Waiting', 'Calling', 'Serving')
             ORDER BY q.CreatedAt DESC"
        );
        $queueStmt->execute([$user['UserID']]);
        $queueEntries = $queueStmt->fetchAll();

        foreach ($queueEntries as &$queueEntry) {
            $posStmt = $pdo->prepare(
                "SELECT COUNT(*) FROM Queue WHERE ClinicID = ? AND DATE(CreatedAt) = CURDATE() AND Status = 'Waiting' AND COALESCE(Position, QueueNumber * 10) < (SELECT COALESCE(me.Position, me.QueueNumber * 10) FROM Queue me WHERE me.ClinicID = Queue.ClinicID AND DATE(me.CreatedAt) = CURDATE() AND me.QueueNumber = ? ORDER BY me.QueueID DESC LIMIT 1)"
            );
            $posStmt->execute([$queueEntry['ClinicID'], $queueEntry['QueueNumber']]);
            $queueEntry['AheadCount'] = (int) $posStmt->fetchColumn();
            $queueEntry['EstimatedWaitMinutes'] = $queueEntry['Status'] === 'Waiting' ? $queueEntry['AheadCount'] * $minutesPerPatient : 0;
        }
        unset($queueEntry);

        $annStmt = $pdo->prepare(
            "SELECT a.AnnouncementID, a.Title, a.Category, a.Message, a.PhotoPath, a.AffectsDate, a.CreatedAt, c.ClinicName
             FROM Announcements a LEFT JOIN Clinic c ON c.ClinicID = a.ClinicID
             WHERE a.Audience IN ('Everyone', 'Patients')
               AND NOT EXISTS (SELECT 1 FROM AnnouncementDismissals d WHERE d.UserID = ? AND d.AnnouncementID = a.AnnouncementID)
             ORDER BY a.CreatedAt DESC LIMIT 3"
        );
        $annStmt->execute([$user['UserID']]);
        $announcements = $annStmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Patient dashboard failed: ' . $e->getMessage());
        $dataError = 'Your appointment information is temporarily unavailable.';
    }
} else {
    $dataError = 'Your appointment information is temporarily unavailable.';
}
$pageTitle = 'Patient Dashboard — HealthQueue';
$appointmentCount = (int) ($appointmentCount ?? count($appointments));
$primaryQueue = $queueEntries[0] ?? null;
$nextAppointment = $appointments[0] ?? null;
$hour = (int) date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
require __DIR__ . '/../includes/header.php';
?>

<main class="portal-shell patient-dashboard-page"><div class="container patient-dashboard-container">
  <header class="patient-dash-welcome">
    <div><h1><?= $greeting ?>, <?= htmlspecialchars($user['FirstName']) ?>!</h1><p>Here’s your healthcare overview.</p></div>
    <span class="patient-dash-tagline">Your health, your time <span aria-hidden="true">♡</span></span>
  </header>
  <?php if (isset($_GET['dismissed'])): ?><p class="form-message success" role="status">Announcement deleted.</p><?php endif; ?>
  <?php if (isset($_GET['booked'])): ?><p class="form-message success" role="status">Your appointment request was submitted. The clinic will confirm it soon.</p><?php endif; ?>
  <?php if ($dataError): ?><p class="form-message error" role="alert"><?= htmlspecialchars($dataError) ?></p><?php endif; ?>
  <?php if ($unpaidCount > 0): ?><p class="form-message error" role="alert">You have <?= $unpaidCount ?> request(s) awaiting payment. <a href="<?= HQ_BASE_URL ?>/patient/my-appointments.php">Complete payment to send them to the clinic</a></p><?php endif; ?>

  <section class="patient-dash-stats" aria-label="Your healthcare summary">
    <article class="pd-stat-card pd-stat-queue"><span class="pd-stat-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span><div class="pd-stat-content"><span>Live Queue</span>
      <?php if ($primaryQueue): $queueNumber = $primaryQueue['ScheduledNumber'] !== null ? 'S-' . (int) $primaryQueue['ScheduledNumber'] : 'A-' . str_pad((string) (int) ($primaryQueue['RegularNumber'] ?? $primaryQueue['QueueNumber']), 3, '0', STR_PAD_LEFT); ?>
        <strong><?= htmlspecialchars($queueNumber) ?></strong><small><?= $primaryQueue['Status'] === 'Waiting' ? (int) $primaryQueue['AheadCount'] . ' patients ahead · ~' . (int) $primaryQueue['EstimatedWaitMinutes'] . ' min' : ($primaryQueue['Status'] === 'Calling' ? 'Please proceed to the counter' : 'You are being seen') ?></small><?php if ($primaryQueue['Status'] === 'Waiting'): ?><span class="pd-queue-progress"><i style="width:<?= max(12, min(100, (int) (100 / ((int) $primaryQueue['AheadCount'] + 1)))) ?>%"></i></span><?php endif; ?>
      <?php else: ?><strong>—</strong><small>No active queue today</small><?php endif; ?>
      </div><div class="pd-queue-current"><span>Now serving</span><strong><?= $primaryQueue && $primaryQueue['NowServing'] ? '#' . (int) $primaryQueue['NowServing'] : '—' ?></strong><a href="<?= HQ_BASE_URL ?>/patient/queue-status.php">View live queue</a></div>
    </article>
    <a class="pd-stat-card" href="<?= HQ_BASE_URL ?>/patient/my-appointments.php"><span class="pd-stat-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18M8 15h2M14 15h2M8 18h2"/></svg></span><div class="pd-stat-content"><span>Upcoming Appointments</span><strong><?= (int) $appointmentCount ?></strong><small><?= $nextAppointment ? 'Next: ' . date('M j', strtotime($nextAppointment['AppointmentDate'])) . ($nextAppointment['AppointmentTime'] ? ' · ' . date('g:i A', strtotime($nextAppointment['AppointmentTime'])) : '') : 'No upcoming visits' ?></small><em>View all</em></div></a>
    <a class="pd-stat-card" href="<?= HQ_BASE_URL ?>/patient/notifications.php"><span class="pd-stat-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:middle;flex-shrink:0"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg></span><div class="pd-stat-content"><span>Notifications</span><strong><?= (int) $unreadCount ?></strong><small>Unread messages</small><em>View all</em></div></a>
    <a class="pd-stat-card" href="<?= HQ_BASE_URL ?>/patient/medical-records.php"><span class="pd-stat-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="M8 13h8M8 17h6"/></svg></span><div class="pd-stat-content"><span>Medical Records</span><strong><?= (int) $recordCount ?></strong><small>Completed visits</small><em>View all</em></div></a>
  </section>

  <section class="patient-dash-main-grid">
    <div class="patient-dash-primary-column">
      <article class="pd-panel pd-next-appointment"><div class="pd-panel-heading"><h2>Your Next Appointment</h2><a href="<?= HQ_BASE_URL ?>/patient/my-appointments.php">View all</a></div>
        <?php if ($nextAppointment): ?><?php $hasSpecificTime = (bool) $nextAppointment['BookingFeePaid'] && !empty($nextAppointment['AppointmentTime']) && $nextAppointment['AppointmentTime'] !== '00:00:00'; $doctorPhoto = basename((string) ($nextAppointment['PhyProfilePhoto'] ?? '')); ?>
          <div class="pd-appointment-body"><div class="pd-appointment-avatar"><?php if ($doctorPhoto !== '' && is_file(__DIR__ . '/../assets/uploads/avatars/' . $doctorPhoto)): ?><img src="<?= HQ_BASE_URL ?>/assets/uploads/avatars/<?= htmlspecialchars($doctorPhoto) ?>" alt="Dr. <?= htmlspecialchars(($nextAppointment['PhyFirstName'] ?? '') . ' ' . ($nextAppointment['PhyLastName'] ?? '')) ?>"><?php else: ?><span aria-hidden="true">✚</span><?php endif; ?></div><div class="pd-appointment-details"><strong><?= htmlspecialchars($nextAppointment['ClinicName']) ?></strong><span><?= $nextAppointment['PhyFirstName'] ? 'Dr. ' . htmlspecialchars($nextAppointment['PhyFirstName'] . ' ' . $nextAppointment['PhyLastName']) : 'Clinic appointment' ?></span><span><?= htmlspecialchars($nextAppointment['Address']) ?></span><span class="pd-appointment-time">◷ &nbsp;<?= htmlspecialchars(date('M j, Y', strtotime($nextAppointment['AppointmentDate']))) ?><?= $hasSpecificTime ? ' · ' . htmlspecialchars(date('g:i A', strtotime($nextAppointment['AppointmentTime']))) : ' · Queue booking' ?></span><span class="pd-status-pill"><?= htmlspecialchars($nextAppointment['Status']) ?></span><div class="pd-appointment-actions"><a class="btn btn-primary btn-sm" href="<?= HQ_BASE_URL ?>/patient/my-appointments.php">View details</a><a class="btn btn-outline btn-sm" href="<?= HQ_BASE_URL ?>/patient/my-appointments.php">Manage</a></div></div></div>
        <?php else: ?><div class="pd-empty-inline"><strong>No upcoming appointment</strong><span>Browse clinics and request a visit when you’re ready.</span><a href="<?= HQ_BASE_URL ?>/patient/clinics.php">Find a clinic</a></div><?php endif; ?>
      </article>
      <article class="pd-panel pd-clinics-panel"><div class="pd-panel-heading"><div><h2>Browse Nearby Clinics</h2><p>Find participating clinics and available physicians.</p></div><a href="<?= HQ_BASE_URL ?>/patient/clinics.php">View all clinics</a></div><form class="pd-clinic-search" method="get" action="<?= HQ_BASE_URL ?>/patient/clinics.php"><label for="dashboardClinicSearch">Search clinic, location, or specialty</label><input id="dashboardClinicSearch" name="q" type="search" placeholder="Search clinics, areas, or specialties"><button type="submit" aria-label="Search clinics">⌕</button></form><div class="pd-clinic-grid">
        <?php foreach (array_slice($clinics, 0, 3) as $clinic): ?><a class="pd-clinic-card" href="<?= HQ_BASE_URL ?>/patient/clinic-detail.php?clinic_id=<?= (int) $clinic['ClinicID'] ?>"><?php if (!empty($clinic['PhotoUrl'])): ?><img src="<?= HQ_BASE_URL ?>/assets/uploads/clinics/<?= htmlspecialchars($clinic['PhotoUrl']) ?>" alt="" loading="lazy"><?php else: ?><div class="pd-clinic-placeholder">+</div><?php endif; ?><div class="pd-clinic-card-content"><strong><?= htmlspecialchars($clinic['ClinicName']) ?></strong><span>⌖ &nbsp;<?= htmlspecialchars($clinic['Address']) ?></span><small>From PHP <?= htmlspecialchars(number_format((float) $clinic['BaseConsultationFee'], 2)) ?></small><em>View clinic</em></div></a><?php endforeach; ?>
        <?php if (!$clinics): ?><p class="pd-empty-inline">No participating clinics are listed right now.</p><?php endif; ?>
      </div></article>
    </div>
    <aside class="patient-dash-side-column">
      <article class="pd-panel pd-notifications-panel"><div class="pd-panel-heading"><h2>Recent Notifications</h2><a href="<?= HQ_BASE_URL ?>/patient/notifications.php">View all</a></div>
        <?php if ($recentNotifications): ?><ul class="pd-notification-list"><?php foreach ($recentNotifications as $note): ?><li class="<?= !$note['IsRead'] ? 'is-unread' : '' ?>"><span class="pd-notification-icon" aria-hidden="true"><?= !$note['IsRead'] ? '●' : '✓' ?></span><div><strong><?= htmlspecialchars(mb_strimwidth($note['Message'], 0, 42, '…')) ?></strong><p><?= htmlspecialchars(mb_strimwidth($note['Message'], 0, 76, '…')) ?></p><time><?= htmlspecialchars(date('M j, Y · g:i A', strtotime($note['CreatedAt']))) ?></time></div></li><?php endforeach; ?></ul><?php else: ?><div class="pd-empty-inline"><strong>You’re all caught up</strong><span>Appointment and queue updates will appear here.</span></div><?php endif; ?>
      </article>
      <article class="pd-booking-prompt"><div class="pd-booking-art" aria-hidden="true"><img src="<?= HQ_BASE_URL ?>/assets/img/dashboard/doctor-booking-illustration.png" alt=""></div><div class="pd-booking-content"><h2>Need to book an appointment?</h2><p>Find a clinic, choose a doctor, and request your visit.</p><button type="button" class="btn btn-primary" data-modal-open="bookNowModal"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18M12 14v4M10 16h4"/></svg> Book an appointment</button></div></article>
    </aside>
  </section>

  <div class="patient-dash-legacy" hidden>

  <section class="portal-section">
    <div class="dashboard-top-grid">
      <div>
        <?php foreach ($queueEntries as $entry):
          $physicianLabel = $entry['PhyFirstName'] ? 'Dr. ' . $entry['PhyFirstName'] . ' ' . $entry['PhyLastName'] : 'No physician preference';
          $whenLabel = date('l, F j, Y', strtotime($entry['AppointmentDate'])) . ($entry['AppointmentTime'] ? ' at ' . date('g:i A', strtotime($entry['AppointmentTime'])) : ' · queue booking, estimated time in My Appointments');
        ?>
          <div class="queue-hero-card" role="button" tabindex="0" data-queue-detail
               data-number="<?= $entry['ScheduledNumber'] !== null ? 'S-' . (int) $entry['ScheduledNumber'] : '#' . (int) ($entry['RegularNumber'] ?? $entry['QueueNumber']) ?>"
               data-clinic="<?= htmlspecialchars($entry['ClinicName'], ENT_QUOTES) ?>"
               data-address="<?= htmlspecialchars($entry['Address'], ENT_QUOTES) ?>"
               data-status="<?= htmlspecialchars($entry['Status'], ENT_QUOTES) ?>"
               data-when="<?= htmlspecialchars($whenLabel, ENT_QUOTES) ?>"
               data-physician="<?= htmlspecialchars($physicianLabel, ENT_QUOTES) ?>"
               data-concern="<?= htmlspecialchars($entry['Concern'] ?: 'Not specified', ENT_QUOTES) ?>">
            <div class="queue-hero-top">
              <span class="queue-hero-label">Queue</span>
              <span class="queue-hero-live">Live</span>
            </div>
            <div class="queue-hero-number"><?= $entry['ScheduledNumber'] !== null ? 'S-' . (int) $entry['ScheduledNumber'] : '#' . (int) ($entry['RegularNumber'] ?? $entry['QueueNumber']) ?></div>
            <div class="queue-hero-meta">
              <?php if ($entry['Status'] === 'Waiting'): ?>
                <?= (int) $entry['AheadCount'] ?> patient(s) ahead &middot; ~<?= (int) $entry['EstimatedWaitMinutes'] ?> mins
              <?php elseif ($entry['Status'] === 'Calling'): ?>
                You're being called now — please proceed to the counter.
              <?php else: ?>
                You're currently being served.
              <?php endif; ?>
            </div>
            <div class="queue-hero-clinic"><?= htmlspecialchars($entry['ClinicName']) ?></div>
            <a href="<?= HQ_BASE_URL ?>/patient/queue-status.php" class="text-link" onclick="event.stopPropagation();">Live status</a>
          </div>
        <?php endforeach; ?>

        <?php if (!$queueEntries): ?>
          <div class="queue-empty-card">
            <span class="queue-hero-label">Queue</span>
            <h3>No queue for today</h3>
            <p>Once a clinic checks you in for a walk-in visit or a confirmed appointment today, your live position will appear here.</p>
          </div>
        <?php endif; ?>

        <div class="dash-announcements">
          <div class="dash-announcements-head">
            <span class="queue-hero-label">Announcements</span>
            <a href="<?= HQ_BASE_URL ?>/patient/announcements.php" class="text-link">View all</a>
          </div>
          <?php if ($announcements): ?>
            <ul>
              <?php foreach ($announcements as $note): ?>
                <li>
                  <a href="<?= HQ_BASE_URL ?>/patient/announcements.php?open=<?= (int) $note['AnnouncementID'] ?>" data-dash-announcement
                     data-id="<?= (int) $note['AnnouncementID'] ?>"
                     data-title="<?= htmlspecialchars($note['Title'], ENT_QUOTES) ?>"
                     data-source="<?= htmlspecialchars($note['ClinicName'] ?: 'HealthQueue', ENT_QUOTES) ?>"
                     data-category="<?= htmlspecialchars($note['Category'], ENT_QUOTES) ?>"
                     data-posted="<?= htmlspecialchars(date('l, F j, Y · g:i A', strtotime($note['CreatedAt'])), ENT_QUOTES) ?>"
                     data-affects="<?= $note['AffectsDate'] ? htmlspecialchars(date('l, F j, Y', strtotime($note['AffectsDate'])), ENT_QUOTES) : '' ?>"
                     data-photo="<?= htmlspecialchars((string) announcementPhotoUrl($note['PhotoPath']), ENT_QUOTES) ?>"
                     data-message="<?= htmlspecialchars($note['Message'], ENT_QUOTES) ?>">
                    <strong><?= htmlspecialchars($note['Title']) ?></strong>
                    <p><?= htmlspecialchars(mb_strimwidth(preg_replace('/\s+/', ' ', $note['Message']), 0, 90, '…')) ?></p>
                    <span><?= htmlspecialchars(date('M j, Y · g:i A', strtotime($note['CreatedAt']))) ?></span>
                  </a>
                  <form method="post" action="<?= HQ_BASE_URL ?>/patient/announcements.php" class="dash-ann-delete" onsubmit="return confirm('Delete this announcement? It will be removed from your list only.');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                    <input type="hidden" name="form_type" value="dismiss_announcement">
                    <input type="hidden" name="return_to" value="dashboard">
                    <input type="hidden" name="announcement_id" value="<?= (int) $note['AnnouncementID'] ?>">
                    <button type="submit" aria-label="Delete announcement" title="Delete"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg></button>
                  </form>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <p class="dash-announcements-empty">No announcements right now.</p>
          <?php endif; ?>
        </div>
      </div>

      <div>
        <div class="portal-heading"><div><span class="section-kicker">My schedule</span><h2>Upcoming appointments</h2></div><a href="<?= HQ_BASE_URL ?>/patient/my-appointments.php" class="text-link">View All</a></div>
        <?php if ($appointments): ?>
          <div class="appointment-list">
            <?php foreach ($appointments as $appointment): ?>
              <?php $hasSpecificTime = (bool) $appointment['BookingFeePaid'] && !empty($appointment['AppointmentTime']) && $appointment['AppointmentTime'] !== '00:00:00'; ?>
              <article class="appointment-card">
                <div class="appointment-date"><strong><?= htmlspecialchars(date('d', strtotime($appointment['AppointmentDate']))) ?></strong><span><?= htmlspecialchars(date('M', strtotime($appointment['AppointmentDate']))) ?></span></div>
                <div class="appointment-info">
                  <h3><?= htmlspecialchars($appointment['ClinicName']) ?></h3>
                  <p><?= htmlspecialchars(date('l, F j', strtotime($appointment['AppointmentDate']))) ?> · <?= $hasSpecificTime ? htmlspecialchars(date('g:i A', strtotime($appointment['AppointmentTime']))) : 'Queue booking, approximate time in My Appointments' ?></p>
                  <span><?= htmlspecialchars($appointment['Address']) ?></span>
                </div>
                <span class="status-badge status-<?= strtolower(htmlspecialchars($appointment['Status'])) ?>"><?= htmlspecialchars($appointment['Status']) ?></span>
              </article>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="empty-state"><div class="empty-icon">+</div><h3>No upcoming appointments</h3><p>Choose a partner clinic and request a schedule that works for you.</p><a href="<?= HQ_BASE_URL ?>/patient/clinics.php" class="btn btn-primary btn-sm">Find a clinic</a></div>
        <?php endif; ?>
      </div>
    </div>
  </section>
  <section class="portal-section"><div class="portal-heading"><div><span class="section-kicker">Records</span><h2>Consultation records</h2></div><a href="<?= HQ_BASE_URL ?>/patient/medical-records.php" class="text-link">View All</a></div>
  <?php if ($records): ?><div class="compact-list"><?php foreach ($records as $record): ?><article><div><strong><?= htmlspecialchars($record['ClinicName']) ?></strong><span>Dr. <?= htmlspecialchars($record['FirstName'] . ' ' . $record['LastName']) ?> &middot; <?= htmlspecialchars(date('M j, Y', strtotime($record['AppointmentDate']))) ?></span></div><div style="display:flex;gap:14px;align-items:center;"><button type="button" class="text-link" data-view-record="<?= (int) $record['AppointmentID'] ?>">View record</button><button type="button" class="text-link" data-view-record="<?= (int) $record['AppointmentID'] ?>" data-scroll-to="ai-summary">AI summary</button></div></article><?php endforeach; ?></div><?php else: ?><p class="admin-empty">No completed consultations yet. Records appear here once a visit is complete.</p><?php endif; ?></section>
  <section class="portal-section clinics-band"><div class="portal-heading"><div><span class="section-kicker">Care near you</span><h2>Browse Nearby Clinics</h2></div><a href="<?= HQ_BASE_URL ?>/patient/clinics.php" class="text-link">View all clinics</a></div><div class="portal-clinics"><?php foreach ($clinics as $clinic): ?><article class="portal-clinic-card"><?php if (!empty($clinic['PhotoUrl'])): ?><img src="<?= HQ_BASE_URL ?>/assets/uploads/clinics/<?= htmlspecialchars($clinic['PhotoUrl']) ?>" alt="" class="clinic-photo-block"><?php else: ?><div class="clinic-photo-block"></div><?php endif; ?><h3><?= htmlspecialchars($clinic['ClinicName']) ?></h3><p><?= htmlspecialchars($clinic['Address']) ?></p><div><span>From PHP <?= htmlspecialchars(number_format((float) $clinic['BaseConsultationFee'], 2)) ?></span><a href="<?= HQ_BASE_URL ?>/patient/clinic-detail.php?clinic_id=<?= (int) $clinic['ClinicID'] ?>">View</a></div></article><?php endforeach; ?></div></section>
</div></div></main>

<?php require __DIR__ . '/../includes/booking-modal.php'; ?>

<div class="modal-overlay" id="dashAnnouncementModal">
  <div class="modal-box an-modal" role="dialog" aria-modal="true" aria-labelledby="dashAnnTitle">
    <button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
    <div class="an-modal-head">
      <div>
        <span class="an-modal-source" id="dashAnnSource"></span>
        <span class="an-pill an-pill-slate" id="dashAnnCategory"></span>
      </div>
    </div>
    <img class="an-modal-photo" id="dashAnnPhoto" alt="" hidden>
    <h2 id="dashAnnTitle"></h2>
    <p class="an-modal-posted" id="dashAnnPosted"></p>
    <p class="an-modal-affects" id="dashAnnAffects" hidden></p>
    <div class="an-modal-message" id="dashAnnMessage"></div>
    <form method="post" action="<?= HQ_BASE_URL ?>/patient/announcements.php" class="an-modal-actions" onsubmit="return confirm('Delete this announcement? It will be removed from your list only.');">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
      <input type="hidden" name="form_type" value="dismiss_announcement">
      <input type="hidden" name="return_to" value="dashboard">
      <input type="hidden" name="announcement_id" id="dashAnnId" value="">
      <button type="submit" class="btn btn-outline ma-danger">Delete</button>
      <button type="button" class="btn btn-primary" data-modal-close>Close</button>
    </form>
  </div>
</div>

<div class="modal-overlay" id="queueDetailModal">
  <div class="modal-box">
    <button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
    <h2>Queue #<span id="queueDetailNumber"></span></h2>
    <p class="modal-subtitle" id="queueDetailClinic"></p>
    <div class="dev-note" style="margin-bottom:18px;">
      <strong>Status:</strong> <span id="queueDetailStatus"></span><br>
      <strong>Appointment:</strong> <span id="queueDetailWhen"></span><br>
      <strong>Physician:</strong> <span id="queueDetailPhysician"></span><br>
      <strong>Reason for visit:</strong> <span id="queueDetailConcern"></span>
    </div>
    <a href="<?= HQ_BASE_URL ?>/patient/queue-status.php" class="btn btn-primary btn-block">View Live Status</a>
  </div>
</div>

<div class="modal-overlay" id="viewRecordModal">
  <div class="modal-box modal-box-wide" style="max-height:85vh;">
    <button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
    <div id="viewRecordContent"><p class="admin-empty">Loading…</p></div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  // Dashboard announcements open in a modal (the link is a no-JS fallback).
  var annModal = document.getElementById('dashAnnouncementModal');
  var annPills = { 'Closure': 'an-pill-red', 'Schedule change': 'an-pill-amber', 'Event': 'an-pill-green', 'Health advisory': 'an-pill-blue' };
  document.querySelectorAll('[data-dash-announcement]').forEach(function (link) {
    link.addEventListener('click', function (e) {
      e.preventDefault();
      var d = link.dataset;
      document.getElementById('dashAnnSource').textContent = d.source;
      var pill = document.getElementById('dashAnnCategory');
      pill.textContent = d.category;
      pill.className = 'an-pill ' + (annPills[d.category] || 'an-pill-slate');
      var photo = document.getElementById('dashAnnPhoto');
      photo.hidden = !d.photo;
      if (d.photo) photo.src = d.photo;
      document.getElementById('dashAnnTitle').textContent = d.title;
      document.getElementById('dashAnnPosted').textContent = 'Posted ' + d.posted;
      var affects = document.getElementById('dashAnnAffects');
      affects.hidden = !d.affects;
      affects.textContent = 'Affects appointments on ' + d.affects;
      document.getElementById('dashAnnMessage').textContent = d.message;
      document.getElementById('dashAnnId').value = d.id;
      window.hqOpenModal(annModal);
    });
  });

  var queueDetailModal = document.getElementById('queueDetailModal');
  function openQueueDetail(card) {
    if (!queueDetailModal) return;
    document.getElementById('queueDetailNumber').textContent = card.getAttribute('data-number');
    document.getElementById('queueDetailClinic').textContent = card.getAttribute('data-clinic') + ' — ' + card.getAttribute('data-address');
    document.getElementById('queueDetailStatus').textContent = card.getAttribute('data-status');
    document.getElementById('queueDetailWhen').textContent = card.getAttribute('data-when');
    document.getElementById('queueDetailPhysician').textContent = card.getAttribute('data-physician');
    document.getElementById('queueDetailConcern').textContent = card.getAttribute('data-concern');
    window.hqOpenModal(queueDetailModal);
  }
  document.querySelectorAll('[data-queue-detail]').forEach(function (card) {
    card.addEventListener('click', function () { openQueueDetail(card); });
    card.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openQueueDetail(card); }
    });
  });

  var viewRecordModal = document.getElementById('viewRecordModal');
  var viewRecordContent = document.getElementById('viewRecordContent');

  function loadRecord(appointmentId, scrollTo) {
    if (!viewRecordModal || !viewRecordContent) return;
    viewRecordContent.innerHTML = '<p class="admin-empty">Loading…</p>';
    window.hqOpenModal(viewRecordModal);
    fetch('<?= HQ_BASE_URL ?>/patient/consultation.php?appointment_id=' + encodeURIComponent(appointmentId), { headers: { 'X-Requested-With': 'fetch' } })
      .then(function (res) { return res.text(); })
      .then(function (html) {
        viewRecordContent.innerHTML = html;
        if (scrollTo) {
          var target = viewRecordContent.querySelector('#' + scrollTo);
          if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
      })
      .catch(function () {
        viewRecordContent.innerHTML = '<p class="form-message error" role="alert">We could not load this record. Please try again.</p>';
      });
  }

  document.querySelectorAll('[data-view-record]').forEach(function (trigger) {
    trigger.addEventListener('click', function () {
      loadRecord(trigger.getAttribute('data-view-record'), trigger.getAttribute('data-scroll-to'));
    });
  });

  if (viewRecordContent) {
    viewRecordContent.addEventListener('submit', function (e) {
      if (!e.target.matches('.consultation-feedback-form')) return;
      e.preventDefault();
      var form = e.target;
      var formData = new FormData(form);
      fetch(form.action, { method: 'POST', body: formData, headers: { 'X-Requested-With': 'fetch' } })
        .then(function (res) { return res.text(); })
        .then(function (html) { viewRecordContent.innerHTML = html; })
        .catch(function () {
          var err = document.createElement('p');
          err.className = 'form-message error';
          err.textContent = 'We could not save your feedback. Please try again.';
          form.prepend(err);
        });
    });
  }
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
