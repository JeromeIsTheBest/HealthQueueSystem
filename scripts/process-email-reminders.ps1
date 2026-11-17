param([switch]$Check)
# Load only mail settings from local Apache configuration; never print credentials.
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$configFiles = @('C:\xampp\apache\conf\httpd.conf', 'C:\xampp\apache\conf\extra\httpd-xampp.conf')
foreach ($configFile in $configFiles) {
    if (-not (Test-Path -LiteralPath $configFile)) { continue }
    foreach ($configLine in Get-Content -LiteralPath $configFile) {
        if ($configLine -match '^\s*SetEnv\s+(HQ_SMTP_HOST|HQ_SMTP_PORT|HQ_SMTP_USERNAME|HQ_SMTP_PASSWORD|HQ_MAIL_FROM|HQ_MAIL_FROM_NAME)\s+(.+?)\s*$') {
            $mailName = $Matches[1]
            $mailValue = $Matches[2].Trim().Trim('"')
            [Environment]::SetEnvironmentVariable($mailName, $mailValue, 'Process')
        }
    }
}
$workerArguments = @((Join-Path $PSScriptRoot 'process-email-reminders.php'))
if ($Check) { $workerArguments += '--check' }
& 'C:\xampp\php\php.exe' @workerArguments
exit $LASTEXITCODE
