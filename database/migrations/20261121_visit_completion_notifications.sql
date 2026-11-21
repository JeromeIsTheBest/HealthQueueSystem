CREATE TABLE IF NOT EXISTS VisitCompletionNotifications (
 NotificationID INT NOT NULL PRIMARY KEY,
 AppointmentID INT NOT NULL,
 FinalizedVersionID INT NOT NULL,
 UNIQUE KEY uq_visit_confirmation_notice(AppointmentID,FinalizedVersionID),
 FOREIGN KEY(NotificationID) REFERENCES PatientNotifications(NotificationID) ON DELETE CASCADE,
 FOREIGN KEY(AppointmentID) REFERENCES Appointments(AppointmentID),
 FOREIGN KEY(FinalizedVersionID) REFERENCES ConsultationVersions(VersionID)
) ENGINE=InnoDB;
