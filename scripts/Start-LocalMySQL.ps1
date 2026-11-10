$nestRoot = Split-Path -Parent $PSScriptRoot
$mysqlExe = Join-Path $nestRoot '.local/mysql-8.4.10-winx64/bin/mysqld.exe'
$config = Join-Path $nestRoot '.local/mysql.ini'
if (-not (Test-Path $mysqlExe) -or -not (Test-Path $config)) {
    throw 'MySQL local nao encontrado. Consulte README.md.'
}
if (Get-NetTCPConnection -LocalPort 3307 -State Listen -ErrorAction SilentlyContinue) {
    Write-Output 'MySQL local ja esta em execucao na porta 3307.'
    return
}
Start-Process -FilePath $mysqlExe -ArgumentList ('--defaults-file="'+$config+'"') -WindowStyle Hidden
Write-Output 'MySQL local iniciado na porta 3307.'
