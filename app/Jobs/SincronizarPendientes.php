<?php

namespace App\Jobs;

use App\Services\SyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Envía al Manager lo que haya pendiente. Se despacha al cerrar una venta para que
 * llegue en segundos, pero nunca dentro del flujo de la venta: la caja no puede quedar
 * esperando a la red (ManagerApiService usa timeout(30)->retry(3), hasta ~90s).
 *
 * Es único para que varias ventas seguidas no encolen trabajo redundante: un solo push
 * ya envía todo lo pendiente. El scheduler de cada 5 minutos queda como red de seguridad
 * para cuando el worker está caído o el job agotó sus reintentos.
 */
class SincronizarPendientes implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** El lock se suelta rápido para no tragarse una venta hecha durante el envío. */
    public int $uniqueFor = 30;

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [10, 30];
    }

    public function handle(SyncService $sync): void
    {
        $ventas = $sync->pushVentas();
        $movimientos = $sync->pushMovimientos();
        // Después de las ventas: devoluciones y cierres Z las referencian.
        $devoluciones = $sync->pushDevoluciones();
        $turnos = $sync->pushTurnos();

        // Los servicios devuelven ['success' => false] en vez de lanzar excepción, así que
        // hay que fallar a mano para que la cola reintente.
        $resultados = [$ventas, $movimientos, $devoluciones, $turnos];

        if (collect($resultados)->contains(fn ($r) => ! $r['success'])) {
            $error = collect($resultados)->firstWhere('success', false)['error'] ?? 'Error desconocido';

            Log::warning('Sincronización pendiente falló, se reintentará', ['error' => $error]);

            throw new \RuntimeException($error);
        }
    }
}
