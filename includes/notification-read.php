<?php
/** Read only a notification owned by this patient or shared with this staff clinic. */
function hqMarkNotificationRead(PDO $pdo, string $role, int $scopeId, int $notificationId): ?int
{
    if (!in_array($role, ['Patient', 'Staff'], true) || $scopeId <= 0 || $notificationId <= 0) return null;
    $table = $role === 'Patient' ? 'PatientNotifications' : 'Notifications';
    $owner = $role === 'Patient' ? 'PatientID' : 'ClinicID';
    $stmt = $pdo->prepare("SELECT NotificationID FROM {$table} WHERE {$owner} = ? AND NotificationID = ?");
    $stmt->execute([$scopeId, $notificationId]);
    if (!$stmt->fetchColumn()) return null;
    $pdo->prepare("UPDATE {$table} SET IsRead = 1 WHERE {$owner} = ? AND NotificationID = ? AND IsRead = 0")->execute([$scopeId, $notificationId]);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$owner} = ? AND IsRead = 0");
    $stmt->execute([$scopeId]);
    return (int) $stmt->fetchColumn();
}
