<?php
/** Read only a personal patient/physician notification or one shared with this staff clinic. */
function hqMarkNotificationRead(PDO $pdo, string $role, int $scopeId, int $notificationId): ?int
{
    if (!in_array($role, ['Patient', 'Staff', 'Physician'], true) || $scopeId <= 0 || $notificationId <= 0) return null;
    $table = ['Patient'=>'PatientNotifications','Staff'=>'Notifications','Physician'=>'PhysicianNotifications'][$role];
    $owner = ['Patient'=>'PatientID','Staff'=>'ClinicID','Physician'=>'PhysicianID'][$role];
    $stmt = $pdo->prepare("SELECT NotificationID FROM {$table} WHERE {$owner} = ? AND NotificationID = ?");
    $stmt->execute([$scopeId, $notificationId]);
    if (!$stmt->fetchColumn()) return null;
    $pdo->prepare("UPDATE {$table} SET IsRead = 1 WHERE {$owner} = ? AND NotificationID = ? AND IsRead = 0")->execute([$scopeId, $notificationId]);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$owner} = ? AND IsRead = 0");
    $stmt->execute([$scopeId]);
    return (int) $stmt->fetchColumn();
}
