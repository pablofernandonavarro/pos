param(
    [Parameter(Mandatory = $true)]
    [string]$Carpeta,

    # Nombre del POS (ej: "Caja 1"). Se usa como sufijo del nombre de las tareas para
    # que convivan varias instalaciones en la misma maquina sin pisarse.
    [string]$Nombre = ''
)

$ErrorActionPreference = 'Stop'

$Carpeta = (Resolve-Path $Carpeta).Path.TrimEnd('\')
$lanzador = Join-Path $Carpeta 'ejecutar-oculto.vbs'

if (-not (Test-Path $lanzador)) {
    Write-Host "ERROR: no se encontro ejecutar-oculto.vbs en $Carpeta" -ForegroundColor Red
    exit 1
}

$php = (Get-Command php -ErrorAction SilentlyContinue).Source
if (-not $php) {
    Write-Host "ERROR: no se encuentra php en el PATH." -ForegroundColor Red
    exit 1
}

# Si no se paso nombre, intentar leerlo del archivo que deja instalar-pos.bat
if (-not $Nombre) {
    $infoPath = Join-Path $Carpeta '.pos-info'
    if (Test-Path $infoPath) {
        $Nombre = (Get-Content $infoPath -TotalCount 1).Trim()
    }
}

$sufijo = if ($Nombre) { " - $Nombre" } else { '' }
$tareaWorker = "POS Sync Worker$sufijo"
$tareaScheduler = "POS Sync Scheduler$sufijo"

Write-Host "Carpeta del POS : $Carpeta"
Write-Host "PHP             : $php"
if ($Nombre) { Write-Host "Nombre del POS  : $Nombre" }
Write-Host ""

$usuario = "$env:USERDOMAIN\$env:USERNAME"

# Dos disparadores por tarea:
#  - al iniciar sesion, para que arranque con Windows
#  - cada 5 minutos, que junto a IgnoreNew actua como auto-reparacion: si el proceso
#    murio se vuelve a levantar solo, y si sigue vivo el disparo se ignora.
function Registrar-Tarea {
    param([string]$Tarea, [string]$ComandoArtisan, [string]$Descripcion)

    $accion = New-ScheduledTaskAction `
        -Execute 'wscript.exe' `
        -Argument "`"$lanzador`" $ComandoArtisan" `
        -WorkingDirectory $Carpeta

    $alIniciarSesion = New-ScheduledTaskTrigger -AtLogOn -User $usuario
    $cadaCincoMin = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(2) `
        -RepetitionInterval (New-TimeSpan -Minutes 5)

    $opciones = New-ScheduledTaskSettingsSet `
        -MultipleInstances IgnoreNew `
        -AllowStartIfOnBatteries `
        -DontStopIfGoingOnBatteries `
        -StartWhenAvailable `
        -ExecutionTimeLimit ([TimeSpan]::Zero)

    Register-ScheduledTask `
        -TaskName $Tarea `
        -Description $Descripcion `
        -Action $accion `
        -Trigger @($alIniciarSesion, $cadaCincoMin) `
        -Settings $opciones `
        -Force | Out-Null

    Write-Host "  OK  $Tarea" -ForegroundColor Green
}

Registrar-Tarea -Tarea $tareaWorker `
    -ComandoArtisan 'queue:work --tries=3 --sleep=1' `
    -Descripcion "POS ($Carpeta): envia al Manager cada venta apenas se cierra."

Registrar-Tarea -Tarea $tareaScheduler `
    -ComandoArtisan 'schedule:work' `
    -Descripcion "POS ($Carpeta): trae el stock cada minuto y reintenta envios pendientes."

Write-Host ""
Write-Host "Arrancando las tareas ahora..." -ForegroundColor Cyan

# Detener solo las instancias lanzadas por estas tareas. No se matan procesos php
# sueltos a mano: no hay forma confiable de saber a que instalacion pertenecen.
Stop-ScheduledTask -TaskName $tareaWorker -ErrorAction SilentlyContinue
Stop-ScheduledTask -TaskName $tareaScheduler -ErrorAction SilentlyContinue

Start-ScheduledTask -TaskName $tareaWorker
Start-ScheduledTask -TaskName $tareaScheduler

Start-Sleep -Seconds 4

$activos = Get-CimInstance Win32_Process -Filter "Name='php.exe'" |
    Where-Object { $_.CommandLine -like '*queue:work*' -or $_.CommandLine -like '*schedule:work*' }

Write-Host ""
if ($activos) {
    Write-Host "Procesos corriendo:" -ForegroundColor Green
    foreach ($p in $activos) {
        $tipo = if ($p.CommandLine -like '*queue:work*') { 'Worker   ' } else { 'Scheduler' }
        Write-Host "  $tipo PID $($p.ProcessId)"
    }
    Write-Host ""
    Write-Host "Listo. Arranca solo cada vez que se inicie sesion en Windows." -ForegroundColor Green
} else {
    Write-Host "ATENCION: las tareas quedaron registradas pero no se ven procesos php." -ForegroundColor Yellow
    Write-Host "Revisalas en el Programador de tareas (taskschd.msc)." -ForegroundColor Yellow
}

Write-Host ""
Write-Host "Para revertir: desinstalar-inicio-automatico.bat"
