<?php

namespace Tests;

use App\Models\Configuracion;
use App\Models\Producto;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Ningún test habla con un Manager de verdad: el que necesita respuestas usa
        // Http::fake(), y cualquier request no simulado hace fallar el test.
        Http::preventStrayRequests();
    }

    /** Caja instalada y configurada contra el Manager simulado. */
    protected function configurarCaja(): void
    {
        foreach ([
            'manager_api_url' => 'http://manager.fake/api/v1',
            'access_token' => 'token-de-prueba',
            'configurado' => '1',
            'punto_de_venta_id' => '4',
            'pdv_nombre' => 'caja 2',
            'sucursal_nombre' => 'Villa Bosh',
            'ultima_sincronizacion_productos' => now()->toIso8601String(),
            'ultima_sincronizacion_stock' => now()->toIso8601String(),
        ] as $clave => $valor) {
            Configuracion::set($clave, $valor);
        }
    }

    /** @param array<string, mixed> $datos */
    protected function producto(array $datos = []): Producto
    {
        static $id = 100;

        return Producto::forceCreate(array_merge([
            'id' => ++$id,
            'nombre' => "Producto {$id}",
            'codigo_interno' => "ART-{$id}",
            'precio' => 1000,
            'stock' => 10,
            'es_vendible' => true,
            'activo' => true,
        ], $datos));
    }
}
