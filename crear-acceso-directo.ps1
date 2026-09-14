param(
    [Parameter(Mandatory = $true)]
    [string]$Carpeta,

    # Nombre del POS. Si no se pasa, se lee de .pos-info.
    [string]$Nombre = '',

    # URL donde se sirve esta caja. Si no se pasa, se deduce de APP_URL del .env.
    [string]$Url = '',

    # Para cuando lo ejecuta el POS por una orden del Manager: sin nadie delante,
    # un Read-Host colgaria el proceso para siempre. Mejor fallar con un mensaje claro.
    [switch]$NoInteractivo
)

$ErrorActionPreference = 'Stop'

$Carpeta = (Resolve-Path $Carpeta).Path.TrimEnd('\')

if (-not $Nombre) {
    $info = Join-Path $Carpeta '.pos-info'
    if (Test-Path $info) { $Nombre = (Get-Content $info -TotalCount 1).Trim() }
}

if (-not $Nombre) {
    if ($NoInteractivo) {
        Write-Host 'ERROR: falta el nombre del POS y no hay .pos-info.' -ForegroundColor Red
        exit 1
    }
    $Nombre = Read-Host 'Nombre del POS (ej: Caja 1)'
}

if (-not $Url) {
    $linea = Select-String -Path (Join-Path $Carpeta '.env') -Pattern '^APP_URL=' -ErrorAction SilentlyContinue |
        Select-Object -First 1
    if ($linea) { $Url = $linea.Line -replace '^APP_URL=', '' -replace '"', '' }
}

# APP_URL suele quedar en http://localhost cuando el sitio en realidad se sirve por Herd.
# Mejor preguntar que crear un acceso directo que abre la pagina equivocada.
if (-not $Url -or $Url -match '^https?://localhost/?$') {
    if ($NoInteractivo) {
        Write-Host "ERROR: APP_URL no apunta a un sitio servido ($Url). Corregilo en el .env de esta caja." -ForegroundColor Red
        exit 1
    }
    Write-Host "APP_URL no apunta a un sitio servido ($Url)." -ForegroundColor Yellow
    $Url = Read-Host 'URL donde se abre esta caja (ej: http://pos.test)'
}

$Url = $Url.TrimEnd('/')

$chrome = @(
    "$env:ProgramFiles\Google\Chrome\Application\chrome.exe",
    "${env:ProgramFiles(x86)}\Google\Chrome\Application\chrome.exe",
    "$env:LOCALAPPDATA\Google\Chrome\Application\chrome.exe"
) | Where-Object { Test-Path $_ } | Select-Object -First 1

$escritorio = [Environment]::GetFolderPath('Desktop')
$destino = Join-Path $escritorio "POS - $Nombre.lnk"

$sh = New-Object -ComObject WScript.Shell
$lnk = $sh.CreateShortcut($destino)

if ($chrome) {
    # --app abre una ventana sin barra de direcciones ni pestañas: se ve como un programa.
    # Ademas es la unica forma de que el boton "Salir" del POS pueda cerrar la ventana:
    # Chrome solo permite window.close() en ventanas que no son pestañas normales.
    # Va solo: agregarle --new-window hace que no abra nada.
    $lnk.TargetPath = $chrome
    $lnk.Arguments = "--app=$Url"
    Write-Host "Modo aplicacion (Chrome)" -ForegroundColor Green
} else {
    # Sin Chrome se abre en el navegador por defecto. Funciona, pero con pestañas
    # y sin poder cerrarse solo.
    $lnk.TargetPath = $Url
    Write-Host "Chrome no encontrado: se usara el navegador por defecto." -ForegroundColor Yellow
}

$lnk.WorkingDirectory = $Carpeta
$lnk.Description = "Punto de venta $Nombre"
$lnk.IconLocation = "$env:SystemRoot\System32\shell32.dll,165"
$lnk.Save()

Write-Host ""
Write-Host "Acceso directo creado:" -ForegroundColor Green
Write-Host "  $destino"
Write-Host "  -> $Url"
