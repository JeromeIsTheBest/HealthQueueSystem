<?php
/** A source remains current across duplicate saves only when its text is byte-for-byte identical. */
function consultationSummarySourceCurrent(PDO $pdo, int $consultationId, int $summaryId): bool
{
    $s=$pdo->prepare("SELECT src.TranscriptText FROM AITranscriptVersions summary JOIN AITranscriptVersions src ON src.TranscriptVersionID=summary.SourceTranscriptVersionID AND src.ConsultationID=summary.ConsultationID AND src.TranscriptType IN ('Original_STT','Doctor_Corrected') WHERE summary.ConsultationID=? AND summary.TranscriptVersionID=? AND summary.TranscriptType='AI_Summary'");
    $s->execute([$consultationId,$summaryId]); $source=$s->fetchColumn();
    $s=$pdo->prepare("SELECT TranscriptText FROM AITranscriptVersions WHERE ConsultationID=? AND TranscriptType IN ('Original_STT','Doctor_Corrected') ORDER BY TranscriptVersionID DESC LIMIT 1");
    $s->execute([$consultationId]); $latest=$s->fetchColumn();
    return $source!==false && $latest!==false && hash_equals((string)$source,(string)$latest);
}

/** Finalization must explicitly publish a reviewed summary or explicitly keep it private. */
function finalizeConsultationSummary(PDO $pdo,int $consultationId,int $versionId,int $physicianId,int $summaryId,bool $approved,bool $keepPrivate):void
{
    $s=$pdo->prepare("SELECT TranscriptVersionID FROM AITranscriptVersions WHERE ConsultationID=? AND TranscriptType='AI_Summary' ORDER BY TranscriptVersionID DESC LIMIT 1");
    $s->execute([$consultationId]); $latest=(int)$s->fetchColumn();
    if ($approved && $keepPrivate) throw new RuntimeException('Choose either sharing the reviewed summary or keeping it private.');
    if ($latest && !$approved && !$keepPrivate) throw new RuntimeException('Review and approve the AI summary in the finalization dialog, or explicitly choose to keep it private.');
    if ($approved && (!$summaryId || $summaryId!==$latest)) throw new RuntimeException('Review the latest AI summary before finalizing.');
    approveConsultationSummary($pdo,$consultationId,$versionId,$physicianId,$approved?$summaryId:0);
}

/** Caller owns the notes transaction. No AI text is published without explicit approval. */
function approveConsultationSummary(PDO $pdo,int $consultationId,int $versionId,int $physicianId,int $summaryId):void
{
 if(!$pdo->inTransaction())throw new LogicException('Summary approval requires the notes transaction.');
 $pdo->prepare("UPDATE AITranscriptVersions SET ApprovedAt=NULL,ApprovedByPhysicianID=NULL,ApprovedVersionID=NULL WHERE ConsultationID=? AND TranscriptType='AI_Summary'")->execute([$consultationId]);
 if($summaryId<=0)return;
 $stmt=$pdo->prepare("SELECT TranscriptVersionID FROM AITranscriptVersions WHERE ConsultationID=? AND TranscriptType='AI_Summary' ORDER BY TranscriptVersionID DESC LIMIT 1 FOR UPDATE");$stmt->execute([$consultationId]);
 if((int)$stmt->fetchColumn()!==$summaryId)throw new RuntimeException('The AI summary changed. Review the latest summary before approving it.');
 if(!consultationSummarySourceCurrent($pdo,$consultationId,$summaryId))throw new RuntimeException('The transcript changed. Regenerate and review the summary before approving it.');
 $stmt=$pdo->prepare("UPDATE AITranscriptVersions SET ApprovedAt=NOW(),ApprovedByPhysicianID=?,ApprovedVersionID=? WHERE TranscriptVersionID=? AND ConsultationID=? AND TranscriptType='AI_Summary'");$stmt->execute([$physicianId,$versionId,$summaryId,$consultationId]);
 if(!$stmt->rowCount())throw new RuntimeException('Review a valid AI summary before approving publication.');
}
/** Serialize clinical AI writes with completion and notes; no model call under locks. */
function lockConsultationForAi(PDO $pdo,int $consultationId,int $doctorId):void
{
 $s=$pdo->prepare('SELECT AppointmentID FROM Consultations WHERE ConsultationID=? AND DoctorID=?');$s->execute([$consultationId,$doctorId]);$appointmentId=$s->fetchColumn();
 if(!$appointmentId)throw new RuntimeException('Consultation unavailable.');
 $s=$pdo->prepare('SELECT Status FROM Appointments WHERE AppointmentID=? AND PhysicianID=? FOR UPDATE');$s->execute([$appointmentId,$doctorId]);
 if($s->fetchColumn()!=='Confirmed')throw new RuntimeException('This consultation can no longer be edited.');
 $pdo->prepare('SELECT ConsultationID FROM Consultations WHERE ConsultationID=? FOR UPDATE')->execute([$consultationId]);
}
function saveConsultationTranscript(PDO $pdo,int $consultationId,int $doctorId,string $type,string $text):int
{
 if(!in_array($type,['Original_STT','Doctor_Corrected'],true)||trim($text)==='')throw new RuntimeException('A valid transcript is required.');
 $pdo->beginTransaction();
 try{
  lockConsultationForAi($pdo,$consultationId,$doctorId);
  if ($type==='Doctor_Corrected') {
   $same=$pdo->prepare("SELECT TranscriptVersionID,TranscriptText FROM AITranscriptVersions WHERE ConsultationID=? AND TranscriptType IN ('Original_STT','Doctor_Corrected') ORDER BY TranscriptVersionID DESC LIMIT 1");
   $same->execute([$consultationId]);$previous=$same->fetch();
   if ($previous && hash_equals((string)$previous['TranscriptText'],$text)) {$pdo->commit();return (int)$previous['TranscriptVersionID'];}
  }
  $pdo->prepare('INSERT INTO AITranscriptVersions(ConsultationID,TranscriptType,TranscriptText) VALUES(?,?,?)')->execute([$consultationId,$type,$text]);$id=(int)$pdo->lastInsertId();
  $pdo->prepare("UPDATE AITranscriptVersions SET ApprovedAt=NULL,ApprovedByPhysicianID=NULL,ApprovedVersionID=NULL WHERE ConsultationID=? AND TranscriptType='AI_Summary'")->execute([$consultationId]);
  $pdo->commit();return $id;
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function saveGeneratedConsultationSummary(PDO $pdo,int $consultationId,int $doctorId,int $sourceId,string $text):int
{
 $pdo->beginTransaction();
 try{
  lockConsultationForAi($pdo,$consultationId,$doctorId);
  $s=$pdo->prepare("SELECT TranscriptVersionID FROM AITranscriptVersions WHERE ConsultationID=? AND TranscriptType IN ('Original_STT','Doctor_Corrected') ORDER BY TranscriptVersionID DESC LIMIT 1 FOR UPDATE");$s->execute([$consultationId]);
  if(!$sourceId||(int)$s->fetchColumn()!==$sourceId)throw new RuntimeException('The transcript changed during generation. Generate a new summary from the latest correction.');
  $pdo->prepare("INSERT INTO AITranscriptVersions(ConsultationID,TranscriptType,TranscriptText,SourceTranscriptVersionID) VALUES(?,'AI_Summary',?,?)")->execute([$consultationId,$text,$sourceId]);$id=(int)$pdo->lastInsertId();$pdo->commit();return $id;
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function recordingExtension(string $path):?string
{
 return ['video/webm'=>'webm','audio/webm'=>'webm','audio/ogg'=>'ogg','application/ogg'=>'ogg','audio/mp4'=>'m4a','video/mp4'=>'m4a'][mime_content_type($path)]??null;
}

/** Release an existing completed-visit summary after explicit review by its treating physician. */
function publishCompletedConsultationSummary(PDO $pdo, int $appointmentId, int $physicianId, int $summaryId): void
{
    $pdo->beginTransaction();
    try {
        $s = $pdo->prepare("SELECT Status FROM Appointments WHERE AppointmentID=? AND PhysicianID=? FOR UPDATE");
        $s->execute([$appointmentId, $physicianId]);
        if ($s->fetchColumn() !== 'Completed') throw new RuntimeException('This completed consultation is unavailable.');
        $s = $pdo->prepare('SELECT ConsultationID FROM Consultations WHERE AppointmentID=? AND DoctorID=? FOR UPDATE');
        $s->execute([$appointmentId, $physicianId]);
        $consultationId = (int) $s->fetchColumn();
        if (!$consultationId) throw new RuntimeException('Consultation unavailable.');
        $s = $pdo->prepare('SELECT VersionID,Status FROM ConsultationVersions WHERE ConsultationID=? ORDER BY RevisionNumber DESC,VersionID DESC LIMIT 1 FOR UPDATE');
        $s->execute([$consultationId]); $version = $s->fetch();
        if (!$version || $version['Status'] !== 'Finalized') throw new RuntimeException('The latest consultation notes must be finalized.');
        $s = $pdo->prepare("SELECT TranscriptVersionID,SourceTranscriptVersionID,TranscriptText FROM AITranscriptVersions WHERE ConsultationID=? AND TranscriptType='AI_Summary' ORDER BY TranscriptVersionID DESC LIMIT 1 FOR UPDATE");
        $s->execute([$consultationId]); $summary = $s->fetch();
        if (!$summary || (int)$summary['TranscriptVersionID'] !== $summaryId || trim($summary['TranscriptText']) === '' || str_contains($summary['TranscriptText'], '[PLACEHOLDER')) throw new RuntimeException('Review the latest saved AI summary before publishing it.');
        // Historical summaries have no source metadata. They require explicit review against
        // the finalized notes; do not invent or backfill a transcript source for them.
        if ($summary['SourceTranscriptVersionID'] !== null) {
            if (!consultationSummarySourceCurrent($pdo,$consultationId,$summaryId)) throw new RuntimeException('The summary is outdated and cannot be published.');
        }
        $pdo->prepare("UPDATE AITranscriptVersions SET ApprovedAt=NULL,ApprovedByPhysicianID=NULL,ApprovedVersionID=NULL WHERE ConsultationID=? AND TranscriptType='AI_Summary'")->execute([$consultationId]);
        $pdo->prepare('UPDATE AITranscriptVersions SET ApprovedAt=NOW(),ApprovedByPhysicianID=?,ApprovedVersionID=? WHERE TranscriptVersionID=? AND ConsultationID=?')->execute([$physicianId,$version['VersionID'],$summaryId,$consultationId]);
        logActivity($pdo,$physicianId,null,'Approved completed visit AI summary',"Appointment #{$appointmentId}; summary #{$summaryId}; finalized notes #{$version['VersionID']}");
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
