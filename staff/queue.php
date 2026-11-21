<?php
require_once __DIR__.'/../includes/clinic-fees.php';
require_once __DIR__.'/../includes/walk-in-patient.php';
require_once __DIR__ . '/../includes/input-validation.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/sms.php';
require_once __DIR__ . '/../includes/queue-email.php';
require_once __DIR__ . '/../includes/wallet.php';
require_once __DIR__ . '/../includes/queue-actions.php';
require_once __DIR__ . '/../includes/patient-booking-lock.php';

define('HQ_BASE_URL', '..');
requireRole(['Staff']);

// Waiting longer than this is highlighted.
const LONG_WAIT_MINUTES = 30;
const PRIORITY_OPTIONS = ['Senior', 'PWD', 'Pregnant'];
// The waiting line is ordered by COALESCE(Position, QueueNumber * 10).
const QUEUE_ORDER_SQL = "CASE WHEN a.AppointmentTime IS NOT NULL AND TIMESTAMP(a.AppointmentDate, a.AppointmentTime) <= NOW() THEN 0 ELSE 1 END, CASE WHEN a.AppointmentTime IS NOT NULL AND TIMESTAMP(a.AppointmentDate, a.AppointmentTime) <= NOW() THEN TIMESTAMP(a.AppointmentDate, a.AppointmentTime) END, COALESCE(q.Position, q.QueueNumber * 10), q.QueueNumber";

$user = currentUser();
$pdo = getDbConnection();
$errors = [];
$flash = (string)($_SESSION['queue_arrival_feedback']??'');
unset($_SESSION['queue_arrival_feedback']);
if (isset($_GET['registered'])) $flash = 'Walk-in registered successfully.';
$clinicId = (int) $user['ClinicID'];
$physicianFilter = filter_var($_GET['physician'] ?? null, FILTER_VALIDATE_INT) ?: null;
$walkInValues = ['first_name' => '', 'last_name' => '', 'contact_number' => '', 'email' => '', 'physician_id' => '', 'concern' => '', 'priority' => ''];

/** Today's waiting entries for the clinic (optionally one physician), in line order. */
function waitingLine(PDO $pdo, int $clinicId, ?int $physicianId): array
{
    $next=nextTimeQueuePatient($pdo,$clinicId,$physicianId);
    if(!$next)return [];
    return [['QueueID'=>$next['QueueID'],'Pos'=>$next['Position']??((int)$next['QueueNumber']*10)]];
}

/** Marks a waiting entry as Calling and notifies the patient. */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!$pdo) {
        $errors[] = 'The database is temporarily unavailable.';
    } else {
        $formType = $_POST['form_type'] ?? '';
        $queueId = filter_input(INPUT_POST, 'queue_id', FILTER_VALIDATE_INT);

        if ($formType === 'register_walkin') {
            foreach ($walkInValues as $field => $value) $walkInValues[$field] = trim((string) ($_POST[$field] ?? ''));

            $walkInValues['first_name'] = hqCapitalizeName($walkInValues['first_name']);
            $walkInValues['last_name'] = hqCapitalizeName($walkInValues['last_name']);
            if (!hqValidMobile($walkInValues['contact_number'])) $errors[] = 'Enter a valid mobile number';

            if ($walkInValues['first_name'] === '' || $walkInValues['last_name'] === '') $errors[] = 'First and last name are required.';
            if ($walkInValues['contact_number'] === '') $errors[] = 'Contact number is required.';
            if ($walkInValues['email'] !== '' && !filter_var($walkInValues['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address, or leave it blank.';
            if ($walkInValues['priority'] !== '' && !in_array($walkInValues['priority'], PRIORITY_OPTIONS, true)) $errors[] = 'Please choose a valid priority.';

            if (!$errors) {
                try {
                    $pdo->beginTransaction();
                    lockQueueClinic($pdo,$clinicId);

                    $patientRoleStmt = $pdo->query("SELECT RoleID FROM Roles WHERE RoleName = 'Patient'");
                    $patientRoleId = (int) $patientRoleStmt->fetchColumn();

                    $patientId = null;
                    if ($walkInValues['email'] !== '') {
                        $existingStmt = $pdo->prepare('SELECT UserID, RoleID FROM Users WHERE Email = ?');
                        $existingStmt->execute([$walkInValues['email']]);
                        $existing = $existingStmt->fetch();
                        if ($existing) {
                            if ((int) $existing['RoleID'] !== $patientRoleId) {
                                $errors[] = 'That email already belongs to a non-patient account.';
                            } else {
                                $patientId = (int) $existing['UserID'];
                            }
                        }
                    }

                    if (!$errors && !$patientId) {
                        $patientId=createWalkInPatient($pdo,$walkInValues);
                    }

                    if (!$errors) {
                        $physicianId = $walkInValues['physician_id'] !== '' ? (int) $walkInValues['physician_id'] : null;
                        if (!$physicianId) {
                            $singlePhysician = $pdo->prepare("SELECT UserID FROM Users WHERE ClinicID = ? AND RoleID = (SELECT RoleID FROM Roles WHERE RoleName = 'Physician') AND Status = 'Active' AND DeletedAt IS NULL ORDER BY UserID LIMIT 2");
                            $singlePhysician->execute([$clinicId]);
                            $clinicPhysicians = $singlePhysician->fetchAll(PDO::FETCH_COLUMN);
                            if (count($clinicPhysicians) === 1) $physicianId = (int) $clinicPhysicians[0];
                        }
                        if(!$physicianId)throw new RuntimeException('Choose an active physician before registering this walk-in.');
                        lockPatientBooking($pdo,(int)$patientId);
                        assertClinicPhysician($pdo,$clinicId,$physicianId);
                        $insertAppt = $pdo->prepare(
                            "INSERT INTO Appointments (PatientID, ClinicID, PhysicianID, AppointmentDate, AppointmentTime, Concern, Status, BookingFeeAmount)
                             VALUES (?, ?, ?, CURDATE(), NULL, ?, 'Confirmed', ?)"
                        );
                        $insertAppt->execute([$patientId, $clinicId, $physicianId, $walkInValues['concern'] ?: null,currentClinicFee($pdo,$clinicId)]);
                        $appointmentId = (int) $pdo->lastInsertId();

                        $queueNumStmt = $pdo->prepare('SELECT COALESCE(MAX(QueueNumber), 0) + 1 FROM Queue WHERE ClinicID = ? AND DATE(CreatedAt) = CURDATE()');
                        $queueNumStmt->execute([$clinicId]);
                        $queueNumber = (int) $queueNumStmt->fetchColumn();

                        $regularNumStmt = $pdo->prepare('SELECT COALESCE(MAX(RegularNumber), 0) + 1 FROM Queue WHERE ClinicID = ? AND DATE(CreatedAt) = CURDATE() AND RegularNumber IS NOT NULL');
                        $regularNumStmt->execute([$clinicId]);
                        $regularNumber = (int) $regularNumStmt->fetchColumn();
                        // Priority walk-ins go just ahead of the first non-priority patient still waiting.
                        $position = $queueNumber * 10;
                        if ($walkInValues['priority'] !== '') {
                            $firstRegular = $pdo->prepare(
                                "SELECT MIN(COALESCE(q.Position, q.QueueNumber * 10)) FROM Queue q
                                 WHERE q.ClinicID = ? AND DATE(q.CreatedAt) = CURDATE() AND q.Status = 'Waiting' AND q.Priority IS NULL"
                            );
                            $firstRegular->execute([$clinicId]);
                            $firstPos = $firstRegular->fetchColumn();
                            if ($firstPos !== null) $position = (int) $firstPos - 1;
                        }

                        $insertQueue = $pdo->prepare(
                            "INSERT INTO Queue (ClinicID, AppointmentID, PhysicianID, QueueNumber, RegularNumber, Position, Priority, Status, CreatedByStaffID,ArrivedAt) VALUES (?, ?, ?, ?, ?, ?, ?, 'Waiting', ?,NOW())"
                        );
                        $insertQueue->execute([$clinicId, $appointmentId, $physicianId, $queueNumber, $regularNumber, $position, $walkInValues['priority'] ?: null, $user['UserID']]);

                        logActivity($pdo, $user['UserID'], $clinicId, 'Registered walk-in patient', "{$walkInValues['first_name']} {$walkInValues['last_name']}, queue #{$queueNumber}");
                        $pdo->commit();
                        header('Location: ' . HQ_BASE_URL . '/staff/queue.php?registered=1');
                        exit;
                    }

                    if ($errors && $pdo->inTransaction()) $pdo->rollBack();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    error_log('Walk-in registration failed: ' . $e->getMessage());
                    $errors[] = hqDuplicateMobileError($e)?'This mobile number is already registered to another account.':($e instanceof RuntimeException && !($e instanceof PDOException) ? $e->getMessage() : 'We could not register this walk-in. Please try again.');
                }
            }
        } elseif ($formType === 'confirm_arrival' && $queueId) {
            try {
                $changed=confirmQueueArrival($pdo,$queueId,$clinicId,(int)$user['UserID']);
                $_SESSION['queue_arrival_feedback']=$changed?'Patient arrival confirmed. The visit can be called when eligible.':'Arrival was already confirmed.';
                header('Location: '.HQ_BASE_URL.'/staff/queue.php'.($physicianFilter?'?physician='.$physicianFilter:''),true,303);exit;
            } catch(Throwable $e) {$errors[]=$e instanceof PDOException?'We could not confirm arrival.':$e->getMessage();}
        } elseif ($formType === 'call_next') {
            try {
                $filterPhysician = filter_input(INPUT_POST, 'physician_filter', FILTER_VALIDATE_INT) ?: null;
                $called=callNextClinicPatient($pdo,$clinicId,(int)$user['UserID'],$filterPhysician);
                if ($called) {
                    $flash = "Calling queue {$called}.";
                } else {
                    $errors[] = 'No one is waiting in this line.';
                }
            } catch (Throwable $e) {
                error_log('Call next failed: ' . $e->getMessage());
                $errors[] = $e instanceof RuntimeException && !($e instanceof PDOException)?$e->getMessage():'We could not call the next patient.';
            }
        } elseif ($formType === 'recall' && $queueId) {
            try {
                $row = $pdo->prepare(
                    "SELECT q.QueueNumber, a.PatientID, a.AppointmentID FROM Queue q JOIN Appointments a ON a.AppointmentID = q.AppointmentID
                     WHERE q.QueueID = ? AND q.ClinicID = ? AND q.Status = 'Calling'"
                );
                $row->execute([$queueId, $clinicId]);
                if ($entry = $row->fetch()) {
                    notifyPatient($pdo, (int) $entry['PatientID'], "Reminder: it's your turn (queue #{$entry['QueueNumber']}) — please proceed to the counter.", (int) $entry['AppointmentID']);
                    logActivity($pdo, $user['UserID'], $clinicId, 'Recalled patient', "Queue #{$entry['QueueNumber']}");
                    $flash = "Recalled queue #{$entry['QueueNumber']}.";
                } else {
                    $errors[] = 'That patient is no longer being called.';
                }
            } catch (PDOException $e) {
                error_log('Recall failed: ' . $e->getMessage());
                $errors[] = 'We could not recall this patient.';
            }
        } elseif (in_array($formType, ['move_up', 'move_down', 'move_last'], true) && $queueId) {
            try {
                reorderTimeQueue($pdo,$clinicId,$queueId,$formType);
                $flash='Queue order updated. Scheduled appointment times remain protected.';
            } catch (Throwable $e) {
                error_log('Queue reorder failed: ' . $e->getMessage());
                $errors[] = $e instanceof PDOException ? 'We could not reorder the queue.' : $e->getMessage();
            }
        } elseif (in_array($formType, ['call_patient', 'mark_serving', 'skip_forfeit', 'remove_from_queue'], true)) {
            $transitions = [
                'call_patient'      => ['from' => ['Waiting'], 'to' => 'Calling', 'stamp' => 'CalledAt'],
                'mark_serving'      => ['from' => ['Calling'], 'to' => 'Serving', 'stamp' => 'ServedAt'],
                'skip_forfeit'      => ['from' => ['Calling', 'Serving'], 'to' => 'Forfeited_Late', 'stamp' => null],
                'remove_from_queue' => ['from' => null, 'to' => 'Removed', 'stamp' => null],
            ];
            $t = $transitions[$formType];

            if (!$queueId) {
                $errors[] = 'Invalid queue entry.';
            } elseif ($formType === 'remove_from_queue' && trim((string) ($_POST['removal_reason'] ?? '')) === '') {
                $errors[] = 'Please provide a reason for removing this booking from the queue.';
            } else {
                try {
                    if ($formType === 'call_patient') {
                        $called = callQueueEntry($pdo, $queueId, $clinicId, (int) $user['UserID']);
                        if ($called) {
                            $flash = "Calling queue {$called}.";
                        } else {
                            $errors[] = 'That queue entry could not be updated (it may have already changed).';
                        }
                    } elseif ($formType === 'skip_forfeit') {
                        $lookup=$pdo->prepare('SELECT AppointmentID FROM Queue WHERE QueueID=? AND ClinicID=?');$lookup->execute([$queueId,$clinicId]);$targetId=$lookup->fetchColumn();if(!$targetId)throw new RuntimeException('Queue entry not found.');
                        markClinicNoShow($pdo,(int)$targetId,$clinicId,(int)$user['UserID'],null,(int)$queueId);$flash='Marked as no-show. Any paid booking fee is retained.';
                    } elseif ($formType === 'remove_from_queue') {
                        $reason = mb_substr(trim((string) $_POST['removal_reason']), 0, 255);
                        $pdo->beginTransaction();
                        $lookup = $pdo->prepare("SELECT q.AppointmentID, a.PatientID, a.Status FROM Queue q JOIN Appointments a ON a.AppointmentID = q.AppointmentID WHERE q.QueueID = ? AND q.ClinicID = ? AND q.Status IN ('Waiting', 'Calling', 'Serving') FOR UPDATE");
                        $lookup->execute([$queueId, $clinicId]);
                        $target = $lookup->fetch();
                        if (!$target || !in_array($target['Status'], ['Pending', 'Confirmed'], true)) {
                            $pdo->rollBack();
                            $errors[] = 'That booking could not be removed because it is no longer active.';
                        } else {
                            cancelBooking($pdo, (int) $target['AppointmentID'], (int) $target['PatientID'], $reason, 'Refund for clinic-cancelled booking at ');
                            $pdo->commit();
                            logActivity($pdo, $user['UserID'], $clinicId, 'Removed patient booking from queue', "Appointment #{$target['AppointmentID']}: {$reason}");
                            notifyPatient($pdo, (int) $target['PatientID'], 'Your appointment and queue booking was cancelled by the clinic. Reason: ' . $reason, (int) $target['AppointmentID']);
                            $flash = 'Booking removed. The patient was notified.';
                        }
                    } else {
                        $sql = 'UPDATE Queue SET Status = ?' . ($t['stamp'] ? ", {$t['stamp']} = NOW()" : '') . ' WHERE QueueID = ? AND ClinicID = ?';
                        $params = [$t['to'], $queueId, $clinicId];
                        if ($t['from']) {
                            $sql .= ' AND Status IN (' . implode(',', array_fill(0, count($t['from']), '?')) . ')';
                            array_push($params, ...$t['from']);
                        }
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute($params);
                        if ($stmt->rowCount()) {
                            logActivity($pdo, $user['UserID'], $clinicId, 'Updated queue status', "Queue entry {$queueId} set to {$t['to']}");
                            $flash = ['mark_serving' => 'Marked as serving.', 'skip_forfeit' => 'Marked as a no-show.', 'remove_from_queue' => 'Removed from the queue.'][$formType];
                        } else {
                            $errors[] = 'That queue entry could not be updated (it may have already changed).';
                        }
                    }
                } catch (Throwable $e) {
                    if($pdo->inTransaction())$pdo->rollBack();
                    error_log('Queue transition failed: ' . $e->getMessage());
                    $errors[] = $e instanceof PDOException?'We could not update the queue.':$e->getMessage();
                }
            }
        } elseif ($formType === 'confirm_complete') {
            try{$appointmentId=filter_input(INPUT_POST,'appointment_id',FILTER_VALIDATE_INT);if(!$appointmentId)throw new RuntimeException('Invalid appointment.');$changed=completeClinicVisit($pdo,(int)$appointmentId,$clinicId,(int)$user['UserID'],($_POST['attendance_verified']??'')==='1',(string)($_POST['resolution']??''));$flash=$changed?'Visit marked as done.':'This visit is already complete.';}
            catch(Throwable $e){error_log('Queue completion failed: '.$e->getMessage());$errors[]=$e instanceof PDOException?'We could not complete this consultation.':$e->getMessage();}
        } elseif ($formType === 'reschedule_from_queue') {
            try{$appointmentId=filter_input(INPUT_POST,'appointment_id',FILTER_VALIDATE_INT);if(!$appointmentId||!$queueId)throw new RuntimeException('Invalid appointment or queue entry.');rescheduleClinicVisit($pdo,(int)$appointmentId,$clinicId,(int)$user['UserID'],trim((string)($_POST['appointment_date']??'')),trim((string)($_POST['appointment_time']??'')),null,(int)$queueId);$flash='Appointment and queue rescheduled.';}
            catch(Throwable $e){error_log('Queue reschedule failed: '.$e->getMessage());$errors[]=$e instanceof PDOException?'We could not reschedule this visit.':$e->getMessage();}
        }
    }
}

$entries = [];
$upcomingVisits = [];
$physicians = [];
$tabCounts = ['all' => 0];
$dataError = null;

if ($pdo && $clinicId) {
    try {
        $stmt = $pdo->prepare(
            "SELECT q.QueueID, q.QueueNumber, q.ScheduledNumber, q.RegularNumber, q.Status, q.Priority, q.PhysicianID, q.AppointmentID,q.ArrivedAt,q.GraceExtendedAt,q.GraceRescheduleRequestedAt,
                    TIMESTAMPDIFF(MINUTE, q.CreatedAt, NOW()) AS WaitingMinutes,
                    TIMESTAMPDIFF(MINUTE, COALESCE(q.ServedAt, q.CalledAt), NOW()) AS ActiveMinutes,
                    a.AppointmentDate, a.AppointmentTime, a.Concern, a.BookingFeePaid,
                    pat.FirstName, pat.LastName,
                    phy.LastName AS PhyLastName,
                    (SELECT v.Status='Finalized' FROM ConsultationVersions v JOIN Consultations cons ON cons.ConsultationID=v.ConsultationID WHERE cons.AppointmentID=a.AppointmentID ORDER BY v.RevisionNumber DESC,v.VersionID DESC LIMIT 1) AS FinalizedCount
             FROM Queue q
             JOIN Appointments a ON a.AppointmentID = q.AppointmentID
             JOIN Users pat ON pat.UserID = a.PatientID
             LEFT JOIN Users phy ON phy.UserID = q.PhysicianID
             WHERE q.ClinicID = ? AND DATE(q.CreatedAt) = CURDATE()
             ORDER BY " . QUEUE_ORDER_SQL
        );
        $stmt->execute([$clinicId]);
        $entries = $stmt->fetchAll();
        $upcomingSql = "SELECT a.AppointmentID,a.AppointmentDate,a.AppointmentTime,pat.FirstName,pat.LastName,phy.LastName AS PhyLastName
            FROM Appointments a JOIN Users pat ON pat.UserID=a.PatientID LEFT JOIN Users phy ON phy.UserID=a.PhysicianID
            WHERE a.ClinicID=? AND a.Status='Confirmed' AND a.AppointmentDate>CURDATE()";
        $upcomingParams = [$clinicId];
        if ($physicianFilter) { $upcomingSql .= ' AND a.PhysicianID=?'; $upcomingParams[] = $physicianFilter; }
        $upcomingStmt = $pdo->prepare($upcomingSql . ' ORDER BY a.AppointmentDate,a.AppointmentTime,a.AppointmentID LIMIT 20');
        $upcomingStmt->execute($upcomingParams);
        $upcomingVisits = $upcomingStmt->fetchAll();


        $physStmt = $pdo->prepare(
            "SELECT UserID, FirstName, LastName FROM Users
             WHERE ClinicID = ? AND RoleID = (SELECT RoleID FROM Roles WHERE RoleName = 'Physician') AND Status = 'Active'
             ORDER BY LastName"
        );
        $physStmt->execute([$clinicId]);
        $physicians = $physStmt->fetchAll();

        foreach ($entries as $entry) {
            if (!in_array($entry['Status'], ['Waiting', 'Calling', 'Serving'], true)) continue;
            $tabCounts['all']++;
            if ($entry['PhysicianID']) $tabCounts[(int) $entry['PhysicianID']] = ($tabCounts[(int) $entry['PhysicianID']] ?? 0) + 1;
        }
    } catch (PDOException $e) {
        error_log('Queue list load failed: ' . $e->getMessage());
        $dataError = 'The queue is temporarily unavailable.';
    }
}

$timeLine=$pdo?clinicTimeQueue($pdo,$clinicId,$physicianFilter):[];$timeRank=[];$turnTimes=[];
foreach($timeLine as $rank=>$predicted){$timeRank[(int)$predicted['QueueID']]=$rank;$turnTimes[(int)$predicted['QueueID']]=$predicted;}
foreach($entries as &$entry){$entry['EstimatedTurnAt']=$turnTimes[(int)$entry['QueueID']]['EstimatedTurnAt']??null;$entry['EstimatedWaitMinutes']=$turnTimes[(int)$entry['QueueID']]['EstimatedWaitMinutes']??null;$entry['GraceHeld']=!empty($turnTimes[(int)$entry['QueueID']]['GraceHeld']);$entry['GraceDeadline']=$turnTimes[(int)$entry['QueueID']]['GraceDeadline']??null;}
unset($entry);
usort($entries,static fn($a,$b)=>[$timeRank[(int)$a['QueueID']]??PHP_INT_MAX,(int)$a['QueueID']]<=>[$timeRank[(int)$b['QueueID']]??PHP_INT_MAX,(int)$b['QueueID']]);

// Split today's entries for the selected physician tab.
$visible = array_filter($entries, static fn($e) => !$physicianFilter || (int) $e['PhysicianID'] === $physicianFilter);
$active = array_values(array_filter($visible, static fn($e) => empty($e['GraceHeld'])&&in_array($e['Status'], ['Calling', 'Serving'], true)));
$waiting = array_values(array_filter($visible, static fn($e) => empty($e['GraceHeld'])&&$e['Status'] === 'Waiting'));
$awaitingArrival=array_values(array_filter($visible,static fn($e)=>!empty($e['GraceHeld'])));
$eligibleLine=$pdo?waitingLine($pdo,$clinicId,$physicianFilter):[];$canCallNext=(bool)$eligibleLine;$nextQueueId=$eligibleLine?(int)$eligibleLine[0]['QueueID']:0;
$finished = array_values(array_filter($visible, static fn($e) => in_array($e['Status'], ['Completed', 'Forfeited_Late', 'Removed'], true)));
$finishedCounts = ['Completed' => 0, 'Forfeited_Late' => 0, 'Removed' => 0];
foreach ($finished as $entry) $finishedCounts[$entry['Status']]++;

$walkInHasData = (bool) array_filter($walkInValues);
$ticketLabel = static fn(array $e): string => $e['ScheduledNumber'] !== null ? 'S-' . (int) $e['ScheduledNumber'] : '#' . (int) ($e['RegularNumber'] ?? $e['QueueNumber']);
$csrf = htmlspecialchars(csrfToken());
$filterInput = $physicianFilter ? '<input type="hidden" name="physician_filter" value="' . $physicianFilter . '">' : '';
$sourceLabel = static fn(array $e): string => !$e['AppointmentTime'] ? 'Regular queue' : ($e['BookingFeePaid'] ? 'Appointment ' . date('g:i A', strtotime($e['AppointmentTime'])) : 'Walk-in');
$concern = static fn(array $e): string => mb_strimwidth(trim((string) $e['Concern']) ?: 'General', 0, 30, '…');
$minutesLabel = static function (int $minutes): string {
    return $minutes < 60 ? $minutes . ' min' : intdiv($minutes, 60) . ' h ' . ($minutes % 60) . ' min';
};

$nextPatient = null;
foreach ($waiting as $candidate) {
    if (!$candidate['AppointmentTime'] || strtotime($candidate['AppointmentDate'] . ' ' . $candidate['AppointmentTime']) <= time()) {
        if((int)$candidate['QueueID']!==$nextQueueId)continue;
        $nextPatient = $candidate;
        break;
    }
}

// Rendered once so the full page and the periodic auto-refresh share markup.
ob_start();
?>
  <div class="qm-metrics">
    <div><span class="qm-metric-icon" aria-hidden="true"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3"/><path d="M2 21v-3a7 7 0 0 1 14 0v3M17 5a3 3 0 0 1 0 6M18 15a5 5 0 0 1 4 5v2"/></svg></span><div><span>Waiting</span><strong><?= count($waiting) ?></strong></div></div>
    <div><span class="qm-metric-icon" aria-hidden="true"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3v6a5 5 0 0 0 10 0V3M4 3h4M14 3h4M11 14v3a4 4 0 0 0 8 0v-2"/><circle cx="19" cy="12" r="3"/></svg></span><div><span>In consultation</span><strong><?= count(array_filter($active, static fn($e) => $e['Status'] === 'Serving')) ?></strong></div></div>
    <div><span class="qm-metric-icon" aria-hidden="true"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="m7 12 3 3 7-7"/></svg></span><div><span>Completed today</span><strong><?= $finishedCounts['Completed'] ?></strong></div></div>
  </div>
  <div class="qm-workspace"><div class="qm-main-column">
  <section class="qm-current-panel"><h3 class="qm-section-title">Now serving</h3>
  <?php if ($active): ?>
    <?php foreach ($active as $entry): ?>
      <?php $isServing = $entry['Status'] === 'Serving'; ?>
      <section class="qm-serving">
        <div class="qm-serving-top">
          <div class="qm-serving-num"><span><?= $isServing ? 'Serving' : 'Calling' ?></span><strong><?= htmlspecialchars($ticketLabel($entry)) ?></strong></div>
          <div class="qm-serving-info">
            <h2><?= htmlspecialchars($entry['FirstName'] . ' ' . $entry['LastName']) ?><?php if ($entry['Priority']): ?> <span class="qm-priority">Priority · <?= htmlspecialchars($entry['Priority']) ?></span><?php endif; ?></h2>
            <p><?= htmlspecialchars($concern($entry)) ?> · <?= htmlspecialchars(lcfirst($sourceLabel($entry))) ?><?= $entry['PhyLastName'] ? ' · Dr. ' . htmlspecialchars($entry['PhyLastName']) : '' ?> · <?= $isServing ? 'in consultation ' : 'called ' ?><?= $minutesLabel((int) $entry['ActiveMinutes']) ?><?= $isServing ? '' : ' ago' ?></p>
          </div>
        </div>
        <div class="qm-serving-actions">
          <?php if (!$isServing): ?>
            <form method="post"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="form_type" value="recall"><input type="hidden" name="queue_id" value="<?= (int) $entry['QueueID'] ?>">
              <button class="btn btn-outline btn-sm"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5 6 9H2v6h4l5 4V5z"/><path d="M15.5 8.5a5 5 0 0 1 0 7M19 5a10 10 0 0 1 0 14"/></svg>Recall</button></form>
            <form method="post"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="form_type" value="confirm_arrival"><input type="hidden" name="queue_id" value="<?= (int) $entry['QueueID'] ?>">
              <button class="btn btn-outline btn-sm"<?= !empty($entry['ArrivedAt'])?' disabled':'' ?>><?= !empty($entry['ArrivedAt'])?'Arrival confirmed':'Patient arrived' ?></button></form>
          <?php endif; ?>
          <form method="post" onsubmit="return confirm('Mark this visit as no-show? Any paid booking fee is retained.');"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="form_type" value="skip_forfeit"><input type="hidden" name="queue_id" value="<?= (int) $entry['QueueID'] ?>">
            <button class="btn btn-outline btn-sm qm-danger">No-show</button></form>
          <?php if ($isServing): ?>
            <a class="btn btn-outline btn-sm" href="appointments.php?tab=confirm">Review completion</a>
          <?php endif; ?>

        </div>
        <?php if ($isServing && (int) $entry['FinalizedCount'] === 0): ?><p class="qm-hint">"Mark done" unlocks once the physician finalizes their notes.</p><?php endif; ?>
      </section>
    <?php endforeach; ?>
  <?php else: ?>
    <section class="qm-serving qm-serving-empty">
      <h2>No active consultation</h2><p>Call the next eligible patient to begin.</p>

    </section>
  <?php endif; ?>

  </section>
  <section class="qm-waiting-panel">
  <div class="qm-panel-heading"><h3 class="qm-section-title">Waiting queue <span class="qm-count"><?= count($waiting) ?></span></h3><input class="qm-search" type="search" placeholder="Find patient" aria-label="Find patient in waiting queue"></div>
  <div class="qm-column-labels" aria-hidden="true"><span>Ticket</span><span>Patient / physician</span><span>Estimated turn</span><span>Actions</span></div>
  <?php if ($waiting): ?>
    <div class="qm-list">
      <?php foreach ($waiting as $i => $entry): ?>
        <?php $mins = (int) $entry['WaitingMinutes']; $qid = (int) $entry['QueueID']; ?>
        <article class="qm-row" data-patient="<?= htmlspecialchars(mb_strtolower($entry['FirstName'] . ' ' . $entry['LastName'])) ?>">
          <span class="qm-num"><?= htmlspecialchars($ticketLabel($entry)) ?></span>
          <div class="qm-row-main"><span class="qm-patient-avatar" aria-hidden="true"><?= htmlspecialchars(mb_strtoupper(mb_substr($entry['FirstName'],0,1).mb_substr($entry['LastName'],0,1))) ?></span><div>
            <strong><?= htmlspecialchars($entry['FirstName'] . ' ' . $entry['LastName']) ?><?php if ($entry['Priority']): ?> <span class="qm-priority">Priority · <?= htmlspecialchars($entry['Priority']) ?></span><?php endif; ?></strong>
            <p><?= htmlspecialchars($sourceLabel($entry)) ?> · <?= htmlspecialchars($concern($entry)) ?><?= !$physicianFilter && $entry['PhyLastName'] ? ' · Dr. ' . htmlspecialchars($entry['PhyLastName']) : '' ?></p>
          </div></div>
          <span class="qm-turn"><strong><?= $entry['EstimatedTurnAt'] ? htmlspecialchars(date('g:i A',strtotime($entry['EstimatedTurnAt']))) : 'To be confirmed' ?></strong><small><?= $entry['AppointmentTime'] && $entry['AppointmentTime']!=='00:00:00' ? 'Scheduled '.htmlspecialchars(date('g:i A',strtotime($entry['AppointmentTime']))) : 'Approximate' ?></small><small>Waiting <?= $minutesLabel(max(0,$mins)) ?></small></span>
          <div class="qm-icons">
            <form method="post"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="form_type" value="move_up"><input type="hidden" name="queue_id" value="<?= $qid ?>"><?= $filterInput ?>
              <button class="qm-icon" aria-label="Move up" title="Move up"<?= $i === 0 ? ' disabled' : '' ?>><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m18 15-6-6-6 6"/></svg></button></form>
            <form method="post"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="form_type" value="move_down"><input type="hidden" name="queue_id" value="<?= $qid ?>"><?= $filterInput ?>
              <button class="qm-icon" aria-label="Move down" title="Move down"<?= $i === count($waiting) - 1 ? ' disabled' : '' ?>><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg></button></form>
            <details class="qm-more"><summary aria-label="More actions for <?= htmlspecialchars($entry['FirstName'].' '.$entry['LastName']) ?>"><span aria-hidden="true">&bull;&bull;&bull;</span></summary><div class="qm-more-panel">
              <?php if (empty($entry['ArrivedAt'])): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="form_type" value="confirm_arrival"><input type="hidden" name="queue_id" value="<?= $qid ?>"><?= $filterInput ?><button class="qm-menu-action">Patient arrived</button></form><?php else: ?><p class="qm-arrival-confirmed">Arrival confirmed</p><?php endif; ?>

              <form method="post"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="form_type" value="move_last"><input type="hidden" name="queue_id" value="<?= $qid ?>"><?= $filterInput ?><button class="qm-menu-action"<?= $i === count($waiting)-1 ? ' disabled' : '' ?>>Send to end of line</button></form>
              <form method="post" class="qm-remove-form" onsubmit="return confirm('Remove this booking from the queue and cancel the appointment?');"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="form_type" value="remove_from_queue"><input type="hidden" name="queue_id" value="<?= $qid ?>"><?= $filterInput ?>
                <label>Reason for removal<input type="text" name="removal_reason" maxlength="255" required placeholder="Enter reason" class="qm-remove-reason"></label>
                <button class="qm-menu-action qm-icon-danger">Remove booking</button>
              </form>
            </div></details>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <p class="qm-empty">No one is waiting. Register a walk-in or approve a request to add patients.</p>
  <?php endif; ?>

  <p class="qm-search-empty" hidden>No waiting patients match your search.</p>
  </section>
  <?php if ($awaitingArrival): ?>
  <section class="qm-waiting-panel qm-arrival-panel"><h3 class="qm-section-title">Awaiting arrival <span class="qm-count"><?= count($awaitingArrival) ?></span></h3><p class="qm-upcoming-help">These scheduled patients do not block the queue. Confirm arrival when they reach the clinic.</p>
    <?php foreach ($awaitingArrival as $absent): ?>
    <article class="qm-absent-row"><div><strong><?= htmlspecialchars($ticketLabel($absent)) ?> &middot; <?= htmlspecialchars($absent['FirstName'].' '.$absent['LastName']) ?></strong><p>Scheduled <?= htmlspecialchars(date('g:i A',strtotime($absent['AppointmentTime']))) ?> &middot; <?= $absent['PhyLastName']?'Dr. '.htmlspecialchars($absent['PhyLastName']):'Physician' ?></p><small><?= !empty($absent['GraceRescheduleRequestedAt']) ? 'Reschedule requested: staff review required' : ('Arrival deadline: '.htmlspecialchars(date('g:i A',strtotime($absent['GraceDeadline']))).(!empty($absent['GraceExtendedAt'])?' (extended)':'')) ?></small></div>
      <form method="post"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="form_type" value="confirm_arrival"><input type="hidden" name="queue_id" value="<?= (int)$absent['QueueID'] ?>"><?= $filterInput ?><button class="btn btn-outline btn-sm">Patient arrived</button></form>
    </article><?php endforeach; ?>
  </section><?php endif; ?>
  <details class="qm-finished">
    <summary>
      <span>Finished today</span>
      <span class="qm-finished-pills">
        <span class="ma-pill ma-pill-approved"><?= $finishedCounts['Completed'] ?> completed</span>
        <span class="ma-pill ma-pill-declined"><?= $finishedCounts['Forfeited_Late'] ?> no-show</span>
        <span class="ma-pill ma-pill-completed"><?= $finishedCounts['Removed'] ?> removed</span>
      </span>
    </summary>
    <?php if ($finished): ?>
      <ul>
        <?php foreach ($finished as $entry): ?>
          <li><span class="qm-num"><?= htmlspecialchars($ticketLabel($entry)) ?></span><?= htmlspecialchars($entry['FirstName'] . ' ' . $entry['LastName']) ?><em><?= ['Completed' => 'Done', 'Forfeited_Late' => 'No-show', 'Removed' => 'Removed'][$entry['Status']] ?></em></li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <p class="qm-empty">Nothing finished yet today.</p>
    <?php endif; ?>
  </details>
  </div>
  <aside class="qm-side-column">
    <section class="qm-next-panel"><div class="qm-next-heading"><h3>Next patient</h3><?php if ($nextPatient && $canCallNext): ?><span class="qm-ready">Ready to call</span><?php endif; ?></div>
      <?php if ($nextPatient): ?>
      <div class="qm-next-person"><span class="qm-next-avatar"><?= htmlspecialchars(mb_substr($nextPatient['FirstName'],0,1) . mb_substr($nextPatient['LastName'],0,1)) ?></span><div><h2><?= htmlspecialchars($nextPatient['FirstName'] . ' ' . $nextPatient['LastName']) ?></h2><span class="qm-count"><?= htmlspecialchars($ticketLabel($nextPatient)) ?></span><p><?= htmlspecialchars($sourceLabel($nextPatient)) ?></p></div></div>
      <?php else: ?><p class="qm-empty">No patient is ready to be called.</p><?php endif; ?>
      <?php if ($nextPatient): ?><p class="qm-next-doctor"><span>Physician</span><strong><?= $nextPatient['PhyLastName'] ? 'Dr. '.htmlspecialchars($nextPatient['PhyLastName']) : 'No physician assigned' ?></strong></p><?php endif; ?>
      <form method="post"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="form_type" value="call_next"><?= $filterInput ?><button class="btn btn-primary qm-next-button"<?= $canCallNext ? '' : ' disabled' ?>>Call next patient</button></form>
      <?php if ($canCallNext && $nextPatient): ?><p class="qm-next-hint">Calls this patient for Dr. <?= htmlspecialchars($nextPatient['PhyLastName']) ?>.</p><?php endif; ?>
      <?php if (!$canCallNext): ?><p class="qm-next-hint"><?= $active || array_filter($entries, static fn($e) => in_array($e['Status'], ['Calling','Serving'], true)) ? 'A patient is currently being called or seen.' : 'No eligible patient is waiting. Scheduled visits can be called when their time arrives.' ?></p><?php endif; ?>
    </section>
    <a class="qm-appointments-link" href="appointments.php">View appointments</a>
  </aside></div>
  <?php if ($upcomingVisits): ?>
  <section class="qm-upcoming-panel">
    <h3 class="qm-section-title">Upcoming approved visits</h3>
    <p class="qm-upcoming-help">These patients are approved for a future date. They join the active queue on their appointment day.</p>
    <ul class="qm-upcoming-list">
      <?php foreach ($upcomingVisits as $visit): ?>
      <li><div><strong><?= htmlspecialchars($visit['FirstName'].' '.$visit['LastName']) ?></strong><span><?= $visit['PhyLastName'] ? 'Dr. '.htmlspecialchars($visit['PhyLastName']) : 'No physician assigned' ?></span></div><div><strong><?= htmlspecialchars(date('M j, Y',strtotime($visit['AppointmentDate']))) ?></strong><span><?= $visit['AppointmentTime'] ? htmlspecialchars(date('g:i A',strtotime($visit['AppointmentTime']))) : 'Queue booking' ?></span></div><span class="qm-count">Approved</span></li>
      <?php endforeach; ?>
    </ul>
    <a href="appointments.php?tab=all" class="qm-upcoming-link">View all appointments</a>
  </section>
  <?php endif; ?>
<?php
$queueHtml = ob_get_clean();

// Periodic auto-refresh (bottom of page) fetches this URL with an AJAX
// header and swaps in just this fragment.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch') {
    echo $queueHtml;
    exit;
}

$pageTitle = 'Queue Management — HealthQueue';
$isStaffQueue = true;
require __DIR__ . '/../includes/header.php';
?>

<main class="portal-shell"><div class="container qm-page">
  <section class="sd-hero qm-hero" id="walk-in">
    <div>
      <span class="an-eyebrow">Front desk</span>
      <h1>Queue Management</h1>
      <p>Manage today&rsquo;s patients by physician. Future approved visits appear below.</p>
    </div>
    <div class="sd-hero-actions"><span class="qm-date"><?= htmlspecialchars(date('j F Y')) ?></span>
      <a href="<?= HQ_BASE_URL ?>/staff/display-board.php" class="btn sd-btn-ghost" target="_blank" rel="noopener">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="m17 2-5 5-5-5"/></svg>
        Display board
      </a>
      <button type="button" class="btn sd-btn-call" data-modal-open="registerWalkinModal">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/></svg>
        Register walk-in
      </button>
    </div>
  </section>

  <?php if ($flash): ?><p class="form-message success" role="status"><?= htmlspecialchars($flash) ?></p><?php endif; ?>
  <?php if ($errors && !$walkInHasData): ?><div class="form-message error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <?php if ($dataError): ?><p class="form-message error" role="alert"><?= htmlspecialchars($dataError) ?></p><?php endif; ?>

  <nav class="sa-tabs" aria-label="Filter by physician">
    <a href="?" class="sa-tab<?= !$physicianFilter ? ' is-active' : '' ?>">All physicians <span class="sa-count"><?= $tabCounts['all'] ?></span></a>
    <?php foreach ($physicians as $physician): ?>
      <?php $pid = (int) $physician['UserID']; ?>
      <a href="?physician=<?= $pid ?>" class="sa-tab<?= $physicianFilter === $pid ? ' is-active' : '' ?>">Dr. <?= htmlspecialchars($physician['LastName']) ?> <span class="sa-count"><?= $tabCounts[$pid] ?? 0 ?></span></a>
    <?php endforeach; ?>
  </nav>

  <div id="queueListContainer"><?= $queueHtml ?></div>
</div></main>

<div class="modal-overlay" id="registerWalkinModal">
  <div class="modal-box">
    <button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
    <h2>Register Walk-in Patient</h2>
    <?php if ($errors && $walkInHasData): ?><div class="form-message error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <form class="registration-form" method="post">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="form_type" value="register_walkin">
      <div class="form-stack form-cols-2">
        <label>First name<input type="text" name="first_name" value="<?= htmlspecialchars($walkInValues['first_name']) ?>" required></label>
        <label>Last name<input type="text" name="last_name" value="<?= htmlspecialchars($walkInValues['last_name']) ?>" required></label>
      </div>
      <div class="form-stack form-cols-2">
        <label>Contact number<input type="text" name="contact_number" inputmode="numeric" maxlength="11" pattern="09[0-9]{9}" title="Enter a valid mobile number" value="<?= htmlspecialchars($walkInValues['contact_number']) ?>" required></label>
        <label>Email <span class="optional">(optional)</span><input type="email" name="email" value="<?= htmlspecialchars($walkInValues['email']) ?>"></label>
      </div>
      <div class="form-stack form-cols-2">
        <label>Physician
          <select name="physician_id" required>
            <option value="">Choose a physician</option>
            <?php foreach ($physicians as $physician): ?>
              <option value="<?= (int) $physician['UserID'] ?>" <?= $walkInValues['physician_id'] === (string) $physician['UserID'] || count($physicians)===1 || (!$walkInHasData && $physicianFilter === (int) $physician['UserID']) ? 'selected' : '' ?>>Dr. <?= htmlspecialchars($physician['FirstName'] . ' ' . $physician['LastName']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Priority <span class="optional">(goes ahead of the line)</span>
          <select name="priority">
            <option value="">None</option>
            <?php foreach (PRIORITY_OPTIONS as $option): ?>
              <option <?= $walkInValues['priority'] === $option ? 'selected' : '' ?>><?= $option ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>
      <div class="form-stack">
        <label>Reason for visit <span class="optional">(optional)</span><textarea name="concern" rows="3"><?= htmlspecialchars($walkInValues['concern']) ?></textarea></label>
      </div>
      <button type="submit" class="btn btn-primary btn-block">Add to Queue</button>
    </form>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  // Reopen after a failed submit, or open straight away when arriving from
  // the dashboard's "Register walk-in" link (#walk-in).
  if (<?= $walkInHasData ? 'true' : 'false' ?> || window.location.hash === '#walk-in') {
    window.hqOpenModal(document.getElementById('registerWalkinModal'));
  }

  // Auto-refresh the queue so staff see new walk-ins and changes made at
  // other desks. Skipped while the "Finished today" list is open.
  var container = document.getElementById('queueListContainer');
  container.addEventListener('input', function (event) {
    if (!event.target.matches('.qm-search')) return;
    var query = event.target.value.trim().toLocaleLowerCase();
    var matches = 0;
    container.querySelectorAll('[data-patient]').forEach(function (row) {
      row.hidden = !row.dataset.patient.includes(query);
      if (!row.hidden) matches++;
    });
    container.querySelector('.qm-search-empty').hidden = !query || matches > 0;
  });
  setInterval(function () {
    if (container.contains(document.activeElement) || container.querySelector('.qm-search').value || Array.from(container.querySelectorAll('.qm-remove-reason')).some(function (input) { return input.value.trim(); }) || document.querySelector('.modal-overlay.is-open')) return;
    var finished = container.querySelector('.qm-finished');
    if ((finished && finished.open) || container.querySelector('.qm-more[open]')) return;
    fetch(window.location.href, { headers: { 'X-Requested-With': 'fetch' } })
      .then(function (res) { return res.text(); })
      .then(function (html) { container.innerHTML = html; })
      .catch(function () { /* try again next tick */ });
  }, 20000);
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
