<?php
/** Caller owns transaction; the patient row serializes all sessions and clinics. */
function lockPatientBooking(PDO $pdo,int $patientId):void
{
 if(!$pdo->inTransaction())throw new LogicException('Patient booking checks require a transaction.');
 $stmt=$pdo->prepare("SELECT UserID FROM Users WHERE UserID=? AND Status='Active' AND DeletedAt IS NULL AND RoleID=(SELECT RoleID FROM Roles WHERE RoleName='Patient') FOR UPDATE");$stmt->execute([$patientId]);if(!$stmt->fetchColumn())throw new RuntimeException('Patient account unavailable.');
 $stmt=$pdo->prepare("SELECT AppointmentID FROM Appointments WHERE PatientID=? AND Status IN ('Pending','Confirmed') LIMIT 1 FOR UPDATE");$stmt->execute([$patientId]);if($stmt->fetchColumn())throw new RuntimeException('You already have an active booking. Review it before registering another visit.');
}
