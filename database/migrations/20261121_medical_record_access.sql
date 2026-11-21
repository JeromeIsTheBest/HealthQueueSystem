CREATE TABLE IF NOT EXISTS MedicalRecordAccessRequests (
 RequestID INT AUTO_INCREMENT PRIMARY KEY,
 AppointmentID INT NOT NULL,
 PatientID INT NOT NULL,
 PhysicianID INT NOT NULL,
 Status VARCHAR(20) NOT NULL DEFAULT 'Pending',
 RequestedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 RespondedAt DATETIME NULL,
 EndedAt DATETIME NULL,
 UNIQUE KEY uq_record_access_visit (AppointmentID,PhysicianID),
 INDEX idx_record_access_patient (PatientID,Status),
 FOREIGN KEY (AppointmentID) REFERENCES Appointments(AppointmentID),
 FOREIGN KEY (PatientID) REFERENCES Users(UserID),
 FOREIGN KEY (PhysicianID) REFERENCES Users(UserID)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS MedicalRecordAccessFiles (
 RequestID INT NOT NULL,
 RecordID INT NOT NULL,
 PRIMARY KEY(RequestID,RecordID),
 FOREIGN KEY(RequestID) REFERENCES MedicalRecordAccessRequests(RequestID) ON DELETE CASCADE,
 FOREIGN KEY(RecordID) REFERENCES MedicalRecords(RecordID) ON DELETE CASCADE
) ENGINE=InnoDB;
