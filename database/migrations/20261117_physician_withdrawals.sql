-- Simulated physician withdrawals only. No external transfers.
CREATE TABLE IF NOT EXISTS PhysicianWithdrawals (
 WithdrawalID INT AUTO_INCREMENT PRIMARY KEY,
 Reference VARCHAR(40) NOT NULL UNIQUE,
 RequestKey VARCHAR(64) NOT NULL UNIQUE,
 PhysicianID INT NOT NULL,
 Amount DECIMAL(12,2) NOT NULL,
 PayoutMethod VARCHAR(20) NOT NULL,
 AccountName VARCHAR(150) NOT NULL,
 AccountNumber VARCHAR(34) NOT NULL,
 BankName VARCHAR(100) NULL,
 Status VARCHAR(20) NOT NULL DEFAULT 'Pending',
 CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 ReviewedAt DATETIME NULL,
 ReviewedByUserID INT NULL,
 INDEX idx_physician_withdrawals_status (PhysicianID,Status),
 CONSTRAINT fk_withdrawal_physician FOREIGN KEY (PhysicianID) REFERENCES Users(UserID),
 CONSTRAINT fk_withdrawal_reviewer FOREIGN KEY (ReviewedByUserID) REFERENCES Users(UserID)
) ENGINE=InnoDB;
