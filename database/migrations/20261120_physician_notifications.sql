CREATE TABLE IF NOT EXISTS PhysicianNotifications (
 NotificationID INT AUTO_INCREMENT PRIMARY KEY,
 PhysicianID INT NOT NULL,
 FeeRequestID INT NULL,
 Message TEXT NOT NULL,
 IsRead TINYINT NOT NULL DEFAULT 0,
 CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_physician_fee_notice (FeeRequestID),
 INDEX idx_physician_notice_unread (PhysicianID,IsRead,CreatedAt),
 FOREIGN KEY (PhysicianID) REFERENCES Users(UserID),
 FOREIGN KEY (FeeRequestID) REFERENCES ClinicFeeRequests(RequestID)
);
