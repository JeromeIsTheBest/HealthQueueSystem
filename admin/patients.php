<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';

define('HQ_BASE_URL', '..');
requireRole(['Admin']);

const ROLE_NAME = 'Patient';

$user = currentUser();
$pdo = getDbConnection();
$errors = [];
$flash = '';
$search = trim((string) ($_GET['q'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!$pdo) {
        $errors[] = 'The database is temporarily unavailable.';
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
                    logActivity($pdo, $user['UserID'], null, 'Changed patient status', "UserID {$memberId} set to {$status}.");
                    $flash = 'Account status updated.';
                } else {
                    $errors[] = 'The account could not be found.';
                }
            } catch (PDOException $e) {
                error_log('Patient status update failed: ' . $e->getMessage());
                $errors[] = 'We could not update this account right now.';
            }
        }
    }
}

$members = [];
if ($pdo) {
    try {
        $sql = "SELECT u.UserID, u.IDNumber, u.FirstName, u.LastName, u.Email, u.ContactNumber, u.Status, u.CreatedAt,
                       (SELECT COUNT(*) FROM Appointments a WHERE a.PatientID = u.UserID) AS AppointmentCount
                FROM Users u JOIN Roles r ON r.RoleID = u.RoleID
                WHERE r.RoleName = ? AND u.DeletedAt IS NULL";
        $params = [ROLE_NAME];
        if ($search !== '') {
            $sql .= " AND (CONCAT(u.FirstName, ' ', u.LastName) LIKE ? OR u.Email LIKE ? OR u.IDNumber LIKE ?)";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }
        $sql .= ' ORDER BY u.CreatedAt DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $members = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Patients list failed: ' . $e->getMessage());
        $errors[] = 'Patient information is temporarily unavailable.';
    }
}

$pageTitle = 'Manage Patients — HealthQueue';
$directoryKind = 'patients';
require __DIR__ . '/../includes/admin-directory.php';
require __DIR__ . '/../includes/header.php';
?>

<main class="admin-shell dr-page"><div class="container dr-container">
  <?php renderDirectoryHeading('Patient accounts', 'Find patient accounts and manage access.', null, null); ?>

  <?php if ($flash): ?><p class="form-message success" role="status"><?= htmlspecialchars($flash) ?></p><?php endif; ?>
  <?php if ($errors): ?><div class="form-message error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

  <?php renderDirectoryStats(); ?>
  <section class="admin-section dr-panel">
    <div class="portal-heading"><div><span class="section-kicker">Directory</span><h2>Patient directory</h2></div></div>
    <?php renderDirectoryFilters(); ?>
    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead><tr><th>Patient</th><th>Patient ID</th><th>Email</th><th>Contact</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($members as $member): ?>
          <tr>
            <td><span class="dr-person"><span class="dr-avatar"><?= htmlspecialchars(dirInitials($member)) ?></span><strong><?= htmlspecialchars($member['FirstName'].' '.$member['LastName']) ?></strong></span></td>
            <td><?= htmlspecialchars($member['IDNumber'] ?: 'Not assigned') ?></td>
            <td><?= htmlspecialchars($member['Email']) ?></td><td><?= htmlspecialchars($member['ContactNumber'] ?: 'Not provided') ?></td>
            <td><span class="status-badge status-<?= htmlspecialchars(strtolower($member['Status'])) ?>"><?= htmlspecialchars($member['Status']) ?></span></td>
            <td><div class="dr-actions"><button type="button" class="btn btn-outline btn-sm" data-directory-open="patientAccount<?= (int)$member['UserID'] ?>">View account</button><details class="dr-more"><summary aria-label="More patient actions">&hellip;</summary><div class="dr-more-content"><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><input type="hidden" name="form_type" value="update_status"><input type="hidden" name="member_id" value="<?= (int)$member['UserID'] ?>"><?php if ($member['Status']==='Active'): ?><button class="btn btn-outline btn-sm" name="status" value="Inactive" onclick="return confirm('Deactivate this account? The patient will no longer be able to sign in.');">Deactivate</button><?php else: ?><button class="btn btn-primary btn-sm" name="status" value="Active">Activate</button><?php endif; ?></form></div></details></div></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$members): ?><tr><td colspan="6" class="admin-empty"><?= $search !== '' ? 'No patients match "' . htmlspecialchars($search) . '".' : 'No patient accounts yet.' ?></td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php renderDirectoryPagination(); ?>
  </section>
<aside class="dr-help"><strong>Account access</strong><p>Patients register online. Manage their account status here.</p></aside>
</div></main>

<?php foreach ($members as $member): ?><dialog class="modal-box dr-dialog dr-patient-dialog" id="patientAccount<?= (int)$member['UserID'] ?>" aria-labelledby="patientTitle<?= (int)$member['UserID'] ?>"><button type="button" class="modal-close" data-directory-close aria-label="Close">&times;</button><h2 id="patientTitle<?= (int)$member['UserID'] ?>"><?= htmlspecialchars($member['FirstName'].' '.$member['LastName']) ?></h2><p class="modal-subtitle">Patient account</p><dl class="dr-account-details"><?php foreach (['Patient ID'=>$member['IDNumber'] ?: 'Not assigned','Email'=>$member['Email'],'Contact'=>$member['ContactNumber'] ?: 'Not provided','Status'=>$member['Status'],'Appointments'=>(int)$member['AppointmentCount'],'Joined'=>date('M j, Y',strtotime($member['CreatedAt']))] as $label=>$value): ?><div><dt><?= htmlspecialchars($label) ?></dt><dd><?= htmlspecialchars((string)$value) ?></dd></div><?php endforeach; ?></dl><button type="button" class="btn btn-outline" data-directory-close>Close</button></dialog><?php endforeach; ?>
<script src="<?= HQ_BASE_URL ?>/assets/js/admin-directory.js?v=<?= filemtime(__DIR__ . '/../assets/js/admin-directory.js') ?>" defer></script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
