CREATE TABLE IF NOT EXISTS MedicalRecordAccessNotifications (
 NotificationID INT NOT NULL PRIMARY KEY,
 RequestID INT NOT NULL UNIQUE,
 FOREIGN KEY(NotificationID) REFERENCES PatientNotifications(NotificationID) ON DELETE CASCADE,
 FOREIGN KEY(RequestID) REFERENCES MedicalRecordAccessRequests(RequestID) ON DELETE CASCADE
) ENGINE=InnoDB;
