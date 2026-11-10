# Dot-source this file before invoking php, composer, or MySQL from this project.
$nestRoot = Split-Path -Parent $PSScriptRoot
$nestPhp = Join-Path $nestRoot '.local/php'
if (-not (Test-Path (Join-Path $nestPhp 'php.exe'))) {
    throw 'PHP local nao encontrado. Consulte README.md.'
}
$env:Path = "$nestPhp;$(Join-Path $nestRoot '.local/mysql-8.4.10-winx64/bin');$env:Path"
