<?php
require_once __DIR__ . '/../includes/input-validation.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/physician-accounts.php';

define('HQ_BASE_URL', '..');
requireRole(['Admin']);

const ROLE_NAME = 'Physician';

$user = currentUser();
$pdo = getDbConnection();
$errors = [];
$flash = (string) ($_SESSION['admin_success'][basename(__FILE__)] ?? '');
unset($_SESSION['admin_success'][basename(__FILE__)]);
$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT) ?: null;
$search = trim((string) ($_GET['q'] ?? ''));
$values = ['first_name' => '', 'last_name' => '', 'email' => '', 'contact_number' => '', 'clinic_id' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!$pdo) {
        $errors[] = 'The database is temporarily unavailable.';
    } elseif (($_POST['form_type'] ?? '') === 'save_member') {
        $memberId = filter_input(INPUT_POST, 'member_id', FILTER_VALIDATE_INT) ?: null;
        foreach ($values as $field => $value) $values[$field] = trim((string) ($_POST[$field] ?? ''));
        $values['first_name'] = hqCapitalizeName($values['first_name']);
        $values['last_name'] = hqCapitalizeName($values['last_name']);
        $password = (string) ($_POST['password'] ?? '');

        if ($values['first_name'] === '' || $values['last_name'] === '') $errors[] = 'First and last name are required.';
        if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';
        if ($values['contact_number'] === '' || !hqValidMobile($values['contact_number'])) $errors[] = 'Enter a valid mobile number';
        if (!filter_var($values['clinic_id'], FILTER_VALIDATE_INT)) $errors[] = 'Please select a clinic.';
        if (!$memberId && strlen($password) < 8) $errors[] = 'Password must be at least 8 characters long.';
        if ($memberId && $password !== '' && strlen($password) < 8) $errors[] = 'New password must be at least 8 characters long.';

        if (!$errors) {
            try {
                $pdo->beginTransaction();
                $pdo->prepare('SELECT ClinicID FROM Clinic WHERE ClinicID=? FOR UPDATE')->execute([(int)$values['clinic_id']]);
                if($memberId){$old=$pdo->prepare("SELECT ClinicID FROM Users WHERE UserID=? AND RoleID=(SELECT RoleID FROM Roles WHERE RoleName='Physician') FOR UPDATE");$old->execute([$memberId]);if((int)$old->fetchColumn()!==(int)$values['clinic_id'])throw new RuntimeException('Clinic reassignment is restricted to preserve account ownership and clinical history.');}
                $clinicStmt = $pdo->prepare("SELECT ClinicID FROM Clinic WHERE ClinicID = ? AND archived = 0 AND Status = 'Active'");
                $clinicStmt->execute([(int) $values['clinic_id']]);
                $emailStmt = $pdo->prepare('SELECT UserID FROM Users WHERE Email = ? AND UserID <> ?');
                $emailStmt->execute([$values['email'], $memberId ?: 0]);

                if (!$clinicStmt->fetch()) {
                    $errors[] = 'Please select an active clinic.';
                } elseif ($emailStmt->fetch()) {
                    $errors[] = 'An account with this email already exists.';
                } elseif (hqMobileInUse($pdo,$values['contact_number'],(int)($memberId?:0))) {
                    $errors[] = 'This mobile number is already registered to another account.';
                } elseif ($memberId) {
                    $sql = 'UPDATE Users SET FirstName = ?, LastName = ?, Email = ?, ContactNumber = ?, ClinicID = ?';
                    $params = [$values['first_name'], $values['last_name'], $values['email'], $values['contact_number'], (int) $values['clinic_id']];
                    if ($password !== '') { $sql .= ', PasswordHash = ?'; $params[] = password_hash($password, PASSWORD_DEFAULT); }
                    $sql .= ' WHERE UserID = ? AND RoleID = (SELECT RoleID FROM Roles WHERE RoleName = ?)';
                    $params[] = $memberId;
                    $params[] = ROLE_NAME;
                    $pdo->prepare($sql)->execute($params);
                    logActivity($pdo, $user['UserID'], (int) $values['clinic_id'], 'Updated physician', $values['first_name'] . ' ' . $values['last_name']);
                    $flash = 'Physician account updated.';
                } else {
                    $roleStmt = $pdo->prepare('SELECT RoleID FROM Roles WHERE RoleName = ?');
                    $roleStmt->execute([ROLE_NAME]);
                    $role = $roleStmt->fetch();
                    $stmt = $pdo->prepare('INSERT INTO Users (ClinicID, RoleID, FirstName, LastName, Email, ContactNumber, PasswordHash) VALUES (?, ?, ?, ?, ?, ?, ?)');
                    $stmt->execute([(int) $values['clinic_id'], $role['RoleID'], $values['first_name'], $values['last_name'], $values['email'], $values['contact_number'], password_hash($password, PASSWORD_DEFAULT)]);
                    $createdId=(int)$pdo->lastInsertId();
                    assignUserIdNumber($pdo, $createdId);
                    registerPhysicianOwner($pdo,(int)$values['clinic_id'],$createdId);
                    logActivity($pdo, $user['UserID'], (int) $values['clinic_id'], 'Created physician', $values['first_name'] . ' ' . $values['last_name']);
                    $flash = 'Physician account created.';
                }
                if($errors){$pdo->rollBack();}else{$pdo->commit();}
                if (!$errors) {
                    $_SESSION['admin_success'][basename(__FILE__)] = $flash !== '' ? $flash : 'Account saved.';
                header('Location: ' . HQ_BASE_URL . '/admin/physicians.php');
                    exit;
                }
            } catch (RuntimeException $e) {
                if($pdo->inTransaction())$pdo->rollBack();
                $errors[]=hqDuplicateMobileError($e)?'This mobile number is already registered to another account.':($e instanceof PDOException?'We could not save this account.':$e->getMessage());
                error_log('Physician save failed: ' . $e->getMessage());
            }
        }
    } elseif (($_POST['form_type'] ?? '') === 'update_status') {
        $memberId = filter_input(INPUT_POST, 'member_id', FILTER_VALIDATE_INT);
        $status = $_POST['status'] ?? '';
        if (!$memberId || !in_array($status, ['Active', 'Inactive'], true)) {
            $errors[] = 'Invalid account status update.';
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE Users u JOIN Roles r ON r.RoleID = u.RoleID SET u.Status = ? WHERE u.UserID = ? AND r.RoleName = ? AND u.DeletedAt IS NULL");
                $stmt->execute([$status, $memberId, ROLE_NAME]);
                if ($stmt->rowCount()) {
                    logActivity($pdo, $user['UserID'], null, 'Changed physician status', "UserID {$memberId} set to {$status}.");
                    $flash = 'Account status updated.';
                } else {
                    $errors[] = 'The account could not be found.';
                }
            } catch (PDOException $e) {
                error_log('Physician status update failed: ' . $e->getMessage());
                $errors[] = 'We could not update this account.';
            }
        }
    }
}

$activeClinics = [];
$members = [];
$editingMember = null;
if ($pdo) {
    try {
        $activeClinics = $pdo->query("SELECT ClinicID, ClinicName FROM Clinic WHERE archived = 0 AND Status = 'Active' ORDER BY ClinicName")->fetchAll();
        $sql = "SELECT u.UserID, u.ClinicID, u.FirstName, u.LastName, u.Email, u.ContactNumber, u.Status, u.CreatedAt, c.ClinicName, o.MainPhysicianID, (SELECT COUNT(*) FROM Users sub JOIN Roles sr ON sr.RoleID=sub.RoleID WHERE sub.ClinicID=u.ClinicID AND sub.UserID<>o.MainPhysicianID AND sr.RoleName='Physician' AND sub.DeletedAt IS NULL) AS SubAccountCount
                FROM Users u JOIN Roles r ON r.RoleID = u.RoleID LEFT JOIN Clinic c ON c.ClinicID = u.ClinicID LEFT JOIN PhysicianAccountOwners o ON o.ClinicID=u.ClinicID
                WHERE r.RoleName = ? AND u.DeletedAt IS NULL";
        $params = [ROLE_NAME];
        if ($search !== '') {
            $sql .= " AND (CONCAT(u.FirstName, ' ', u.LastName) LIKE ? OR u.Email LIKE ?)";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }
        $sql .= ' ORDER BY c.ClinicName, u.LastName';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $members = $stmt->fetchAll();

        if ($editId) {
            $editStmt = $pdo->prepare(
                "SELECT u.UserID, u.FirstName, u.LastName, u.Email, u.ContactNumber, u.ClinicID
                 FROM Users u JOIN Roles r ON r.RoleID = u.RoleID WHERE u.UserID = ? AND r.RoleName = ?"
            );
            $editStmt->execute([$editId, ROLE_NAME]);
            $editingMember = $editStmt->fetch();
            if ($editingMember) {
                $values = [
                    'first_name' => $editingMember['FirstName'], 'last_name' => $editingMember['LastName'],
                    'email' => $editingMember['Email'], 'contact_number' => $editingMember['ContactNumber'] ?? '',
                    'clinic_id' => (string) $editingMember['ClinicID'],
                ];
            }
        }
    } catch (PDOException $e) {
        error_log('Physicians list failed: ' . $e->getMessage());
        $errors[] = 'Physician information is temporarily unavailable.';
    }
}

$pageTitle = 'Manage Physicians — HealthQueue';
$directoryKind = 'physicians';
require __DIR__ . '/../includes/admin-directory.php';
require __DIR__ . '/../includes/header.php';
?>

<main class="admin-shell dr-page"><div class="container dr-container">
  <?php renderDirectoryHeading('Physicians', 'Create accounts and manage your clinic team.', 'memberDirectoryModal', 'Add physician'); ?>

  <?php if ($flash): ?><p class="form-message success" role="status"><?= htmlspecialchars($flash) ?></p><?php endif; ?>
  <?php if ($errors): ?><div class="form-message error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

  <?php renderDirectoryStats(); ?>

  <dialog class="modal-box dr-dialog" id="memberDirectoryModal" aria-labelledby="directoryFormTitle" data-open-on-load="<?= $editingMember || ($errors && ($_POST['form_type'] ?? '') === 'save_member') ? '1' : '0' ?>">
    <button type="button" class="modal-close" data-directory-close aria-label="Close">&times;</button>
    <div class="portal-heading"><div><span class="section-kicker"><?= $editingMember ? 'Edit physician' : 'Add physician' ?></span><h2 id="directoryFormTitle"><?= $editingMember ? htmlspecialchars($editingMember['FirstName'] . ' ' . $editingMember['LastName']) : 'New physician account' ?></h2></div></div>
    <p>The first physician in a clinic is its Main Account. Additional physicians are Sub Accounts; only Main can access clinic earnings and withdrawals.</p>
    <form class="registration-form" method="post" style="max-width:640px;">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
      <input type="hidden" name="form_type" value="save_member">
      <?php if ($editingMember): ?><input type="hidden" name="member_id" value="<?= (int) $editingMember['UserID'] ?>"><?php endif; ?>
      <div class="form-stack form-cols-2">
        <label>First name<input name="first_name" value="<?= htmlspecialchars($values['first_name']) ?>" required></label>
        <label>Last name<input name="last_name" value="<?= htmlspecialchars($values['last_name']) ?>" required></label>
      </div>
      <div class="form-stack">
        <label>Clinic<select name="clinic_id" required><option value="">Select an active clinic</option><?php foreach ($activeClinics as $clinic): ?><option value="<?= (int) $clinic['ClinicID'] ?>" <?= $values['clinic_id'] === (string) $clinic['ClinicID'] ? 'selected' : '' ?>><?= htmlspecialchars($clinic['ClinicName']) ?></option><?php endforeach; ?></select></label>
        <label>Email address<input type="email" name="email" value="<?= htmlspecialchars($values['email']) ?>" required></label>
        <label>Contact number<input type="tel" name="contact_number" inputmode="numeric" maxlength="11" pattern="09[0-9]{9}" title="Enter a valid mobile number" value="<?= htmlspecialchars($values['contact_number']) ?>" required></label>
        <label><?= $editingMember ? 'New password ' : 'Temporary password' ?><span class="optional"><?= $editingMember ? '(leave blank to keep current)' : '' ?></span><input type="password" name="password" minlength="8" <?= $editingMember ? '' : 'required' ?>></label>
      </div>
      <button type="submit" class="btn btn-primary btn-block"><?= $editingMember ? 'Save changes' : 'Create account' ?></button>
      <?php if ($editingMember): ?><a href="<?= HQ_BASE_URL ?>/admin/physicians.php" class="text-link" style="display:block;text-align:center;margin-top:12px;">Cancel edit</a><?php endif; ?>
    </form>
  </dialog>

  <section class="admin-section dr-panel dr-physician-directory">
    <div class="portal-heading"><div><span class="section-kicker">Directory</span><h2>Physician directory</h2></div></div>
    <?php renderDirectoryFilters(); ?>
    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead><tr><th>Name</th><th>Clinic</th><th>Account type</th><th>Sub accounts</th><th>Email</th><th>Contact</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($members as $member): ?>
          <tr>
            <td><span class="dr-person"><span class="dr-avatar"><?= htmlspecialchars(dirInitials($member)) ?></span><strong>Dr. <?= htmlspecialchars($member['FirstName'] . ' ' . $member['LastName']) ?></strong></span></td>
            <td><?= htmlspecialchars($member['ClinicName'] ?? 'Not assigned') ?></td>
            <td><?= (int)$member['MainPhysicianID']===(int)$member['UserID']?'Main Account':'Sub Account' ?></td>
            <td><?php if((int)$member['MainPhysicianID']===(int)$member['UserID']): ?><div class="dr-sub-accounts"><span class="dr-sub-count"><?= (int)$member['SubAccountCount'] ?></span><button type="button" class="btn btn-outline btn-sm" data-directory-open="subs<?= (int)$member['UserID'] ?>">View sub-accounts</button></div><?php else: ?>&mdash;<?php endif; ?></td>
            <td><?= htmlspecialchars($member['Email']) ?></td>
            <td><?= htmlspecialchars($member['ContactNumber'] ?? '') ?></td>
            <td><span class="status-badge status-<?= strtolower(htmlspecialchars($member['Status'])) ?>"><?= htmlspecialchars($member['Status']) ?></span></td>
            <td>
              <div class="dr-actions">
                <a href="<?= HQ_BASE_URL ?>/admin/physicians.php?edit=<?= (int) $member['UserID'] ?>#memberDirectoryModal" class="btn btn-outline btn-sm">Edit</a>

                <form method="post" class="dr-status-action"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><input type="hidden" name="form_type" value="update_status"><input type="hidden" name="member_id" value="<?= (int) $member['UserID'] ?>">
                  <?php if ($member['Status'] === 'Active'): ?><button class="btn btn-outline btn-sm" name="status" value="Inactive" onclick="return confirm('Deactivate this account? The physician will no longer be able to sign in.');">Deactivate</button>
                  <?php else: ?><button class="btn btn-primary btn-sm" name="status" value="Active">Activate</button><?php endif; ?>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$members): ?><tr><td colspan="8" class="admin-empty"><?= $search !== '' ? 'No physicians match "' . htmlspecialchars($search) . '".' : 'No physician accounts yet.' ?></td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php renderDirectoryPagination(); ?>
  </section>
<?php foreach($members as $main): if((int)$main['MainPhysicianID']!==(int)$main['UserID'])continue;
 $subStmt=$pdo->prepare("SELECT u.FirstName,u.LastName,u.Status,u.CreatedAt FROM Users u JOIN Roles r ON r.RoleID=u.RoleID WHERE u.ClinicID=? AND u.UserID<>? AND r.RoleName='Physician' AND u.DeletedAt IS NULL ORDER BY u.LastName");$subStmt->execute([$main['ClinicID'],$main['UserID']]); ?>
<dialog class="modal-box dr-dialog" id="subs<?= (int)$main['UserID'] ?>" aria-labelledby="subsTitle<?= (int)$main['UserID'] ?>"><button type="button" class="modal-close" data-directory-close aria-label="Close">&times;</button><h2 id="subsTitle<?= (int)$main['UserID'] ?>">Sub-accounts</h2><p>Under Dr. <?= htmlspecialchars($main['FirstName'].' '.$main['LastName']) ?> ? <?= htmlspecialchars($main['ClinicName']) ?></p><table class="admin-table"><thead><tr><th>Physician</th><th>Status</th><th>Added</th></tr></thead><tbody><?php $subRows=$subStmt->fetchAll();foreach($subRows as $sub): ?><tr><td>Dr. <?= htmlspecialchars($sub['FirstName'].' '.$sub['LastName']) ?></td><td><?= htmlspecialchars($sub['Status']) ?></td><td><?= htmlspecialchars(date('j M Y',strtotime($sub['CreatedAt']))) ?></td></tr><?php endforeach; ?><?php if(!$subRows): ?><tr><td colspan="3">No sub-accounts yet.</td></tr><?php endif; ?></tbody></table><p>Active sub-accounts appear in patient booking.</p></dialog>
<?php endforeach; ?>
<aside class="dr-help"><strong>Clinic assignments</strong><p>Each physician account is assigned to a clinic.</p></aside>
</div></main>

<script src="<?= HQ_BASE_URL ?>/assets/js/admin-directory.js?v=<?= filemtime(__DIR__ . '/../assets/js/admin-directory.js') ?>" defer></script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
