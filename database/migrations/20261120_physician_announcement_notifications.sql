ALTER TABLE PhysicianNotifications ADD COLUMN IF NOT EXISTS AnnouncementID INT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uq_physician_announcement ON PhysicianNotifications(PhysicianID,AnnouncementID);
