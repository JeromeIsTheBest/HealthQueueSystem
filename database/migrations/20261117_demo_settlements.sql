-- Capstone simulation only. These records never initiate bank transfers.
CREATE TABLE IF NOT EXISTS DemoSettlements (
 SettlementID INT AUTO_INCREMENT PRIMARY KEY,
 RequestKey VARCHAR(64) NOT NULL,
 Reference VARCHAR(40) NOT NULL,
 GrossAmount DECIMAL(12,2) NOT NULL,
 CreatedByUserID INT NOT NULL,
 CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_demo_settlement_request (RequestKey),
 UNIQUE KEY uq_demo_settlement_reference (Reference),
 CONSTRAINT fk_demo_settlement_admin FOREIGN KEY (CreatedByUserID) REFERENCES Users(UserID)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS DemoSettlementItems (
 SettlementID INT NOT NULL,
 PaymentID INT NOT NULL,
 PRIMARY KEY (SettlementID,PaymentID),
 UNIQUE KEY uq_demo_settled_payment (PaymentID),
 CONSTRAINT fk_demo_item_settlement FOREIGN KEY (SettlementID) REFERENCES DemoSettlements(SettlementID),
 CONSTRAINT fk_demo_item_payment FOREIGN KEY (PaymentID) REFERENCES AppointmentPayments(PaymentID)
) ENGINE=InnoDB;
