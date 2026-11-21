<?php
require_once __DIR__.'/../includes/clinic-fees.php';
require_once __DIR__ . '/../includes/input-validation.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/clinic-photos.php';
require_once __DIR__ . '/../includes/clinic-management.php';

define('HQ_BASE_URL', '..');
requireRole(['Admin']);

$user = currentUser();
$pdo = getDbConnection();
$errors = [];
$flash = (string) ($_SESSION['admin_success'][basename(__FILE__)] ?? '');
unset($_SESSION['admin_success'][basename(__FILE__)]);
$showArchived = isset($_GET['archived']);
$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT) ?: null;
$search = trim((string) ($_GET['q'] ?? ''));
$values = ['clinic_name' => '', 'address' => '', 'contact_number' => '', 'base_consultation_fee' => '', 'description' => '', 'specialties' => '', 'open_days' => [1, 2, 3, 4, 5, 6], 'open_time' => '08:00', 'close_time' => '17:00'];
$weekdayLabels = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!$pdo) {
        $errors[] = 'The database is temporarily unavailable.';
    } elseif (($_POST['form_type'] ?? '') === 'save_clinic') {
        foreach(['clinic_name','address','contact_number','base_consultation_fee','specialties'] as $field)$values[$field]=trim((string)($_POST[$field]??''));
        try {
            $wasEdit=!empty($_POST['clinic_id']);
            saveAdminClinicBasics($pdo,(int)$user['UserID'],$_POST,$_FILES);
            $_SESSION['admin_success'][basename(__FILE__)]=$wasEdit?'Clinic registration details updated.':'Clinic added. Assign its main physician to configure opening hours and public profile.';
            header('Location: '.HQ_BASE_URL.'/admin/clinics.php',true,303);exit;
        }catch(Throwable $e){error_log('Clinic registration save failed: '.$e->getMessage());$errors[]=$e instanceof PDOException?'We could not save this clinic. Please try again.':$e->getMessage();}
    } elseif (($_POST['form_type'] ?? '') === 'update_clinic_status') {
        $clinicId = filter_input(INPUT_POST, 'clinic_id', FILTER_VALIDATE_INT);
        $status = $_POST['status'] ?? '';
        if (!$clinicId || !in_array($status, ['Active', 'Inactive'], true)) {
            $errors[] = 'Invalid clinic status update.';
        } else {
            try {
                $stmt = $pdo->prepare('UPDATE Clinic SET Status = ? WHERE ClinicID = ? AND archived = 0');
                $stmt->execute([$status, $clinicId]);
                if ($stmt->rowCount()) {
                    logActivity($pdo, $user['UserID'], $clinicId, 'Changed clinic status', "Set to {$status}.");
                    $flash = 'Clinic status updated.';
                } else {
                    $errors[] = 'The clinic could not be found.';
                }
            } catch (PDOException $e) {
                error_log('Clinic status update failed: ' . $e->getMessage());
                $errors[] = 'We could not update the clinic status.';
            }
        }
    } elseif (($_POST['form_type'] ?? '') === 'archive_clinic') {
        $clinicId = filter_input(INPUT_POST, 'clinic_id', FILTER_VALIDATE_INT);
        if (!$clinicId) {
            $errors[] = 'Invalid clinic.';
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE Clinic SET archived = 1, DeletedAt = NOW(), Status = 'Inactive' WHERE ClinicID = ?");
                $stmt->execute([$clinicId]);
                logActivity($pdo, $user['UserID'], $clinicId, 'Archived clinic', 'Removed from the active directory.');
                $flash = 'Clinic archived. It no longer appears to patients or in the active directory.';
            } catch (PDOException $e) {
                error_log('Clinic archive failed: ' . $e->getMessage());
                $errors[] = 'We could not archive this clinic.';
            }
        }
    } elseif (($_POST['form_type'] ?? '') === 'restore_clinic') {
        $clinicId = filter_input(INPUT_POST, 'clinic_id', FILTER_VALIDATE_INT);
        if (!$clinicId) {
            $errors[] = 'Invalid clinic.';
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE Clinic SET archived = 0, DeletedAt = NULL, Status = 'Inactive' WHERE ClinicID = ?");
                $stmt->execute([$clinicId]);
                logActivity($pdo, $user['UserID'], $clinicId, 'Restored clinic', 'Restored from the archive as Inactive.');
                $flash = 'Clinic restored. Activate it to let patients book again.';
            } catch (PDOException $e) {
                error_log('Clinic restore failed: ' . $e->getMessage());
                $errors[] = 'We could not restore this clinic.';
            }
        }
    }
}

$clinics = [];
$editingClinic = null;
if ($pdo) {
    try {
        $sql = 'SELECT ClinicID, ClinicName, Address, ContactNumber, BaseConsultationFee, Specialties, Latitude, Longitude, PhotoUrl, Status, archived, (SELECT COUNT(*) FROM PhysicianAccountOwners o WHERE o.ClinicID=Clinic.ClinicID) AS HasMainPhysician FROM Clinic WHERE archived = ?';
        $params = [$showArchived ? 1 : 0];
        if ($search !== '') {
            $sql .= ' AND (ClinicName LIKE ? OR Address LIKE ?)';
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }
        $sql .= ' ORDER BY ClinicName';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $clinics = clinicFeeRows($pdo,$stmt->fetchAll());

        if ($editId) {
            $editStmt = $pdo->prepare('SELECT ClinicID, ClinicName, Address, ContactNumber, BaseConsultationFee, Description, Specialties, OpenDays, OpenTime, CloseTime, PhotoUrl, Latitude, Longitude, (SELECT COUNT(*) FROM PhysicianAccountOwners o WHERE o.ClinicID=Clinic.ClinicID) AS HasMainPhysician FROM Clinic WHERE ClinicID = ?');
            $editStmt->execute([$editId]);
            $editingClinic = $editStmt->fetch();
            if($editingClinic)$editingClinic['BaseConsultationFee']=currentClinicFee($pdo,(int)$editingClinic['ClinicID']);
            if ($editingClinic) {
                $values = [
                    'clinic_name' => $editingClinic['ClinicName'],
                    'address' => $editingClinic['Address'],
                    'contact_number' => $editingClinic['ContactNumber'],
                    'base_consultation_fee' => number_format((float) $editingClinic['BaseConsultationFee'], 2, '.', ''),
                    'description' => $editingClinic['Description'] ?? '',
                    'specialties' => $editingClinic['Specialties'] ?? '',
                    'open_days' => array_map('intval', explode(',', $editingClinic['OpenDays'])),
                    'open_time' => substr($editingClinic['OpenTime'], 0, 5),
                    'close_time' => substr($editingClinic['CloseTime'], 0, 5),
                ];
            }
        }
    } catch (PDOException $e) {
        error_log('Clinics list failed: ' . $e->getMessage());
        $errors[] = 'Clinic information is temporarily unavailable.';
    }
}

$pageTitle = 'Manage Clinics — HealthQueue';
$directoryKind = 'clinics';
require __DIR__ . '/../includes/admin-directory.php';
require __DIR__ . '/../includes/header.php';
?>

<main class="admin-shell dr-page ac-page"><div class="container dr-container">
  <?php renderDirectoryHeading('Manage clinics', 'Keep your clinic directory organized and up to date.', 'clinicDirectoryModal', 'Add clinic'); ?>

  <?php if ($flash): ?><p class="form-message success" role="status"><?= htmlspecialchars($flash) ?></p><?php endif; ?>
  <?php if ($errors): ?><div class="form-message error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

  <?php renderDirectoryStats(); ?>
  <dialog class="modal-box dr-dialog" id="clinicDirectoryModal" aria-labelledby="directoryFormTitle" data-open-on-load="<?= $editingClinic || ($errors && ($_POST['form_type'] ?? '') === 'save_clinic') ? '1' : '0' ?>">
    <button type="button" class="modal-close" data-directory-close aria-label="Close">&times;</button>
    <div class="portal-heading"><div><span class="section-kicker"><?= $editingClinic ? 'Edit clinic' : 'Add clinic' ?></span><h2 id="directoryFormTitle"><?= $editingClinic ? htmlspecialchars($editingClinic['ClinicName']) : 'New clinic' ?></h2></div></div>
    <form class="registration-form" method="post" enctype="multipart/form-data" style="max-width:640px;">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
      <input type="hidden" name="form_type" value="save_clinic">
      <input type="hidden" name="clinic_id" value="<?= (int) ($editingClinic['ClinicID'] ?? 0) ?>">
      <div class="form-stack">
        <label>Clinic name<input name="clinic_name" value="<?= htmlspecialchars($values['clinic_name']) ?>" required></label>
        <label>Address<input name="address" value="<?= htmlspecialchars($values['address']) ?>" required></label>
      </div>
      <div class="form-stack form-cols-2">
        <label>Contact number<input type="tel" name="contact_number" inputmode="numeric" maxlength="11" pattern="09[0-9]{9}" title="Enter a valid mobile number" value="<?= htmlspecialchars($values['contact_number']) ?>" required></label>
        <label>Consultation fee (PHP)<input type="number" step="0.01" min="0" name="base_consultation_fee" <?= !empty($editingClinic['HasMainPhysician'])?'readonly':'' ?> value="<?= htmlspecialchars($values['base_consultation_fee']) ?>" required></label>
      </div>
      <div class="form-stack">
        <label>Specialties <span class="optional">(comma-separated, e.g. General, Pediatrics, Dental)</span><input name="specialties" value="<?= htmlspecialchars($values['specialties']) ?>" maxlength="255" placeholder="General, Pediatrics"></label>
      </div>
      <div class="form-stack form-cols-2">
        <label>Map latitude<input type="number" name="latitude" step="any" min="-90" max="90" value="<?= htmlspecialchars((string) ($_POST['latitude'] ?? $editingClinic['Latitude'] ?? '')) ?>" placeholder="e.g. 10.3157"></label>
        <label>Map longitude<input type="number" name="longitude" step="any" min="-180" max="180" value="<?= htmlspecialchars((string) ($_POST['longitude'] ?? $editingClinic['Longitude'] ?? '')) ?>" placeholder="e.g. 123.8854"></label>
      </div>
      <p style="font-size:12px;color:var(--slate-500);">To show this clinic on the map, right-click its exact location in Google Maps and copy the latitude and longitude. Leave both blank to omit its pin.</p>
      <p class="dev-note">The main physician manages clinic opening days, hours, photos and description from Manage Clinic. After a main physician is assigned, consultation fee changes require an approved fee-change request. Newly added clinics remain closed for booking until those hours are set.</p>
      <button type="submit" class="btn btn-primary btn-block"><?= $editingClinic ? 'Save changes' : 'Add clinic' ?></button>
      <?php if ($editingClinic): ?><a href="<?= HQ_BASE_URL ?>/admin/clinics.php" class="text-link" style="display:block;text-align:center;margin-top:12px;">Cancel edit</a><?php endif; ?>
    </form>
  </dialog>

  <section class="admin-section dr-panel">
    <div class="portal-heading"><h2>Clinic directory</h2><a class="text-link ac-export" href="export.php?type=clinics">Export CSV</a></div>
    <nav class="dr-directory-tabs ac-tabs" aria-label="Clinic directory view"><a href="<?= htmlspecialchars(dirUrl(['archived'=>null,'page'=>1])) ?>"<?= !$showArchived ? ' aria-current="page"' : '' ?>>Active directory</a><a href="clinic-requests.php">Clinic requests <span class="ac-count"><?= (int)$dirStats['pending'] ?></span></a><a href="<?= htmlspecialchars(dirUrl(['archived'=>1,'page'=>1])) ?>"<?= $showArchived ? ' aria-current="page"' : '' ?>>Archived</a></nav>
    <?php renderDirectoryFilters(); ?>
    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead><tr><th>Clinic</th><th>Location</th><th>Contact</th><th>Consultation fee</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($clinics as $clinic): ?>
          <tr>
            <td><span class="ac-clinic-name"><?php $cover=clinicCoverPhoto($pdo,$clinic);if($cover): ?><img src="<?= HQ_BASE_URL ?>/assets/uploads/clinics/<?= rawurlencode($cover) ?>" alt="" loading="lazy" width="58" height="44"><?php else: ?><span class="dr-avatar dr-clinic-avatar"><?= dirIcon('clinic') ?></span><?php endif; ?><strong><?= htmlspecialchars($clinic['ClinicName']) ?></strong></span></td>
            <td><span class="ac-detail"><?= dirIcon('pin') ?><span><?= htmlspecialchars($clinic['Address']) ?></span></span></td>
            <td><span class="ac-detail ac-phone"><?= dirIcon('phone') ?><span><?= htmlspecialchars($clinic['ContactNumber']) ?></span></span></td>
            <td>PHP <?= number_format((float) $clinic['BaseConsultationFee'], 2) ?></td>
            <td><span class="status-badge status-<?= strtolower(htmlspecialchars($clinic['Status'])) ?>"><?= htmlspecialchars($clinic['Status']) ?></span></td>
            <td>
              <div class="dr-actions">
                <?php if (!$showArchived): ?>
                  <a href="<?= HQ_BASE_URL ?>/admin/clinics.php?edit=<?= (int) $clinic['ClinicID'] ?>#clinicDirectoryModal" class="btn btn-outline btn-sm" data-clinic-edit="<?= htmlspecialchars(json_encode($clinic, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>">Edit</a>
                  <details class="dr-more dr-action-menu"><summary aria-label="More clinic actions">&hellip;</summary><div class="dr-more-content">
                  <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><input type="hidden" name="form_type" value="update_clinic_status"><input type="hidden" name="clinic_id" value="<?= (int) $clinic['ClinicID'] ?>">
                    <?php if ($clinic['Status'] === 'Active'): ?><button class="btn btn-outline btn-sm" name="status" value="Inactive" onclick="return confirm('Set this clinic to inactive? Patients will no longer be able to request appointments.');"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M9 8v8M15 8v8"/></svg><span>Set inactive</span></button>
                <?php else: ?><button class="btn btn-primary btn-sm" name="status" value="Active"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/></svg><span>Activate clinic</span></button><?php endif; ?>
                  </form>
                  <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><input type="hidden" name="form_type" value="archive_clinic"><input type="hidden" name="clinic_id" value="<?= (int) $clinic['ClinicID'] ?>">
                    <button class="btn btn-outline btn-sm dr-archive-action" onclick="return confirm('Archive this clinic? It will be hidden from patients and the active directory. This can be undone.');"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 8h16v12H4zM3 4h18v4H3zM9 12h6"/></svg><span>Archive clinic</span></button>
                  </form>
                  </div></details>
                <?php else: ?>
                  <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><input type="hidden" name="form_type" value="restore_clinic"><input type="hidden" name="clinic_id" value="<?= (int) $clinic['ClinicID'] ?>">
                    <button class="btn btn-primary btn-sm">Restore</button>
                  </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$clinics): ?><tr><td colspan="6" class="admin-empty"><?= $search !== '' ? 'No clinics match "' . htmlspecialchars($search) . '".' : 'No clinics to show here.' ?></td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php renderDirectoryPagination(); ?>
  </section>
<aside class="dr-help ac-help"><div><strong>Clinic fee changes</strong><p>Main physicians submit requests. Review the proposed fee and effective date before approval.</p></div><a class="text-link" href="clinic-fees.php">Review requests</a></aside>
</div></main>

<script src="<?= HQ_BASE_URL ?>/assets/js/admin-directory.js?v=<?= filemtime(__DIR__ . '/../assets/js/admin-directory.js') ?>" defer></script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
