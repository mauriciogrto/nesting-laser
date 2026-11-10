$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$workerPath = Join-Path $PSScriptRoot 'pc-worker.py'
$task = Get-ScheduledTask -TaskName 'NestLaserPcWorkerManual' -ErrorAction SilentlyContinue
if ($task -and $task.State -eq 'Running') {
    Stop-ScheduledTask -TaskName 'NestLaserPcWorkerManual'
}

# Scheduled-task termination may leave the Python child and Node engine alive.
$escapedPath = [regex]::Escape($workerPath)
Get-CimInstance Win32_Process |
    Where-Object { $_.Name -eq 'python.exe' -and $_.CommandLine -match $escapedPath } |
    ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }

$listener = Get-NetTCPConnection -LocalPort 31999 -State Listen -ErrorAction SilentlyContinue |
    Select-Object -First 1
if ($listener) {
    $engine = Get-CimInstance Win32_Process -Filter "ProcessId=$($listener.OwningProcess)"
    if ($engine.Name -eq 'node.exe' -and $engine.CommandLine -eq 'node server.js') {
        Stop-Process -Id $engine.ProcessId -Force
    }
}
