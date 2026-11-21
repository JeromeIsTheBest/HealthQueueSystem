ALTER TABLE Users ADD COLUMN IF NOT EXISTS PhysicianSpecialty VARCHAR(100) NULL;
-- One financial owner per clinic. Clinical assignments remain unchanged.
CREATE TABLE IF NOT EXISTS PhysicianAccountOwners (
 ClinicID INT PRIMARY KEY,
 MainPhysicianID INT NOT NULL UNIQUE,
 CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (ClinicID) REFERENCES Clinic(ClinicID),
 FOREIGN KEY (MainPhysicianID) REFERENCES Users(UserID)
);
INSERT IGNORE INTO PhysicianAccountOwners (ClinicID,MainPhysicianID)
 SELECT u.ClinicID,MIN(u.UserID) FROM Users u JOIN Roles r ON r.RoleID=u.RoleID
 WHERE r.RoleName='Physician' AND u.ClinicID IS NOT NULL AND u.DeletedAt IS NULL GROUP BY u.ClinicID;
ALTER TABLE AppointmentPayments ADD COLUMN IF NOT EXISTS FinancialOwnerUserID INT NULL,
 ADD INDEX IF NOT EXISTS idx_payment_financial_owner (FinancialOwnerUserID);
ALTER TABLE PhysicianWithdrawals ADD COLUMN IF NOT EXISTS FinancialOwnerUserID INT NULL,
 ADD INDEX IF NOT EXISTS idx_withdrawal_financial_owner (FinancialOwnerUserID);
-- Preserve the performing physician and original requesting physician; snapshot pooled ownership.
UPDATE AppointmentPayments p JOIN Appointments a ON a.AppointmentID=p.AppointmentID
 LEFT JOIN PhysicianAccountOwners o ON o.ClinicID=a.ClinicID
 SET p.FinancialOwnerUserID=COALESCE(o.MainPhysicianID,a.PhysicianID) WHERE p.FinancialOwnerUserID IS NULL;
UPDATE PhysicianWithdrawals w JOIN Users u ON u.UserID=w.PhysicianID
 LEFT JOIN PhysicianAccountOwners o ON o.ClinicID=u.ClinicID
 SET w.FinancialOwnerUserID=COALESCE(o.MainPhysicianID,w.PhysicianID) WHERE w.FinancialOwnerUserID IS NULL;
ALTER TABLE AppointmentPayments ADD FOREIGN KEY IF NOT EXISTS fk_payment_financial_owner (FinancialOwnerUserID) REFERENCES Users(UserID);
ALTER TABLE PhysicianWithdrawals ADD FOREIGN KEY IF NOT EXISTS fk_withdrawal_financial_owner (FinancialOwnerUserID) REFERENCES Users(UserID);
