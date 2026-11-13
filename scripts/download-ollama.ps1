$ErrorActionPreference = 'Stop'
$downloadRoot = Split-Path $PSScriptRoot -Parent
$downloadTotal = 1675999728L
$downloadChunk = [long][math]::Ceiling($downloadTotal / 8)
$downloadJobs = @()
for ($downloadIndex = 0; $downloadIndex -lt 8; $downloadIndex++) {
    $downloadJobs += Start-Job -ArgumentList $downloadRoot,$downloadIndex,$downloadChunk,$downloadTotal -ScriptBlock {
        param($root,$index,$chunk,$total)
        $ErrorActionPreference = 'Stop'
        $part = Join-Path $root "storage/ollama-installer-$index.part"
        $tail = "$part.tail"
        $headers = "$part.headers"
        $start = $index * $chunk
        $end = [math]::Min($total-1, ($index+1)*$chunk-1)
        $required = $end-$start+1
        for ($attempt=0; $attempt -lt 180; $attempt++) {
            $saved = if (Test-Path -LiteralPath $part) { (Get-Item -LiteralPath $part).Length } else { 0 }
            if ($saved -eq $required) { return }
            if ($saved -gt $required) { throw 'Unexpected segment length' }
            $offset = $start + $saved
            $ErrorActionPreference = 'Continue'
            & curl.exe -L --fail --silent --show-error --connect-timeout 20 --max-time 120 --range "$offset-$end" -D $headers -o $tail 'https://github.com/ollama/ollama/releases/download/v0.40.14/OllamaSetup.exe' 2>$null
            $curlExit = $LASTEXITCODE
            $ErrorActionPreference = 'Stop'
            if ((Test-Path -LiteralPath $tail) -and (Test-Path -LiteralPath $headers)) {
                $ranges = [regex]::Matches([IO.File]::ReadAllText($headers), '(?im)^content-range:\s*bytes\s+(\d+)-(\d+)/(\d+)')
                $bytes = (Get-Item -LiteralPath $tail).Length
                if ($ranges.Count -gt 0 -and [long]$ranges[$ranges.Count-1].Groups[1].Value -eq $offset -and $bytes -le ($required-$saved)) {
                    $output = [IO.File]::Open($part, [IO.FileMode]::Append)
                    $inputFile = [IO.File]::OpenRead($tail)
                    try { $inputFile.CopyTo($output) } finally { $inputFile.Dispose(); $output.Dispose() }
                    [IO.File]::WriteAllBytes($tail, [byte[]]@())
                }
            }
            Start-Sleep -Seconds 2
        }
        throw 'Segment download could not finish; partial files have been retained.'
    }
}
foreach ($downloadJob in $downloadJobs) {
    Wait-Job $downloadJob | Out-Null
    if ($downloadJob.State -ne 'Completed') { Receive-Job $downloadJob; throw 'Installer download failed; partial files retained.' }
    Receive-Job $downloadJob
}
$downloadPath = Join-Path $downloadRoot 'storage/OllamaSetup.exe'
$downloadOutput = [IO.File]::Create($downloadPath)
try {
    for ($downloadIndex=0; $downloadIndex -lt 8; $downloadIndex++) {
        $downloadInput = [IO.File]::OpenRead((Join-Path $downloadRoot "storage/ollama-installer-$downloadIndex.part"))
        try { $downloadInput.CopyTo($downloadOutput) } finally { $downloadInput.Dispose() }
    }
} finally { $downloadOutput.Dispose() }
if ((Get-Item -LiteralPath $downloadPath).Length -ne $downloadTotal) { throw 'Unexpected installer size' }
if ((Get-AuthenticodeSignature $downloadPath).Status -ne 'Valid') { throw 'Installer signature validation failed' }
$installProcess = Start-Process -FilePath $downloadPath -ArgumentList '/VERYSILENT','/SUPPRESSMSGBOXES','/NORESTART' -WindowStyle Hidden -PassThru
$installProcess.WaitForExit()
if ($installProcess.ExitCode -ne 0) { throw 'Ollama installer failed' }
Write-Output 'Ollama installed successfully.'
