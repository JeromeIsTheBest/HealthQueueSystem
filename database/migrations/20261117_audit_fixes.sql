ALTER TABLE WalletTransactions ADD COLUMN IF NOT EXISTS RequestKey CHAR(64) NULL;
ALTER TABLE WalletTransactions ADD UNIQUE INDEX IF NOT EXISTS uq_wallet_request_key (RequestKey);
CREATE TABLE IF NOT EXISTS RequestRateLimits (
 BucketKey CHAR(64) PRIMARY KEY,
 WindowStart DATETIME NOT NULL,
 Attempts INT NOT NULL DEFAULT 1,
 INDEX idx_rate_window (WindowStart)
) ENGINE=InnoDB;
ALTER TABLE AITranscriptVersions ADD COLUMN IF NOT EXISTS ApprovedAt DATETIME NULL;
ALTER TABLE AITranscriptVersions ADD COLUMN IF NOT EXISTS ApprovedByPhysicianID INT NULL;
ALTER TABLE AITranscriptVersions ADD COLUMN IF NOT EXISTS ApprovedVersionID INT NULL;

ALTER TABLE AITranscriptVersions ADD FOREIGN KEY IF NOT EXISTS fk_ai_approved_physician (ApprovedByPhysicianID) REFERENCES Users(UserID);
ALTER TABLE AITranscriptVersions ADD FOREIGN KEY IF NOT EXISTS fk_ai_approved_version (ApprovedVersionID) REFERENCES ConsultationVersions(VersionID);
