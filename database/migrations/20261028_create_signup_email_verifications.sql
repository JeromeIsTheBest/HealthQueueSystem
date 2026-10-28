CREATE TABLE IF NOT EXISTS SignupEmailVerifications (
    SignupVerificationID INT AUTO_INCREMENT PRIMARY KEY,
    Email               VARCHAR(100) NOT NULL,
    TokenHash           CHAR(64) NOT NULL UNIQUE,
    ExpiresAt            DATETIME NOT NULL,
    VerifiedAt           DATETIME NULL,
    UsedAt               DATETIME NULL,
    CreatedAt            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_signup_verification_email (Email)
);
