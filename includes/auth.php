<?php
/**
 * Session management, CSRF protection, and role-based access control (RBAC).
 * Require this at the top of any page that needs to know who is signed in.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/rate-limits.php';

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/** Returns the signed-in user's session data, or null if no one is signed in. */
function currentUser(): ?array
{
    $cached = $_SESSION['user'] ?? null;
    if (!$cached) return null;
    static $validatedKey = null;
    $key = $cached['UserID'] . ':' . ($_SESSION['auth_stamp'] ?? '');
    if ($validatedKey === $key) return $_SESSION['user'];
    try {
        $pdo = getDbConnection();
        if (!$pdo) throw new RuntimeException('Account verification unavailable.');
        $stmt = $pdo->prepare('SELECT u.UserID,u.ClinicID,u.RoleID,u.FirstName,u.LastName,u.Email,u.PasswordHash,u.Status,u.DeletedAt,r.RoleName FROM Users u JOIN Roles r ON r.RoleID=u.RoleID WHERE u.UserID=?');
        $stmt->execute([$cached['UserID']]);$fresh=$stmt->fetch();
        if (!$fresh || $fresh['Status'] !== 'Active' || $fresh['DeletedAt'] !== null
            || empty($_SESSION['auth_stamp']) || !hash_equals($_SESSION['auth_stamp'],hash('sha256',$fresh['PasswordHash'].'|'.strtolower($fresh['Email'])))
            || (int)$fresh['RoleID'] !== (int)$cached['RoleID'] || (int)$fresh['ClinicID'] !== (int)$cached['ClinicID']) {
            unset($_SESSION['user'],$_SESSION['auth_stamp']);return null;
        }
        unset($fresh['PasswordHash'],$fresh['Status'],$fresh['DeletedAt']);
        $_SESSION['user']=$fresh;$validatedKey=$key;return $fresh;
    } catch(Throwable $e) { error_log('Account session could not be verified.');unset($_SESSION['user'],$_SESSION['auth_stamp']);return null; }
}

/** True if a user is currently signed in. */
function isLoggedIn(): bool
{
    return currentUser() !== null;
}

/** Stores a user's session data after successful login/registration. */
function loginUser(array $user): void
{
    $stmt=getDbConnection()->prepare('SELECT PasswordHash,Email FROM Users WHERE UserID=?');
    $stmt->execute([$user['UserID']]);$identity=$stmt->fetch();
    if(!$identity)throw new RuntimeException('Account unavailable.');
    session_regenerate_id(true);
    $_SESSION['auth_stamp']=hash('sha256',$identity['PasswordHash'].'|'.strtolower($identity['Email']));
    $_SESSION['user'] = [
        'UserID'    => (int) $user['UserID'],
        'ClinicID'  => $user['ClinicID'] !== null ? (int) $user['ClinicID'] : null,
        'RoleID'    => (int) $user['RoleID'],
        'RoleName'  => $user['RoleName'],
        'FirstName' => $user['FirstName'],
        'LastName'  => $user['LastName'],
        'Email'     => $user['Email'],
    ];
}

/** Destroys the current session (logout). */
function logoutUser(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie('PHPSESSID', '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

/** The relative prefix ('' at root, '..' one level deep) used to build redirect URLs. */
function hqBaseUrl(): string
{
    return defined('HQ_BASE_URL') ? HQ_BASE_URL : '';
}

/** Redirects a guest to the login page; call at the top of any protected page. */
function requireLogin(): void
{
    if (!isLoggedIn()) {
        header('Location: ' . hqBaseUrl() . '/auth/login.php');
        exit;
    }
}

/** Redirects an already-logged-in visitor away from guest-only pages (login/register). */
function requireGuest(): void
{
    if (isLoggedIn()) {
        header('Location: ' . hqBaseUrl() . '/' . dashboardPathFor(currentUser()['RoleName']));
        exit;
    }
}

/**
 * Restricts a page to one or more roles. Call requireLogin() first, or use
 * this after it — an unauthenticated visitor is sent to the login page and
 * a wrong-role visitor is sent to their own dashboard instead of a blank 403.
 */
function requireRole(array $allowedRoles): void
{
    requireLogin();
    $user = currentUser();
    if (!in_array($user['RoleName'], $allowedRoles, true)) {
        header('Location: ' . hqBaseUrl() . '/' . dashboardPathFor($user['RoleName']));
        exit;
    }
}

/** Maps a role name to its dashboard's relative path from the app root. */
function dashboardPathFor(string $roleName): string
{
    return match ($roleName) {
        'Patient'   => 'patient/dashboard.php',
        'Staff'     => 'staff/dashboard.php',
        'Physician' => 'physician/dashboard.php',
        'Admin'     => 'admin/dashboard.php',
        default     => 'index.php',
    };
}

/**
 * Creates a password reset token for a user: generates a random raw token,
 * stores only its SHA-256 hash (valid for 30 minutes), and returns the raw
 * token so the caller can build a reset link with it. The raw token is
 * never persisted — only its hash is, mirroring how PasswordHash works.
 */
function createPasswordResetToken(PDO $pdo, int $userId): string
{
    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);

    $stmt = $pdo->prepare(
        'INSERT INTO PasswordResets (UserID, TokenHash, ExpiresAt)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))'
    );
    $stmt->execute([$userId, $tokenHash]);

    return $rawToken;
}

/**
 * Looks up a raw reset token and returns the matching {ResetID, UserID,
 * Email, FirstName} row if it exists, hasn't expired, and hasn't been used
 * yet — otherwise null.
 */
function findValidPasswordReset(PDO $pdo, string $rawToken): ?array
{
    $tokenHash = hash('sha256', $rawToken);

    $stmt = $pdo->prepare(
        'SELECT pr.ResetID, u.UserID, u.Email, u.FirstName
         FROM PasswordResets pr
         JOIN Users u ON u.UserID = pr.UserID
         WHERE pr.TokenHash = ? AND pr.UsedAt IS NULL AND pr.ExpiresAt > NOW()'
    );
    $stmt->execute([$tokenHash]);
    $row = $stmt->fetch();

    return $row ?: null;
}

/** Marks a password reset token as used so it cannot be replayed. */
function consumePasswordResetToken(PDO $pdo, int $resetId): void
{
    $stmt = $pdo->prepare('UPDATE PasswordResets SET UsedAt = NOW() WHERE ResetID = ?');
    $stmt->execute([$resetId]);
}

/** Creates a hashed, single-use verification link valid for 24 hours. */
function createEmailVerificationToken(PDO $pdo, int $userId): string
{
    $rawToken = bin2hex(random_bytes(32));
    $pdo->prepare('DELETE FROM EmailVerificationTokens WHERE UserID = ? AND UsedAt IS NULL')->execute([$userId]);
    $stmt = $pdo->prepare('INSERT INTO EmailVerificationTokens (UserID, TokenHash, ExpiresAt) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))');
    $stmt->execute([$userId, hash('sha256', $rawToken)]);
    return $rawToken;
}

/** Verifies and consumes one valid email token, then marks its patient verified. */
function verifyPatientEmail(PDO $pdo, string $rawToken): bool
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "SELECT t.VerificationID, u.UserID FROM EmailVerificationTokens t
             JOIN Users u ON u.UserID = t.UserID JOIN Roles r ON r.RoleID = u.RoleID
             WHERE t.TokenHash = ? AND t.UsedAt IS NULL AND t.ExpiresAt > NOW() AND u.EmailVerifiedAt IS NULL AND r.RoleName = 'Patient'
             FOR UPDATE"
        );
        $stmt->execute([hash('sha256', $rawToken)]);
        $row = $stmt->fetch();
        if (!$row) {
            $pdo->rollBack();
            return false;
        }
        $pdo->prepare('UPDATE Users SET EmailVerifiedAt = NOW() WHERE UserID = ?')->execute([(int) $row['UserID']]);
        $pdo->prepare('UPDATE EmailVerificationTokens SET UsedAt = NOW() WHERE VerificationID = ?')->execute([(int) $row['VerificationID']]);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Creates a one-time token used to verify email before patient registration. */
function createSignupEmailVerification(PDO $pdo, string $email): string
{
    $rawToken = bin2hex(random_bytes(32));
    $pdo->prepare('DELETE FROM SignupEmailVerifications WHERE Email = ? AND UsedAt IS NULL')->execute([$email]);
    $stmt = $pdo->prepare('INSERT INTO SignupEmailVerifications (Email, TokenHash, ExpiresAt) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))');
    $stmt->execute([$email, hash('sha256', $rawToken)]);
    return $rawToken;
}

/** Validate the code only in the browser session that requested it. No email is sent here. */
function verifySignupCode(PDO $pdo, string $email, string $code): ?string
{
    $challenge = $_SESSION['signup_code'] ?? [];
    if (!$challenge || empty($challenge['hash']) || ($challenge['expires'] ?? 0) <= time() || ($challenge['attempts'] ?? 0) >= 5 || strcasecmp((string) ($challenge['email'] ?? ''), $email) !== 0) {
        return 'This code is expired or unavailable. Request a new code.';
    }
    $_SESSION['signup_code']['attempts']++;
    if (!preg_match('/^[0-9]{6}$/D', $code) || !password_verify($code, $challenge['hash'])) {
        return 'Incorrect verification code. Please try again.';
    }
    $stmt = $pdo->prepare('UPDATE SignupEmailVerifications SET VerifiedAt = NOW() WHERE TokenHash = ? AND Email = ? AND UsedAt IS NULL AND VerifiedAt IS NULL AND ExpiresAt > NOW()');
    $stmt->execute([hash('sha256', $challenge['token']), $email]);
    if ($stmt->rowCount() !== 1) return 'This code has expired. Request a new code.';
    unset($_SESSION['signup_code']['hash']);
    return null;
}

/** Returns the confirmed email for a signup token, if it is still eligible. */
function findSignupEmailVerification(PDO $pdo, string $rawToken): ?array
{
    $stmt = $pdo->prepare('SELECT Email FROM SignupEmailVerifications WHERE TokenHash = ? AND VerifiedAt IS NOT NULL AND UsedAt IS NULL AND ExpiresAt > NOW()');
    $stmt->execute([hash('sha256', $rawToken)]);
    return $stmt->fetch() ?: null;
}

/** Generates (and caches in-session) a CSRF token for the current session. */
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Verifies a submitted CSRF token against the session's token. */
function verifyCsrfToken(?string $submitted): bool
{
    return is_string($submitted)
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $submitted);
}

/**
 * Role codes for the public 10-digit ID number: RR YY NNNNNN
 * (role code, 2-digit registration year, per-role-per-year sequence),
 * e.g. 0126000001 = first patient registered in 2026.
 */
const HQ_ID_ROLE_CODES = ['Patient' => '01', 'Physician' => '02', 'Staff' => '03', 'Admin' => '04'];

/**
 * Assigns Users.IDNumber to a newly created account (no-op if it already has
 * one). Call right after the INSERT. The number never changes afterwards --
 * even if the user's role does -- so it stays safe to print or share.
 */
function assignUserIdNumber(PDO $pdo, int $userId): ?string
{
    $stmt = $pdo->prepare(
        'SELECT u.IDNumber, r.RoleName, DATE_FORMAT(u.CreatedAt, "%y") AS Yr
         FROM Users u JOIN Roles r ON r.RoleID = u.RoleID WHERE u.UserID = ?'
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row) return null;
    if ($row['IDNumber']) return $row['IDNumber'];

    $prefix = (HQ_ID_ROLE_CODES[$row['RoleName']] ?? '00') . $row['Yr'];

    // The unique index on IDNumber guards against two sign-ups racing for the
    // same number; on a collision just recompute and try again.
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $maxStmt = $pdo->prepare('SELECT MAX(IDNumber) FROM Users WHERE IDNumber LIKE ?');
        $maxStmt->execute([$prefix . '%']);
        $max = $maxStmt->fetchColumn();
        $next = $prefix . str_pad((string) ($max ? (int) substr($max, 4) + 1 : 1), 6, '0', STR_PAD_LEFT);
        try {
            $pdo->prepare('UPDATE Users SET IDNumber = ? WHERE UserID = ? AND IDNumber IS NULL')->execute([$next, $userId]);
            return $next;
        } catch (PDOException $e) {
            if ($e->errorInfo[1] !== 1062) throw $e; // 1062 = duplicate key
        }
    }
    throw new RuntimeException('Could not assign an ID number to user ' . $userId);
}

/** Lock the reset token while changing the password and consuming all reset links. */
function resetPasswordOnce(PDO $pdo, string $rawToken, string $password): bool
{
    if(strlen($password)<8)throw new InvalidArgumentException('Password must be at least 8 characters long.');
    $pdo->beginTransaction();
    try {
        $stmt=$pdo->prepare("SELECT pr.ResetID,pr.UserID FROM PasswordResets pr JOIN Users u ON u.UserID=pr.UserID WHERE pr.TokenHash=? AND pr.UsedAt IS NULL AND pr.ExpiresAt>NOW() AND u.Status='Active' AND u.DeletedAt IS NULL FOR UPDATE");
        $stmt->execute([hash('sha256',$rawToken)]);$row=$stmt->fetch();
        if(!$row){$pdo->rollBack();return false;}
        $pdo->prepare('UPDATE Users SET PasswordHash=? WHERE UserID=?')->execute([password_hash($password,PASSWORD_DEFAULT),$row['UserID']]);
        $pdo->prepare('UPDATE PasswordResets SET UsedAt=NOW() WHERE UserID=? AND UsedAt IS NULL')->execute([$row['UserID']]);
        $pdo->commit();return true;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
