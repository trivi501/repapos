# Prepara lo que se sube al VPS. Se corre en Windows, desde la carpeta del proyecto:
#
#   powershell -ExecutionPolicy Bypass -File deploy\empaquetar.ps1            solo el código
#   powershell -ExecutionPolicy Bypass -File deploy\empaquetar.ps1 -ConDatos  además, tu base de datos, fotos y APP_KEY
#
# Los archivos quedan en la carpeta "paquetes". El paquete de datos trae información de tus clientes:
# no lo compartas y bórralo cuando ya esté en el servidor.
param([switch]$ConDatos)

$ErrorActionPreference = 'Stop'
$raiz = Split-Path $PSScriptRoot -Parent
$salida = Join-Path $raiz 'paquetes'
$fecha = Get-Date -Format 'yyyyMMdd-HHmm'
$tar = Join-Path $env:SystemRoot 'System32\tar.exe'

New-Item -ItemType Directory -Force $salida | Out-Null
# Para que no terminen en un repositorio si algún día se usa git.
[IO.File]::WriteAllText((Join-Path $salida '.gitignore'), "*`n")

# Un script con finales de línea de Windows no corre en Linux.
Get-ChildItem (Join-Path $raiz 'deploy') -Filter *.sh | ForEach-Object {
    if ([IO.File]::ReadAllText($_.FullName).Contains("`r")) { throw "$($_.Name) tiene finales de línea CRLF." }
}

Write-Host '==> Compilando Angular' -ForegroundColor Cyan
Push-Location (Join-Path $raiz 'frontend')
try {
    npm.cmd run build -- --configuration production
    if ($LASTEXITCODE -ne 0) { throw 'Falló la compilación de Angular.' }
} finally { Pop-Location }

Write-Host '==> Empaquetando el código' -ForegroundColor Cyan
$codigo = Join-Path $salida "repapos-$fecha.tar.gz"
Push-Location $raiz
try {
    & $tar --format=gnutar -czf $codigo `
        --exclude=backend/vendor `
        --exclude=backend/.git `
        --exclude=backend/.phpunit.result.cache `
        --exclude=backend/node_modules `
        --exclude=backend/storage `
        --exclude=backend/tests `
        --exclude=backend/.env `
        --exclude=backend/.env.backup `
        --exclude=backend/.phpunit.cache `
        --exclude=backend/public/storage `
        --exclude=backend/bootstrap/cache `
        --exclude=backend/database/database.sqlite `
        backend frontend/dist/frontend/browser deploy
    if ($LASTEXITCODE -ne 0) { throw 'Falló tar.' }
} finally { Pop-Location }
Write-Host "    $codigo"

if ($ConDatos) {
    Write-Host '==> Empaquetando tus datos' -ForegroundColor Cyan
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
        Write-Host "    $datos"
    } finally {
        Remove-Item Env:\MYSQL_PWD -ErrorAction SilentlyContinue
        Remove-Item $tmp -Recurse -Force -ErrorAction SilentlyContinue
    }
}

Write-Host "`nListo. Sigue los pasos de deploy\INSTALAR-VPS.md" -ForegroundColor Green
