<#
    Compila la app de escritorio (NativePHP) del POS desde este mismo repo.

    La instalacion clasica y la de escritorio comparten todo el codigo. Lo unico propio del
    escritorio son sus dependencias (composer.escritorio.json: agrega nativephp/desktop, que
    pide PHP 8.4, mientras las cajas clasicas corren con PHP 8.2) y su .env (.env.escritorio).

    Nunca se compila en la carpeta del repo: ahi estan el .env, la base y la identidad de
    una caja real, y NativePHP mete en el ejecutable lo que encuentra. Se arma una copia
    limpia en ..\pos-build-escritorio y se compila ahi.

    Uso:
        .\compilar-escritorio.ps1 -Version 1.3.0
        .\compilar-escritorio.ps1 -Version 1.3.0 -Manager C:\MisLaravel\manager   (compila y publica)
        .\compilar-escritorio.ps1 -SoloPreparar      (arma la copia para probar con native:run)
#>
param(
    [string]$Version = '',
    [switch]$SoloPreparar,
    [switch]$SinTests,
    [string]$Manager = '',
    [string]$Destino = ''
)

$ErrorActionPreference = 'Stop'
$repo = $PSScriptRoot
if (-not $Destino) { $Destino = Join-Path (Split-Path $repo -Parent) 'pos-build-escritorio' }
$sinBom = New-Object System.Text.UTF8Encoding $false
$inicio = Get-Date

function Paso([string]$texto) { Write-Host ''; Write-Host "== $texto" -ForegroundColor Cyan }
function Falla([string]$mensaje) { Write-Host ''; Write-Host "ERROR: $mensaje" -ForegroundColor Red; exit 1 }
function Ejecutar([string]$descripcion, [scriptblock]$bloque) {
    # Los programas externos (npm, composer) escriben avisos en stderr; en PowerShell 5.1
    # eso con 'Stop' corta el script. Se juzgan por su codigo de salida.
    $ErrorActionPreference = 'Continue'
    & $bloque 2>&1 | ForEach-Object { Write-Host $_ }
    if ($LASTEXITCODE -ne 0) { Falla "$descripcion (codigo $LASTEXITCODE)" }
}

# --- .env de escritorio y version -------------------------------------------------------
$envEscritorio = Join-Path $repo '.env.escritorio'
if (-not (Test-Path $envEscritorio)) {
    $bytes = New-Object byte[] 32
    [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
    $plantilla = [IO.File]::ReadAllText((Join-Path $repo '.env.escritorio.example'))
    $plantilla = $plantilla -replace '(?m)^APP_KEY=.*$', ('APP_KEY=base64:' + [Convert]::ToBase64String($bytes))
    [IO.File]::WriteAllText($envEscritorio, $plantilla, $sinBom)
    Write-Host 'Se creo .env.escritorio con una APP_KEY nueva (no se versiona: guardala).' -ForegroundColor Yellow
}
$contenidoEnv = [IO.File]::ReadAllText($envEscritorio)
$versionActual = if ($contenidoEnv -match '(?m)^NATIVEPHP_APP_VERSION=(.+)$') { $Matches[1].Trim() } else { '0.0.0' }

if (-not $SoloPreparar) {
    if ($Version -notmatch '^\d+\.\d+\.\d+$') {
        Falla "Indica la version a compilar, por ejemplo: -Version 1.3.0 (la ultima fue $versionActual)."
    }
    if ([version]$Version -le [version]$versionActual) {
        Falla "La version $Version no es mayor que la ultima compilada ($versionActual). Las cajas solo corren las migraciones cuando la version sube."
    }
} else {
    $Version = $versionActual
}

# --- Herramientas -----------------------------------------------------------------------
Paso 'Verificando herramientas'
$herd = Join-Path $env:USERPROFILE '.config\herd\bin'
if (Test-Path $herd) { $env:PATH = "$herd;$env:PATH" }

$phpId = [int](& php -r 'echo PHP_VERSION_ID;')
if ($phpId -lt 80400) { Falla "Hace falta PHP 8.4 o mayor para compilar (el PATH tiene $phpId). Instala Laravel Herd." }
if ((& php -r "echo extension_loaded('zip') ? 1 : 0;") -ne '1') { Falla 'El PHP para compilar necesita la extension zip.' }
foreach ($herramienta in 'composer', 'npm', 'robocopy') {
    if (-not (Get-Command $herramienta -ErrorAction SilentlyContinue)) { Falla "No se encuentra $herramienta en el PATH." }
}
Write-Host "PHP $(& php -r 'echo PHP_VERSION;') | repo $repo | compilacion $Destino"

# --- Dependencias: el escritorio tiene que llevar lo mismo que la clasica + NativePHP ---
Paso 'Comparando dependencias de la clasica y del escritorio'
$clasica = (Get-Content (Join-Path $repo 'composer.json') -Raw | ConvertFrom-Json).require
$escritorio = (Get-Content (Join-Path $repo 'composer.escritorio.json') -Raw | ConvertFrom-Json).require
$propias = @('php', 'nativephp/desktop')
$diferencias = @()
foreach ($p in $clasica.PSObject.Properties) {
    if ($propias -contains $p.Name) { continue }
    $enEscritorio = $escritorio.PSObject.Properties[$p.Name]
    if (-not $enEscritorio -or $enEscritorio.Value -ne $p.Value) { $diferencias += "$($p.Name) $($p.Value)" }
}
foreach ($p in $escritorio.PSObject.Properties) {
    if ($propias -contains $p.Name) { continue }
    if (-not $clasica.PSObject.Properties[$p.Name]) { $diferencias += "$($p.Name) (solo en escritorio)" }
}
if ($diferencias.Count -gt 0) {
    Falla ("composer.json y composer.escritorio.json no piden lo mismo: " + ($diferencias -join ', ') + ". Agrega la dependencia en los dos y volve a compilar.")
}
Write-Host 'OK'

# --- Assets -----------------------------------------------------------------------------
Paso 'Compilando assets (npm run build)'
Push-Location $repo
try { Ejecutar 'Fallo npm run build' { & npm run build } } finally { Pop-Location }

# --- Copia limpia -----------------------------------------------------------------------
Paso "Armando la copia limpia en $Destino"
$excluirCarpetas = @(
    (Join-Path $repo '.git'), (Join-Path $repo 'vendor'), (Join-Path $repo 'storage'),
    (Join-Path $repo 'nativephp'), (Join-Path $repo 'dist'), 'node_modules'
)
# composer.* se excluyen porque en la copia se reemplazan por los de escritorio, y con
# /MIR lo excluido tampoco se borra del destino (asi se conservan vendor y node_modules).
$excluirArchivos = @(
    '.env', '.env.escritorio', '.pos-info', '*.sqlite', '*.sqlite-wal', '*.sqlite-shm', '*.sqlite-journal',
    '.phpunit.result.cache', 'composer.json', 'composer.lock', 'composer.escritorio.json', 'composer.escritorio.lock'
)
& robocopy $repo $Destino /MIR /IS /R:1 /W:1 /NFL /NDL /NJH /NJS /NP /XD @excluirCarpetas /XF @excluirArchivos | Out-Null
if ($LASTEXITCODE -ge 8) { Falla "robocopy fallo (codigo $LASTEXITCODE)." }

Copy-Item (Join-Path $repo 'composer.escritorio.json') (Join-Path $Destino 'composer.json') -Force
$lockRepo = Join-Path $repo 'composer.escritorio.lock'
$lockCopia = Join-Path $Destino 'composer.lock'
if (Test-Path $lockRepo) { Copy-Item $lockRepo $lockCopia -Force }

# Caches de la clasica (packages.php lista sus providers, sin NativePHP): se regeneran.
Get-ChildItem (Join-Path $Destino 'bootstrap\cache') -Filter '*.php' -ErrorAction SilentlyContinue | Remove-Item -Force
foreach ($carpeta in 'storage\app\public', 'storage\framework\cache\data', 'storage\framework\sessions', 'storage\framework\views', 'storage\framework\testing', 'storage\logs') {
    New-Item -ItemType Directory -Force -Path (Join-Path $Destino $carpeta) | Out-Null
}

$envCopia = $contenidoEnv -replace '(?m)^NATIVEPHP_APP_VERSION=.*$', "NATIVEPHP_APP_VERSION=$Version"
if ($envCopia -notmatch '(?m)^NATIVEPHP_APP_VERSION=') { $envCopia = $envCopia.TrimEnd() + "`nNATIVEPHP_APP_VERSION=$Version`n" }
[IO.File]::WriteAllText((Join-Path $Destino '.env'), $envCopia, $sinBom)

# --- Dependencias de escritorio ---------------------------------------------------------
Paso 'Instalando dependencias de escritorio (composer.escritorio.json)'
Push-Location $Destino
try {
    Ejecutar 'Fallo composer install' { & composer install --no-interaction --prefer-dist }
    if (-not (Test-Path $lockRepo) -or ((Get-FileHash $lockCopia).Hash -ne (Get-FileHash $lockRepo).Hash)) {
        Copy-Item $lockCopia $lockRepo -Force
        Write-Host 'Se actualizo composer.escritorio.lock en el repo: commitealo.' -ForegroundColor Yellow
    }

    if (-not $SinTests) {
        Paso 'Tests con NativePHP instalado'
        Ejecutar 'Fallaron los tests: no se compila' { & php vendor\bin\phpunit }
    }
} finally { Pop-Location }

if ($SoloPreparar) {
    Write-Host ''
    Write-Host "Copia lista en $Destino (version $Version)." -ForegroundColor Green
    Write-Host "Para probarla: cd `"$Destino`"; php artisan native:run"
    exit 0
}

# --- Compilacion ------------------------------------------------------------------------
Paso "Compilando la app de escritorio $Version (varios minutos)"
Push-Location $Destino
try {
    $ErrorActionPreference = 'Continue'
    & php artisan native:build win --no-interaction 2>&1 | ForEach-Object { Write-Host $_ }
    $codigoBuild = $LASTEXITCODE
    $ErrorActionPreference = 'Stop'
} finally { Pop-Location }

$compilada = Join-Path $Destino 'nativephp\electron\dist\win-unpacked'
$exe = Get-ChildItem $compilada -Filter '*.exe' -ErrorAction SilentlyContinue | Where-Object { $_.LastWriteTime -gt $inicio } | Select-Object -First 1
if (-not $exe) { Falla "native:build no genero la carpeta win-unpacked (codigo $codigoBuild)." }
if ($codigoBuild -ne 0) {
    Write-Host 'native:build termino con error, pero win-unpacked quedo generada: es el instalador NSIS, que en esta maquina falla. Se distribuye la carpeta.' -ForegroundColor Yellow
}

Paso 'Revisando lo que quedo dentro del ejecutable'
$app = Join-Path $compilada 'resources\build\app'
$prohibidos = Get-ChildItem $app -Recurse -Force -Include '*.sqlite', '*.sqlite-wal', '*.sqlite-shm', '.pos-info', '.env.escritorio' -ErrorAction SilentlyContinue
if ($prohibidos) { Falla ('Se colaron datos de una caja en la compilacion: ' + (($prohibidos | ForEach-Object FullName) -join ', ')) }
if ((Get-Content (Join-Path $app '.env') -Raw) -notmatch "(?m)^NATIVEPHP_APP_VERSION=$([regex]::Escape($Version))\s*$") {
    Falla "El .env compilado no tiene NATIVEPHP_APP_VERSION=$Version."
}
Write-Host 'OK'

# Recien ahora queda registrada como la ultima version compilada.
[IO.File]::WriteAllText($envEscritorio, ($contenidoEnv -replace '(?m)^NATIVEPHP_APP_VERSION=.*$', "NATIVEPHP_APP_VERSION=$Version"), $sinBom)

if ($Manager) {
    Paso 'Publicando en el Manager'
    Push-Location $Manager
    try { Ejecutar 'Fallo pos:publicar-escritorio' { & php artisan pos:publicar-escritorio $compilada } } finally { Pop-Location }
}

Write-Host ''
Write-Host "App de escritorio $Version compilada en:" -ForegroundColor Green
Write-Host "  $compilada"
if (-not $Manager) {
    Write-Host 'Para que las sucursales la descarguen, en el Manager:'
    Write-Host "  php artisan pos:publicar-escritorio `"$compilada`""
}
Write-Host "Sugerido: git tag escritorio-v$Version"
