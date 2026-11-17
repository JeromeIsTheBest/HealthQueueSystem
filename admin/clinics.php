<?php
require_once __DIR__ . '/../includes/input-validation.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/clinic-photos.php';

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
        $clinicId = filter_input(INPUT_POST, 'clinic_id', FILTER_VALIDATE_INT) ?: null;
        $values['clinic_name'] = trim((string) ($_POST['clinic_name'] ?? ''));
        $values['address'] = trim((string) ($_POST['address'] ?? ''));
        $values['contact_number'] = trim((string) ($_POST['contact_number'] ?? ''));
        $values['base_consultation_fee'] = trim((string) ($_POST['base_consultation_fee'] ?? ''));
        $values['description'] = trim((string) ($_POST['description'] ?? ''));
        // Normalise "general, Pediatrics ," into "General, Pediatrics".
        $specialtyList = array_map(static fn(string $s): string => ucfirst(trim($s)), explode(',', (string) ($_POST['specialties'] ?? '')));
        $values['specialties'] = implode(', ', array_unique(array_filter($specialtyList, 'strlen')));
        $values['open_days'] = array_values(array_intersect(array_keys($weekdayLabels), array_map('intval', (array) ($_POST['open_days'] ?? []))));
        $values['open_time'] = (string) ($_POST['open_time'] ?? '');
        $values['close_time'] = (string) ($_POST['close_time'] ?? '');

        if ($values['clinic_name'] === '') $errors[] = 'Clinic name is required.';
        if ($values['address'] === '') $errors[] = 'Address is required.';
        if ($values['contact_number'] === '') $errors[] = 'Contact number is required.';
        if (!hqValidMobile($values['contact_number'])) $errors[] = 'Enter a valid mobile number';
        if (!is_numeric($values['base_consultation_fee']) || (float) $values['base_consultation_fee'] < 0) {
            $errors[] = 'Consultation fee must be a valid non-negative amount.';
        }
        if (mb_strlen($values['specialties']) > 255) $errors[] = 'Specialties must be 255 characters or fewer.';
        if (!$values['open_days']) $errors[] = 'Select at least one opening day.';
        if (!preg_match('/^\d{2}:\d{2}$/', $values['open_time']) || !preg_match('/^\d{2}:\d{2}$/', $values['close_time'])) {
            $errors[] = 'Please enter valid opening and closing times.';
        } elseif ($values['close_time'] <= $values['open_time']) {
            $errors[] = 'Closing time must be later than opening time.';
        }

        $latitude = trim((string) ($_POST['latitude'] ?? ''));
        $longitude = trim((string) ($_POST['longitude'] ?? ''));
        if (($latitude === '') !== ($longitude === '') || ($latitude !== '' && (!is_numeric($latitude) || !is_numeric($longitude) || abs((float) $latitude) > 90 || abs((float) $longitude) > 180))) {
            $errors[] = 'Enter both map coordinates: latitude -90 to 90 and longitude -180 to 180.';
        }
        $uploads = [];
        $files = $_FILES['photos'] ?? [];
        $count = is_array($files['name'] ?? null) ? count($files['name']) : 0;
        if ($count > 10) $errors[] = 'Select at most 10 photos per upload.';
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        for ($i = 0; $i < min($count, 10); $i++) {
            $error = $files['error'][$i] ?? UPLOAD_ERR_NO_FILE;
            if ($error === UPLOAD_ERR_NO_FILE) continue;
            $tmp = $files['tmp_name'][$i] ?? '';
            if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
                $errors[] = 'A photo could not be uploaded. Please try again.';
                continue;
            }
            if (($files['size'][$i] ?? 0) <= 0 || $files['size'][$i] > 4 * 1024 * 1024) {
                $errors[] = 'Each clinic photo must be 4 MB or smaller.';
                continue;
            }
            $mime = mime_content_type($tmp);
            if (!isset($allowed[$mime]) || !getimagesize($tmp)) {
                $errors[] = 'Clinic photos must be valid JPG, PNG, or WEBP images.';
                continue;
            }
            $uploads[] = ['tmp' => $tmp, 'name' => 'clinic_' . bin2hex(random_bytes(16)) . '.' . $allowed[$mime]];
        }
        if (!$errors) {
            $savedPaths = [];
            $committed = false;
            $removedPhotos = [];
            try {
                $pdo->beginTransaction();
                $params = [$values['clinic_name'], $values['address'], $values['contact_number'], (float) $values['base_consultation_fee'], $values['description'] ?: null, $values['specialties'] ?: null, implode(',', $values['open_days']), $values['open_time'], $values['close_time']];
                $wasEdit = (bool) $clinicId;
                if ($clinicId) {
                    $existing = $pdo->prepare('SELECT ClinicID FROM Clinic WHERE ClinicID = ? FOR UPDATE');
                    $existing->execute([$clinicId]);
                    if (!$existing->fetchColumn()) throw new RuntimeException('Clinic not found.');
                    $coverStmt = $pdo->prepare('SELECT ClinicID, PhotoUrl FROM Clinic WHERE ClinicID = ?');
                    $coverStmt->execute([$clinicId]);
                    $existingClinic = $coverStmt->fetch();
                    $requestedRemovals = array_filter((array) ($_POST['remove_photos'] ?? []), 'is_string');
                    $removedPhotos = array_values(array_intersect(clinicPhotos($pdo, $existingClinic), $requestedRemovals));
                    foreach ($removedPhotos as $removedPhoto) {
                        $pdo->prepare('DELETE FROM ClinicPhoto WHERE ClinicID = ? AND Filename = ?')->execute([$clinicId, $removedPhoto]);
                    }
                    if (in_array($existingClinic['PhotoUrl'], $removedPhotos, true)) {
                        $nextCover = $pdo->prepare('SELECT Filename FROM ClinicPhoto WHERE ClinicID = ? ORDER BY PhotoID LIMIT 1');
                        $nextCover->execute([$clinicId]);
                        $pdo->prepare('UPDATE Clinic SET PhotoUrl = ? WHERE ClinicID = ?')->execute([$nextCover->fetchColumn() ?: null, $clinicId]);
                    }
                    $pdo->prepare('UPDATE Clinic SET ClinicName = ?, Address = ?, ContactNumber = ?, BaseConsultationFee = ?, Description = ?, Specialties = ?, OpenDays = ?, OpenTime = ?, CloseTime = ? WHERE ClinicID = ?')->execute(array_merge($params, [$clinicId]));
                } else {
                    $pdo->prepare('INSERT INTO Clinic (ClinicName, Address, ContactNumber, BaseConsultationFee, Description, Specialties, OpenDays, OpenTime, CloseTime) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute($params);
                    $clinicId = (int) $pdo->lastInsertId();
                }
                $pdo->prepare('UPDATE Clinic SET Latitude = ?, Longitude = ? WHERE ClinicID = ?')->execute([$latitude === '' ? null : (float) $latitude, $longitude === '' ? null : (float) $longitude, $clinicId]);
                $uploadDir = __DIR__ . '/../assets/uploads/clinics';
                if ($uploads && !is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) throw new RuntimeException('Upload directory unavailable.');
                foreach ($uploads as $upload) {
                    $path = $uploadDir . '/' . $upload['name'];
                    if (!move_uploaded_file($upload['tmp'], $path)) throw new RuntimeException('Could not save photo.');
                    $savedPaths[] = $path;
                    $pdo->prepare('INSERT INTO ClinicPhoto (ClinicID, Filename) VALUES (?, ?)')->execute([$clinicId, $upload['name']]);
                    $pdo->prepare("UPDATE Clinic SET PhotoUrl = ? WHERE ClinicID = ? AND (PhotoUrl IS NULL OR PhotoUrl = '')")->execute([$upload['name'], $clinicId]);
                }
                saveClinicPhotoOrder($pdo, $clinicId, (array) ($_POST['photo_order'] ?? []));
                logActivity($pdo, $user['UserID'], $clinicId, $wasEdit ? 'Updated clinic' : 'Created clinic', $values['clinic_name']);
                $pdo->commit();
                $committed = true;
                foreach ($removedPhotos as $removedPhoto) {
                    $references = $pdo->prepare('SELECT (SELECT COUNT(*) FROM Clinic WHERE PhotoUrl = ?) + (SELECT COUNT(*) FROM ClinicPhoto WHERE Filename = ?)');
                    $references->execute([$removedPhoto, $removedPhoto]);
                    $removedPath = $uploadDir . '/' . basename($removedPhoto);
                    if (!(int) $references->fetchColumn() && is_file($removedPath)) {
                        if (!unlink($removedPath)) error_log('Could not remove unused clinic photo: ' . $removedPhoto);
                    }
                }
                $_SESSION['admin_success'][basename(__FILE__)] = $flash !== '' ? $flash : ($wasEdit ? 'Clinic updated.' : 'Clinic created.');
                header('Location: ' . HQ_BASE_URL . '/admin/clinics.php');
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                if (!$committed) {
                    foreach ($savedPaths as $path) if (is_file($path)) unlink($path);
                }
                error_log('Clinic save failed: ' . $e->getMessage());
                $errors[] = 'We could not save this clinic and its photos. Please try again.';
            }
        }
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
        $sql = 'SELECT ClinicID, ClinicName, Address, ContactNumber, BaseConsultationFee, PhotoUrl, Status, archived FROM Clinic WHERE archived = ?';
        $params = [$showArchived ? 1 : 0];
        if ($search !== '') {
            $sql .= ' AND (ClinicName LIKE ? OR Address LIKE ?)';
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }
        $sql .= ' ORDER BY ClinicName';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $clinics = $stmt->fetchAll();

        if ($editId) {
            $editStmt = $pdo->prepare('SELECT ClinicID, ClinicName, Address, ContactNumber, BaseConsultationFee, Description, Specialties, OpenDays, OpenTime, CloseTime, PhotoUrl, Latitude, Longitude FROM Clinic WHERE ClinicID = ?');
            $editStmt->execute([$editId]);
            $editingClinic = $editStmt->fetch();
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

<main class="admin-shell dr-page"><div class="container dr-container">
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
      <?php if ($editingClinic): ?><input type="hidden" name="clinic_id" value="<?= (int) $editingClinic['ClinicID'] ?>"><?php endif; ?>
      <div class="form-stack">
        <label>Clinic name<input name="clinic_name" value="<?= htmlspecialchars($values['clinic_name']) ?>" required></label>
        <label>Address<input name="address" value="<?= htmlspecialchars($values['address']) ?>" required></label>
      </div>
      <div class="form-stack form-cols-2">
        <label>Contact number<input type="tel" name="contact_number" inputmode="numeric" maxlength="11" pattern="09[0-9]{9}" title="Enter a valid mobile number" value="<?= htmlspecialchars($values['contact_number']) ?>" required></label>
        <label>Consultation fee (PHP)<input type="number" step="0.01" min="0" name="base_consultation_fee" value="<?= htmlspecialchars($values['base_consultation_fee']) ?>" required></label>
      </div>
      <div class="form-stack">
        <label>Specialties <span class="optional">(comma-separated, e.g. General, Pediatrics, Dental)</span><input name="specialties" value="<?= htmlspecialchars($values['specialties']) ?>" maxlength="255" placeholder="General, Pediatrics"></label>
        <div>
          <span style="display:block;margin-bottom:6px;color:var(--slate-700);font-size:13px;font-weight:700;">Open days</span>
          <div style="display:flex;flex-wrap:wrap;gap:8px 14px;">
            <?php foreach ($weekdayLabels as $dayNum => $dayLabel): ?>
              <label style="display:flex;align-items:center;gap:6px;font-size:13px;font-weight:600;"><input type="checkbox" name="open_days[]" value="<?= $dayNum ?>"<?= in_array($dayNum, $values['open_days'], true) ? ' checked' : '' ?>><?= $dayLabel ?></label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <div class="form-stack form-cols-2">
        <label>Opening time<input type="time" name="open_time" value="<?= htmlspecialchars($values['open_time']) ?>" required></label>
        <label>Closing time<input type="time" name="close_time" value="<?= htmlspecialchars($values['close_time']) ?>" required></label>
      </div>
      <div class="form-stack form-cols-2">
        <label>Map latitude<input type="number" name="latitude" step="any" min="-90" max="90" value="<?= htmlspecialchars((string) ($_POST['latitude'] ?? $editingClinic['Latitude'] ?? '')) ?>" placeholder="e.g. 10.3157"></label>
        <label>Map longitude<input type="number" name="longitude" step="any" min="-180" max="180" value="<?= htmlspecialchars((string) ($_POST['longitude'] ?? $editingClinic['Longitude'] ?? '')) ?>" placeholder="e.g. 123.8854"></label>
      </div>
      <p style="font-size:12px;color:var(--slate-500);">To show this clinic on the map, right-click its exact location in Google Maps and copy the latitude and longitude. Leave both blank to omit its pin.</p>
      <div class="form-stack">
        <label>Description <span class="optional">(shown on the clinic's public profile)</span><textarea name="description" rows="4" placeholder="A short description of this clinic for patients."><?= htmlspecialchars($values['description']) ?></textarea></label>
        <label>Clinic photos <span class="optional">(up to 10 per upload, JPG/PNG/WEBP, 4 MB each; new photos are added to your gallery)</span>
          <?php if ($editingClinic): ?>
            <span class="admin-clinic-photo-previews">
              <?php foreach (clinicPhotos($pdo, $editingClinic) as $photo): ?>
                <span class="admin-clinic-photo-item" draggable="true">
                  <input type="hidden" name="photo_order[]" value="<?= htmlspecialchars($photo) ?>">
                  <img src="<?= HQ_BASE_URL ?>/assets/uploads/clinics/<?= rawurlencode($photo) ?>" alt="Saved clinic photo" width="100" height="76">
                  <button type="button" class="clinic-photo-remove" data-saved-photo="<?= htmlspecialchars($photo) ?>" aria-label="Remove clinic photo">&times;</button>
                </span>
              <?php endforeach; ?>
            </span>
          <?php endif; ?>
          <input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple>
          <span class="admin-clinic-photo-previews" id="newClinicPhotoPreviews"></span>
          <small>Drag photos to arrange them. The first photo is the cover. Use × to remove a photo, then Save changes to apply.</small>
        </label>
      </div>
      <button type="submit" class="btn btn-primary btn-block"><?= $editingClinic ? 'Save changes' : 'Add clinic' ?></button>
      <?php if ($editingClinic): ?><a href="<?= HQ_BASE_URL ?>/admin/clinics.php" class="text-link" style="display:block;text-align:center;margin-top:12px;">Cancel edit</a><?php endif; ?>
    </form>
  </dialog>

  <section class="admin-section dr-panel">
    <div class="portal-heading"><h2>Clinic directory</h2><nav class="dr-directory-tabs" aria-label="Clinic directory view"><a href="clinic-requests.php">Clinic requests (<?= (int)$dirStats['pending'] ?>)</a><a href="<?= htmlspecialchars(dirUrl(['archived'=>null,'page'=>1])) ?>"<?= !$showArchived ? ' aria-current="page"' : '' ?>>Active directory</a><a href="<?= htmlspecialchars(dirUrl(['archived'=>1,'page'=>1])) ?>"<?= $showArchived ? ' aria-current="page"' : '' ?>>Archived</a></nav></div>
    <?php renderDirectoryFilters(); ?>
    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead><tr><th>Photo</th><th>Clinic</th><th>Location</th><th>Contact</th><th>Fee</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($clinics as $clinic): ?>
          <tr>
            <td><?php if (!empty($clinic['PhotoUrl'])): ?><img src="<?= HQ_BASE_URL ?>/assets/uploads/clinics/<?= htmlspecialchars($clinic['PhotoUrl']) ?>" alt="" style="width:52px;height:40px;object-fit:cover;border-radius:6px;"><?php else: ?><span class="dr-avatar dr-clinic-avatar"><?= dirIcon('clinic') ?></span><?php endif; ?></td>
            <td><strong><?= htmlspecialchars($clinic['ClinicName']) ?></strong></td>
            <td><?= htmlspecialchars($clinic['Address']) ?></td>
            <td><?= htmlspecialchars($clinic['ContactNumber']) ?></td>
            <td>PHP <?= number_format((float) $clinic['BaseConsultationFee'], 2) ?></td>
            <td><span class="status-badge status-<?= strtolower(htmlspecialchars($clinic['Status'])) ?>"><?= htmlspecialchars($clinic['Status']) ?></span></td>
            <td>
              <div class="dr-actions">
                <?php if (!$showArchived): ?>
                  <a href="<?= HQ_BASE_URL ?>/admin/clinics.php?edit=<?= (int) $clinic['ClinicID'] ?>#clinicDirectoryModal" class="btn btn-outline btn-sm">Edit</a>
                  <details class="dr-more"><summary aria-label="More clinic actions">&hellip;</summary><div class="dr-more-content">
                  <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><input type="hidden" name="form_type" value="update_clinic_status"><input type="hidden" name="clinic_id" value="<?= (int) $clinic['ClinicID'] ?>">
                    <?php if ($clinic['Status'] === 'Active'): ?><button class="btn btn-outline btn-sm" name="status" value="Inactive" onclick="return confirm('Set this clinic to inactive? Patients will no longer be able to request appointments.');">Set inactive</button>
                      </div></details>
                <?php else: ?><button class="btn btn-primary btn-sm" name="status" value="Active">Activate</button><?php endif; ?>
                  </form>
                  <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><input type="hidden" name="form_type" value="archive_clinic"><input type="hidden" name="clinic_id" value="<?= (int) $clinic['ClinicID'] ?>">
                    <button class="btn btn-outline btn-sm" onclick="return confirm('Archive this clinic? It will be hidden from patients and the active directory. This can be undone.');">Archive</button>
                  </form>
                <?php else: ?>
                  <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><input type="hidden" name="form_type" value="restore_clinic"><input type="hidden" name="clinic_id" value="<?= (int) $clinic['ClinicID'] ?>">
                    <button class="btn btn-primary btn-sm">Restore</button>
                  </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$clinics): ?><tr><td colspan="7" class="admin-empty"><?= $search !== '' ? 'No clinics match "' . htmlspecialchars($search) . '".' : 'No clinics to show here.' ?></td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php renderDirectoryPagination(); ?>
  </section>
<aside class="dr-help"><strong>Clinic details matter</strong><p>Keep contact details, fees, photos and operating hours current for patients.</p></aside>
</div></main>

<script src="<?= HQ_BASE_URL ?>/assets/js/admin-clinic-photos.js?v=<?= filemtime(__DIR__ . '/../assets/js/admin-clinic-photos.js') ?>" defer></script>
<script src="<?= HQ_BASE_URL ?>/assets/js/admin-directory.js?v=<?= filemtime(__DIR__ . '/../assets/js/admin-directory.js') ?>" defer></script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
