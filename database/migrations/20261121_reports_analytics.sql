ALTER TABLE Queue ADD COLUMN IF NOT EXISTS FirstCalledAt DATETIME NULL;
CREATE TABLE IF NOT EXISTS AITranscriptEvaluations (
 EvaluationID INT AUTO_INCREMENT PRIMARY KEY,
 TranscriptVersionID INT NOT NULL UNIQUE,
 PhysicianID INT NOT NULL,
 ClinicID INT NOT NULL,
 Language VARCHAR(30) NOT NULL,
 ReferenceText MEDIUMTEXT NOT NULL,
 ReferenceHash CHAR(64) NOT NULL,
 HypothesisHash CHAR(64) NOT NULL,
 ReferenceWords INT NOT NULL,
 HypothesisWords INT NOT NULL,
 Substitutions INT NOT NULL,
 Deletions INT NOT NULL,
 Insertions INT NOT NULL,
 WordErrorRate DECIMAL(9,4) NOT NULL,
 EvaluatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_ai_evaluation_scope (ClinicID,PhysicianID,EvaluatedAt),
 FOREIGN KEY (TranscriptVersionID) REFERENCES AITranscriptVersions(TranscriptVersionID),
 FOREIGN KEY (PhysicianID) REFERENCES Users(UserID),
 FOREIGN KEY (ClinicID) REFERENCES Clinic(ClinicID)
) ENGINE=InnoDB;
