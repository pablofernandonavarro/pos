<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CheckPrecios extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check:precios';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verificar precios y listas';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('=== BASE DE DATOS LOCAL ===');
        $this->info('Listas de Precios:');
        $listas = \App\Models\ListaPrecio::all();
        foreach ($listas as $lista) {
            $this->line("ID: {$lista->id} | Nombre: {$lista->nombre} | Factor: {$lista->factor} | Default: " . ($lista->es_default ? 'SÍ' : 'NO'));
        }

        $this->newLine();
        $this->info('Producto ZAP001:');
        $producto = \App\Models\Producto::where('codigo_interno', 'ZAP001')->first();
        if ($producto) {
            $this->line("Precio base: {$producto->precio}");
            $this->line("Precio efectivo: {$producto->getPrecioEfectivo()}");
        } else {
            $this->error('Producto no encontrado');
        }

        $this->newLine();
        $this->info('=== API DEL MANAGER ===');
        $apiService = app(\App\Services\ManagerApiService::class);

        // Precios
        $response = $apiService->syncPrecios();
        if ($response['success']) {
            $this->info('Listas devueltas por el API:');
            foreach ($response['listas'] as $lista) {
                $this->line(json_encode($lista, JSON_PRETTY_PRINT));
            }
        } else {
            $this->error('Error al consultar API: ' . ($response['error'] ?? 'Desconocido'));
        }

        // Stock
        $this->newLine();
        $this->info('Stock desde API:');
        $stockResponse = $apiService->syncStock();
        if ($stockResponse['success']) {
            $this->line("Total items en stock: " . count($stockResponse['data']));
            // Buscar ZAP001 por product_id
            $producto = \App\Models\Producto::where('codigo_interno', 'ZAP001')->first();
            if ($producto) {
                $zap001Stock = collect($stockResponse['data'])->firstWhere('product_id', $producto->id);
                if ($zap001Stock) {
                    $this->line("Stock de ZAP001 (ID: {$producto->id}): {$zap001Stock['cantidad']} unidades");
                } else {
                    $this->warn("ZAP001 (ID: {$producto->id}) no tiene stock en esta sucursal");
                }
            }
        } else {
            $this->error('Error al consultar stock: ' . ($stockResponse['error'] ?? 'Desconocido'));
        }
    }
}
