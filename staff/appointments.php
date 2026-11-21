<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/sms.php';
require_once __DIR__ . '/../includes/wallet.php';
require_once __DIR__ . '/../includes/queue-actions.php';
require_once __DIR__ . '/../includes/demo-revenue.php';
require_once __DIR__ . '/../includes/availability.php';

define('HQ_BASE_URL', '..');
requireRole(['Staff']);

$user = currentUser();
$pdo = getDbConnection();
$errors = [];
$flash = '';
$clinicId = (int) $user['ClinicID'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!$pdo) {
        $errors[] = 'The database is temporarily unavailable.';
    } else {
        $formType = $_POST['form_type'] ?? '';
        $appointmentId = filter_input(INPUT_POST, 'appointment_id', FILTER_VALIDATE_INT);

        if (!$appointmentId) {
            $errors[] = 'Invalid appointment.';
        } elseif ($formType === 'approve_reschedule') {
            try{$requestId=filter_input(INPUT_POST,'request_id',FILTER_VALIDATE_INT);if(!$requestId)throw new RuntimeException('Invalid reschedule request.');rescheduleClinicVisit($pdo,(int)$appointmentId,$clinicId,(int)$user['UserID'],'','',(int)$requestId);$flash='Reschedule request approved.';}catch(Throwable $e){error_log('Reschedule review failed: '.$e->getMessage());$errors[]=$e instanceof PDOException?'We could not review this reschedule request.':$e->getMessage();}
        } elseif ($formType === 'decline_reschedule') {
            try{
                $requestId=filter_input(INPUT_POST,'request_id',FILTER_VALIDATE_INT);if(!$requestId)throw new RuntimeException('Invalid reschedule request.');
                $pdo->beginTransaction();lockQueueClinic($pdo,$clinicId);
                $r=$pdo->prepare("SELECT r.PatientID FROM AppointmentRescheduleRequests r JOIN Appointments a ON a.AppointmentID=r.AppointmentID WHERE r.RequestID=? AND r.AppointmentID=? AND r.ClinicID=? AND r.Status='Pending' AND a.Status IN ('Pending','Confirmed') FOR UPDATE");$r->execute([$requestId,$appointmentId,$clinicId]);$patient=$r->fetchColumn();if(!$patient)throw new RuntimeException('This reschedule request is no longer pending.');
                $pdo->prepare("UPDATE AppointmentRescheduleRequests SET Status='Declined',ReviewedBy=?,ReviewedAt=NOW() WHERE RequestID=?")->execute([$user['UserID'],$requestId]);
                notifyPatient($pdo,(int)$patient,'The clinic declined your reschedule request. Your original appointment remains active.',(int)$appointmentId);$pdo->commit();$flash='Reschedule request declined.';
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('Reschedule decline failed: '.$e->getMessage());$errors[]=$e instanceof PDOException?'We could not review this request.':$e->getMessage();}
        } elseif ($formType === 'accept_request') {
            try {
                $physicianId=filter_input(INPUT_POST,'physician_id',FILTER_VALIDATE_INT)?:null;
                $approved=approveClinicAppointment($pdo,(int)$appointmentId,$clinicId,$physicianId,(int)$user['UserID']);
                queuePatientSms($pdo,(int)$approved['PatientID'],(int)$appointmentId,'confirmed:'.$appointmentId,'confirmed',appointmentApprovalSms($approved));
                notifyPatient($pdo,(int)$approved['PatientID'],'Your appointment on '.$approved['AppointmentDate'].' has been confirmed. Your queue number is '.$approved['ticket'].'.',(int)$appointmentId);
                logActivity($pdo,(int)$user['UserID'],$clinicId,'Accepted appointment request','Appointment #'.$appointmentId.', queue '.$approved['ticket']);
                $flash='Appointment request accepted as queue '.$approved['ticket'].'.';
            } catch(PDOException $e) {error_log('Accept request failed: '.$e->getMessage());$errors[]='We could not accept this request.';}
            catch(RuntimeException $e) {$errors[]=$e->getMessage();}
        } elseif ($formType === 'reject_request') {
            // The reason is required and is shown to the patient.
            $declineReason = trim((string) ($_POST['decline_reason'] ?? ''));
            if ($declineReason === 'Other') $declineReason = trim((string) ($_POST['decline_reason_other'] ?? ''));
            $declineReason = mb_substr($declineReason, 0, 255);
            if ($declineReason === '') {
                $errors[] = 'Please provide a reason for declining this appointment.';
            } else {
            try {
                $pdo->beginTransaction();
                    lockQueueClinic($pdo,$clinicId);
                $lookup = $pdo->prepare(
                    "SELECT a.PatientID, a.BookingFeePaid, c.ClinicName, COALESCE(a.BookingFeeAmount,c.BaseConsultationFee) AS BaseConsultationFee
                     FROM Appointments a JOIN Clinic c ON c.ClinicID = a.ClinicID
                     WHERE a.AppointmentID = ? AND a.ClinicID = ? AND a.Status = 'Pending' FOR UPDATE"
                );
                $lookup->execute([$appointmentId, $clinicId]);
                $target = $lookup->fetch();

                if (!$target) {
                    $pdo->rollBack();
                    $errors[] = 'That request could not be found.';
                } else {
                    $pdo->prepare("UPDATE Appointments SET Status = 'Cancelled', DeclineReason = ? WHERE AppointmentID = ? AND ClinicID = ?")
                        ->execute([$declineReason !== '' ? $declineReason : 'Declined by the clinic', $appointmentId, $clinicId]);
                    // A paid booking fee goes back to the patient's wallet.
                    $refund = $target['BookingFeePaid'] ? walletBookingAmount($pdo,(int)$target['PatientID'],(int)$appointmentId) : 0.0;
                    if ($refund > 0) {
                        walletRefund($pdo, (int) $target['PatientID'], $refund, $appointmentId, 'Refund for declined request at ' . $target['ClinicName']);
                    }
                    $pdo->commit();

                    logActivity($pdo, $user['UserID'], $clinicId, 'Declined appointment request', "Appointment #{$appointmentId}" . ($declineReason !== '' ? " ({$declineReason})" : ''));
                    notifyPatient(
                        $pdo,
                        (int) $target['PatientID'],
                        'Your appointment request was declined by the clinic' . ($declineReason !== '' ? ": {$declineReason}." : '.')
                            . ($refund > 0 ? ' PHP ' . number_format($refund, 2) . ' was refunded to your wallet.' : ''),
                        $appointmentId
                    );
                    $flash = 'Request declined' . ($refund > 0 ? ' and ₱' . number_format($refund) . ' refunded to the patient.' : '.');
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Reject request failed: ' . $e->getMessage());
                $errors[] = 'We could not decline this request.';
            }
            }
        } elseif ($formType === 'reschedule_request') {
            try{rescheduleClinicVisit($pdo,(int)$appointmentId,$clinicId,(int)$user['UserID'],trim((string)($_POST['appointment_date']??'')),trim((string)($_POST['appointment_time']??'')));$flash='Appointment rescheduled.';}
            catch(Throwable $e){error_log('Reschedule failed: '.$e->getMessage());$errors[]=$e instanceof PDOException?'We could not reschedule this appointment.':$e->getMessage();}
        } elseif ($formType === 'confirm_complete') {
            try{$changed=completeClinicVisit($pdo,(int)$appointmentId,$clinicId,(int)$user['UserID'],($_POST['attendance_verified']??'')==='1',(string)($_POST['resolution']??''));$flash=$changed?'Consultation confirmed as complete.':'This consultation is already complete.';}
            catch(Throwable $e){error_log('Completion failed: '.$e->getMessage());$errors[]=$e instanceof PDOException?'We could not complete this consultation.':$e->getMessage();}
        } elseif ($formType === 'mark_no_show') {
            try{markClinicNoShow($pdo,(int)$appointmentId,$clinicId,(int)$user['UserID']);$flash='Appointment marked as no-show. Any paid booking fee is retained.';}
            catch(Throwable $e){error_log('No-show failed: '.$e->getMessage());$errors[]=$e instanceof PDOException?'We could not update this visit.':$e->getMessage();}
        }
    }

    // Approve/Decline buttons on the staff dashboard post here and go back.
    if (($_POST['return_to'] ?? '') === 'dashboard') {
        $_SESSION['dashboard_flash'] = $errors ? ['error', implode(' ', $errors)] : ['success', $flash];
        header('Location: ' . HQ_BASE_URL . '/staff/dashboard.php');
        exit;
    }
}

$tabs = ['requests' => 'Requests', 'reschedules' => 'Reschedule requests', 'today' => 'Today', 'confirm' => 'Confirm completion', 'expired'=>'Needs review', 'all' => 'All'];
$activeTab = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'requests';
$search = trim((string) ($_GET['q'] ?? ''));
$dateFilter = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['date'] ?? '')) ? $_GET['date'] : '';
$declineReasons = ['Fully booked', 'Physician unavailable', 'Other'];

$requests = [];
$rescheduleRequests = [];
$todayList = [];
$pendingConfirmations = [];
$expiredList=[];
$allList = [];
$physicians = [];
$physicianSchedule = [];
$tabCounts = array_fill_keys(array_keys($tabs), 0);
$today = null;
$dataError = null;

if ($pdo && $clinicId) {
    try {
        $today = (string) $pdo->query('SELECT CURDATE()')->fetchColumn();
        $listDate = $dateFilter ?: $today;

        // Shared search/date filters for the Requests and All lists.
        $filterSql = '';
        $filterParams = [];
        if ($search !== '') {
            $filterSql .= " AND CONCAT(pat.FirstName, ' ', pat.LastName) LIKE ?";
            $filterParams[] = '%' . $search . '%';
        }
        if ($dateFilter !== '') {
            $filterSql .= ' AND a.AppointmentDate = ?';
            $filterParams[] = $dateFilter;
        }

        $stmt = $pdo->prepare(
            "SELECT a.AppointmentID, a.AppointmentDate, a.AppointmentTime, a.Concern, a.PhysicianID, a.BookingFeePaid,
                    TIMESTAMPDIFF(MINUTE, a.CreatedAt, NOW()) AS MinutesAgo,
                    pat.UserID AS PatientUserID, pat.FirstName, pat.LastName, pat.Email, pat.ContactNumber,
                    COALESCE(a.BookingFeeAmount,c.BaseConsultationFee) AS BaseConsultationFee,
                    (SELECT CONCAT('Dr. ', ph.FirstName, ' ', ph.LastName) FROM Users ph WHERE ph.UserID = a.PhysicianID) AS PhysicianName,
                    (SELECT COUNT(*) FROM Appointments h WHERE h.PatientID = a.PatientID AND h.ClinicID = a.ClinicID) AS VisitCount
             FROM Appointments a
             JOIN Users pat ON pat.UserID = a.PatientID
             JOIN Clinic c ON c.ClinicID = a.ClinicID
             WHERE a.ClinicID = ? AND a.Status = 'Pending' AND a.BookingFeePaid = 1{$filterSql}
             ORDER BY a.AppointmentDate, a.AppointmentTime"
        );
        $stmt->execute(array_merge([$clinicId], $filterParams));
        $requests = $stmt->fetchAll();

        $rescheduleSql = '';
        $rescheduleParams = [$clinicId];
        if ($search !== '') {
            $rescheduleSql .= " AND CONCAT(pat.FirstName, ' ', pat.LastName) LIKE ?";
            $rescheduleParams[] = '%' . $search . '%';
        }
        if ($dateFilter !== '') {
            $rescheduleSql .= ' AND r.RequestedDate = ?';
            $rescheduleParams[] = $dateFilter;
        }
        $rescheduleStmt = $pdo->prepare(
            "SELECT r.RequestID, r.AppointmentID, r.PatientID, r.RequestedDate, r.RequestedTime, r.RequestedAt,
                    a.AppointmentDate AS OriginalDate, a.AppointmentTime AS OriginalTime, a.Status AS AppointmentStatus,
                    pat.FirstName, pat.LastName, pat.ContactNumber
             FROM AppointmentRescheduleRequests r
             JOIN Appointments a ON a.AppointmentID = r.AppointmentID
             JOIN Users pat ON pat.UserID = r.PatientID
             WHERE r.ClinicID = ? AND r.Status = 'Pending' AND a.Status IN ('Pending', 'Confirmed'){$rescheduleSql}
             ORDER BY r.RequestedAt"
        );
        $rescheduleStmt->execute($rescheduleParams);
        $rescheduleRequests = $rescheduleStmt->fetchAll();

        $physStmt = $pdo->prepare(
            "SELECT UserID, FirstName, LastName, AvailabilityStatus FROM Users
             WHERE ClinicID = ? AND RoleID = (SELECT RoleID FROM Roles WHERE RoleName = 'Physician') AND Status = 'Active'
             ORDER BY LastName"
        );
        $physStmt->execute([$clinicId]);
        $physicians = $physStmt->fetchAll();

        // Each physician's date-based availability, used to warn when a
        // request is assigned to someone who has no hours (or a day off) then.
        foreach ($physicians as $physician) {
            $physicianSchedule[(int) $physician['UserID']] = [
                'name'    => 'Dr. ' . $physician['LastName'],
                'status'  => $physician['AvailabilityStatus'],
                'working' => [],
                'off'     => [],
            ];
        }
        $schedStmt = $pdo->prepare(
            "SELECT pa.PhysicianID, pa.AvailDate, MAX(pa.IsDayOff) AS DayOff, SUM(pa.IsDayOff = 0) AS Blocks
             FROM PhysicianDateAvailability pa JOIN Users u ON u.UserID = pa.PhysicianID
             WHERE u.ClinicID = ? AND pa.AvailDate >= CURDATE()
             GROUP BY pa.PhysicianID, pa.AvailDate"
        );
        $schedStmt->execute([$clinicId]);
        foreach ($schedStmt->fetchAll() as $row) {
            $pid = (int) $row['PhysicianID'];
            if (!isset($physicianSchedule[$pid])) continue;
            if ((int) $row['DayOff']) $physicianSchedule[$pid]['off'][] = $row['AvailDate'];
            elseif ((int) $row['Blocks']) $physicianSchedule[$pid]['working'][] = $row['AvailDate'];
        }

        $todayStmt = $pdo->prepare(
            "SELECT a.AppointmentID, a.AppointmentTime, a.Concern, a.Status, a.BookingFeePaid,
                    pat.FirstName, pat.LastName, phy.LastName AS PhyLastName,
                    (SELECT q.QueueNumber FROM Queue q WHERE q.AppointmentID = a.AppointmentID ORDER BY q.CreatedAt DESC LIMIT 1) AS QueueNumber,
                    (SELECT q.ScheduledNumber FROM Queue q WHERE q.AppointmentID = a.AppointmentID ORDER BY q.CreatedAt DESC LIMIT 1) AS ScheduledNumber,
                    (SELECT q.RegularNumber FROM Queue q WHERE q.AppointmentID = a.AppointmentID ORDER BY q.CreatedAt DESC LIMIT 1) AS RegularNumber,
                    (SELECT q.Status FROM Queue q WHERE q.AppointmentID = a.AppointmentID ORDER BY q.CreatedAt DESC LIMIT 1) AS QueueStatus
             FROM Appointments a
             JOIN Users pat ON pat.UserID = a.PatientID
             LEFT JOIN Users phy ON phy.UserID = a.PhysicianID
             WHERE a.ClinicID = ? AND a.AppointmentDate = ? AND a.Status IN ('Confirmed', 'Completed')
             ORDER BY a.AppointmentTime"
        );
        $todayStmt->execute([$clinicId, $listDate]);
        $todayList = $todayStmt->fetchAll();

        $confirmStmt = $pdo->prepare(
            "SELECT a.AppointmentID, a.AppointmentDate, a.AppointmentTime,
                    pat.FirstName, pat.LastName, phy.LastName AS PhyLastName,
                    (SELECT cr.Status FROM VisitCompletionReviews cr WHERE cr.AppointmentID=a.AppointmentID) AS CompletionReviewStatus,
                    (SELECT cr.IssueText FROM VisitCompletionReviews cr WHERE cr.AppointmentID=a.AppointmentID) AS CompletionIssue,
                    (SELECT v.UpdatedAt FROM ConsultationVersions v
                     JOIN Consultations cons ON cons.ConsultationID = v.ConsultationID
                     WHERE cons.AppointmentID = a.AppointmentID AND v.Status = 'Finalized' AND v.VersionID=(SELECT newest.VersionID FROM ConsultationVersions newest WHERE newest.ConsultationID=cons.ConsultationID ORDER BY newest.RevisionNumber DESC,newest.VersionID DESC LIMIT 1)
                     ORDER BY v.RevisionNumber DESC LIMIT 1) AS FinalizedAt
             FROM Appointments a
             JOIN Users pat ON pat.UserID = a.PatientID
             LEFT JOIN Users phy ON phy.UserID = a.PhysicianID
             WHERE a.ClinicID = ? AND a.Status = 'Confirmed'
               AND EXISTS (
                   SELECT 1 FROM ConsultationVersions v
                   JOIN Consultations cons ON cons.ConsultationID = v.ConsultationID
                   WHERE cons.AppointmentID = a.AppointmentID AND v.Status = 'Finalized' AND v.VersionID=(SELECT newest.VersionID FROM ConsultationVersions newest WHERE newest.ConsultationID=cons.ConsultationID ORDER BY newest.RevisionNumber DESC,newest.VersionID DESC LIMIT 1)
               )
             ORDER BY a.AppointmentDate, a.AppointmentTime"
        );
        $confirmStmt->execute([$clinicId]);
        $pendingConfirmations = $confirmStmt->fetchAll();

        $allStmt = $pdo->prepare(
            "SELECT a.AppointmentID, a.AppointmentDate, a.AppointmentTime, a.Concern, a.Status, a.BookingFeePaid, a.CancellationReason, a.DeclineReason,
                    pat.FirstName, pat.LastName, phy.LastName AS PhyLastName
             FROM Appointments a
             JOIN Users pat ON pat.UserID = a.PatientID
             LEFT JOIN Users phy ON phy.UserID = a.PhysicianID
             WHERE a.ClinicID = ?{$filterSql}
             ORDER BY a.AppointmentDate DESC, a.AppointmentTime DESC
             LIMIT 100"
        );
        $allStmt->execute(array_merge([$clinicId], $filterParams));
        $allList = $allStmt->fetchAll();

        $expired=$pdo->prepare("SELECT a.AppointmentID,a.AppointmentDate,a.Status,pat.FirstName,pat.LastName,CONCAT('Dr. ',phy.FirstName,' ',phy.LastName) AS PhysicianName,(SELECT v.Status FROM Consultations c JOIN ConsultationVersions v ON v.ConsultationID=c.ConsultationID WHERE c.AppointmentID=a.AppointmentID ORDER BY v.RevisionNumber DESC,v.VersionID DESC LIMIT 1) AS LastNoteStatus,(SELECT COUNT(*) FROM Consultations c JOIN ConsultationVersions v ON v.ConsultationID=c.ConsultationID WHERE c.AppointmentID=a.AppointmentID AND v.Status='Finalized') AS HasFinalized FROM Appointments a JOIN Users pat ON pat.UserID=a.PatientID LEFT JOIN Users phy ON phy.UserID=a.PhysicianID WHERE a.ClinicID=? AND a.Status IN ('Pending','Confirmed') AND a.AppointmentDate<CURDATE() ORDER BY a.AppointmentDate LIMIT 100");$expired->execute([$clinicId]);$expiredList=$expired->fetchAll();
        $tabCounts = ['requests' => count($requests), 'reschedules' => count($rescheduleRequests), 'today' => count($todayList), 'confirm' => count($pendingConfirmations),'expired'=>count($expiredList), 'all' => count($allList)];
    } catch (PDOException $e) {
        error_log('Appointments list load failed: ' . $e->getMessage());
        $dataError = 'Appointments are temporarily unavailable.';
    }
}

$initials = static fn(array $row): string => strtoupper(mb_substr($row['FirstName'], 0, 1) . mb_substr($row['LastName'], 0, 1));
$timeAgo = static function (int $minutes): string {
    if ($minutes < 1) return 'just now';
    if ($minutes < 60) return $minutes . ' min ago';
    if ($minutes < 1440) {
        $h = intdiv($minutes, 60);
        return $h . ' hour' . ($h === 1 ? '' : 's') . ' ago';
    }
    $d = intdiv($minutes, 1440);
    return $d . ' day' . ($d === 1 ? '' : 's') . ' ago';
};
$concernLabel = static fn(?string $concern): string => mb_strimwidth(trim((string) $concern) ?: 'General', 0, 32, '…');
$statusPill = static function (array $row): array {
    if ($row['Status'] === 'Cancelled') {
        return !empty($row['DeclineReason']) || empty($row['CancellationReason']) ? ['Declined', 'ma-pill-declined'] : ['Cancelled', 'ma-pill-declined'];
    }
    return ['Pending' => ['Pending', 'ma-pill-pending'], 'Confirmed' => ['Approved', 'ma-pill-approved'], 'Completed' => ['Completed', 'ma-pill-completed'], 'NoShow' => ['No-show','ma-pill-declined']][$row['Status']] ?? [$row['Status'], 'ma-pill-completed'];
};
$tabUrl = static function (string $tab) use ($search, $dateFilter): string {
    return '?' . http_build_query(array_filter(['tab' => $tab, 'q' => $search, 'date' => $dateFilter], 'strlen'));
};

$pageTitle = 'Appointments — HealthQueue';
$isStaffAppointments = true;$isVisitCompletionPage=true;
require __DIR__ . '/../includes/header.php';
?>

<main class="portal-shell"><div class="container sa-page">
  <section class="sa-hero">
    <span class="an-eyebrow">Front desk</span>
    <h1>Appointments</h1>
    <p>Review requests and keep your clinic schedule moving.</p>

    <div class="sa-hero-actions"><span class="sa-current-date"><?= htmlspecialchars(date('j F Y')) ?></span><a class="btn btn-primary" href="queue.php#walk-in"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="9" cy="7" r="4"/><path d="M2 21v-2a7 7 0 0 1 14 0v2M19 8v6M16 11h6"/></svg>Register walk-in</a></div>
  </section>

  <?php if ($flash): ?><p class="form-message success" role="status"><?= htmlspecialchars($flash) ?></p><?php endif; ?>
  <?php if ($errors): ?><div class="form-message error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <?php if ($dataError): ?><p class="form-message error" role="alert"><?= htmlspecialchars($dataError) ?></p><?php endif; ?>

  <section class="sa-workspace">
  <nav class="sa-tabs" aria-label="Appointment lists">
    <?php foreach ($tabs as $key => $label): ?>
      <a href="<?= htmlspecialchars($tabUrl($key)) ?>" class="sa-tab<?= ($activeTab === $key ? ' is-active' : '') . ($key === 'expired' && $tabCounts[$key] > 0 ? ' needs-attention' : '') ?>"<?= $activeTab === $key ? ' aria-current="page"' : '' ?>>
        <?= $key === 'today' && $dateFilter && $dateFilter !== $today ? htmlspecialchars(date('M j', strtotime($dateFilter))) : $label ?>
        <?php if ($key !== 'all'): ?><span class="sa-count<?= in_array($key,['confirm','expired'],true) && $tabCounts[$key] ? ' is-amber' : '' ?>"><?= $tabCounts[$key] ?></span><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </nav>
    <form method="get" class="sa-filters">
      <input type="hidden" name="tab" value="<?= htmlspecialchars($activeTab) ?>">
      <label class="sa-filter-field sa-name-filter"><span>Patient name</span><span class="sa-search">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
        <input type="search" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search patient name">
      </span></label>
      <label class="sa-filter-field sa-date-filter"><span>Appointment date</span><input type="date" name="date" value="<?= htmlspecialchars($dateFilter) ?>"></label>
      <button type="submit" class="btn sa-filter-btn">Apply filters</button>
      <?php if ($search !== '' || $dateFilter !== ''): ?><a href="?tab=<?= htmlspecialchars($activeTab) ?>" class="sa-clear">Clear filters</a><?php endif; ?>
    </form>
  <header class="sa-list-heading"><h2><?= htmlspecialchars(['requests' => 'Appointment requests', 'reschedules' => 'Reschedule requests', 'today' => 'Today’s appointments', 'confirm' => 'Visits to confirm', 'expired'=>'Past visits needing review', 'all' => 'All appointments'][$activeTab]) ?></h2><p><?= $activeTab === 'requests' ? 'Paid bookings awaiting approval. Approve to add a patient to the queue; assign a physician when needed.' : 'Review and manage your clinic appointments.' ?></p></header>

  <?php if ($activeTab === 'reschedules'): ?>
    <?php if ($rescheduleRequests): ?>
      <div class="sa-list">
        <?php foreach ($rescheduleRequests as $request): ?>
          <article class="sa-item">
            <div class="sa-head">
              <span class="sa-avatar"><?= htmlspecialchars($initials($request)) ?></span>
              <div>
                <strong><?= htmlspecialchars($request['FirstName'] . ' ' . $request['LastName']) ?></strong>
                <p>Current: <?= htmlspecialchars(date('M j, Y', strtotime($request['OriginalDate']))) ?><?= $request['OriginalTime'] ? ' at ' . htmlspecialchars(date('g:i A', strtotime($request['OriginalTime']))) : ' · Queue booking' ?></p>
                <p>Requested: <?= htmlspecialchars(date('M j, Y', strtotime($request['RequestedDate']))) ?> at <?= htmlspecialchars(date('g:i A', strtotime($request['RequestedTime']))) ?></p>
              </div>
            </div>
            <div class="sa-actions">
              <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                <input type="hidden" name="appointment_id" value="<?= (int) $request['AppointmentID'] ?>">
                <input type="hidden" name="request_id" value="<?= (int) $request['RequestID'] ?>">
                <button class="btn btn-outline btn-sm" name="form_type" value="decline_reschedule">Decline</button>
                <button class="btn btn-outline btn-sm sa-approve" name="form_type" value="approve_reschedule">Approve new time</button>
              </form>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="ma-empty"><h3>No reschedule requests</h3><p>Patient requests to change an appointment time will appear here.</p></div>
    <?php endif; ?>
  <?php elseif ($activeTab === 'requests'): ?>
    <?php if ($requests): ?>
      <div class="sa-list">
        <?php foreach ($requests as $req): ?>
          <?php
            $id = (int) $req['AppointmentID'];
            $refund = $req['BookingFeePaid'] ? (float) $req['BaseConsultationFee'] : 0;
            $weekday = (int) date('N', strtotime($req['AppointmentDate']));
          ?>
          <article class="sa-item" data-request data-date="<?= htmlspecialchars($req['AppointmentDate']) ?>" data-weekday="<?= $weekday ?>" data-date-label="<?= htmlspecialchars(date('M j', strtotime($req['AppointmentDate']))) ?>" data-is-today="<?= $req['AppointmentDate'] === $today ? '1' : '0' ?>">
            <div class="sa-head">
              <span class="sa-avatar"><?= htmlspecialchars($initials($req)) ?></span>
              <div>
                <strong><?= htmlspecialchars($req['FirstName'] . ' ' . $req['LastName']) ?></strong>
                <p>Requested <?= htmlspecialchars($timeAgo((int) $req['MinutesAgo'])) ?></p>
              </div>
            </div>

            <div class="sa-request-date"><span class="sa-field-label">Date &amp; time</span><strong><?= htmlspecialchars(date('j M Y', strtotime($req['AppointmentDate']))) ?> &middot; <?= $req['AppointmentTime'] ? htmlspecialchars(date('g:i A', strtotime($req['AppointmentTime']))) : 'Queue booking' ?></strong></div>
            <form method="post" class="sa-approve-form">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
              <input type="hidden" name="appointment_id" value="<?= $id ?>">
              <div class="sa-assignment"><label class="sa-field-label" for="physician-<?= $id ?>">Physician</label><span class="sa-physician-name<?= empty($req['PhysicianID']) ? ' is-unassigned' : '' ?>"><?= htmlspecialchars($req['PhysicianName'] ?: 'No physician selected') ?></span><select id="physician-<?= $id ?>" name="physician_id" class="sa-physician" aria-label="Assign physician">
                <option value="">Choose a physician…</option>
                <?php foreach ($physicians as $physician): ?>
                  <option value="<?= (int) $physician['UserID'] ?>" <?= (int) $req['PhysicianID'] === (int) $physician['UserID'] ? 'selected' : '' ?>>Dr. <?= htmlspecialchars($physician['FirstName'] . ' ' . $physician['LastName']) ?></option>
                <?php endforeach; ?>
              </select></div>
              <span class="sa-fee">Fee paid</span>
              <div class="sa-actions">
                <button type="button" class="btn btn-outline btn-sm" data-open-decline>Decline</button>
                <button type="submit" name="form_type" value="accept_request" class="btn btn-outline btn-sm sa-approve"><?= empty($req['PhysicianID']) ? 'Assign physician' : 'Approve' ?></button>
                <button type="button" class="sa-link" data-toggle="details-<?= $id ?>">Details</button>
                <button type="button" class="sa-link" data-toggle="resched-<?= $id ?>">Reschedule</button>
              </div>
              <p class="sa-warning" hidden></p>
            </form>

            <div class="sa-details" id="details-<?= $id ?>" hidden>
              <span><b>Email</b> <?= htmlspecialchars($req['Email']) ?></span>
              <span><b>Contact</b> <?= htmlspecialchars($req['ContactNumber'] ?: 'Not provided') ?></span>
              <span><b>Visits here</b> <?= (int) $req['VisitCount'] ?></span>
            </div>

            <form method="post" class="sa-resched" id="resched-<?= $id ?>" hidden>
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
              <input type="hidden" name="form_type" value="reschedule_request">
              <input type="hidden" name="appointment_id" value="<?= $id ?>">
              <input type="date" name="appointment_date" min="<?= htmlspecialchars($today) ?>" value="<?= htmlspecialchars($req['AppointmentDate']) ?>" required>
              <input type="time" name="appointment_time" value="<?= htmlspecialchars($req['AppointmentTime']) ?>" required>
              <button type="submit" class="btn btn-outline btn-sm">Save new schedule</button>
            </form>

            <form method="post" class="sa-decline" hidden>
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
              <input type="hidden" name="form_type" value="reject_request">
              <input type="hidden" name="appointment_id" value="<?= $id ?>">
              <p class="sa-decline-label">Reason for declining <span>(sent to patient)</span></p>
              <div class="sa-chips">
                <?php foreach ($declineReasons as $reason): ?>
                  <label class="sa-chip"><input type="radio" name="decline_reason" value="<?= htmlspecialchars($reason) ?>" required><span><?= htmlspecialchars($reason) ?></span></label>
                <?php endforeach; ?>
              </div>
              <input type="text" name="decline_reason_other" class="sa-other" maxlength="200" placeholder="Tell the patient why" hidden>
              <?php if ($refund > 0): ?>
                <p class="sa-refund">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="6" width="20" height="14" rx="2"/><path d="M16 13h2M2 10h20"/></svg>
                  ₱<?= number_format($refund) ?> will be refunded to the patient's wallet.
                </p>
              <?php endif; ?>
              <div class="sa-actions">
                <button type="button" class="btn btn-outline btn-sm" data-cancel-decline>Cancel</button>
                <button type="submit" class="btn btn-sm sa-confirm-decline">Confirm decline</button>
              </div>
            </form>
          </article>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="ma-empty sa-empty-requests"><svg width="42" height="42" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 4H5v18h14V4h-4"/><rect x="9" y="2" width="6" height="4" rx="1"/><path d="m8 13 3 3 5-6"/></svg><h3>No requests waiting</h3><p><?= $search !== '' || $dateFilter !== '' ? 'No requests match your search.' : 'New appointment requests for your clinic will appear here.' ?></p></div>
    <?php endif; ?>

  <?php elseif ($activeTab === 'today'): ?>
    <?php if ($todayList): ?>
      <div class="sa-list">
        <?php foreach ($todayList as $row): ?>
          <?php [$pillLabel, $pillClass] = $statusPill($row); ?>
          <article class="sa-item sa-row">
            <span class="sa-time"><?= $row['AppointmentTime'] ? htmlspecialchars(date('g:i A', strtotime($row['AppointmentTime']))) : 'Queue' ?></span>
            <div class="sa-row-main">
              <strong><?= htmlspecialchars($row['FirstName'] . ' ' . $row['LastName']) ?></strong>
              <p><?= htmlspecialchars($concernLabel($row['Concern'])) ?> · <?= $row['PhyLastName'] ? 'Dr. ' . htmlspecialchars($row['PhyLastName']) : 'No physician' ?><?= $row['BookingFeePaid'] ? '' : ' · walk-in' ?><?= $row['QueueNumber'] ? ' - queue ' . ($row['ScheduledNumber'] !== null ? 'S-' . (int) $row['ScheduledNumber'] : '#' . (int) ($row['RegularNumber'] ?? $row['QueueNumber'])) . ' (' . htmlspecialchars(str_replace('_', ' ', $row['QueueStatus'])) . ')' : '' ?></p>
            </div>
            <span class="ma-pill <?= $pillClass ?>"><?= $pillLabel ?></span>
          </article>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="ma-empty"><h3>Nothing scheduled</h3><p>No approved appointments on <?= htmlspecialchars(date('M j, Y', strtotime($dateFilter ?: ($today ?? 'now')))) ?>.</p></div>
    <?php endif; ?>

  <?php elseif ($activeTab === 'expired'): ?>
    <?php require __DIR__.'/../includes/expired-visit-review.php'; ?>
  <?php elseif ($activeTab === 'confirm'): ?>
    <?php if ($pendingConfirmations): ?>
      <div class="sa-list">
        <?php foreach ($pendingConfirmations as $item): ?>
          <article class="sa-item sa-row" id="completion-<?= (int)$item['AppointmentID'] ?>">
            <span class="sa-avatar"><?= htmlspecialchars($initials($item)) ?></span>
            <div class="sa-row-main">
              <strong><?= htmlspecialchars($item['FirstName'] . ' ' . $item['LastName']) ?></strong>
              <p><?= $item['PhyLastName'] ? 'Dr. ' . htmlspecialchars($item['PhyLastName']) : 'No physician' ?> · <?= htmlspecialchars(date('M j, Y', strtotime($item['AppointmentDate']))) ?><?= $item['FinalizedAt'] ? ' · notes finalized ' . htmlspecialchars(date('M j, g:i A', strtotime($item['FinalizedAt']))) : '' ?></p>
            </div>
            <div class="vc-staff-review"><span class="status-badge"><?= $item['CompletionReviewStatus']==='IssueReported'?'Issue reported - needs review':'Awaiting patient response' ?></span><?php if($item['CompletionIssue']): ?><p class="vc-issue-text"><?= nl2br(htmlspecialchars($item['CompletionIssue'])) ?></p><?php endif; ?><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><input type="hidden" name="form_type" value="confirm_complete"><input type="hidden" name="appointment_id" value="<?= (int)$item['AppointmentID'] ?>">
<?php if($item['CompletionReviewStatus']==='IssueReported'): ?><label>Review outcome<textarea name="resolution" minlength="10" maxlength="2000" rows="3" required placeholder="Explain how you reviewed the report and verified completion."></textarea></label><?php endif; ?>
<label class="vc-verify"><input type="checkbox" name="attendance_verified" value="1" required> <?= $item['CompletionReviewStatus']==='IssueReported'?'I reviewed the report and verified that the visit was completed.':'I verified that this consultation took place.' ?></label><button class="btn btn-primary btn-sm">Confirm completion as Staff</button></form><small>No automatic completion for non-response. Leave unresolved issues under review.</small></div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="ma-empty"><h3>Nothing to confirm</h3><p>Finalized visits awaiting patient response or Staff follow-up appear here.</p></div>
    <?php endif; ?>

  <?php else: ?>
    <?php if ($allList): ?>
      <div class="sa-list">
        <?php foreach ($allList as $row): ?>
          <?php [$pillLabel, $pillClass] = $statusPill($row); $ts = strtotime($row['AppointmentDate']); ?>
          <article class="sa-item sa-row">
            <div class="ma-date"><span><?= strtoupper(date('M', $ts)) ?></span><strong><?= date('j', $ts) ?></strong></div>
            <div class="sa-row-main">
              <strong><?= htmlspecialchars($row['FirstName'] . ' ' . $row['LastName']) ?></strong>
              <p><?= $row['AppointmentTime'] ? htmlspecialchars(date('g:i A', strtotime($row['AppointmentTime']))) : 'Queue booking' ?> · <?= htmlspecialchars($concernLabel($row['Concern'])) ?> · <?= $row['PhyLastName'] ? 'Dr. ' . htmlspecialchars($row['PhyLastName']) : 'No physician' ?><?= $row['DeclineReason'] ? ' · ' . htmlspecialchars($row['DeclineReason']) : '' ?></p>
            </div>
            <span class="ma-pill <?= $pillClass ?>"><?= $pillLabel ?></span>
          </article>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="ma-empty"><h3>No appointments found</h3><p>Try a different name or date.</p></div>
    <?php endif; ?>
  <?php endif; ?>
  <?php if ($tabCounts[$activeTab] > 0): ?>
  <p class="sa-list-total"><?= (int) $tabCounts[$activeTab] ?> <?= $activeTab === 'requests' ? 'requests awaiting review' : 'appointments' ?></p>
  <?php endif; ?>
  </section>
</div></main>

<script>
document.addEventListener('DOMContentLoaded', function () {
  // Physician schedules: { id: { name, status, days: [ISO weekdays] } }.
  var schedules = <?= json_encode($physicianSchedule, JSON_HEX_TAG | JSON_HEX_AMP) ?>;

  document.querySelectorAll('[data-request]').forEach(function (item) {
    var select = item.querySelector('.sa-physician');
    var warning = item.querySelector('.sa-warning');
    var approveForm = item.querySelector('.sa-approve-form');
    var declineForm = item.querySelector('.sa-decline');
    var otherInput = declineForm.querySelector('.sa-other');

    // Warn when the chosen physician doesn't work that weekday (per their
    // weekly schedule) or, for today's requests, is marked unavailable.
    function checkPhysician() {
      var info = schedules[select.value];
      var message = '';
      if (info) {
        var date = item.getAttribute('data-date');
        var label = item.getAttribute('data-date-label');
        if (info.off.indexOf(date) !== -1) {
          message = info.name + ' has a day off on ' + label + '. Assign another physician or decline.';
        } else if (info.working.indexOf(date) === -1) {
          message = info.name + ' has no hours set on ' + label + '. Assign another physician or decline.';
        } else if (item.getAttribute('data-is-today') === '1' && info.status !== 'Available') {
          message = info.name + ' is marked "' + info.status + '" today. Assign another physician or decline.';
        }
      }
      warning.hidden = !message;
      warning.textContent = message ? '⚠ ' + message : '';
    }
    var approveButton = approveForm.querySelector('.sa-approve');
    approveForm.addEventListener('submit', function (event) {
      if (!select.value) {
        event.preventDefault();
        select.focus();
        warning.textContent = 'Choose a physician before approving this visit.';
        warning.hidden = false;
      }
    });
    select.addEventListener('change', function () {
      item.querySelector('.sa-physician-name').textContent = select.value ? select.options[select.selectedIndex].text : 'No physician selected';
      approveButton.textContent = select.value ? 'Approve' : 'Assign physician';
      checkPhysician();
    });
    checkPhysician();

    item.querySelector('[data-open-decline]').addEventListener('click', function () {
      approveForm.hidden = true;
      declineForm.hidden = false;
      item.classList.add('is-declining');
    });
    item.querySelector('[data-cancel-decline]').addEventListener('click', function () {
      declineForm.hidden = true;
      approveForm.hidden = false;
      item.classList.remove('is-declining');
    });
    declineForm.querySelectorAll('input[name="decline_reason"]').forEach(function (radio) {
      radio.addEventListener('change', function () {
        var isOther = radio.value === 'Other' && radio.checked;
        otherInput.hidden = !isOther;
        otherInput.required = isOther;
        if (isOther) otherInput.focus();
      });
    });

    item.querySelectorAll('[data-toggle]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var panel = document.getElementById(btn.getAttribute('data-toggle'));
        if (panel) panel.hidden = !panel.hidden;
      });
    });
  });
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
