<?php
require_once __DIR__ . '/../includes/input-validation.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/wallet.php';
require_once __DIR__ . '/../includes/patient-profile-details.php';

define('HQ_BASE_URL', '..');
requireRole(['Patient']);

$user = currentUser();
$pdo = getDbConnection();
$errors = [];
$flash = '';
$profile = ['FirstName' => $user['FirstName'], 'LastName' => $user['LastName'], 'Email' => $user['Email'], 'ContactNumber' => '', 'ProfilePhoto' => null, 'CreatedAt' => null, 'BloodType' => null, 'Allergies' => null, 'EmergencyContactName' => null, 'EmergencyContactNumber' => null];
$details = [];
$verified = false;
$detailsUpdated = null;
$bloodTypes = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

if ($pdo) {
    try {
        $stmt = $pdo->prepare('SELECT IDNumber, FirstName, LastName, Email, ContactNumber, ProfilePhoto, CreatedAt, BloodType, Allergies, EmergencyContactName, EmergencyContactNumber FROM Users WHERE UserID = ?');
        $stmt->execute([$user['UserID']]);
        if ($row = $stmt->fetch()) {
            $profile = $row;
            // Accounts created before ID numbers existed get one on first view.
            if (empty($profile['IDNumber'])) $profile['IDNumber'] = assignUserIdNumber($pdo, (int) $user['UserID']);
        }
    } catch (PDOException $e) {
        error_log('Patient profile load failed: ' . $e->getMessage());
    }
}

if ($pdo) {
    try {
        $extra = $pdo->prepare('SELECT Details, UpdatedAt FROM PatientProfileDetails WHERE UserID = ?');
        $extra->execute([$user['UserID']]);
        if ($row = $extra->fetch()) { $details = json_decode($row['Details'], true) ?: []; $detailsUpdated = $row['UpdatedAt']; }
        $verify = $pdo->prepare('SELECT EmailVerifiedAt FROM Users WHERE UserID = ?');
        $verify->execute([$user['UserID']]);
        $verified = (bool) $verify->fetchColumn();
    } catch (PDOException $e) { error_log('Profile details load failed: ' . $e->getMessage()); }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!$pdo) {
        $errors[] = 'The database is temporarily unavailable.';
    } elseif (($_POST['form_type'] ?? '') === 'notification_preferences') {
        try {
            foreach (['email_notifications', 'sms_notifications', 'in_app_notifications'] as $key) $details[$key] = isset($_POST[$key]);
            savePatientProfileDetails($pdo, (int) $user['UserID'], $details);
            $flash = 'Notification preferences saved.';
        } catch (PDOException $e) { $errors[] = 'Could not save preferences.'; }
    } elseif (($_POST['form_type'] ?? '') === 'deactivate_account') {
        try {
            $pdo->beginTransaction();
            $active = $pdo->prepare("SELECT AppointmentID FROM Appointments WHERE PatientID = ? AND Status IN ('Pending', 'Confirmed') FOR UPDATE");
            $active->execute([$user['UserID']]);
            foreach ($active->fetchAll(PDO::FETCH_COLUMN) as $activeAppointmentId) {
                cancelBooking($pdo, (int) $activeAppointmentId, (int) $user['UserID'], 'Patient account deactivated', 'Refund for cancelled booking at ');
            }
            $pdo->prepare("UPDATE Users SET Status = 'Inactive', DeletedAt = NOW() WHERE UserID = ? AND RoleID = (SELECT RoleID FROM Roles WHERE RoleName = 'Patient')")->execute([$user['UserID']]);
            $pdo->commit();
            logoutUser();
            header('Location: ' . HQ_BASE_URL . '/auth/login.php?deactivated=1');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Patient account deactivation failed: ' . $e->getMessage());
            $errors[] = 'We could not deactivate your account right now.';
        }
    } elseif (($_POST['form_type'] ?? '') === 'update_profile') {
        $firstName = hqCapitalizeName((string) ($_POST['first_name'] ?? ''));
        $lastName  = hqCapitalizeName((string) ($_POST['last_name'] ?? ''));
        $email     = trim((string) ($_POST['email'] ?? ''));
        $contact   = trim((string) ($_POST['contact_number'] ?? ''));
        $birth = trim((string) ($_POST['date_of_birth'] ?? ''));
        $sex = (string) ($_POST['sex'] ?? '');
        $address = trim((string) ($_POST['address'] ?? ''));
        if ($birth !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $birth) || !strtotime($birth) || date('Y-m-d', strtotime($birth)) !== $birth || $birth > date('Y-m-d'))) $errors[] = 'Enter a valid date of birth.';
        if (!in_array($sex, ['', 'Male', 'Female', 'Prefer not to say'], true)) $errors[] = 'Choose a valid sex.';
        if (mb_strlen($address) > 500) $errors[] = 'Address must be 500 characters or fewer.';

        if (!hqValidMobile($contact)) $errors[] = 'Enter a valid mobile number';
        if ($firstName === '' || $lastName === '') $errors[] = 'First and last name are required.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';

        if (!$errors) {
            try {
                $dupe = $pdo->prepare('SELECT UserID FROM Users WHERE Email = ? AND UserID <> ?');
                $dupe->execute([$email, $user['UserID']]);
                if ($dupe->fetch()) {
                    $errors[] = 'Another account already uses that email address.';
                } else {
                    $emailChanged = strcasecmp($email, $profile['Email']) !== 0;
                    $pdo->prepare('UPDATE Users SET FirstName = ?, LastName = ?, EmailVerifiedAt = CASE WHEN Email <> ? THEN NULL ELSE EmailVerifiedAt END, Email = ?, ContactNumber = ? WHERE UserID = ?')
                        ->execute([$firstName, $lastName, $email, $email, $contact ?: null, $user['UserID']]);
                    if ($emailChanged) {
                        $pdo->prepare('DELETE FROM EmailVerificationTokens WHERE UserID=?')->execute([$user['UserID']]);
                        $pdo->prepare('UPDATE PasswordResets SET UsedAt=NOW() WHERE UserID=? AND UsedAt IS NULL')->execute([$user['UserID']]);
                        logoutUser();header('Location: '.HQ_BASE_URL.'/auth/resend-verification.php?email_changed=1',true,303);exit;
                    }
                    loginUser(['UserID' => $user['UserID'], 'ClinicID' => $user['ClinicID'], 'RoleID' => $user['RoleID'], 'RoleName' => $user['RoleName'], 'FirstName' => $firstName, 'LastName' => $lastName, 'Email' => $email]);
                    logActivity($pdo, $user['UserID'], null, 'Updated profile', 'Patient updated their own account details.');
                    $details = array_merge($details, ['date_of_birth' => $birth, 'sex' => $sex, 'address' => $address]);
                    savePatientProfileDetails($pdo, (int) $user['UserID'], $details);
                    $flash = 'Profile updated.';
                    $profile['FirstName'] = $firstName;
                    $profile['LastName'] = $lastName;
                    $profile['Email'] = $email;
                    $profile['ContactNumber'] = $contact;
                    $user = currentUser();
                }
            } catch (PDOException $e) {
                error_log('Patient profile update failed: ' . $e->getMessage());
                $errors[] = 'We could not update your profile right now.';
            }
        }
    } elseif (($_POST['form_type'] ?? '') === 'update_medical') {
        $bloodType    = trim((string) ($_POST['blood_type'] ?? ''));
        $allergies    = trim((string) ($_POST['allergies'] ?? ''));
        $emergName    = hqCapitalizeName((string) ($_POST['emergency_contact_name'] ?? ''));
        $emergNumber  = trim((string) ($_POST['emergency_contact_number'] ?? ''));

        if ($bloodType !== '' && !in_array($bloodType, $bloodTypes, true)) $errors[] = 'Please choose a valid blood type.';
        if (mb_strlen($allergies) > 500) $errors[] = 'Allergies must be 500 characters or fewer.';
        if (mb_strlen($emergName) > 100) $errors[] = 'Emergency contact name must be 100 characters or fewer.';
        if (!hqValidMobile($emergNumber)) $errors[] = 'Enter a valid mobile number';

        if (!$errors) {
            try {
                $pdo->prepare('UPDATE Users SET BloodType = ?, Allergies = ?, EmergencyContactName = ?, EmergencyContactNumber = ? WHERE UserID = ?')
                    ->execute([$bloodType ?: null, $allergies ?: null, $emergName ?: null, $emergNumber ?: null, $user['UserID']]);
                logActivity($pdo, $user['UserID'], null, 'Updated medical information', 'Patient updated their own medical information.');
                foreach (['medical_conditions', 'current_medications'] as $key) $details[$key] = mb_substr(trim((string) ($_POST[$key] ?? '')), 0, 1000);
                savePatientProfileDetails($pdo, (int) $user['UserID'], $details);
                $detailsUpdated = date('Y-m-d H:i:s');
                $flash = 'Medical information updated.';
                $profile['BloodType'] = $bloodType ?: null;
                $profile['Allergies'] = $allergies ?: null;
                $profile['EmergencyContactName'] = $emergName ?: null;
                $profile['EmergencyContactNumber'] = $emergNumber ?: null;
            } catch (PDOException $e) {
                error_log('Patient medical info update failed: ' . $e->getMessage());
                $errors[] = 'We could not update your medical information right now.';
            }
        }
    } elseif (($_POST['form_type'] ?? '') === 'change_password') {
        $current  = (string) ($_POST['current_password'] ?? '');
        $newPass  = (string) ($_POST['new_password'] ?? '');
        $confirm  = (string) ($_POST['new_password_confirm'] ?? '');

        try {
            $stmt = $pdo->prepare('SELECT PasswordHash FROM Users WHERE UserID = ?');
            $stmt->execute([$user['UserID']]);
            $row = $stmt->fetch();

            if (!$row || !password_verify($current, $row['PasswordHash'])) {
                $errors[] = 'Your current password is incorrect.';
            } elseif (strlen($newPass) < 8) {
                $errors[] = 'New password must be at least 8 characters long.';
            } elseif ($newPass !== $confirm) {
                $errors[] = 'New password and confirmation do not match.';
            } else {
                $pdo->prepare('UPDATE Users SET PasswordHash = ? WHERE UserID = ?')
                    ->execute([password_hash($newPass, PASSWORD_DEFAULT), $user['UserID']]);
                $pdo->prepare('UPDATE PasswordResets SET UsedAt=NOW() WHERE UserID=? AND UsedAt IS NULL')->execute([$user['UserID']]);
                loginUser($user);
                logActivity($pdo, $user['UserID'], null, 'Changed password', 'Patient changed their own password.');
                $flash = 'Password changed.';
            }
        } catch (PDOException $e) {
            error_log('Patient password change failed: ' . $e->getMessage());
            $errors[] = 'We could not change your password right now.';
        }
    } elseif (($_POST['form_type'] ?? '') === 'change_photo') {
        $file = $_FILES['photo'] ?? null;
        $allowedTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];

        if (!$file || $file['error'] !== UPLOAD_ERR_OK || $file['size'] <= 0) {
            $errors[] = 'Please choose a photo to upload.';
        } elseif ($file['size'] > 2 * 1024 * 1024) {
            $errors[] = 'Photo is too large (2 MB limit).';
        } else {
            $mime = mime_content_type($file['tmp_name']);
            if (!isset($allowedTypes[$mime])) {
                $errors[] = 'Please upload a JPG, PNG, GIF, or WEBP image.';
            } else {
                try {
                    $uploadDir = __DIR__ . '/../assets/uploads/avatars';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    $filename = 'user_' . $user['UserID'] . '_' . bin2hex(random_bytes(6)) . '.' . $allowedTypes[$mime];

                    if (!empty($profile['ProfilePhoto']) && is_file($uploadDir . '/' . basename($profile['ProfilePhoto']))) {
                        unlink($uploadDir . '/' . basename($profile['ProfilePhoto']));
                    }

                    if (!move_uploaded_file($file['tmp_name'], $uploadDir . '/' . $filename)) {
                        $errors[] = 'We could not save this photo. Please try again.';
                    } else {
                        $pdo->prepare('UPDATE Users SET ProfilePhoto = ? WHERE UserID = ?')->execute([$filename, $user['UserID']]);
                        logActivity($pdo, $user['UserID'], null, 'Changed profile photo', null);
                        $flash = 'Profile photo updated.';
                        $profile['ProfilePhoto'] = $filename;
                    }
                } catch (PDOException $e) {
                    error_log('Photo update failed: ' . $e->getMessage());
                    $errors[] = 'We could not save this photo. Please try again.';
                }
            }
        }
    }
}

// If a form failed validation, reopen the modal the patient was editing in
// instead of stranding the error behind a closed modal. Submitted values are
// echoed back ($old) so the patient doesn't have to retype them.
$reopenModal = null;
$old = [];
if ($errors) {
    $formType = $_POST['form_type'] ?? '';
    $reopenModal = [
        'change_password' => 'changePasswordModal',
        'change_photo'    => 'photoModal',
        'update_profile'  => 'personalInfoModal',
        'update_medical'  => 'medicalInfoModal',
    ][$formType] ?? null;
    if (in_array($formType, ['update_profile', 'update_medical'], true)) {
        $old = $_POST;
    }
}
// Consultation History card: the 5 most recent completed consultations with
// a preview of the physician's latest finalized notes. The full list lives
// on the Medical Records page.
$recentConsultations = [];
$consultationTotal = 0;
if ($pdo) {
    try {
        $stmt = $pdo->prepare(
            "SELECT a.AppointmentID, a.AppointmentDate, c.ClinicName, u.FirstName, u.LastName,
                    (SELECT v.ClinicalNotes
                     FROM Consultations cons
                     JOIN ConsultationVersions v ON v.ConsultationID = cons.ConsultationID
                     WHERE cons.AppointmentID = a.AppointmentID AND v.Status <> 'Draft'
                     ORDER BY v.RevisionNumber DESC LIMIT 1) AS LatestNotes
             FROM Appointments a
             JOIN Clinic c ON c.ClinicID = a.ClinicID
             JOIN Users u ON u.UserID = a.PhysicianID
             WHERE a.PatientID = ? AND a.Status = 'Completed'
             ORDER BY a.AppointmentDate DESC, a.AppointmentTime DESC
             LIMIT 5"
        );
        $stmt->execute([$user['UserID']]);
        $recentConsultations = $stmt->fetchAll();

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM Appointments WHERE PatientID = ? AND Status = 'Completed'");
        $countStmt->execute([$user['UserID']]);
        $consultationTotal = (int) $countStmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('Profile consultation history load failed: ' . $e->getMessage());
    }
}

$formValue = function (string $postKey, ?string $current) use ($old): string {
    return htmlspecialchars((string) ($old[$postKey] ?? $current ?? ''));
};

$profileIcon = static function (string $name): string {
    $paths = [
        'person' => '<circle cx="12" cy="7" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2z"/>',
        'medical' => '<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8z"/><path d="M3 12h5l2-4 4 8 2-4h5"/>',
        'records' => '<rect x="5" y="4" width="14" height="18" rx="2"/><path d="M9 2h6v4H9zM9 11h6M9 16h6"/>',
        'bell' => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M10 21h4"/>',
        'shield' => '<path d="m12 2 9 4v6c0 5-9 10-9 10S3 17 3 12V6zM8 12l3 3 5-6"/>',
        'edit' => '<path d="m16 3 5 5-12 12-6 1 1-6zM14 5l5 5"/>',
    ];
    return '<svg class="pp-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? $paths['person']) . '</svg>';
};
$completionValues = [$profile['FirstName'], $profile['LastName'], $profile['Email'], $profile['ContactNumber'], $profile['ProfilePhoto'], $profile['BloodType'], $profile['EmergencyContactName'], $details['date_of_birth'] ?? '', $details['sex'] ?? '', $details['address'] ?? ''];
$completion = (int) round(count(array_filter($completionValues)) / count($completionValues) * 100);
$displayDetail = static fn(string $key): string => !empty($details[$key]) ? htmlspecialchars($details[$key]) : '<span class="info-empty">Not provided</span>';
$pageTitle = 'My Profile';
require __DIR__ . '/../includes/header.php';
?>

<main class="portal-shell patient-profile-page"><div class="profile-container">
  <?php if(!empty($profile['ContactNumber']) && !hqValidMobile($profile['ContactNumber'])): ?><p class="form-message warning" role="status">Enter a valid mobile number</p><?php endif; ?>
  <?php if ($flash): ?><p class="form-message success" role="status"><?= htmlspecialchars($flash) ?></p><?php endif; ?>
  <?php if ($errors && !$reopenModal): ?><div class="form-message error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

  <div class="profile-grid">
    <div class="pp-left-column"><aside class="profile-card">
      <div class="profile-banner"></div>
      <div class="profile-avatar-wrap">
        <?php if (!empty($profile['ProfilePhoto'])): ?>
          <img src="<?= HQ_BASE_URL ?>/assets/uploads/avatars/<?= htmlspecialchars($profile['ProfilePhoto']) ?>" alt="Profile photo">
        <?php else: ?>
          <div class="profile-avatar-fallback"><?= htmlspecialchars(strtoupper(substr($profile['FirstName'], 0, 1) . substr($profile['LastName'], 0, 1))) ?></div>
        <?php endif; ?>
        <button type="button" class="profile-avatar-edit" data-modal-open="photoModal" aria-label="Change photo">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2Z"/><circle cx="12" cy="13" r="4"/></svg>
        </button>
      </div>
      <h3 class="profile-name"><?= htmlspecialchars($profile['FirstName'] . ' ' . $profile['LastName']) ?></h3>
      <p class="profile-email"><?= htmlspecialchars($profile['Email']) ?></p>

      <div class="pp-verification <?= $verified ? 'is-verified' : '' ?>"><?= $verified ? '&#10003; Email Verified' : 'Email not verified' ?></div>
      <dl class="profile-meta-list">
        <div class="profile-meta-row"><dt>Patient ID</dt><dd><?= !empty($profile['IDNumber']) ? htmlspecialchars($profile['IDNumber']) : '<span class="info-empty">Not assigned</span>' ?></dd></div>
        <div class="profile-meta-row"><dt>Contact Number</dt><dd><?= $profile['ContactNumber'] ? htmlspecialchars($profile['ContactNumber']) : '<span class="info-empty">Not provided</span>' ?></dd></div>
        <div class="profile-meta-row"><dt>Member Since</dt><dd><?= $profile['CreatedAt'] ? htmlspecialchars(date('M Y', strtotime($profile['CreatedAt']))) : '<span class="info-empty">Not provided</span>' ?></dd></div>
      </dl>

      <div class="pp-completion"><div><strong>Profile Completion</strong><strong><?= $completion ?>%</strong></div><progress max="100" value="<?= $completion ?>"><?= $completion ?>%</progress><p>Complete your profile to get the best HealthQueue experience.</p></div>
      <button type="button" class="btn btn-outline btn-block" data-modal-open="personalInfoModal"><?= $profileIcon('edit') ?> Complete Profile</button>
    </aside><article class="pp-medical-card"><div class="pp-card-art"><?= $profileIcon('records') ?><span><?= $profileIcon('shield') ?></span></div><h2>My Medical Card</h2><p>A quick view of your important<br>medical information.</p><button type="button" class="btn btn-primary" data-modal-open="medicalCardModal">View Medical Card</button></article></div>

    <div class="profile-info-column">
      <div class="info-card">
        <div class="info-card-header">
          <h2><?= $profileIcon('person') ?> Personal Information</h2>
          <button type="button" class="text-link pp-edit" data-modal-open="personalInfoModal"><?= $profileIcon('edit') ?> Edit</button>
        </div>
        <div class="info-fields-grid">
          <div class="info-field">
            <span class="info-field-label">First name</span>
            <p class="info-field-value"><?= htmlspecialchars($profile['FirstName']) ?></p>
          </div>
          <div class="info-field">
            <span class="info-field-label">Last name</span>
            <p class="info-field-value"><?= htmlspecialchars($profile['LastName']) ?></p>
          </div>
          <div class="info-field">
            <span class="info-field-label">Email address</span>
            <p class="info-field-value"><?= htmlspecialchars($profile['Email']) ?></p>
          </div>
          <div class="info-field">
            <span class="info-field-label">Contact number</span>
            <p class="info-field-value"><?= $profile['ContactNumber'] ? htmlspecialchars($profile['ContactNumber']) : '<span class="info-empty">Not provided</span>' ?></p>
          </div>
          <div class="info-field"><span class="info-field-label">Date of Birth</span><p class="info-field-value"><?= !empty($details['date_of_birth']) ? htmlspecialchars(date('M j, Y', strtotime($details['date_of_birth']))) : '<span class="info-empty">Not provided</span>' ?></p></div>
          <div class="info-field"><span class="info-field-label">Sex</span><p class="info-field-value"><?= $displayDetail('sex') ?></p></div>
          <div class="info-field pp-address"><span class="info-field-label">Address / Location</span><p class="info-field-value"><?= $displayDetail('address') ?></p></div>
        </div>
      </div>

      <div class="info-card">
        <div class="info-card-header">
          <h2><?= $profileIcon('medical') ?> Medical Information</h2>
          <button type="button" class="text-link pp-edit" data-modal-open="medicalInfoModal"><?= $profileIcon('edit') ?> Edit</button>
        </div>
        <div class="info-fields-grid">
          <div class="info-field">
            <span class="info-field-label">Blood type</span>
            <p class="info-field-value"><?= $profile['BloodType'] ? htmlspecialchars($profile['BloodType']) : '<span class="info-empty">Not provided</span>' ?></p>
          </div>
          <div class="info-field">
            <span class="info-field-label">Allergies</span>
            <p class="info-field-value"><?= $profile['Allergies'] ? nl2br(htmlspecialchars($profile['Allergies'])) : '<span class="info-empty">Not provided</span>' ?></p>
          </div>
          <div class="info-field">
            <span class="info-field-label">Emergency contact</span>
            <p class="info-field-value"><?php
              $emergency = trim(($profile['EmergencyContactName'] ?? '') . ($profile['EmergencyContactNumber'] ? ' · ' . $profile['EmergencyContactNumber'] : ''), ' ·');
              echo $emergency !== '' ? htmlspecialchars($emergency) : '<span class="info-empty">Not provided</span>';
            ?></p>
          </div>
        </div>
        <div class="pp-medical-extra"><div><span>Medical Conditions</span><strong><?= $displayDetail('medical_conditions') ?></strong></div><div><span>Current Medications</span><strong><?= $displayDetail('current_medications') ?></strong></div></div>
        <a href="<?= HQ_BASE_URL ?>/patient/medical-records.php" class="text-link" style="display:inline-block;margin-top:14px;">Manage medical records <span>&rarr;</span></a>
      </div>

      <div class="info-card">
        <div class="info-card-header">
          <h2><?= $profileIcon('records') ?> Consultation History<?php if ($consultationTotal): ?> <span style="color:var(--slate-400);font-weight:600;font-size:.8em;">(<?= $consultationTotal ?>)</span><?php endif; ?></h2>

            <a href="<?= HQ_BASE_URL ?>/patient/medical-records.php" class="text-link">View All Records <span>&rarr;</span></a>

        </div>
        <?php if ($recentConsultations): ?><div class="pp-history-wrap"><table class="pp-history"><thead><tr><th>Date</th><th>Physician</th><th>Clinic</th><th>Status</th><th>Action</th></tr></thead><tbody><?php foreach ($recentConsultations as $item): ?><tr><td><?= htmlspecialchars(date('M j, Y', strtotime($item['AppointmentDate']))) ?></td><td>Dr. <?= htmlspecialchars($item['FirstName'] . ' ' . $item['LastName']) ?></td><td><?= htmlspecialchars($item['ClinicName']) ?></td><td><span class="pp-done">&#10003; Done</span></td><td><button type="button" class="pp-edit text-link" data-view-record="<?= (int) $item['AppointmentID'] ?>">View Details</button></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><p class="pp-empty">No completed consultations yet. Your consultation history will appear here.</p><?php endif; ?>
      </div>
      <div class="pp-bottom-grid"><section class="info-card pp-preferences"><div class="info-card-header"><h2><?= $profileIcon('bell') ?> Notification Preferences</h2></div><p class="pp-subtitle">Choose how you want to receive updates and reminders.</p><form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>"><input type="hidden" name="form_type" value="notification_preferences">
      <?php foreach (['email_notifications' => ['Email Notifications', 'Appointment, queue, and clinic updates'], 'sms_notifications' => ['SMS Notifications', 'Queue and appointment reminders'], 'in_app_notifications' => ['In-App Notifications', 'Real-time updates inside HealthQueue']] as $key => $labels): ?><label class="pp-preference"><span><strong><?= $labels[0] ?></strong><small><?= $labels[1] ?></small></span><input type="checkbox" name="<?= $key ?>" value="1" role="switch"<?= ($details[$key] ?? true) ? ' checked' : '' ?>></label><?php endforeach; ?><p class="pp-note">Preferences are saved for your account. Delivery depends on the available notification services.</p><button class="text-link" type="submit">Save preferences</button></form></section>
      <div class="info-card">
        <div class="info-card-header"><h2><?= $profileIcon('shield') ?> Account &amp; Security</h2></div>
        <button class="pp-security-row" type="button" data-modal-open="changePasswordModal"><span><strong>Change Password</strong><small>Update your account password</small></span><span>&rsaquo;</span></button>
        <div class="pp-security-row"><span><strong>Email Verification</strong><small><?= $verified ? 'Your email is verified' : 'Your email is not verified' ?></small></span><span class="pp-verification <?= $verified ? 'is-verified' : '' ?>"><?= $verified ? 'Verified' : 'Unverified' ?></span></div>
        <div class="pp-security-row"><span><strong>Current Session</strong><small>You are signed in on this browser</small></span><?= $profileIcon('shield') ?></div>
        <p>Deactivation signs you out and prevents future sign-ins. Active bookings will be cancelled and eligible booking fees refunded.</p>
        <form method="post" onsubmit="return confirm('Deactivate your patient account? Active bookings will be cancelled.');">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
          <input type="hidden" name="form_type" value="deactivate_account">
          <button type="submit" class="btn btn-outline ma-danger">Deactivate Account &rsaquo;</button>
        </form>
      </div></div>
    </div>
  </div>
</div></main>

<div class="modal-overlay" id="medicalCardModal"><div class="modal-box pp-card-modal"><button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button><h2><?= $profileIcon('shield') ?> My Medical Card</h2><h3><?= htmlspecialchars($profile['FirstName'] . ' ' . $profile['LastName']) ?></h3><p>Patient ID: <?= htmlspecialchars($profile['IDNumber'] ?? 'Not assigned') ?></p><dl><dt>Blood type</dt><dd><?= htmlspecialchars($profile['BloodType'] ?: 'Not provided') ?></dd><dt>Allergies</dt><dd><?= htmlspecialchars($profile['Allergies'] ?: 'Not provided') ?></dd><dt>Medical conditions</dt><dd><?= $displayDetail('medical_conditions') ?></dd><dt>Current medications</dt><dd><?= $displayDetail('current_medications') ?></dd><dt>Emergency contact</dt><dd><?= htmlspecialchars(trim(($profile['EmergencyContactName'] ?? '') . ' ' . ($profile['EmergencyContactNumber'] ?? '')) ?: 'Not provided') ?></dd></dl><p class="pp-note">Patient-provided information. Confirm details with your physician.</p></div></div>
<div class="modal-overlay" id="personalInfoModal">
  <div class="modal-box">
    <button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
    <h2>Personal Information</h2>
    <?php if ($reopenModal === 'personalInfoModal' && $errors): ?><div class="form-message error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
      <input type="hidden" name="form_type" value="update_profile">
      <div class="form-stack">
        <label>First name<input type="text" name="first_name" value="<?= $formValue('first_name', $profile['FirstName']) ?>" required></label>
        <label>Last name<input type="text" name="last_name" value="<?= $formValue('last_name', $profile['LastName']) ?>" required></label>
        <label>Email address<input type="email" name="email" value="<?= $formValue('email', $profile['Email']) ?>" required></label>
        <label>Contact number<input type="tel" name="contact_number" inputmode="numeric" maxlength="11" pattern="09[0-9]{9}" title="Enter a valid mobile number" value="<?= $formValue('contact_number', $profile['ContactNumber']) ?>"></label>
        <label>Date of birth<input type="date" name="date_of_birth" max="<?= date('Y-m-d') ?>" value="<?= $formValue('date_of_birth', $details['date_of_birth'] ?? '') ?>"></label>
        <label>Sex<select name="sex"><?php foreach (['' => 'Not specified', 'Male' => 'Male', 'Female' => 'Female', 'Prefer not to say' => 'Prefer not to say'] as $value => $label): ?><option value="<?= $value ?>"<?= ($old['sex'] ?? $details['sex'] ?? '') === $value ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
        <label>Address / Location<textarea name="address" maxlength="500"><?= $formValue('address', $details['address'] ?? '') ?></textarea></label>
      </div>
      <button type="submit" class="btn btn-primary btn-block" style="margin-top:14px;">Save Changes</button>
    </form>
  </div>
</div>

<div class="modal-overlay" id="medicalInfoModal">
  <div class="modal-box">
    <button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
    <h2>Medical Information</h2>
    <?php if ($reopenModal === 'medicalInfoModal' && $errors): ?><div class="form-message error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
      <input type="hidden" name="form_type" value="update_medical">
      <label>Medical conditions<textarea name="medical_conditions" maxlength="1000"><?= $formValue('medical_conditions', $details['medical_conditions'] ?? '') ?></textarea></label>
      <label>Current medications<textarea name="current_medications" maxlength="1000"><?= $formValue('current_medications', $details['current_medications'] ?? '') ?></textarea></label>
      <?php $selectedBlood = $old['blood_type'] ?? $profile['BloodType'] ?? ''; ?>
      <div class="form-stack">
        <label>Blood type
          <select name="blood_type">
            <option value="">Not specified</option>
            <?php foreach ($bloodTypes as $type): ?>
              <option value="<?= $type ?>"<?= $selectedBlood === $type ? ' selected' : '' ?>><?= $type ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Allergies<textarea name="allergies" rows="3" maxlength="500" placeholder="e.g. Penicillin, peanuts"><?= $formValue('allergies', $profile['Allergies']) ?></textarea></label>
        <label>Emergency contact name<input type="text" name="emergency_contact_name" maxlength="100" value="<?= $formValue('emergency_contact_name', $profile['EmergencyContactName']) ?>"></label>
        <label>Emergency contact number<input type="tel" name="emergency_contact_number" inputmode="numeric" maxlength="11" pattern="09[0-9]{9}" title="Enter a valid mobile number" value="<?= $formValue('emergency_contact_number', $profile['EmergencyContactNumber']) ?>"></label>
      </div>
      <button type="submit" class="btn btn-primary btn-block" style="margin-top:14px;">Save Changes</button>
    </form>
  </div>
</div>

<div class="modal-overlay" id="photoModal">
  <div class="modal-box">
    <button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
    <h2>Profile photo</h2>
    <p class="modal-subtitle">JPG, PNG, GIF, or WEBP — 2 MB max.</p>
    <?php if ($reopenModal === 'photoModal' && $errors): ?><div class="form-message error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
      <input type="hidden" name="form_type" value="change_photo">
      <div class="form-stack">
        <label>Choose a photo<input type="file" name="photo" accept="image/jpeg,image/png,image/gif,image/webp" required></label>
      </div>
      <button type="submit" class="btn btn-primary btn-block" style="margin-top:14px;">Upload Photo</button>
    </form>
  </div>
</div>

<div class="modal-overlay" id="changePasswordModal">
  <div class="modal-box">
    <button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
    <h2>Change Password</h2>
    <?php if ($reopenModal === 'changePasswordModal' && $errors): ?><div class="form-message error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
      <input type="hidden" name="form_type" value="change_password">
      <div class="form-stack">
        <label>Current password<input type="password" name="current_password" required></label>
        <label>New password<input type="password" name="new_password" minlength="8" required></label>
        <label>Confirm new password<input type="password" name="new_password_confirm" minlength="8" required></label>
      </div>
      <button type="submit" class="btn btn-primary btn-block" style="margin-top:14px;">Change Password</button>
    </form>
  </div>
</div>

<?php if ($reopenModal): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  window.hqOpenModal(document.getElementById('<?= $reopenModal ?>'));
});
</script>
<?php endif; ?>

<div class="modal-overlay" id="viewRecordModal">
  <div class="modal-box modal-box-wide" style="max-height:85vh;">
    <button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
    <div id="viewRecordContent"><p class="admin-empty">Loading…</p></div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  // Same consultation-record modal as the Medical Records page: the record
  // (and its feedback form) is rendered by patient/consultation.php.
  var viewRecordModal = document.getElementById('viewRecordModal');
  var viewRecordContent = document.getElementById('viewRecordContent');

  document.querySelectorAll('[data-view-record]').forEach(function (trigger) {
    trigger.addEventListener('click', function () {
      viewRecordContent.innerHTML = '<p class="admin-empty">Loading…</p>';
      window.hqOpenModal(viewRecordModal);
      fetch('<?= HQ_BASE_URL ?>/patient/consultation.php?appointment_id=' + encodeURIComponent(trigger.getAttribute('data-view-record')), { headers: { 'X-Requested-With': 'fetch' } })
        .then(function (res) { return res.text(); })
        .then(function (html) { viewRecordContent.innerHTML = html; })
        .catch(function () {
          viewRecordContent.innerHTML = '<p class="form-message error" role="alert">We could not load this record. Please try again.</p>';
        });
    });
  });

  viewRecordContent.addEventListener('submit', function (e) {
    if (!e.target.matches('.consultation-feedback-form')) return;
    e.preventDefault();
    var form = e.target;
    fetch(form.action, { method: 'POST', body: new FormData(form), headers: { 'X-Requested-With': 'fetch' } })
      .then(function (res) { return res.text(); })
      .then(function (html) { viewRecordContent.innerHTML = html; })
      .catch(function () {
        var err = document.createElement('p');
        err.className = 'form-message error';
        err.textContent = 'We could not save your feedback. Please try again.';
        form.prepend(err);
      });
  });
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
