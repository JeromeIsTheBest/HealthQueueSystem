ALTER TABLE Notifications ADD COLUMN IF NOT EXISTS AnnouncementID INT NULL;
ALTER TABLE PatientNotifications ADD COLUMN IF NOT EXISTS AnnouncementID INT NULL;
ALTER TABLE Notifications MODIFY Message TEXT NOT NULL;
ALTER TABLE PatientNotifications MODIFY Message TEXT NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uq_staff_announcement ON Notifications(ClinicID,AnnouncementID);
CREATE UNIQUE INDEX IF NOT EXISTS uq_patient_announcement ON PatientNotifications(PatientID,AnnouncementID);
