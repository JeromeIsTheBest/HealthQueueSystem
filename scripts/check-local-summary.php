<?php
require_once __DIR__ . '/../includes/ai-transcription.php';
try {
    $summary = summarizeTranscript('The patient reports a cough for two days. The physician discussed the cough. No medication, treatment instructions, or follow-up were discussed.');
    if (!str_contains($summary, 'Main concerns:') || !str_contains($summary, 'Follow-up:')) {
        throw new RuntimeException('Missing summary sections.');
    }
    echo "Local summary generation succeeded. Review model accuracy separately.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
