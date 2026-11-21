<?php
/** Uses the existing temporary-table rehearsal fixtures; never changes live records. */
if (PHP_SAPI !== 'cli') exit;
require __DIR__.'/test-workflow-fixes.php';
$cons=(int)$pdo->query('SELECT ConsultationID FROM Consultations WHERE AppointmentID=1')->fetchColumn();
$pdo->prepare("INSERT INTO AITranscriptVersions(ConsultationID,TranscriptType,TranscriptText) VALUES(?,'AI_Summary','Fixture reviewed summary')")->execute([$cons]);
$summary=(int)$pdo->lastInsertId();
$source=file_get_contents(__DIR__.'/../patient/consultation.php');
if (!preg_match('/\$summaryStmt = \$pdo->prepare\(\s*"(.*?)"\s*\);/s',$source,$match)) throw new LogicException('Patient summary query not found');
$patientQuery=$pdo->prepare($match[1]);
$patientQuery->execute([1]);workflowCheck(!$patientQuery->fetch(),'Patient cannot see an unapproved summary');
workflowReject(fn()=>publishCompletedConsultationSummary($pdo,1,11,$summary),'Another physician cannot release a summary');
publishCompletedConsultationSummary($pdo,1,10,$summary);
$patientQuery->execute([1]);workflowCheck($patientQuery->fetch()['TranscriptText']==='Fixture reviewed summary','Physician-reviewed historical summary appears in actual patient query');
workflowCheck($pdo->query("SELECT COUNT(*) FROM AuditLogs WHERE ActionExecuted='Approved completed visit AI summary'")->fetchColumn()>0,'Summary publication is audited');
$pdo->prepare("INSERT INTO AITranscriptVersions(ConsultationID,TranscriptType,TranscriptText) VALUES(?,'Original_STT','Fixture source')")->execute([$cons]);$input=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO AITranscriptVersions(ConsultationID,TranscriptType,TranscriptText,SourceTranscriptVersionID) VALUES(?,'AI_Summary','Fixture tracked summary',?)")->execute([$cons,$input]);$newSummary=(int)$pdo->lastInsertId();
workflowReject(fn()=>publishCompletedConsultationSummary($pdo,1,10,$summary),'Replaced summary cannot be approved');
publishCompletedConsultationSummary($pdo,1,10,$newSummary);
$patientQuery->execute([1]);workflowCheck($patientQuery->fetch()['TranscriptText']==='Fixture tracked summary','Tracked summary appears after review');
// Reproduce historical duplicate-save revisions without exposing patient text.
$pdo->prepare("INSERT INTO AITranscriptVersions(ConsultationID,TranscriptType,TranscriptText) VALUES(?,'Doctor_Corrected','Fixture source')")->execute([$cons]);
workflowCheck(consultationSummarySourceCurrent($pdo,$cons,$newSummary),'Identical source revision does not make the summary stale');
$version=(int)$pdo->query('SELECT VersionID FROM ConsultationVersions WHERE ConsultationID='.$cons.' ORDER BY RevisionNumber DESC LIMIT 1')->fetchColumn();
$finalize=function(bool $approve,bool $private,int $id) use($pdo,$cons,$version) {
    $pdo->beginTransaction();
    try {finalizeConsultationSummary($pdo,$cons,$version,10,$id,$approve,$private);$pdo->commit();}
    catch(Throwable $e) {$pdo->rollBack();throw $e;}
};
workflowReject(fn()=>$finalize(false,false,$newSummary),'Finalization cannot silently omit summary approval');
workflowReject(fn()=>$finalize(true,true,$newSummary),'Conflicting publication choices rejected');
workflowReject(fn()=>$finalize(true,false,$summary),'Finalization rejects an older summary ID');
$finalize(true,false,$newSummary);
$patientQuery->execute([1]);workflowCheck($patientQuery->fetch()['TranscriptText']==='Fixture tracked summary','Finalizing reviewed notes publishes summary in patient query despite identical source save');
$finalize(false,true,$newSummary);
$patientQuery->execute([1]);workflowCheck(!$patientQuery->fetch(),'Explicit keep-private choice withholds summary');
$finalize(true,false,$newSummary);
// Use a confirmed fixture for the real transcript-saving helper.
fixtureVisit($pdo,90,1,10,$today,null);$dedupCons=fixtureNotes($pdo,90,10);
$dedupSource=saveConsultationTranscript($pdo,$dedupCons,10,'Original_STT','Unchanged fixture');
$dedupSummary=saveGeneratedConsultationSummary($pdo,$dedupCons,10,$dedupSource,'Fixture summary');
$duplicate=saveConsultationTranscript($pdo,$dedupCons,10,'Doctor_Corrected','Unchanged fixture');
workflowCheck($duplicate===$dedupSource,'Saving an unchanged correction reuses the current source');
workflowCheck(consultationSummarySourceCurrent($pdo,$dedupCons,$dedupSummary),'Unchanged correction preserves summary eligibility');
saveConsultationTranscript($pdo,$dedupCons,10,'Doctor_Corrected','Changed fixture');
workflowCheck(!consultationSummarySourceCurrent($pdo,$dedupCons,$dedupSummary),'A real transcript change still invalidates the summary');
$pdo->prepare("INSERT INTO AITranscriptVersions(ConsultationID,TranscriptType,TranscriptText) VALUES(?,'Doctor_Corrected','Changed source')")->execute([$cons]);
workflowReject(fn()=>publishCompletedConsultationSummary($pdo,1,10,$newSummary),'Stale tracked summary cannot be released');
$pdo->prepare("INSERT INTO ConsultationVersions(ConsultationID,EditedByPhysicianID,RevisionNumber,ClinicalNotes,Status) VALUES(?,10,2,'Draft fixture','Draft')")->execute([$cons]);
workflowReject(fn()=>publishCompletedConsultationSummary($pdo,1,10,$newSummary),'Latest draft notes block completed summary release');
echo "Publication checks completed with temporary fixtures; no AI or messages sent.\n";
