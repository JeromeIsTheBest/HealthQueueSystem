-- Apply this migration to an existing HealthQueue database before using
-- patient reschedule requests or cancellation flows that withdraw requests.
-- Safe to run more than once.
CREATE TABLE IF NOT EXISTS AppointmentRescheduleRequests (
    RequestID       INT AUTO_INCREMENT PRIMARY KEY,
    AppointmentID  INT NOT NULL,
    PatientID       INT NOT NULL,
    ClinicID        INT NOT NULL,
    RequestedDate   DATE NOT NULL,
    RequestedTime   TIME NOT NULL,
    Status          VARCHAR(20) NOT NULL DEFAULT 'Pending',
    RequestedAt     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ReviewedBy      INT NULL,
    ReviewedAt      DATETIME NULL,
    CONSTRAINT fk_reschedule_appointment FOREIGN KEY (AppointmentID) REFERENCES Appointments (AppointmentID),
    CONSTRAINT fk_reschedule_patient FOREIGN KEY (PatientID) REFERENCES Users (UserID),
    CONSTRAINT fk_reschedule_clinic FOREIGN KEY (ClinicID) REFERENCES Clinic (ClinicID),
    CONSTRAINT fk_reschedule_reviewer FOREIGN KEY (ReviewedBy) REFERENCES Users (UserID),
    INDEX idx_reschedule_clinic_status (ClinicID, Status, RequestedAt)
);
