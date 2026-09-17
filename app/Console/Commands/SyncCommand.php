<?php

namespace App\Console\Commands;

use App\Services\EscritorioService;
use App\Services\FacturacionService;
use App\Services\RemitosEntrantesService;
use App\Services\SyncService;
use Illuminate\Console\Command;

class SyncCommand extends Command
{
    protected $signature = 'pos:sync {--pull : Solo traer datos del Manager} {--push : Solo enviar datos al Manager} {--stock : Solo traer stock (liviano, apto para correr seguido)} {--productos : Solo productos nuevos o modificados desde la última vez}';

    protected $description = 'Sincroniza el POS con el Manager';

    public function handle(SyncService $syncService): int
    {
        $stockOnly = $this->option('stock');
        $pullOnly = $this->option('pull');
        $pushOnly = $this->option('push');

        if ($stockOnly) {
            return $this->syncStock($syncService);
        }

        if ($this->option('productos')) {
            $resultado = $syncService->syncProductos();

            if (! $resultado['success']) {
                $this->error('❌ Error al traer productos: '.($resultado['error'] ?? 'desconocido'));

                return self::FAILURE;
            }

            $this->info("🏷️  Productos nuevos o modificados: {$resultado['cantidad']}");

            return self::SUCCESS;
        }

        $this->info('🔄 Iniciando sincronización...');

        if ($pullOnly) {
            return $this->syncPull($syncService);
        }

        if ($pushOnly) {
            return $this->syncPush($syncService);
        }

        return $this->syncBidireccional($syncService);
    }

    /**
     * Solo stock: un GET y un update por producto. A diferencia del pull completo,
     * no toca la tabla de precios (que syncPrecios trunca y reconstruye entera),
     * así que se puede correr cada pocos minutos sin costo real.
     */
    private function syncStock(SyncService $syncService): int
    {
        $resultado = $syncService->syncStock();

        // Los remitos en camino y las promociones viajan con el stock: misma frecuencia y
        // mismo proceso, y si fallan no afectan al stock (ni al revés).
        $this->syncRemitos();

        $promociones = $syncService->syncPromociones();
        $promociones['success']
            ? $this->info("💳 Promociones bancarias: {$promociones['cantidad']}")
            : $this->warn('⚠️  Promociones: '.($promociones['error'] ?? 'error desconocido'));

        $cajeros = $syncService->syncCajeros();
        $cajeros['success']
            ? $this->info("👤 Cajeros: {$cajeros['cantidad']}")
            : $this->warn('⚠️  Cajeros: '.($cajeros['error'] ?? 'error desconocido'));

        $clientes = $syncService->syncClientes();
        $clientes['success']
            ? $this->info("🧑 Clientes: {$clientes['cantidad']}")
            : $this->warn('⚠️  Clientes: '.($clientes['error'] ?? 'error desconocido'));

        $sucursales = $syncService->syncSucursales();
        $sucursales['success']
            ? $this->info("🏢 Sucursales: {$sucursales['cantidad']}")
            : $this->warn('⚠️  Sucursales: '.($sucursales['error'] ?? 'error desconocido'));

        $configRemitos = $syncService->syncConfiguracionRemitos();
        $configRemitos['success']
            ? $this->info('⚙️  Configuración de remitos sincronizada')
            : $this->warn('⚠️  Configuración de remitos: '.($configRemitos['error'] ?? 'error desconocido'));

        // Facturación: si sigue activa y el resultado de las facturas que quedaron pendientes.
        $facturacion = $syncService->syncFacturacion();

        if (! $facturacion['success']) {
            $this->warn('⚠️  Facturación: '.($facturacion['error'] ?? 'error desconocido'));
        } else {
            $pendientes = app(FacturacionService::class)->actualizarPendientes();
            $pendientes['success']
                ? $this->info('🧾 Comprobantes actualizados: '.$pendientes['actualizados'])
                : $this->warn('⚠️  Comprobantes: '.($pendientes['error'] ?? 'error desconocido'));
        }

        if ($resultado['success']) {
            $this->info("✅ Stock actualizado: {$resultado['cantidad']} producto(s)");

            return self::SUCCESS;
        }

        $this->error('❌ Error al traer stock: '.($resultado['error'] ?? 'desconocido'));

        return self::FAILURE;
    }

    private function syncRemitos(): void
    {
        $remitos = app(RemitosEntrantesService::class)->sincronizar();

        if (! $remitos['success']) {
            $this->warn('⚠️  Remitos: '.($remitos['error'] ?? 'error desconocido'));

            return;
        }

        $this->info("📦 Remitos por recibir: {$remitos['cantidad']}");

        if ($remitos['nuevos'] !== []) {
            app(EscritorioService::class)->notificarRemitosNuevos($remitos['nuevos']);
        }
    }

    private function syncPull(SyncService $syncService): int
    {
        $this->info('⬇️  Sincronizando desde Manager...');

        $resultado = $syncService->syncInicial();

        if ($resultado['success']) {
            $this->info('✅ Sincronización completada:');
            $this->line("   • Productos: {$resultado['resultados']['productos']['cantidad']}");
            $this->line("   • Precios: {$resultado['resultados']['precios']['cantidad']}");
            $this->line("   • Stock: {$resultado['resultados']['stock']['cantidad']}");

            return self::SUCCESS;
        }

        $this->error('❌ Error en sincronización: '.$resultado['error']);

        return self::FAILURE;
    }

    private function syncPush(SyncService $syncService): int
    {
        $this->info('⬆️  Enviando datos al Manager...');

        // Enviar ventas
        $ventasResult = $syncService->pushVentas();
        if ($ventasResult['success']) {
            $this->info("✅ Ventas: {$ventasResult['cantidad']} sincronizadas");
        } else {
            $this->warn("⚠️  Ventas: {$ventasResult['error']}");
        }

        // Enviar movimientos
        $movResult = $syncService->pushMovimientos();
        if ($movResult['success']) {
            $this->info("✅ Movimientos: {$movResult['cantidad']} sincronizados");
        } else {
            $this->warn("⚠️  Movimientos: {$movResult['error']}");
        }

        $devResult = $syncService->pushDevoluciones();
        if ($devResult['success']) {
            $this->info("✅ Devoluciones: {$devResult['cantidad']} sincronizadas");
        } else {
            $this->warn("⚠️  Devoluciones: {$devResult['error']}");
        }

        // Enviar turnos de caja (el abierto se actualiza; los cerrados llegan con su Z)
        $turnosResult = $syncService->pushTurnos();
        if ($turnosResult['success']) {
            $this->info("✅ Cierres de caja: {$turnosResult['cantidad']} sincronizados");
        } else {
            $this->warn("⚠️  Turnos de caja: {$turnosResult['error']}");
        }

        return self::SUCCESS;
    }

    private function syncBidireccional(SyncService $syncService): int
    {
        $this->info('🔄 Sincronización bidireccional...');

        $resultado = $syncService->syncBidireccional();

        if ($resultado['success']) {
            $this->info('✅ Sincronización completada exitosamente');

            return self::SUCCESS;
        }

        $this->error('❌ Error en sincronización');
        $this->error(json_encode($resultado, JSON_PRETTY_PRINT));

        return self::FAILURE;
    }
}
