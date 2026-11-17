param([switch]$Check)
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
& 'C:\xampp\php\php.exe' @smsWorkerArguments
exit $LASTEXITCODE
