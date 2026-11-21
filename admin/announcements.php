<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/announcements.php';

define('HQ_BASE_URL', '..');
requireRole(['Admin']);

$user = currentUser();
$pdo = getDbConnection();
$errors = [];
$flash = (string) ($_SESSION['admin_success'][basename(__FILE__)] ?? '');
unset($_SESSION['admin_success'][basename(__FILE__)]);
$values = ['title' => '', 'message' => '', 'audience' => 'Everyone', 'clinic_id' => '', 'category' => 'General', 'affects_date' => ''];
$audiences = ['Everyone', 'Patients', 'Staff', 'Physicians'];
$categories = ANNOUNCEMENT_CATEGORIES;
$clinicOptions = $pdo ? $pdo->query("SELECT ClinicID, ClinicName FROM Clinic WHERE archived = 0 ORDER BY ClinicName")->fetchAll(PDO::FETCH_KEY_PAIR) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!$pdo) {
        $errors[] = 'The database is temporarily unavailable.';
    } else {
        $values['title'] = trim((string) ($_POST['title'] ?? ''));
        $values['message'] = trim((string) ($_POST['message'] ?? ''));
        $values['audience'] = (string) ($_POST['audience'] ?? 'Everyone');
        $values['clinic_id'] = (string) ($_POST['clinic_id'] ?? '');
        $values['category'] = (string) ($_POST['category'] ?? 'General');
        $values['affects_date'] = trim((string) ($_POST['affects_date'] ?? ''));

        if ($values['title'] === '') $errors[] = 'Title is required.';
        if ($values['message'] === '') $errors[] = 'Message is required.';
        if (!in_array($values['audience'], $audiences, true)) $errors[] = 'Please choose a valid audience.';
        if (!in_array($values['category'], $categories, true)) $errors[] = 'Please choose a valid category.';
        if ($values['clinic_id'] !== '' && !isset($clinicOptions[(int) $values['clinic_id']])) $errors[] = 'Please choose a valid clinic.';
        if ($values['affects_date'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $values['affects_date'])) $errors[] = 'Please enter a valid affected date.';
        if ($values['affects_date'] !== '' && $values['clinic_id'] === '') $errors[] = 'An affected date only applies to a clinic announcement -- choose the clinic it is from.';

        $photo = null;
        if (!$errors) {
            [$photo, $photoError] = saveAnnouncementPhoto($_FILES['photo'] ?? null);
            if ($photoError) $errors[] = $photoError;
        }

        if (!$errors) {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('INSERT INTO Announcements (PostedByUserID, ClinicID, Title, Category, Message, PhotoPath, Audience, AffectsDate) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$user['UserID'], $values['clinic_id'] !== '' ? (int) $values['clinic_id'] : null, $values['title'], $values['category'], $values['message'], $photo, $values['audience'], $values['affects_date'] ?: null]);
                notifyAnnouncementAudience($pdo,(int)$pdo->lastInsertId());
                logActivity($pdo, $user['UserID'], null, 'Posted announcement', $values['title'] . ' (' . $values['audience'] . ')');
                $pdo->commit();
                $_SESSION['admin_success'][basename(__FILE__)] = $flash !== '' ? $flash : 'Announcement published.';
                header('Location: ' . HQ_BASE_URL . '/admin/announcements.php');
                exit;
            } catch (Throwable $e) {
                if($pdo->inTransaction())$pdo->rollBack();
                deleteAnnouncementPhoto($photo);
                error_log('Announcement save failed: ' . $e->getMessage());
                $errors[] = 'We could not post this announcement.';
            }
        }
    }
}

$announcements = [];
if ($pdo) {
    try {
        $announcements = $pdo->query(
            "SELECT a.AnnouncementID, a.Title, a.Category, a.Message, a.PhotoPath, a.Audience, a.AffectsDate, a.CreatedAt, u.FirstName, u.LastName, c.ClinicName
             FROM Announcements a JOIN Users u ON u.UserID = a.PostedByUserID LEFT JOIN Clinic c ON c.ClinicID = a.ClinicID
             ORDER BY a.CreatedAt DESC LIMIT 30"
        )->fetchAll();
    } catch (PDOException $e) {
        error_log('Announcements list failed: ' . $e->getMessage());
        $errors[] = 'Announcement history is temporarily unavailable.';
    }
}

$pageTitle = 'Announcements — HealthQueue';
$isAdminAnnouncements = true;
require __DIR__ . '/../includes/header.php';
?>

<main class="admin-shell aa-page"><div class="container aa-container">
<p class="aa-breadcrumb">Administration <span>/</span> Announcements</p>
<header class="aa-heading"><div><h1>Announcements</h1><p>Keep your community informed.</p></div><time datetime="<?= date('Y-m-d') ?>"><?= date('j F Y') ?></time></header>
<?php if ($flash): ?><p class="form-message success" role="status"><?= htmlspecialchars($flash) ?></p><?php endif; ?>
<?php if ($errors): ?><div class="form-message error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<div class="aa-columns">
<section class="aa-panel" aria-labelledby="aa-compose-title">
<h2 id="aa-compose-title">Post an update</h2>
<form class="aa-form" method="post" enctype="multipart/form-data">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
<label>Title<input name="title" placeholder="Enter announcement title" value="<?= htmlspecialchars($values['title']) ?>" required></label>
<div class="aa-field-row">
<label>Audience<select name="audience"><?php foreach ($audiences as $audience): ?><option value="<?= htmlspecialchars($audience) ?>" <?= $values['audience']===$audience?'selected':'' ?>><?= htmlspecialchars($audience) ?></option><?php endforeach; ?></select></label>
<label>From<select name="clinic_id"><option value="">HealthQueue (platform-wide)</option><?php foreach ($clinicOptions as $optId=>$optName): ?><option value="<?= (int)$optId ?>" <?= $values['clinic_id']===(string)$optId?'selected':'' ?>><?= htmlspecialchars($optName) ?></option><?php endforeach; ?></select></label>
</div>
<div class="aa-field-row">
<label>Category<select name="category"><?php foreach ($categories as $category): ?><option <?= $values['category']===$category?'selected':'' ?>><?= htmlspecialchars($category) ?></option><?php endforeach; ?></select></label>
<label>Affected date <span class="aa-optional">(optional)</span><input type="date" name="affects_date" value="<?= htmlspecialchars($values['affects_date']) ?>" aria-describedby="aa-date-help"></label>
</div>
<p id="aa-date-help" class="aa-hint">Choose a clinic to pin this update for its patients booked on the affected date.</p>
<label>Message<textarea name="message" rows="5" placeholder="Write a clear update for your community..." required><?= htmlspecialchars($values['message']) ?></textarea></label>
<div><label class="aa-photo-label" for="aa-photo">Photo <span class="aa-optional">(optional)</span></label>
<div class="aa-upload"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M12 16V3m-5 5 5-5 5 5M4 15v6h16v-6"/></svg><span>Add a photo</span><small>JPG, PNG, WEBP or GIF ? Up to 5 MB</small><input id="aa-photo" type="file" name="photo" accept="image/jpeg,image/png,image/webp,image/gif" aria-describedby="aa-photo-name"></div><p class="aa-hint" id="aa-photo-name" aria-live="polite"></p></div>
<button type="submit" class="btn btn-primary btn-block">Publish announcement</button>
</form></section>
<section class="aa-panel aa-history" aria-labelledby="aa-history-title">
<h2 id="aa-history-title">Recent announcements <span class="aa-count"><?= count($announcements) ?></span></h2>
<?php if($announcements): ?><div class="aa-history-list">
<?php foreach($announcements as $announcement): ?>
<article class="aa-card"><h3><?= htmlspecialchars($announcement['Title']) ?></h3>
<div class="aa-tags"><span><?= htmlspecialchars($announcement['Category']) ?></span><span class="aa-audience"><?= htmlspecialchars($announcement['Audience']) ?></span></div>
<p class="aa-message"><?= nl2br(htmlspecialchars($announcement['Message'])) ?></p>
<?php if($announcement['PhotoPath']): ?><img src="<?= htmlspecialchars(announcementPhotoUrl($announcement['PhotoPath'])) ?>" alt="" class="aa-photo"><?php endif; ?>
<p class="aa-meta"><?= htmlspecialchars($announcement['ClinicName'] ?: 'HealthQueue') ?> &middot; <?= htmlspecialchars(date('j M Y',strtotime($announcement['CreatedAt']))) ?></p>
<?php if($announcement['AffectsDate']): ?><p class="aa-meta">Affects appointments on <?= htmlspecialchars(date('j M Y',strtotime($announcement['AffectsDate']))) ?></p><?php endif; ?>
<p class="aa-meta">Posted by <?= htmlspecialchars($announcement['FirstName'].' '.$announcement['LastName']) ?></p>
</article>
<?php endforeach; ?></div><?php else: ?><div class="aa-empty"><h3>No announcements yet</h3><p>Publish your first update using the form.</p></div><?php endif; ?>
</section></div></div></main>
<script src="<?= HQ_BASE_URL ?>/assets/js/admin-announcements.js?v=<?= filemtime(__DIR__ . '/../assets/js/admin-announcements.js') ?>" defer></script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
