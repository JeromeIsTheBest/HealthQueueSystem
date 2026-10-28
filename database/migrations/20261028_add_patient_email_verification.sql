-- Existing accounts are trusted as already verified; new registrations
-- explicitly create patients with EmailVerifiedAt = NULL.
ALTER TABLE Users ADD COLUMN EmailVerifiedAt DATETIME NULL AFTER Email;
UPDATE Users SET EmailVerifiedAt = COALESCE(CreatedAt, NOW()) WHERE EmailVerifiedAt IS NULL;

CREATE TABLE IF NOT EXISTS EmailVerificationTokens (
    VerificationID INT AUTO_INCREMENT PRIMARY KEY,
    UserID        INT NOT NULL,
    TokenHash     CHAR(64) NOT NULL UNIQUE,
    ExpiresAt     DATETIME NOT NULL,
    UsedAt        DATETIME NULL,
    CreatedAt     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_email_verification_user FOREIGN KEY (UserID) REFERENCES Users (UserID) ON DELETE CASCADE,
    INDEX idx_email_verification_user (UserID)
);
