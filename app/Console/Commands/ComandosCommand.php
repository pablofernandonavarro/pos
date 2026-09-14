<?php

namespace App\Console\Commands;

use App\Models\Configuracion;
use App\Models\Precio;
use App\Models\Producto;
use App\Services\ManagerApiService;
use App\Services\SyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class ComandosCommand extends Command
{
    protected $signature = 'pos:comandos';

    protected $description = 'Ejecuta las órdenes que el Manager dejó para esta caja';

    public function handle(ManagerApiService $managerApi, SyncService $syncService): int
    {
        if (! Configuracion::isConfigured()) {
            return self::SUCCESS;
        }

        $respuesta = $managerApi->obtenerComandos();

        if (! $respuesta['success']) {
            // Sin conexión no es un fallo: se reintenta al minuto siguiente.
            return self::SUCCESS;
        }

        $comandos = $respuesta['data'];

        if ($comandos === []) {
            return self::SUCCESS;
        }

        foreach ($comandos as $comando) {
            $this->info("Ejecutando: {$comando['comando']}");

            try {
                $resultado = $this->ejecutar($comando['comando'], $syncService);
                $managerApi->reportarComando($comando['id'], true, $resultado);
                $this->info("  ✅ {$resultado}");
            } catch (\Throwable $e) {
                Log::error('Comando del Manager falló', [
                    'comando' => $comando['comando'],
                    'error' => $e->getMessage(),
                ]);

                $managerApi->reportarComando($comando['id'], false, $e->getMessage());
                $this->error("  ❌ {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Traduce el identificador recibido a una acción concreta.
     *
     * El match es la frontera de seguridad: lo que llega del Manager es una etiqueta de
     * una lista cerrada, nunca algo que se ejecute como shell. Un valor desconocido falla
     * en vez de intentar interpretarlo.
     */
    private function ejecutar(string $comando, SyncService $syncService): string
    {
        return match ($comando) {
            'resincronizar' => $this->resincronizar($syncService),
            'resincronizar_stock' => $this->resincronizarStock($syncService),
            'rearmar_catalogo' => $this->rearmarCatalogo($syncService),
            'reenviar_pendientes' => $this->reenviarPendientes($syncService),
            'limpiar_cache' => $this->limpiarCache(),
            'recrear_acceso_directo' => $this->recrearAccesoDirecto(),
            'actualizar' => $this->actualizar(),
            default => throw new \RuntimeException("Orden desconocida: {$comando}"),
        };
    }

    private function resincronizar(SyncService $syncService): string
    {
        $r = $syncService->syncInicial();

        if (! $r['success']) {
            throw new \RuntimeException($r['error'] ?? 'Falló la sincronización');
        }

        return "Productos: {$r['resultados']['productos']['cantidad']}, "
            ."precios: {$r['resultados']['precios']['cantidad']}, "
            ."stock: {$r['resultados']['stock']['cantidad']}";
    }

    private function resincronizarStock(SyncService $syncService): string
    {
        $r = $syncService->syncStock();

        if (! $r['success']) {
            throw new \RuntimeException($r['error'] ?? 'Falló la actualización de stock');
        }

        return "Stock actualizado en {$r['cantidad']} producto(s)";
    }

    /**
     * Para cuando el catálogo local quedó inconsistente: se borra y se baja entero.
     * Las ventas no se tocan, solo el catálogo, que es reconstruible desde el Manager.
     */
    private function rearmarCatalogo(SyncService $syncService): string
    {
        DB::transaction(function () {
            Precio::query()->delete();
            Producto::query()->delete();

            // Sin esto syncProductos() haría delta sync y no traería nada.
            Configuracion::set('ultima_sincronizacion_productos', null);
        });

        return 'Catálogo borrado. '.$this->resincronizar($syncService);
    }

    private function reenviarPendientes(SyncService $syncService): string
    {
        $ventas = $syncService->pushVentas();
        $movimientos = $syncService->pushMovimientos();

        if (! $ventas['success'] || ! $movimientos['success']) {
            throw new \RuntimeException($ventas['error'] ?? $movimientos['error'] ?? 'Falló el envío');
        }

        return "Ventas enviadas: {$ventas['cantidad']}, movimientos: {$movimientos['cantidad']}";
    }

    private function limpiarCache(): string
    {
        Artisan::call('optimize:clear');

        return 'Cachés limpiadas';
    }

    /**
     * Baja y aplica la última versión del código.
     *
     * Se corre en un proceso aparte a propósito: esta orden reemplaza archivos del propio
     * POS, incluido este mismo comando. Hacerlo dentro del proceso que ya tiene las clases
     * viejas cargadas en memoria es pedir un comportamiento impredecible.
     */
    private function actualizar(): string
    {
        $proceso = new Process([PHP_BINARY, 'artisan', 'pos:actualizar'], base_path());
        $proceso->setTimeout(600);
        $proceso->run();

        $salida = trim($proceso->getOutput().$proceso->getErrorOutput());

        if (! $proceso->isSuccessful()) {
            throw new \RuntimeException(mb_substr($salida, -500) ?: 'Falló la actualización');
        }

        // Solo interesa la última línea útil, no todo el log del proceso.
        $lineas = array_values(array_filter(explode("\n", $salida), fn ($l) => trim($l) !== ''));

        return trim(end($lineas) ?: 'Actualización aplicada');
    }

    /**
     * Vuelve a crear el ícono del escritorio, para cuando alguien lo borró sin querer.
     *
     * Es el único comando que ejecuta algo fuera de PHP, así que vale aclarar por qué no
     * abre la puerta a ejecución remota: la ruta del script es fija y está dentro de esta
     * instalación, y los argumentos van en un array (Process no pasa por el shell, no hay
     * concatenación). Del Manager solo llega la etiqueta 'recrear_acceso_directo'.
     */
    private function recrearAccesoDirecto(): string
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            throw new \RuntimeException('Solo disponible en Windows');
        }

        $script = base_path('crear-acceso-directo.ps1');

        if (! file_exists($script)) {
            throw new \RuntimeException('Falta crear-acceso-directo.ps1 en esta instalación');
        }

        $proceso = new Process([
            'powershell', '-NoProfile', '-ExecutionPolicy', 'Bypass',
            '-File', $script,
            '-Carpeta', base_path(),
            '-Nombre', (string) Configuracion::get('pdv_nombre', ''),
            '-Url', (string) config('app.url'),
            '-NoInteractivo',
        ]);

        $proceso->setTimeout(60);
        $proceso->run();

        if (! $proceso->isSuccessful()) {
            throw new \RuntimeException(trim($proceso->getErrorOutput() ?: $proceso->getOutput()) ?: 'Falló la creación del acceso directo');
        }

        $salida = trim($proceso->getOutput());

        // El script sale con código 0 pero imprime ERROR cuando no puede resolver la URL.
        if (str_contains($salida, 'ERROR:')) {
            throw new \RuntimeException($salida);
        }

        return 'Acceso directo recreado en el escritorio';
    }
}
