<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

define('HQ_BASE_URL', '..');
requireRole(['Patient']);

$user = currentUser();
$pdo = getDbConnection();
$errors = [];
$flash = '';

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
                    $pdo->prepare('UPDATE PatientNotifications SET IsRead = 1 WHERE PatientID = ? AND NotificationID = ?')->execute([$user['UserID'], $notificationId]);
                    $flash = 'Notification marked as read.';
                } catch (PDOException $e) {
                    error_log('Patient mark notification read failed: ' . $e->getMessage());
                    $errors[] = 'We could not update this notification.';
                }
            }
        } elseif ($formType === 'mark_all_read') {
            try {
                $pdo->prepare('UPDATE PatientNotifications SET IsRead = 1 WHERE PatientID = ? AND IsRead = 0')->execute([$user['UserID']]);
                $flash = 'All notifications marked as read.';
            } catch (PDOException $e) {
                error_log('Patient mark all read failed: ' . $e->getMessage());
                $errors[] = 'We could not update your notifications.';
            }
        } elseif ($formType === 'delete_selected') {
            $ids = array_filter(array_map('intval', (array) ($_POST['notification_ids'] ?? [])));
            if (!$ids) {
                $errors[] = 'Select at least one notification to delete.';
            } else {
                try {
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $stmt = $pdo->prepare("DELETE FROM PatientNotifications WHERE PatientID = ? AND NotificationID IN ({$placeholders})");
                    $stmt->execute([$user['UserID'], ...$ids]);
                    $flash = count($ids) . ' notification(s) deleted.';
                } catch (PDOException $e) {
                    error_log('Delete selected patient notifications failed: ' . $e->getMessage());
                    $errors[] = 'We could not delete these notifications.';
                }
            }
        } elseif ($formType === 'delete_all') {
            try {
                $pdo->prepare('DELETE FROM PatientNotifications WHERE PatientID = ?')->execute([$user['UserID']]);
                $flash = 'All notifications deleted.';
            } catch (PDOException $e) {
                error_log('Delete all patient notifications failed: ' . $e->getMessage());
                $errors[] = 'We could not delete your notifications.';
            }
        }
    }
}

$notifications = [];
$dataError = null;

if ($pdo) {
    try {
        $stmt = $pdo->prepare('SELECT NotificationID, Message, IsRead, CreatedAt FROM PatientNotifications WHERE PatientID = ? ORDER BY CreatedAt DESC LIMIT 50');
        $stmt->execute([$user['UserID']]);
        $notifications = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Patient notifications load failed: ' . $e->getMessage());
        $dataError = 'Notifications are temporarily unavailable.';
    }
}

$pageTitle = 'Notifications — HealthQueue';
require __DIR__ . '/../includes/header.php';
?>

<?php
$inboxEyebrow = 'Updates';
$inboxDescription = 'Booking confirmations, queue calls, and record updates.';
$inboxEmptyDescription = 'Updates about your appointments and queue status will show up here.';
require __DIR__ . '/../includes/notification-inbox.php';
?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
