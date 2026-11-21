<?php
/** The patient may change an appointment until two hours before its start. */
function appointmentChangeOpen(PDO $pdo, int $appointmentId, int $patientId): bool
{
    $stmt = $pdo->prepare(
        "SELECT TIMESTAMP(a.AppointmentDate, COALESCE(NULLIF(a.AppointmentTime,'00:00:00'), c.CloseTime)) - INTERVAL 2 HOUR > NOW()
         FROM Appointments a JOIN Clinic c ON c.ClinicID = a.ClinicID
         WHERE a.AppointmentID = ? AND a.PatientID = ? AND a.Status IN ('Pending', 'Confirmed') AND NOT EXISTS(SELECT 1 FROM VisitCompletionReviews cr WHERE cr.AppointmentID=a.AppointmentID AND cr.Status IN ('AwaitingPatient','IssueReported'))"
    );
    $stmt->execute([$appointmentId, $patientId]);
    return (bool) $stmt->fetchColumn();
}

/** Rescheduling alone has a special scheduled-arrival grace exception; cancellation does not. */
function appointmentRescheduleOpen(PDO $pdo,int $appointmentId,int $patientId):bool
{
    $review=$pdo->prepare("SELECT AppointmentID FROM VisitCompletionReviews WHERE AppointmentID=? AND Status IN ('AwaitingPatient','IssueReported')");$review->execute([$appointmentId]);if($review->fetchColumn())return false;
    require_once __DIR__.'/scheduled-grace.php';
    return appointmentChangeOpen($pdo,$appointmentId,$patientId)||appointmentGraceRescheduleOpen($pdo,$appointmentId,$patientId);
}
