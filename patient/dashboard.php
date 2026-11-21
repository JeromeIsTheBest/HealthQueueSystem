<?php
require_once __DIR__.'/../includes/clinic-location.php';
require_once __DIR__.'/../includes/clinic-fees.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/queue-actions.php';
require_once __DIR__ . '/../includes/announcements.php';

define('HQ_BASE_URL', '..');
requireRole(['Patient']);

$user = currentUser();
$pdo = getDbConnection();
$appointments = [];
$clinics = [];
$records = [];
$recordCount = 0;
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

        $clinics = $pdo->query("SELECT ClinicID, ClinicName, Address, Latitude, Longitude, BaseConsultationFee, PhotoUrl, Specialties, OpenDays, OpenTime, CloseTime FROM Clinic WHERE archived = 0 AND Status = 'Active' ORDER BY ClinicName")->fetchAll();
        $clinics=clinicFeeRows($pdo,$clinics);
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
            $progress=patientQueueProgress($pdo,(int)$queueEntry['QueueID']);$queueEntry['AheadCount']=$progress['Ahead'];$queueEntry['NowServing']=$progress['NowServing'];$queueEntry['GraceHeld']=$progress['GraceHeld'];$queueEntry['GraceDeadline']=$progress['GraceDeadline'];
            $queueEntry['EstimatedWaitMinutes'] = $queueEntry['Status'] === 'Waiting' ? $progress['EstimatedWaitMinutes'] : 0;
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
$isClinicNearbyPage=true;
$pageTitle = 'Patient Dashboard — HealthQueue';
$appointmentCount = (int) ($appointmentCount ?? count($appointments));
$primaryQueue = $queueEntries[0] ?? null;
$nextAppointment = null;
foreach ($appointments as $upcomingVisit) {
    if ($primaryQueue && (int)$upcomingVisit['AppointmentID'] === (int)$primaryQueue['AppointmentID']) continue;
    $nextAppointment = $upcomingVisit;
    break;
}
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
    <article class="pd-stat-card pd-stat-queue<?= !$primaryQueue ? ' pd-queue-empty' : '' ?>"><span class="pd-stat-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span><div class="pd-stat-content"><span>Live Queue</span>
      <?php if ($primaryQueue): $queueNumber = $primaryQueue['ScheduledNumber'] !== null ? 'S-' . (int) $primaryQueue['ScheduledNumber'] : 'A-' . str_pad((string) (int) ($primaryQueue['RegularNumber'] ?? $primaryQueue['QueueNumber']), 3, '0', STR_PAD_LEFT); ?>
        <strong><?= htmlspecialchars($queueNumber) ?></strong><small><?= !empty($primaryQueue['GraceHeld']) ? 'Awaiting arrival confirmation from the clinic' : ($primaryQueue['Status'] === 'Waiting' ? (int) $primaryQueue['AheadCount'] . ' patients ahead · ~' . (int) $primaryQueue['EstimatedWaitMinutes'] . ' min' : ($primaryQueue['Status'] === 'Calling' ? 'Please proceed to the counter' : 'You are being seen')) ?></small><?php if ($primaryQueue['Status'] === 'Waiting' && empty($primaryQueue['GraceHeld'])): ?><span class="pd-queue-progress"><i style="width:<?= max(12, min(100, (int) (100 / ((int) $primaryQueue['AheadCount'] + 1)))) ?>%"></i></span><?php endif; ?>
      <?php else: ?><strong class="pd-queue-desktop-empty">&mdash;</strong><small class="pd-queue-desktop-empty">No active queue today</small><?php endif; ?>
      </div><div class="pd-queue-current"><span>Now serving</span><strong><?= $primaryQueue && $primaryQueue['NowServing'] ? '#' . (int) $primaryQueue['NowServing'] : '—' ?></strong><a href="<?= HQ_BASE_URL ?>/patient/queue-status.php">View live queue</a></div>
      <?php if (!$primaryQueue): ?>
      <div class="pd-queue-empty-content">
        <svg class="pd-queue-calendar" width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="3"/><path d="M16 3v4M8 3v4M3 11h18M8 15h.01M12 15h.01M16 15h.01M8 18h.01M12 18h.01"/></svg>
        <h2>No active queue</h2>
        <p><?= $dataError ? 'Queue information is temporarily unavailable. Please try again.' : ($nextAppointment ? 'View your upcoming visit in My Appointments. Your queue will appear once it is ready.' : 'Book a visit to join your clinic&rsquo;s queue.') ?></p>
        <?php if (!$dataError && !$nextAppointment): ?>
          <a class="btn btn-primary pd-queue-book" href="<?= HQ_BASE_URL ?>/patient/clinics.php"><svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18M12 14v5M9.5 16.5h5"/></svg>Book an Appointment</a>
        <?php elseif (!$dataError): ?>
          <a class="btn btn-outline pd-queue-book" href="<?= HQ_BASE_URL ?>/patient/my-appointments.php">View my appointment</a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </article>
    <a class="pd-stat-card pd-stat-upcoming" href="<?= HQ_BASE_URL ?>/patient/my-appointments.php"><span class="pd-stat-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18M8 15h2M14 15h2M8 18h2"/></svg></span><div class="pd-stat-content"><span>Upcoming Appointments</span><strong><?= (int) $appointmentCount ?></strong><small><?= $nextAppointment ? 'Next: ' . date('M j', strtotime($nextAppointment['AppointmentDate'])) . ($nextAppointment['AppointmentTime'] ? ' · ' . date('g:i A', strtotime($nextAppointment['AppointmentTime'])) : '') : ($primaryQueue ? 'Current visit is in Live Queue' : 'No upcoming visits') ?></small><em>View all</em></div></a>
    <a class="pd-stat-card pd-stat-records" href="<?= HQ_BASE_URL ?>/patient/medical-records.php"><span class="pd-stat-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="M8 13h8M8 17h6"/></svg></span><div class="pd-stat-content"><span>Medical Records</span><strong><?= (int) $recordCount ?></strong><small>Completed visits</small><em>View all</em></div></a>
  </section>

  <section class="patient-dash-main-grid pd-main-single-column">
    <div class="patient-dash-primary-column">
      <article class="pd-panel pd-clinics-panel" data-nearby-root><div class="pd-panel-heading"><div><h2><span class="pd-care-desktop">Browse Nearby Clinics</span><span class="pd-care-mobile">Care near you</span></h2><p><span class="pd-care-desktop">Find participating clinics and available physicians.</span><span class="pd-care-mobile">Find a clinic for your next visit.</span></p></div><a href="<?= HQ_BASE_URL ?>/patient/clinics.php">View all clinics</a></div><?php renderClinicLocationControls(); ?><form class="pd-clinic-search" method="get" action="<?= HQ_BASE_URL ?>/patient/clinics.php"><label for="dashboardClinicSearch">Search clinic, location, or specialty</label><input id="dashboardClinicSearch" name="q" type="search" placeholder="Search clinics, areas, or specialties"><button type="submit" aria-label="Search clinics"><svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/></svg></button></form><div class="pd-clinic-grid pd-care-desktop" data-nearby-list>
        <?php foreach ($clinics as $clinic): ?><article class="pd-clinic-card" <?= clinicNearbyAttributes($clinic) ?>><?php if (!empty($clinic['PhotoUrl'])): ?><img src="<?= HQ_BASE_URL ?>/assets/uploads/clinics/<?= htmlspecialchars($clinic['PhotoUrl']) ?>" alt="" loading="lazy"><?php else: ?><div class="pd-clinic-placeholder">+</div><?php endif; ?><div class="pd-clinic-card-content"><strong><?= htmlspecialchars($clinic['ClinicName']) ?></strong><span>⌖ &nbsp;<?= htmlspecialchars($clinic['Address']) ?></span><small>From PHP <?= htmlspecialchars(number_format((float) $clinic['BaseConsultationFee'], 2)) ?></small><span class="nearby-distance" data-nearby-distance hidden></span><div class="nearby-card-actions"><a href="<?= HQ_BASE_URL ?>/patient/clinic-detail.php?clinic_id=<?= (int)$clinic['ClinicID'] ?>">View clinic</a><a class="nearby-directions" href="<?= htmlspecialchars(clinicDirectionsUrl($clinic),ENT_QUOTES) ?>" target="_blank" rel="noopener noreferrer">Get directions</a></div></div></article><?php endforeach; ?>
        <?php if (!$clinics): ?><p class="pd-empty-inline">No participating clinics are listed right now.</p><?php endif; ?>
      </div>
      <div class="pd-care-mobile pd-care-content">
        <?php if ($clinics): ?>
        <div class="pd-care-carousel" id="nearbyClinicCarousel" data-nearby-list role="region" aria-label="Participating clinics" tabindex="0">
          <?php foreach ($clinics as $clinic):
            $openToday = in_array((string)date('N'), array_map('trim',explode(',',(string)$clinic['OpenDays'])),true) && $clinic['OpenTime'] < $clinic['CloseTime'];
            $openNow = $openToday && date('H:i:s') >= $clinic['OpenTime'] && date('H:i:s') < $clinic['CloseTime'];
          ?>
          <article class="pd-care-card" <?= clinicNearbyAttributes($clinic) ?>>
            <div class="pd-care-photo">
              <?php if (!empty($clinic['PhotoUrl'])): ?><img src="<?= HQ_BASE_URL ?>/assets/uploads/clinics/<?= htmlspecialchars($clinic['PhotoUrl']) ?>" alt="" loading="lazy">
              <?php else: ?><div class="pd-care-photo-placeholder" aria-hidden="true"><svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><path d="M4 21V6h16v15M2 21h20M9 21v-5h6v5M12 3v7M8.5 6.5h7M8 13h.01M16 13h.01"/></svg></div><?php endif; ?>
              <span class="pd-care-hours<?= $openNow ? ' is-open' : '' ?>"><?= $openNow ? 'Open now' : 'Closed now' ?></span>
            </div>
            <div class="pd-care-body">
              <h3><?= htmlspecialchars($clinic['ClinicName']) ?></h3><span class="nearby-distance" data-nearby-distance hidden></span>
              <p class="pd-care-location"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/></svg><?= htmlspecialchars($clinic['Address']) ?></p>
              <?php if (trim((string)$clinic['Specialties']) !== ''): ?><p class="pd-care-specialty"><?= htmlspecialchars($clinic['Specialties']) ?></p><?php endif; ?>
              <div class="pd-care-price"><span>Consultation from</span><strong>PHP <?= number_format((float)$clinic['BaseConsultationFee'],2) ?></strong></div>
              <div class="nearby-card-actions"><a class="btn btn-primary pd-care-view" href="<?= HQ_BASE_URL ?>/patient/clinic-detail.php?clinic_id=<?= (int)$clinic['ClinicID'] ?>">View clinic</a><a class="nearby-directions" href="<?= htmlspecialchars(clinicDirectionsUrl($clinic),ENT_QUOTES) ?>" target="_blank" rel="noopener noreferrer">Get directions</a></div>
            </div>
          </article>
          <?php endforeach; ?>
        </div>
        <?php if (count($clinics)>1): ?>
        <div class="pd-care-pagination" aria-label="Choose clinic card"><?php foreach ($clinics as $position=>$clinic): ?><button type="button" data-clinic-slide="<?= $position ?>" aria-label="Show <?= htmlspecialchars($clinic['ClinicName']) ?>"<?= $position===0?' aria-current="true"':'' ?>></button><?php endforeach; ?></div>
        <p class="pd-care-hint">Swipe to explore clinics</p>
        <?php endif; ?>
        <div class="pd-care-help"><svg width="27" height="27" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18M8 15h2M14 15h2"/></svg><div><strong>Ready for your next visit?</strong><p>Choose a clinic to see physicians and available schedules.</p></div></div>
        <?php else: ?><p class="pd-empty-inline">No participating clinics are listed right now.</p><?php endif; ?>
      </div></article>
    </div>
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



<script>
(function () {
  var carousel = document.getElementById('nearbyClinicCarousel');
  if (!carousel) return;
  var cards = Array.from(carousel.querySelectorAll('.pd-care-card'));
  var dots = Array.from(document.querySelectorAll('[data-clinic-slide]'));
  var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  carousel.addEventListener('hq:clinics-sorted', function () { cards = Array.from(carousel.querySelectorAll('.pd-care-card')); dots.forEach(function (dot,index) { dot.setAttribute('aria-label','Show '+cards[index].querySelector('h3').textContent);if(index===0)dot.setAttribute('aria-current','true');else dot.removeAttribute('aria-current'); }); });
  dots.forEach(function (dot, index) {
    dot.addEventListener('click', function () {
      carousel.scrollTo({left: cards[index].offsetLeft - cards[0].offsetLeft, behavior: reducedMotion.matches ? 'auto' : 'smooth'});
    });
  });
  var frame = 0;
  carousel.addEventListener('scroll', function () {
    if (frame) return;
    frame = requestAnimationFrame(function () {
      var nearest = 0, distance = Infinity;
      cards.forEach(function (card, index) {
        var delta = Math.abs(card.offsetLeft - cards[0].offsetLeft - carousel.scrollLeft);
        if (delta < distance) {distance = delta; nearest = index;}
      });
      dots.forEach(function (dot, index) {if (index === nearest) dot.setAttribute('aria-current','true'); else dot.removeAttribute('aria-current');});
      frame = 0;
    });
  }, {passive:true});
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
