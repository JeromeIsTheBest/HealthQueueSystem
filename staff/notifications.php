<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';

define('HQ_BASE_URL', '..');
requireRole(['Staff']);

$user = currentUser();
$pdo = getDbConnection();
$errors = [];
$flash = '';
$clinicId = (int) $user['ClinicID'];

require __DIR__ . '/../includes/notification-read-request.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!$pdo) {
        $errors[] = 'The database is temporarily unavailable.';
    } else {
        $formType = $_POST['form_type'] ?? '';

        if ($formType === 'mark_read') {
            $notificationId = filter_input(INPUT_POST, 'notification_id', FILTER_VALIDATE_INT);
            if (!$notificationId) {
                $errors[] = 'Choose a valid notification.';
            } else {
                try {
                    $pdo->prepare('UPDATE Notifications SET IsRead = 1 WHERE ClinicID = ? AND NotificationID = ?')->execute([$clinicId, $notificationId]);
                    logActivity($pdo, $user['UserID'], $clinicId, 'Marked notification read', 'Notification #' . $notificationId);
                    $flash = 'Notification marked as read.';
                } catch (PDOException $e) {
                    error_log('Clinic mark notification read failed: ' . $e->getMessage());
                    $errors[] = 'We could not update this notification.';
                }
            }
        } elseif ($formType === 'mark_all_read') {
            try {
                $pdo->prepare('UPDATE Notifications SET IsRead = 1 WHERE ClinicID = ? AND IsRead = 0')->execute([$clinicId]);
                logActivity($pdo, $user['UserID'], $clinicId, 'Marked all notifications read', null);
                $flash = 'All notifications marked as read.';
            } catch (PDOException $e) {
                error_log('Mark all read failed: ' . $e->getMessage());
                $errors[] = 'We could not update your notifications.';
            }
        } elseif ($formType === 'delete_selected') {
            $ids = array_filter(array_map('intval', (array) ($_POST['notification_ids'] ?? [])));
            if (!$ids) {
                $errors[] = 'Select at least one notification to delete.';
            } else {
                try {
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $stmt = $pdo->prepare("DELETE FROM Notifications WHERE ClinicID = ? AND NotificationID IN ({$placeholders})");
                    $stmt->execute([$clinicId, ...$ids]);
                    logActivity($pdo, $user['UserID'], $clinicId, 'Deleted notifications', count($ids) . ' notification(s)');
                    $flash = count($ids) . ' notification(s) deleted.';
                } catch (PDOException $e) {
                    error_log('Delete selected notifications failed: ' . $e->getMessage());
                    $errors[] = 'We could not delete these notifications.';
                }
            }
        } elseif ($formType === 'delete_all') {
            try {
                $pdo->prepare('DELETE FROM Notifications WHERE ClinicID = ?')->execute([$clinicId]);
                logActivity($pdo, $user['UserID'], $clinicId, 'Deleted all notifications', null);
                $flash = 'All notifications deleted.';
            } catch (PDOException $e) {
                error_log('Delete all notifications failed: ' . $e->getMessage());
                $errors[] = 'We could not delete your notifications.';
            }
        }
    }
}

$notifications = [];
$dataError = null;

if ($pdo && $clinicId) {
    try {
        $stmt = $pdo->prepare('SELECT NotificationID, Message, IsRead, RelatedAppointmentID, CreatedAt FROM Notifications WHERE ClinicID = ? ORDER BY CreatedAt DESC LIMIT 50');
        $stmt->execute([$clinicId]);
        $notifications = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Notifications load failed: ' . $e->getMessage());
        $dataError = 'Notifications are temporarily unavailable.';
    }
}

$pageTitle = 'Notifications — HealthQueue';
require __DIR__ . '/../includes/header.php';
?>

<?php
$inboxEyebrow = 'Front desk';
$inboxDescription = "Shared across your clinic's team. Marking notifications read or deleting them updates the inbox for everyone at your clinic.";
$inboxEmptyDescription = 'New appointment requests for your clinic will show up here.';
require __DIR__ . '/../includes/notification-inbox.php';
?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
