<?php
/** Shared writes for the five explicit relationships; no delivery or payment services. */
function consumeLinkedRequestLimit(PDO $pdo,string $scope,string $identity,int $limit,int $seconds=900,?int $userId=null):bool {
 $seconds=max(1,$seconds);$key=hash('sha256','healthqueue|'.$scope.'|'.strtolower(trim($identity)));
 // UserID describes this bucket's latest request, not every person sharing an IP.
 $sql="INSERT INTO RequestRateLimits(BucketKey,WindowStart,Attempts,UserID) VALUES(?,NOW(),1,(SELECT UserID FROM Users WHERE UserID=? AND Status='Active' AND DeletedAt IS NULL))
 ON DUPLICATE KEY UPDATE Attempts=IF(WindowStart<=NOW()-INTERVAL {$seconds} SECOND,1,Attempts+1),WindowStart=IF(WindowStart<=NOW()-INTERVAL {$seconds} SECOND,NOW(),WindowStart),UserID=VALUES(UserID)";
 $pdo->prepare($sql)->execute([$key,$userId]);$q=$pdo->prepare('SELECT Attempts FROM RequestRateLimits WHERE BucketKey=?');$q->execute([$key]);return (int)$q->fetchColumn()<=$limit;
}
function linkSignupVerificationToUser(PDO $pdo,string $token,int $userId):void {
 if(!$pdo->inTransaction())throw new LogicException('Registration owns the verification transaction.');
 $q=$pdo->prepare("UPDATE SignupEmailVerifications v JOIN Users u ON u.UserID=? AND LOWER(u.Email)=LOWER(v.Email) JOIN Roles r ON r.RoleID=u.RoleID AND r.RoleName='Patient'
 SET v.UserID=u.UserID,v.UsedAt=NOW() WHERE v.TokenHash=? AND v.VerifiedAt IS NOT NULL AND v.UsedAt IS NULL AND v.ExpiresAt>NOW() AND u.Status='Active' AND u.DeletedAt IS NULL");$q->execute([$userId,hash('sha256',$token)]);
 if($q->rowCount()!==1)throw new RuntimeException('Signup email verification is missing or no longer valid.');
}
function approveClinicInquiryWithLink(PDO $pdo,int $inquiryId):int {
 if(!$pdo->inTransaction())throw new LogicException('Clinic review owns its transaction.');
 require_once __DIR__.'/clinic-inquiry.php';$q=$pdo->prepare('SELECT * FROM ClinicRegistrationInquiry WHERE InquiryID=? FOR UPDATE');$q->execute([$inquiryId]);$r=$q->fetch();
 if(!$r||$r['Status']!=='Pending')throw new RuntimeException('This registration inquiry has already been reviewed.');
 $id=clinicFromRegistrationInquiry($pdo,$r);$pdo->prepare("UPDATE ClinicRegistrationInquiry SET Status='Approved',ApprovedClinicID=? WHERE InquiryID=?")->execute([$id,$inquiryId]);return $id;
}
function insertReferencedSms(PDO $pdo,int $patientId,int $appointmentId,string $eventKey,string $event,string $message):bool {
 $q=$pdo->prepare("INSERT INTO SmsOutbox(PatientID,AppointmentID,EventKey,EventType,Message)
 SELECT a.PatientID,a.AppointmentID,?,?,? FROM Appointments a JOIN Users u ON u.UserID=a.PatientID JOIN Roles r ON r.RoleID=u.RoleID
 WHERE a.AppointmentID=? AND a.PatientID=? AND r.RoleName='Patient' AND u.Status='Active' AND u.DeletedAt IS NULL
 ON DUPLICATE KEY UPDATE EventKey=EventKey");$q->execute([$eventKey,$event,$message,$appointmentId,$patientId]);return $q->rowCount()===1;
}

/** Attribute a verified successful request without changing its protection counters. */
function linkRequestLimitActor(PDO $pdo,string $scope,string $identity,int $userId):void {
 $key=hash('sha256','healthqueue|'.$scope.'|'.strtolower(trim($identity)));
 $q=$pdo->prepare("UPDATE RequestRateLimits b JOIN Users u ON u.UserID=? AND u.Status='Active' AND u.DeletedAt IS NULL SET b.UserID=u.UserID WHERE b.BucketKey=?");$q->execute([$userId,$key]);
}
