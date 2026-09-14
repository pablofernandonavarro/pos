param(
    # Carpeta del POS a partir de la cual se arma el kit.
    [string]$Origen = '',

    # Dónde dejar el kit listo para copiar.
    [string]$Destino = ''
)

$ErrorActionPreference = 'Stop'

if (-not $Origen) { $Origen = $PSScriptRoot }
$Origen = (Resolve-Path $Origen).Path.TrimEnd('\')

if (-not $Destino) { $Destino = Join-Path (Split-Path $Origen -Parent) 'pos-kit' }

Write-Host "Origen : $Origen"
Write-Host "Destino: $Destino"
Write-Host ''

if (-not (Test-Path (Join-Path $Origen 'artisan'))) {
    Write-Host "ERROR: $Origen no parece un proyecto POS." -ForegroundColor Red
    exit 1
}

if (Test-Path $Destino) {
    Write-Host "La carpeta $Destino ya existe y se va a reemplazar." -ForegroundColor Yellow
    Remove-Item $Destino -Recurse -Force
}

Write-Host 'Copiando (puede tardar, son ~110 MB con las dependencias)...'

# Se copia con vendor y node_modules para que el kit funcione sin internet en la caja
# destino. Lo que NO viaja es todo lo que identifica a ESTA instalación.
$excluirCarpetas = @('.git', "$Origen\storage\logs", "$Origen\storage\app", "$Origen\storage\framework")
# .env.escritorio lleva la APP_KEY de la app de escritorio: tampoco viaja.
$excluirArchivos = @('.env', '.env.escritorio', '.pos-info', 'database.sqlite', 'iniciar-*.bat')

$argumentos = @($Origen, $Destino, '/E', '/NFL', '/NDL', '/NJH', '/NJS', '/NP', '/R:1', '/W:1')
$argumentos += '/XD'
$argumentos += $excluirCarpetas
$argumentos += '/XF'
$argumentos += $excluirArchivos

robocopy @argumentos | Out-Null

# robocopy usa códigos de salida < 8 para "todo bien"; 8 o más es error real.
if ($LASTEXITCODE -ge 8) {
    Write-Host "ERROR: falló la copia (robocopy $LASTEXITCODE)." -ForegroundColor Red
    exit 1
}

# Laravel necesita que estas carpetas existan, pero vacías.
foreach ($d in @(
    'storage\app', 'storage\framework\cache\data', 'storage\framework\sessions',
    'storage\framework\views', 'storage\logs', 'bootstrap\cache'
)) {
    New-Item -ItemType Directory -Force (Join-Path $Destino $d) | Out-Null
    New-Item -ItemType File -Force (Join-Path $Destino "$d\.gitignore") | Out-Null
}

# Red de seguridad: si alguno de estos quedó, el kit no sirve.
$prohibidos = @('.env', '.env.escritorio', '.pos-info', 'database\database.sqlite')
$encontrados = $prohibidos | Where-Object { Test-Path (Join-Path $Destino $_) }

if ($encontrados) {
    Write-Host ''
    Write-Host 'ERROR: el kit quedó con archivos de esta instalación:' -ForegroundColor Red
    $encontrados | ForEach-Object { Write-Host "  $_" -ForegroundColor Red }
    Write-Host 'No lo copies a otra máquina.' -ForegroundColor Red
    exit 1
}

$mb = [math]::Round((Get-ChildItem $Destino -Recurse -File | Measure-Object Length -Sum).Sum / 1MB)

Write-Host ''
Write-Host 'Kit listo.' -ForegroundColor Green
Write-Host "  $Destino  ($mb MB)"
Write-Host ''
Write-Host 'Verificado que NO lleva: .env, .pos-info, database.sqlite, logs ni respaldos.'
Write-Host ''
Write-Host 'En la maquina de la caja:'
Write-Host '  1. Copiar esta carpeta (pendrive o red)'
Write-Host '  2. Ejecutar instalar-pos.bat adentro'
Write-Host '  3. Poner el nombre del POS y pegar el codigo del Manager'
