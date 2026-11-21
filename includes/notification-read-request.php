<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'auto_mark_read') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        echo json_encode(['ok'=>false,'message'=>'Your session expired. Refresh the page and try again.']);
    } elseif (!$pdo) {
        http_response_code(503);
        echo json_encode(['ok'=>false,'message'=>'We could not mark this notification as read. Please try again.']);
    } else {
        require_once __DIR__ . '/notification-read.php';
        $id = filter_input(INPUT_POST, 'notification_id', FILTER_VALIDATE_INT) ?: 0;
        $scope = in_array($user['RoleName'],['Patient','Physician'],true) ? (int)$user['UserID'] : (int)$user['ClinicID'];
        try {
            $unread = hqMarkNotificationRead($pdo, $user['RoleName'], $scope, $id);
            if ($unread === null) {
                http_response_code(404);
                echo json_encode(['ok'=>false,'message'=>'This notification is no longer available.']);
            } else echo json_encode(['ok'=>true,'unread'=>$unread]);
        } catch (PDOException $e) {
            error_log('Auto mark notification read failed: '.$e->getMessage());
            http_response_code(503);
            echo json_encode(['ok'=>false,'message'=>'We could not mark this notification as read. Please try again.']);
        }
    }
    exit;
}
