<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/email.php';

define('HQ_BASE_URL', '..');
requireGuest();
$pageTitle = 'Resend Verification — HealthQueue';
$pdo = getDbConnection();
$email = '';
$submitted = false;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    }
    $email = trim((string) ($_POST['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';
    if (!$errors && !$pdo) $errors[] = 'Email verification is temporarily unavailable. Please try again later.';

    if (!$errors && $pdo) {
        $submitted = true;
        try {
            $stmt = $pdo->prepare(
                "SELECT u.UserID, u.FirstName FROM Users u JOIN Roles r ON r.RoleID = u.RoleID
                 WHERE u.Email = ? AND u.DeletedAt IS NULL AND u.EmailVerifiedAt IS NULL AND r.RoleName = 'Patient'
                   AND NOT EXISTS (SELECT 1 FROM EmailVerificationTokens t WHERE t.UserID = u.UserID AND t.CreatedAt > DATE_SUB(NOW(), INTERVAL 1 MINUTE))"
            );
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            if ($user) {
                $token = createEmailVerificationToken($pdo, (int) $user['UserID']);
                $url = healthQueueUrl('auth/verify-email.php?token=' . urlencode($token));
                sendEmailVerificationEmail($email, (string) $user['FirstName'], $url);
            }
        } catch (Throwable $e) {
            error_log('Resend email verification failed: ' . $e->getMessage());
            // Keep the same confirmation for known and unknown email addresses.
        }
    }
}

require __DIR__ . '/../includes/header.php';
?>
<section class="auth-shell"><div class="auth-card">
  <div class="auth-card-header"><span class="brand-mark"><img src="<?= HQ_BASE_URL ?>/assets/img/logo.png" alt=""></span><h2>Resend verification email</h2><p>Enter the address used to create your account.</p></div>
  <?php if ($errors): ?><div class="form-message error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <?php if ($submitted): ?>
    <div class="form-message success" role="status">If that address belongs to an unverified HealthQueue patient account, a verification link will be sent when eligible. Check your inbox and spam folder.</div>
  <?php else: ?>
    <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><div class="form-stack"><label>Email address<input type="email" name="email" value="<?= htmlspecialchars($email) ?>" required autofocus></label></div><button class="btn btn-primary btn-block" type="submit">Send verification link</button></form>
  <?php endif; ?>
  <p class="auth-footer-note"><a href="<?= HQ_BASE_URL ?>/auth/login.php">Back to sign in</a></p>
</div></section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
