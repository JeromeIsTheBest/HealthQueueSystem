<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

define('HQ_BASE_URL', '..');
requireGuest();
$pageTitle = 'Email Verified — HealthQueue';
$pdo = getDbConnection();
$token = (string) ($_GET['token'] ?? '');
$verified = false;
$error = '';

if ($token === '') {
    $error = 'This verification link is invalid.';
} elseif (!$pdo) {
    $error = 'Email verification is temporarily unavailable. Please try again later.';
} else {
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT SignupVerificationID FROM SignupEmailVerifications WHERE TokenHash = ? AND UsedAt IS NULL AND ExpiresAt > NOW() FOR UPDATE');
        $stmt->execute([hash('sha256', $token)]);
        $row = $stmt->fetch();
        if ($row) {
            $pdo->prepare('UPDATE SignupEmailVerifications SET VerifiedAt = COALESCE(VerifiedAt, NOW()) WHERE SignupVerificationID = ?')->execute([(int) $row['SignupVerificationID']]);
            $pdo->commit();
            $verified = true;
        } else {
            $pdo->rollBack();
            $error = 'This verification link is invalid, expired, or already used. Request a new one.';
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Signup email confirmation failed: ' . $e->getMessage());
        $error = 'We could not verify this email right now. Please try again later.';
    }
}

$signupUrl = HQ_BASE_URL . '/auth/register.php?email_verified_token=' . urlencode($token);
require __DIR__ . '/../includes/header.php';
?>
<section class="auth-shell"><div class="auth-card">
  <div class="auth-card-header"><span class="brand-mark"><img src="<?= HQ_BASE_URL ?>/assets/img/logo.png" alt=""></span><h2><?= $verified ? 'Email verified' : 'Email verification' ?></h2></div>
  <?php if ($verified): ?>
    <div class="form-message success" role="status">Your email was verified successfully. Return to the signup form to see the check mark and finish creating your account.</div>
    <a href="<?= htmlspecialchars($signupUrl) ?>" class="btn btn-primary btn-block">Return to signup</a>
  <?php else: ?>
    <div class="form-message error" role="alert"><?= htmlspecialchars($error) ?></div>
    <a href="<?= HQ_BASE_URL ?>/auth/register.php" class="btn btn-outline btn-block">Back to signup</a>
  <?php endif; ?>
</div></section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
