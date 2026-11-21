<?php
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../config/db.php';require __DIR__.'/../includes/announcements.php';
if(!in_array('--apply',$argv,true)){echo "Use --apply to install physician announcement alerts and recover missing existing alerts.\n";exit;}
$pdo=getDbConnection();if(!$pdo)exit(1);
$pdo->exec(file_get_contents(__DIR__.'/../database/migrations/20261120_physician_announcement_notifications.sql'));
$check=$pdo->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='PhysicianNotifications' AND CONSTRAINT_NAME='fk_physician_notice_announcement'");if(!(int)$check->fetchColumn())$pdo->exec('ALTER TABLE PhysicianNotifications ADD CONSTRAINT fk_physician_notice_announcement FOREIGN KEY (AnnouncementID) REFERENCES Announcements(AnnouncementID) ON DELETE CASCADE');
$ids=$pdo->query("SELECT AnnouncementID FROM Announcements WHERE Audience IN ('Physicians','Everyone')")->fetchAll(PDO::FETCH_COLUMN);$count=0;
foreach($ids as $id){$pdo->beginTransaction();try{$count+=notifyPhysiciansOfAnnouncement($pdo,(int)$id);$pdo->commit();}catch(Throwable $e){$pdo->rollBack();throw $e;}}
echo "Physician announcement alerts installed. Missing in-app alerts recovered: $count. No SMS or email sent.\n";
