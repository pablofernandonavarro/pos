<?php

namespace App\Services;

class RemitosSalientesService
{
    public function __construct(private ManagerApiService $api) {}

    public function configuracion(): array
    {
        return $this->api->obtenerConfiguracionRemitos();
    }

    public function sucursalesDestino(): array
    {
        $result = $this->api->obtenerSucursales();

        if (! $result['success']) {
            return [];
        }

        return $result['data'] ?? [];
    }

    public function crear(int $destinoSucursalId, array $items, ?string $observaciones = null): array
    {
        return $this->api->crearRemito($destinoSucursalId, $items, $observaciones);
    }
}
