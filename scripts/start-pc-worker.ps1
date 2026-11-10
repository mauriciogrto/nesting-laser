$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$secretFile = Join-Path $projectRoot '.local/pc-worker-secret.dpapi'
$python = Join-Path $projectRoot '.local/venv/Scripts/python.exe'
$worker = Join-Path $PSScriptRoot 'pc-worker.py'
$logDir = Join-Path $projectRoot '.local/logs'
New-Item -ItemType Directory -Path $logDir -Force | Out-Null

$securePassword = Get-Content -LiteralPath $secretFile | ConvertTo-SecureString
$credential = [pscredential]::new($env:NEST_SFTP_USER, $securePassword)
$env:NEST_FTP_PASS = $credential.GetNetworkCredential().Password
Set-Location -LiteralPath $projectRoot
$logFile = Join-Path $logDir 'pc-worker.log'

function Stop-StaleWorker {
    $escapedPath = [regex]::Escape($worker)
    Get-CimInstance Win32_Process |
        Where-Object { $_.Name -eq 'python.exe' -and $_.CommandLine -match $escapedPath } |
        ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
}

while ($true) {
    # Task Scheduler can stop PowerShell without terminating its Python child.
    # Claim this manual session before starting another queue consumer.
    Stop-StaleWorker
    try {
        $ErrorActionPreference = 'Continue'
        try {
            & $python -u $worker *>> $logFile
            $workerExitCode = $LASTEXITCODE
        } finally {
            $ErrorActionPreference = 'Stop'
        }
    } catch {
        $workerExitCode = 1
        Add-Content -LiteralPath $logFile -Value ("Worker launcher error: " + $_.Exception.Message)
    }
    Add-Content -LiteralPath $logFile -Value ("Worker stopped with code " + $workerExitCode + "; retrying in five seconds.")
    Start-Sleep -Seconds 5
}
