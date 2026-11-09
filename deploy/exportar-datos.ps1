# Prepara el paquete con tus datos para la primera instalación. El código va por Git; esto no.
# Se corre en Windows, desde la carpeta del proyecto, con MySQL de Laragon encendido:
#
#   powershell -ExecutionPolicy Bypass -File deploy\exportar-datos.ps1
#
# Trae tu base de datos, fotos, logos y APP_KEY. Queda en la carpeta "paquetes":
# no lo compartas y bórralo cuando ya esté instalado en el servidor.
$ErrorActionPreference = 'Stop'
$raiz = Split-Path $PSScriptRoot -Parent
$salida = Join-Path $raiz 'paquetes'
$fecha = Get-Date -Format 'yyyyMMdd-HHmm'
$tar = Join-Path $env:SystemRoot 'System32\tar.exe'

New-Item -ItemType Directory -Force $salida | Out-Null

$envLocal = @{}
Get-Content (Join-Path $raiz 'backend\.env') | ForEach-Object {
    if ($_ -match '^\s*([A-Z_]+)\s*=\s*"?(.*?)"?\s*$') { $envLocal[$Matches[1]] = $Matches[2] }
}
if ($envLocal['APP_KEY'] -notmatch '^base64:') { throw 'No encontré APP_KEY en backend\.env.' }

$mysqldump = Get-ChildItem 'C:\laragon\bin\mysql\*\bin\mysqldump.exe' | Select-Object -Last 1
if (-not $mysqldump) { throw 'No encontré mysqldump de Laragon.' }

$tmp = Join-Path $salida "tmp-datos-$fecha"
New-Item -ItemType Directory -Force $tmp | Out-Null
try {
    $env:MYSQL_PWD = $envLocal['DB_PASSWORD']
    & $mysqldump.FullName --host=$($envLocal['DB_HOST']) --port=$($envLocal['DB_PORT']) --user=$($envLocal['DB_USERNAME']) `
        --single-transaction --no-tablespaces --set-gtid-purged=OFF --default-character-set=utf8mb4 `
        "--result-file=$tmp\datos.sql" $envLocal['DB_DATABASE']
    if ($LASTEXITCODE -ne 0) { throw 'Falló mysqldump. ¿Está MySQL encendido en Laragon?' }

    [IO.File]::WriteAllText("$tmp\app_key.txt", $envLocal['APP_KEY'])

    foreach ($d in 'public', 'private') {
        $origen = Join-Path $raiz "backend\storage\app\$d"
        $destino = "$tmp\storage\$d"
        New-Item -ItemType Directory -Force $destino | Out-Null
        if (Test-Path $origen) { Copy-Item "$origen\*" $destino -Recurse -Force }
    }

    $datos = Join-Path $salida "repapos-datos-$fecha.tar.gz"
    & $tar --format=gnutar -czf $datos -C $tmp datos.sql app_key.txt storage
    if ($LASTEXITCODE -ne 0) { throw 'Falló tar.' }
    Write-Host "Listo: $datos" -ForegroundColor Green
} finally {
    Remove-Item Env:\MYSQL_PWD -ErrorAction SilentlyContinue
    Remove-Item $tmp -Recurse -Force -ErrorAction SilentlyContinue
}
