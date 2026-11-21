<?php
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../config/db.php';require __DIR__.'/../includes/announcements.php';
if(!in_array('--apply',$argv,true)){echo "Use --apply to install announcement alerts for Staff and Patients and recover missed in-app alerts.\n";exit;}
$pdo=getDbConnection();if(!$pdo)exit(1);
$pdo->exec(file_get_contents(__DIR__.'/../database/migrations/20261120_announcement_inbox_delivery.sql'));
foreach(['Notifications'=>'fk_staff_notice_announcement','PatientNotifications'=>'fk_patient_notice_announcement'] as $table=>$name){
 $s=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=?');$s->execute([$table,$name]);
 if(!(int)$s->fetchColumn())$pdo->exec('ALTER TABLE '.$table.' ADD CONSTRAINT '.$name.' FOREIGN KEY (AnnouncementID) REFERENCES Announcements(AnnouncementID) ON DELETE CASCADE');
}
$count=0;foreach($pdo->query('SELECT AnnouncementID FROM Announcements')->fetchAll(PDO::FETCH_COLUMN) as $id){$pdo->beginTransaction();try{$count+=notifyAnnouncementAudience($pdo,(int)$id);$pdo->commit();}catch(Throwable $e){$pdo->rollBack();throw $e;}}
echo "Announcement inbox delivery installed. Missing in-app alerts recovered: $count. No SMS or email sent.\n";
