<?php

namespace App\Console\Commands;

use App\Services\SyncService;
use Illuminate\Console\Command;

class SyncCommand extends Command
{
    protected $signature = 'pos:sync {--pull : Solo traer datos del Manager} {--push : Solo enviar datos al Manager}';

    protected $description = 'Sincroniza el POS con el Manager';

    public function handle(SyncService $syncService): int
    {
        $this->info('🔄 Iniciando sincronización...');

        $pullOnly = $this->option('pull');
        $pushOnly = $this->option('push');

        if ($pullOnly) {
            return $this->syncPull($syncService);
        }

        if ($pushOnly) {
            return $this->syncPush($syncService);
        }

        return $this->syncBidireccional($syncService);
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
