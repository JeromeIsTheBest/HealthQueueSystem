<?php
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
$token = (string) ($_GET['token'] ?? '');
$result = ['verified' => false];
$pdo = getDbConnection();

if ($token !== '' && $pdo) {
    try {
        $stmt = $pdo->prepare('SELECT VerifiedAt FROM SignupEmailVerifications WHERE TokenHash = ? AND UsedAt IS NULL AND ExpiresAt > NOW()');
        $stmt->execute([hash('sha256', $token)]);
        $verifiedAt = $stmt->fetchColumn();
        $result['verified'] = $verifiedAt !== false && $verifiedAt !== null;
    } catch (Throwable $e) {
        error_log('Signup email status lookup failed: ' . $e->getMessage());
        http_response_code(503);
    }
}

echo json_encode($result);
