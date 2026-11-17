<?php
function patientAllowsNotification(PDO $pdo,int $patientId,string $preference):bool
{
 if(!in_array($preference,['email_notifications','sms_notifications','in_app_notifications'],true))throw new InvalidArgumentException('Unknown notification preference.');
 $stmt=$pdo->prepare('SELECT Details FROM PatientProfileDetails WHERE UserID=?');$stmt->execute([$patientId]);$details=json_decode($stmt->fetchColumn()?:'{}',true);
 return !isset($details[$preference]) || (bool)$details[$preference];
}
