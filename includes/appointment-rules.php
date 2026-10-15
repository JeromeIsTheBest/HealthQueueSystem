<?php
/** The patient may change an appointment until two hours before its start. */
function appointmentChangeOpen(PDO $pdo, int $appointmentId, int $patientId): bool
{
    $stmt = $pdo->prepare(
        "SELECT TIMESTAMP(a.AppointmentDate, COALESCE(a.AppointmentTime, SUBTIME(c.CloseTime, '02:00:00'))) > NOW()
         FROM Appointments a JOIN Clinic c ON c.ClinicID = a.ClinicID
         WHERE a.AppointmentID = ? AND a.PatientID = ? AND a.Status IN ('Pending', 'Confirmed')"
    );
    $stmt->execute([$appointmentId, $patientId]);
    return (bool) $stmt->fetchColumn();
}
