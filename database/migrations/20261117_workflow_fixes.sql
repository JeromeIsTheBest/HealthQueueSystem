ALTER TABLE AITranscriptVersions
 ADD COLUMN IF NOT EXISTS SourceTranscriptVersionID INT NULL,
 ADD INDEX IF NOT EXISTS idx_ai_source_version (SourceTranscriptVersionID),
 ADD FOREIGN KEY IF NOT EXISTS fk_ai_source_version (SourceTranscriptVersionID) REFERENCES AITranscriptVersions(TranscriptVersionID);
