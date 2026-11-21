param(
    [switch]$Check,
    [ValidateRange(0,55)][int]$WatchSeconds = 0,
    [ValidateRange(3,60)][int]$PollSeconds = 5
)
$ErrorActionPreference = 'Stop'
$graceArguments = @((Join-Path $PSScriptRoot 'process-queue-grace.php'))
if ($Check) { $graceArguments += '--check' }
$graceWatch = [System.Diagnostics.Stopwatch]::StartNew()
$graceExitCode = 0
do {
    & 'C:\xampp\php\php.exe' @graceArguments
    $graceExitCode = $LASTEXITCODE
    if ($Check -or $WatchSeconds -eq 0) { break }
    $graceRemaining = [int](($WatchSeconds * 1000) - $graceWatch.ElapsedMilliseconds)
    if ($graceRemaining -le 0) { break }
    Start-Sleep -Milliseconds ([Math]::Min($PollSeconds * 1000,$graceRemaining))
} while ($graceWatch.Elapsed.TotalSeconds -lt $WatchSeconds)
exit $graceExitCode
