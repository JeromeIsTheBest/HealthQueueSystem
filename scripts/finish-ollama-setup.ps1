$ErrorActionPreference = 'Stop'
$setupRoot = Split-Path $PSScriptRoot -Parent
$setupStatus = Join-Path $setupRoot 'storage/ollama-setup-status.txt'
$setupExe = Join-Path $env:LOCALAPPDATA 'Programs/Ollama/ollama.exe'
try {
    'Waiting for the official Ollama installer to finish.' | Set-Content $setupStatus
    $setupDeadline = (Get-Date).AddHours(6)
    while (!(Test-Path -LiteralPath $setupExe)) {
        if ((Get-Date) -gt $setupDeadline) { throw 'Ollama installation did not finish. Check the installer download.' }
        Start-Sleep -Seconds 5
    }
    $setupReady = $false
    try { $null = Invoke-RestMethod http://127.0.0.1:11434/api/tags -TimeoutSec 3; $setupReady = $true } catch { }
    if (!$setupReady) {
        $env:OLLAMA_HOST = '127.0.0.1:11434'
        Start-Process -FilePath $setupExe -ArgumentList 'serve' -WindowStyle Hidden -RedirectStandardOutput (Join-Path $setupRoot 'storage/ollama-server.log') -RedirectStandardError (Join-Path $setupRoot 'storage/ollama-server-error.log') | Out-Null
        for ($setupAttempt = 0; $setupAttempt -lt 30; $setupAttempt++) {
            try { $null = Invoke-RestMethod http://127.0.0.1:11434/api/tags -TimeoutSec 2; $setupReady = $true; break } catch { Start-Sleep -Seconds 2 }
        }
    }
    if (!$setupReady) { throw 'Ollama could not start on the local port.' }
    'Downloading qwen2.5:1.5b for local summaries. This can take several minutes.' | Set-Content $setupStatus
    $setupPull = Invoke-RestMethod http://127.0.0.1:11434/api/pull -Method Post -ContentType 'application/json' -Body '{"model":"qwen2.5:1.5b","stream":false}' -TimeoutSec 3600
    if ($setupPull.status -ne 'success') { throw 'Model download did not complete.' }
    'Model installed. Checking PHP summary generation with a fictional transcript.' | Set-Content $setupStatus
    $setupPhp = 'C:/xampp/php/php.exe'
    $setupTest = Join-Path $PSScriptRoot 'check-local-summary.php'
    & $setupPhp $setupTest
    if ($LASTEXITCODE -ne 0) { throw 'PHP summary generation check failed.' }
    'Ready: Ollama and qwen2.5:1.5b installed; PHP summary generation check passed.' | Set-Content $setupStatus
} catch {
    ('Setup needs attention: ' + $_.Exception.Message) | Set-Content $setupStatus
    exit 1
}
