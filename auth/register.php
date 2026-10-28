<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/email.php';

define('HQ_BASE_URL', '..');
requireGuest();

$pageTitle = 'Create Your Account — HealthQueue';
$pdo = getDbConnection();

$errors = [];
$emailVerificationSent = false;
$emailVerified = false;
$signupToken = trim((string) ($_GET['email_verified_token'] ?? $_POST['email_verification_token'] ?? ''));
$pendingSignupToken = trim((string) ($_POST['email_verification_token'] ?? ''));
$values = ['first_name' => '', 'last_name' => '', 'email' => '', 'contact_number' => ''];

if ($signupToken !== '' && $pdo) {
    try {
        $verification = findSignupEmailVerification($pdo, $signupToken);
        if ($verification) {
            $emailVerified = true;
            $values['email'] = (string) $verification['Email'];
            if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_SESSION['signup_draft'])) {
                foreach (['first_name', 'last_name', 'contact_number'] as $field) {
                    $values[$field] = trim((string) ($_SESSION['signup_draft'][$field] ?? ''));
                }
            }
        } elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $errors[] = 'This email verification has expired or was already used. Please request a new link.';
            $signupToken = '';
        }
    } catch (PDOException $e) {
        error_log('Signup email verification lookup failed: ' . $e->getMessage());
        $errors[] = 'Email verification is temporarily unavailable. Please try again later.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    }

    foreach ($values as $field => $value) {
        $values[$field] = trim((string) ($_POST[$field] ?? ''));
    }
    $password        = (string) ($_POST['password'] ?? '');
    $passwordConfirm  = (string) ($_POST['password_confirm'] ?? '');
    $intent = (string) ($_POST['intent'] ?? 'create_account');

    if ($values['email'] === '') {
        $errors[] = 'Email address is required.';
    } elseif (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if ($intent === 'create_account') {
        if ($values['first_name'] === '') $errors[] = 'First name is required.';
        if ($values['last_name'] === '')  $errors[] = 'Last name is required.';
        if ($values['contact_number'] === '') $errors[] = 'Contact number is required.';
        if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters long.';
        elseif ($password !== $passwordConfirm) $errors[] = 'Password and confirmation do not match.';
        if (!$emailVerified || $signupToken === '') $errors[] = 'Verify your email address before creating your account.';
    }

    if (!$errors && !$pdo) {
        $errors[] = 'Registration is temporarily unavailable. Please try again later.';
    }

    if (!$errors && $pdo) {
        try {
            $existing = $pdo->prepare('SELECT UserID FROM Users WHERE Email = ?');
            $existing->execute([$values['email']]);
            if ($existing->fetch()) {
                $errors[] = 'An account with that email already exists. Try signing in instead.';
            }
        } catch (PDOException $e) {
            error_log('Register lookup failed: ' . $e->getMessage());
            $errors[] = 'We could not process your registration right now. Please try again later.';
        }
    }

    if (!$errors && $pdo && $intent === 'send_email_verification') {
        try {
            $recent = $pdo->prepare('SELECT COUNT(*) FROM SignupEmailVerifications WHERE Email = ? AND CreatedAt > DATE_SUB(NOW(), INTERVAL 1 MINUTE)');
            $recent->execute([$values['email']]);
            if ((int) $recent->fetchColumn() > 0) {
                $errors[] = 'A verification link was just requested. Check your inbox or wait one minute before requesting another.';
            } else {
                $_SESSION['signup_draft'] = [
                    'first_name' => $values['first_name'],
                    'last_name' => $values['last_name'],
                    'contact_number' => $values['contact_number'],
                ];
                $token = createSignupEmailVerification($pdo, $values['email']);
                $url = healthQueueUrl('auth/verify-signup-email.php?token=' . urlencode($token));
                $emailVerificationSent = sendSignupEmailVerificationEmail($values['email'], $url);
                $pendingSignupToken = $token;
                if (!$emailVerificationSent) $errors[] = 'We could not send the verification email. Check the Gmail setup and try again in one minute.';
            }
        } catch (Throwable $e) {
            error_log('Signup verification email request failed: ' . $e->getMessage());
            $errors[] = 'We could not send a verification link right now. Please try again later.';
        }
    }

    if (!$errors && $pdo && $intent === 'create_account') {
        try {
            $pdo->beginTransaction();
            $tokenStmt = $pdo->prepare('SELECT Email FROM SignupEmailVerifications WHERE TokenHash = ? AND VerifiedAt IS NOT NULL AND UsedAt IS NULL AND ExpiresAt > NOW() FOR UPDATE');
            $tokenStmt->execute([hash('sha256', $signupToken)]);
            $verifiedRow = $tokenStmt->fetch();
            if (!$verifiedRow || strcasecmp((string) $verifiedRow['Email'], $values['email']) !== 0) {
                throw new RuntimeException('Signup email verification is missing or no longer valid.');
            }

            $roleStmt = $pdo->prepare('SELECT RoleID FROM Roles WHERE RoleName = ?');
            $roleStmt->execute(['Patient']);
            $role = $roleStmt->fetch();

            if (!$role) {
                throw new RuntimeException('Patient role is unavailable.');
            }
            $insert = $pdo->prepare(
                'INSERT INTO Users (ClinicID, RoleID, FirstName, LastName, Email, ContactNumber, PasswordHash, EmailVerifiedAt)
                 VALUES (NULL, ?, ?, ?, ?, ?, ?, NOW())'
            );
            $insert->execute([
                $role['RoleID'], $values['first_name'], $values['last_name'], $values['email'],
                $values['contact_number'], password_hash($password, PASSWORD_DEFAULT),
            ]);
            $newUserId = (int) $pdo->lastInsertId();
            assignUserIdNumber($pdo, $newUserId);
            $pdo->prepare('UPDATE SignupEmailVerifications SET UsedAt = NOW() WHERE TokenHash = ?')->execute([hash('sha256', $signupToken)]);
            $pdo->commit();
            unset($_SESSION['signup_draft']);
            loginUser([
                'UserID' => $newUserId, 'ClinicID' => null, 'RoleID' => $role['RoleID'],
                'RoleName' => 'Patient', 'FirstName' => $values['first_name'],
                'LastName' => $values['last_name'], 'Email' => $values['email'],
            ]);
            header('Location: ' . HQ_BASE_URL . '/patient/dashboard.php');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Registration failed: ' . $e->getMessage());
            $errors[] = $e instanceof RuntimeException ? $e->getMessage() : 'We could not create your account right now. Please try again later.';
        }
    }
}

require __DIR__ . '/../includes/header.php';
?>

<section class="auth-shell">
  <div class="auth-card">
    <div class="auth-card-header">
      <span class="brand-mark">
        <img src="<?= HQ_BASE_URL ?>/assets/img/logo.png" alt="">
      </span>
      <h2>Create your account</h2>
      <p>Book appointments and track your queue position online.</p>
    </div>

    <?php if ($errors): ?>
      <div class="form-message error" role="alert">
        <strong>Please check the following:</strong>
        <ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>

    <?php if ($emailVerificationSent): ?>
      <div class="form-message success" role="status">Verification link sent. Open it from your inbox, then return here to finish creating your account.</div>
    <?php endif; ?>
    <?php if ($emailVerified): ?>
      <div class="form-message success" role="status">Email verified. Complete the form to create your account.</div>
    <?php endif; ?>
    <form method="post" action="">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
      <?php if ($emailVerified): ?><input type="hidden" name="email_verification_token" value="<?= htmlspecialchars($signupToken) ?>"><?php endif; ?>

      <div class="form-stack form-cols-2">
        <label>First name
          <input type="text" name="first_name" value="<?= htmlspecialchars($values['first_name']) ?>" required autofocus>
        </label>
        <label>Last name
          <input type="text" name="last_name" value="<?= htmlspecialchars($values['last_name']) ?>" required>
        </label>
      </div>

      <div class="form-stack">
        <div class="signup-email-row" id="signupEmailRow">
          <label>Email address
            <input id="signupEmailInput" type="email" name="email" value="<?= htmlspecialchars($values['email']) ?>" <?= $emailVerified ? 'readonly' : '' ?> required>
          </label>
          <?php if (!$emailVerified): ?>
            <button type="submit" name="intent" value="send_email_verification" formnovalidate class="btn btn-outline signup-email-verify">Verify email</button>
          <?php endif; ?>
          <span id="signupEmailVerifiedMark" class="signup-email-verified-mark" <?= $emailVerified ? '' : 'hidden' ?> aria-live="polite">✓ Verified</span>
        </div>
        <?php if ($emailVerified): ?>
          <input type="hidden" name="email_verification_token" value="<?= htmlspecialchars($signupToken) ?>">
        <?php elseif ($pendingSignupToken !== ''): ?>
          <input type="hidden" id="pendingSignupEmailToken" name="email_verification_token" value="<?= htmlspecialchars($pendingSignupToken) ?>">
        <?php endif; ?>
        <label>Contact number
          <input type="tel" name="contact_number" value="<?= htmlspecialchars($values['contact_number']) ?>" placeholder="09XX XXX XXXX" required>
        </label>
        <label>Password
          <input type="password" name="password" minlength="8" required>
        </label>
        <label>Confirm password
          <input type="password" name="password_confirm" minlength="8" required>
        </label>
      </div>

      <button type="submit" class="btn btn-primary btn-block">Create account</button>
    </form>

    <p class="auth-footer-note">
      Already have an account? <a href="<?= HQ_BASE_URL ?>/auth/login.php">Sign in</a>
    </p>
  </div>
</section>

<?php if (!$emailVerified && $pendingSignupToken !== ''): ?>
<script>
(function () {
  var token = <?= json_encode($pendingSignupToken) ?>;
  var input = document.getElementById('signupEmailInput');
  var button = document.querySelector('.signup-email-verify');
  var mark = document.getElementById('signupEmailVerifiedMark');
  var checking = false;
  function checkVerification() {
    if (checking || document.hidden) return;
    checking = true;
    fetch('<?= HQ_BASE_URL ?>/auth/signup-email-status.php?token=' + encodeURIComponent(token), { headers: { 'Accept': 'application/json' }, cache: 'no-store' })
      .then(function (response) { return response.json(); })
      .then(function (data) {
        if (data.verified) {
          input.readOnly = true;
          if (button) button.hidden = true;
          mark.hidden = false;
        }
      })
      .catch(function () {})
      .finally(function () { checking = false; });
  }
  checkVerification();
  window.setInterval(checkVerification, 3000);
  window.addEventListener('focus', checkVerification);
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
