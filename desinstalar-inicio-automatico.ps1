param(
    [Parameter(Mandatory = $true)]
    [string]$Carpeta
)

$ErrorActionPreference = 'Stop'

$Carpeta = (Resolve-Path $Carpeta).Path.TrimEnd('\')

# Se buscan las tareas por la carpeta que aparece en su descripcion, para no tocar
# las de otro POS instalado en la misma maquina.
$tareas = Get-ScheduledTask -ErrorAction SilentlyContinue | Where-Object {
    $_.TaskName -like 'POS Sync *' -and $_.Description -like "*$Carpeta*"
}

if (-not $tareas) {
    Write-Host "No hay tareas registradas para $Carpeta" -ForegroundColor Yellow
    exit 0
}

foreach ($t in $tareas) {
    Stop-ScheduledTask -TaskName $t.TaskName -ErrorAction SilentlyContinue
    Unregister-ScheduledTask -TaskName $t.TaskName -Confirm:$false
    Write-Host "  Eliminada: $($t.TaskName)" -ForegroundColor Green
}

Write-Host ""
Write-Host "Listo. Para volver a activarlo: instalar-inicio-automatico.bat"
