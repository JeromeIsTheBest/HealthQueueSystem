ALTER TABLE Appointments ADD COLUMN IF NOT EXISTS BookingFeeAmount DECIMAL(10,2) NULL;
CREATE TABLE IF NOT EXISTS ClinicFeeRequests (
 RequestID INT AUTO_INCREMENT PRIMARY KEY,
 ClinicID INT NOT NULL,
 RequestedBy INT NOT NULL,
 CurrentFee DECIMAL(10,2) NOT NULL,
 ProposedFee DECIMAL(10,2) NOT NULL,
 EffectiveDate DATE NOT NULL,
 Reason VARCHAR(1000) NOT NULL,
 Status ENUM('Pending','Approved','Declined') NOT NULL DEFAULT 'Pending',
 RequestedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 ReviewedBy INT NULL,
 ReviewedAt DATETIME NULL,
 UNIQUE KEY uq_fee_request_replay (ClinicID,RequestID),
 INDEX idx_clinic_fee_effective (ClinicID,Status,EffectiveDate),
 FOREIGN KEY (ClinicID) REFERENCES Clinic(ClinicID),
 FOREIGN KEY (RequestedBy) REFERENCES Users(UserID),
 FOREIGN KEY (ReviewedBy) REFERENCES Users(UserID)
);
-- Freeze pre-existing bookings before introducing fee changes. Paid wallet evidence wins.
UPDATE Appointments a JOIN Clinic c ON c.ClinicID=a.ClinicID
LEFT JOIN (SELECT RelatedAppointmentID,SUM(Amount) Paid FROM WalletTransactions WHERE Type='BookingPayment' GROUP BY RelatedAppointmentID) t ON t.RelatedAppointmentID=a.AppointmentID
SET a.BookingFeeAmount=COALESCE(t.Paid,c.BaseConsultationFee) WHERE a.BookingFeeAmount IS NULL;
