<?php
/** Local Whisper transcription. Summary generation is configured separately. */
function transcribeAudio(string $audioFilePath, string $language = 'auto'): string
{
    return transcribeAudioResult($audioFilePath, $language)['text'];
}

/** Returns the text and actual model/language so callers can show diagnostics. */
function transcribeAudioResult(string $audioFilePath, string $language = 'auto'): array
{
    if (!in_array($language, ['auto', 'tl', 'en'], true)) {
        throw new InvalidArgumentException('Choose Automatic, Tagalog, or English.');
    }
    $root = dirname(__DIR__);
    $python = getenv('HQ_WHISPER_PYTHON') ?: $root . '/.venv-whisper/Scripts/python.exe';
    if (!is_file($python) || !is_readable($audioFilePath)) {
        throw new RuntimeException('Whisper or the consultation recording is unavailable.');
    }
    if (!function_exists('proc_open')) {
        throw new RuntimeException('PHP process execution is disabled.');
    }
    $output = tempnam(sys_get_temp_dir(), 'hq-stt-');
    $errors = tempnam(sys_get_temp_dir(), 'hq-err-');
    if ($output === false || $errors === false) {
        if ($output !== false) unlink($output);
        if ($errors !== false) unlink($errors);
        throw new RuntimeException('Could not create transcription working files.');
    }
    try {
        set_time_limit(660);
        $process = proc_open([$python, $root . '/scripts/transcribe.py', $audioFilePath, '--language', $language],
            [0 => ['pipe', 'r'], 1 => ['file', $output, 'w'], 2 => ['file', $errors, 'w']], $pipes, $root,
            null, ['bypass_shell' => true, 'create_no_window' => true]);
        if (!is_resource($process)) throw new RuntimeException('Could not start Whisper.');
        fclose($pipes[0]);
        $deadline = microtime(true) + 600;
        do {
            $status = proc_get_status($process);
            if (!$status['running']) break;
            if (microtime(true) > $deadline) {
                proc_terminate($process);
                proc_close($process);
                throw new RuntimeException('Transcription timed out. Try a shorter recording.');
            }
            usleep(200000);
        } while (true);
        proc_close($process);
        $result = json_decode(file_get_contents($output), true);
        if (empty($result['ok']) || !is_string($result['text'] ?? null) || trim($result['text']) === '') {
            error_log('Whisper failed: ' . ($result['error'] ?? 'Invalid response; check Python dependencies.'));
            throw new RuntimeException('Whisper could not transcribe this recording. Check FFmpeg and the local Whisper installation.');
        }
        return [
            'text' => trim($result['text']),
            'model' => (string) ($result['model'] ?? 'unknown'),
            'language' => (string) ($result['language'] ?? 'unknown'),
        ];
    } finally {
        unlink($output);
        unlink($errors);
    }
}

/** Summary generation is handled by the local Ollama service. */
function summarizeTranscript(string $transcriptText): string
{
    require_once __DIR__ . '/ai-summary.php';
    return generateLocalSummary($transcriptText);
}
