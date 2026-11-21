<?php
/**
 * Shared announcement helpers for the admin (platform-wide) and staff
 * (own clinic) posting pages.
 */

const ANNOUNCEMENT_CATEGORIES = ['General', 'Closure', 'Schedule change', 'Event', 'Health advisory'];
const ANNOUNCEMENT_PHOTO_DIR = __DIR__ . '/../assets/uploads/announcements';

/**
 * Validates and stores an optional announcement photo from $_FILES['photo'].
 * Returns [filename|null, error|null]; [null, null] when no file was chosen.
 */
function saveAnnouncementPhoto(?array $file): array
{
    if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) return [null, null];
    if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] <= 0) return [null, 'We could not read the uploaded photo. Please try again.'];
    if ($file['size'] > 5 * 1024 * 1024) return [null, 'Announcement photo is too large (5 MB limit).'];

    $allowedTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $mime = mime_content_type($file['tmp_name']);
    if (!isset($allowedTypes[$mime])) return [null, 'Please upload a JPG, PNG, WEBP, or GIF image.'];

    if (!is_dir(ANNOUNCEMENT_PHOTO_DIR)) mkdir(ANNOUNCEMENT_PHOTO_DIR, 0755, true);
    $filename = 'announcement_' . bin2hex(random_bytes(8)) . '.' . $allowedTypes[$mime];
    if (!move_uploaded_file($file['tmp_name'], ANNOUNCEMENT_PHOTO_DIR . '/' . $filename)) {
        return [null, 'We could not save the photo. Please try again.'];
    }
    return [$filename, null];
}

/** Deletes a stored announcement photo, if any. */
function deleteAnnouncementPhoto(?string $filename): void
{
    if ($filename && is_file(ANNOUNCEMENT_PHOTO_DIR . '/' . basename($filename))) {
        unlink(ANNOUNCEMENT_PHOTO_DIR . '/' . basename($filename));
    }
}

/** Public URL for an announcement photo (relative to HQ_BASE_URL). */
function announcementPhotoUrl(?string $filename): ?string
{
    return $filename ? HQ_BASE_URL . '/assets/uploads/announcements/' . rawurlencode(basename($filename)) : null;
}

/** Personal in-app announcement alerts; caller owns the publishing transaction. */
function notifyPhysiciansOfAnnouncement(PDO $pdo,int $announcementId):int
{
    if(!$pdo->inTransaction())throw new LogicException('Announcement alerts require a transaction.');
    $s=$pdo->prepare("SELECT a.*,c.ClinicName FROM Announcements a LEFT JOIN Clinic c ON c.ClinicID=a.ClinicID WHERE a.AnnouncementID=?");$s->execute([$announcementId]);$a=$s->fetch();
    if(!$a||!in_array($a['Audience'],['Physicians','Everyone'],true))return 0;
    $message='Announcement: '.$a['Title']."\n".$a['Message']."\nFrom: ".($a['ClinicName']?:'HealthQueue');
    if($a['AffectsDate'])$message.="\nAffected date: ".date('j F Y',strtotime($a['AffectsDate']));
    $s=$pdo->prepare("INSERT INTO PhysicianNotifications(PhysicianID,AnnouncementID,Message)
        SELECT u.UserID,?,? FROM Users u JOIN Roles r ON r.RoleID=u.RoleID
        WHERE r.RoleName='Physician' AND u.Status='Active' AND u.DeletedAt IS NULL
          AND u.CreatedAt<=? AND (? IS NULL OR u.ClinicID=?)
        ON DUPLICATE KEY UPDATE NotificationID=PhysicianNotifications.NotificationID");
    $s->execute([$announcementId,$message,$a['CreatedAt'],$a['ClinicID'],$a['ClinicID']]);return $s->rowCount();
}

/** Deliver only to the selected audience; staff alerts retain the existing shared-clinic read state. */
function notifyAnnouncementAudience(PDO $pdo,int $announcementId):int
{
    if(!$pdo->inTransaction())throw new LogicException('Announcement alerts require a transaction.');
    require_once __DIR__.'/notification-preferences.php';
    $count=notifyPhysiciansOfAnnouncement($pdo,$announcementId);
    $s=$pdo->prepare('SELECT a.*,c.ClinicName FROM Announcements a LEFT JOIN Clinic c ON c.ClinicID=a.ClinicID WHERE a.AnnouncementID=?');$s->execute([$announcementId]);$a=$s->fetch();if(!$a)return $count;
    $message='Announcement: '.$a['Title']."\n".$a['Message']."\nFrom: ".($a['ClinicName']?:'HealthQueue');if($a['AffectsDate'])$message.="\nAffected date: ".date('j F Y',strtotime($a['AffectsDate']));
    if(in_array($a['Audience'],['Staff','Everyone'],true)){
        $s=$pdo->prepare("INSERT INTO Notifications(ClinicID,AnnouncementID,Message)
            SELECT DISTINCT u.ClinicID,?,? FROM Users u JOIN Roles r ON r.RoleID=u.RoleID JOIN Clinic c ON c.ClinicID=u.ClinicID
            WHERE r.RoleName='Staff' AND u.Status='Active' AND u.DeletedAt IS NULL AND c.archived=0
              AND u.CreatedAt<=? AND (? IS NULL OR u.ClinicID=?)
            ON DUPLICATE KEY UPDATE NotificationID=Notifications.NotificationID");
        $s->execute([$announcementId,$message,$a['CreatedAt'],$a['ClinicID'],$a['ClinicID']]);$count+=$s->rowCount();
    }
    if(in_array($a['Audience'],['Patients','Everyone'],true)){
        $s=$pdo->prepare("SELECT u.UserID FROM Users u JOIN Roles r ON r.RoleID=u.RoleID
            WHERE r.RoleName='Patient' AND u.Status='Active' AND u.DeletedAt IS NULL AND u.CreatedAt<=?
              AND (? IS NULL OR EXISTS(SELECT 1 FROM Appointments ap WHERE ap.PatientID=u.UserID AND ap.ClinicID=? AND ap.CreatedAt<=?))
              AND NOT EXISTS(SELECT 1 FROM AnnouncementDismissals d WHERE d.UserID=u.UserID AND d.AnnouncementID=?)");
        $s->execute([$a['CreatedAt'],$a['ClinicID'],$a['ClinicID'],$a['CreatedAt'],$announcementId]);$ids=$s->fetchAll(PDO::FETCH_COLUMN);
        $insert=$pdo->prepare('INSERT INTO PatientNotifications(PatientID,AnnouncementID,Message) VALUES(?,?,?) ON DUPLICATE KEY UPDATE NotificationID=PatientNotifications.NotificationID');
        foreach($ids as $id)if(patientAllowsNotification($pdo,(int)$id,'in_app_notifications')){$insert->execute([$id,$announcementId,$message]);$count+=$insert->rowCount();}
    }
    return $count;
}
