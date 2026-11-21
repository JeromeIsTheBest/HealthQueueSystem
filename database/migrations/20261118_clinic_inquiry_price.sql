ALTER TABLE ClinicRegistrationInquiry ADD COLUMN IF NOT EXISTS BaseConsultationFee DECIMAL(10,2) NULL AFTER StaffCount;
