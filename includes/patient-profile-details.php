<?php
function savePatientProfileDetails(PDO $pdo, int $userId, array $details): void
{
    $stmt = $pdo->prepare('INSERT INTO PatientProfileDetails (UserID, Details) VALUES (?, ?) ON DUPLICATE KEY UPDATE Details = VALUES(Details)');
    $stmt->execute([$userId, json_encode($details, JSON_THROW_ON_ERROR)]);
}
