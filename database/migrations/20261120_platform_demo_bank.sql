CREATE TABLE IF NOT EXISTS PlatformDemoBankTransfers (
 TransferID INT AUTO_INCREMENT PRIMARY KEY,
 PaymentID INT NOT NULL,
 Reference VARCHAR(50) NOT NULL,
 Amount DECIMAL(12,2) NOT NULL,
 BankLabel VARCHAR(100) NOT NULL DEFAULT 'HealthQueue Demo Bank',
 AccountLastFour CHAR(4) NOT NULL DEFAULT '0000',
 Status VARCHAR(30) NOT NULL DEFAULT 'CompletedSimulated',
 CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_platform_bank_payment (PaymentID),
 UNIQUE KEY uq_platform_bank_reference (Reference),
 FOREIGN KEY (PaymentID) REFERENCES AppointmentPayments(PaymentID)
) ENGINE=InnoDB;
