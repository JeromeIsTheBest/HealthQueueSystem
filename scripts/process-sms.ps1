param(
    [switch]$Check,
    [ValidateRange(0, 55)][int]$WatchSeconds = 0,
    [ValidateRange(3, 60)][int]$PollSeconds = 5
)
# Read SMS credentials locally without exposing them in output or command arguments.
$ErrorActionPreference = 'Stop'
foreach ($smsConfigFile in @('C:\xampp\apache\conf\httpd.conf','C:\xampp\apache\conf\extra\httpd-xampp.conf')) {
    if (-not (Test-Path -LiteralPath $smsConfigFile)) { continue }
    foreach ($smsConfigLine in Get-Content -LiteralPath $smsConfigFile) {
        if ($smsConfigLine -match '^\s*SetEnv\s+(HQ_SMS_ENABLED|HQ_TEXTBEE_API_KEY|HQ_TEXTBEE_DEVICE_ID)\s+(.+?)\s*$') {
            [Environment]::SetEnvironmentVariable($Matches[1], $Matches[2].Trim().Trim('"'), 'Process')
        }
    }
}
$smsWorkerArguments = @((Join-Path $PSScriptRoot 'process-sms.php'))
if ($Check) { $smsWorkerArguments += '--check' }
# The scheduled task can poll inside its one-minute run; manual invocations stay one-shot.
# PHP retains the worker lock and outbox deduplication, including across overlapping launches.
$smsWatch = [System.Diagnostics.Stopwatch]::StartNew()
$smsExitCode = 0
do {
    & 'C:\xampp\php\php.exe' @smsWorkerArguments
    $smsExitCode = $LASTEXITCODE
    if ($Check -or $WatchSeconds -eq 0) { break }
    $smsRemainingMilliseconds = [int](($WatchSeconds * 1000) - $smsWatch.ElapsedMilliseconds)
    if ($smsRemainingMilliseconds -le 0) { break }
    Start-Sleep -Milliseconds ([Math]::Min($PollSeconds * 1000, $smsRemainingMilliseconds))
} while ($smsWatch.Elapsed.TotalSeconds -lt $WatchSeconds)
exit $smsExitCode
