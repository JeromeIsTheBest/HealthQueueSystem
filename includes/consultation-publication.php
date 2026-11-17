<?php
/** Caller owns the notes transaction. No AI text is published without explicit approval. */
function approveConsultationSummary(PDO $pdo,int $consultationId,int $versionId,int $physicianId,int $summaryId):void
{
 if(!$pdo->inTransaction())throw new LogicException('Summary approval requires the notes transaction.');
 $pdo->prepare("UPDATE AITranscriptVersions SET ApprovedAt=NULL,ApprovedByPhysicianID=NULL,ApprovedVersionID=NULL WHERE ConsultationID=? AND TranscriptType='AI_Summary'")->execute([$consultationId]);
 if($summaryId<=0)return;
 $stmt=$pdo->prepare("SELECT TranscriptVersionID FROM AITranscriptVersions WHERE ConsultationID=? ORDER BY TranscriptVersionID DESC LIMIT 1 FOR UPDATE");$stmt->execute([$consultationId]);
 if((int)$stmt->fetchColumn()!==$summaryId)throw new RuntimeException('The AI summary changed. Review the latest summary before approving it.');
 $stmt=$pdo->prepare("UPDATE AITranscriptVersions SET ApprovedAt=NOW(),ApprovedByPhysicianID=?,ApprovedVersionID=? WHERE TranscriptVersionID=? AND ConsultationID=? AND TranscriptType='AI_Summary'");$stmt->execute([$physicianId,$versionId,$summaryId,$consultationId]);
 if(!$stmt->rowCount())throw new RuntimeException('Review a valid AI summary before approving publication.');
}
function recordingExtension(string $path):?string
{
 return ['video/webm'=>'webm','audio/webm'=>'webm','audio/ogg'=>'ogg','application/ogg'=>'ogg','audio/mp4'=>'m4a','video/mp4'=>'m4a'][mime_content_type($path)]??null;
}
