-- Additive relationships for previously standalone tables. Existing unknown tracking links remain NULL.
-- Prefer scripts/setup-standalone-links.php --apply for preflight and a private backup.
ALTER TABLE ClinicRegistrationInquiry ADD COLUMN IF NOT EXISTS ApprovedClinicID INT NULL DEFAULT NULL;
ALTER TABLE SignupEmailVerifications ADD COLUMN IF NOT EXISTS UserID INT NULL DEFAULT NULL;
ALTER TABLE RequestRateLimits ADD COLUMN IF NOT EXISTS UserID INT NULL DEFAULT NULL;
ALTER TABLE SmsOutbox MODIFY COLUMN PatientID INT NULL DEFAULT NULL, MODIFY COLUMN AppointmentID INT NULL DEFAULT NULL;
ALTER TABLE ClinicRegistrationInquiry ADD CONSTRAINT fk_inquiry_approved_clinic FOREIGN KEY(ApprovedClinicID) REFERENCES Clinic(ClinicID) ON DELETE SET NULL ON UPDATE RESTRICT;
ALTER TABLE SignupEmailVerifications ADD CONSTRAINT fk_signup_verification_user FOREIGN KEY(UserID) REFERENCES Users(UserID) ON DELETE SET NULL ON UPDATE RESTRICT;
ALTER TABLE RequestRateLimits ADD CONSTRAINT fk_rate_limit_user FOREIGN KEY(UserID) REFERENCES Users(UserID) ON DELETE SET NULL ON UPDATE RESTRICT;
ALTER TABLE SmsOutbox ADD CONSTRAINT fk_sms_patient FOREIGN KEY(PatientID) REFERENCES Users(UserID) ON DELETE SET NULL ON UPDATE RESTRICT;
ALTER TABLE SmsOutbox ADD CONSTRAINT fk_sms_appointment FOREIGN KEY(AppointmentID) REFERENCES Appointments(AppointmentID) ON DELETE SET NULL ON UPDATE RESTRICT;
