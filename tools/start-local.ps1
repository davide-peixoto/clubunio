param([ValidateRange(1024, 65535)][int]$Port = 8093)

$ErrorActionPreference = 'Stop'
$root = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$php = 'C:\xampp\php\php.exe'
if (-not (Test-Path -LiteralPath $php -PathType Leaf)) {
    throw 'PHP nicht gefunden: C:\xampp\php\php.exe'
}
if (Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue) {
    throw "Port $Port ist bereits belegt. Der bestehende Prozess bleibt unveraendert."
}
$arguments = @('-d', 'extension=pdo_pgsql', '-S', "127.0.0.1:$Port", '-t', 'public')
$process = Start-Process -FilePath $php -ArgumentList $arguments -WorkingDirectory $root -WindowStyle Hidden -PassThru `
    -RedirectStandardOutput (Join-Path $root 'storage\logs\server-out.log') `
    -RedirectStandardError (Join-Path $root 'storage\logs\server-error.log')
Write-Output "ClubUnio gestartet: http://127.0.0.1:$Port/login (PID $($process.Id))"
