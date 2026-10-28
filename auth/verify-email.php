<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
requireGuest();

define('HQ_BASE_URL', '..');
$pageTitle = 'Verify Email — HealthQueue';
$pdo = getDbConnection();
$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$verified = false;
$valid = false;
$error = '';

if ($token !== '' && $pdo) {
    try {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
                $error = 'Your session expired. Please open the link from your email again.';
            } else {
                $verified = verifyPatientEmail($pdo, $token);
                $valid = $verified;
                if (!$verified) $error = 'This verification link is invalid, expired, or already used. Request a new link to continue.';
            }
        } else {
            $stmt = $pdo->prepare(
                "SELECT 1 FROM EmailVerificationTokens t JOIN Users u ON u.UserID = t.UserID JOIN Roles r ON r.RoleID = u.RoleID
                 WHERE t.TokenHash = ? AND t.UsedAt IS NULL AND t.ExpiresAt > NOW() AND u.EmailVerifiedAt IS NULL AND r.RoleName = 'Patient'"
            );
            $stmt->execute([hash('sha256', $token)]);
            $valid = (bool) $stmt->fetchColumn();
            if (!$valid) $error = 'This verification link is invalid, expired, or already used. Request a new link to continue.';
        }
    } catch (Throwable $e) {
        error_log('Email verification failed: ' . $e->getMessage());
        $error = 'We could not verify your email right now. Please try again later.';
    }
} elseif ($token === '') {
    $error = 'This verification link is invalid.';
} else {
    $error = 'Email verification is temporarily unavailable. Please try again later.';
}

require __DIR__ . '/../includes/header.php';
?>
<section class="auth-shell"><div class="auth-card">
  <div class="auth-card-header"><span class="brand-mark"><img src="<?= HQ_BASE_URL ?>/assets/img/logo.png" alt=""></span><h2>Verify your email</h2></div>
  <?php if ($verified): ?>
    <div class="form-message success" role="status">Your email is verified. You can now sign in.</div>
    <a href="<?= HQ_BASE_URL ?>/auth/login.php" class="btn btn-primary btn-block">Sign in</a>
  <?php else: ?>
    <?php if ($error): ?><div class="form-message <?= $valid ? 'success' : 'error' ?>" role="status"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($valid): ?>
      <p>Confirm that you can access this email address to activate your HealthQueue account.</p>
      <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>"><button class="btn btn-primary btn-block" type="submit">Verify email address</button></form>
    <?php else: ?>
      <a href="<?= HQ_BASE_URL ?>/auth/resend-verification.php" class="btn btn-outline btn-block">Request a new link</a>
    <?php endif; ?>
  <?php endif; ?>
</div></section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
