<?php

namespace App\Services;

use App\Models\Configuracion;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ManagerApiService
{
    private string $baseUrl;
    private ?string $token;

    public function __construct()
    {
        $this->baseUrl = rtrim(Configuracion::get('manager_api_url', ''), '/');
        $this->token = Configuracion::get('access_token');
    }

    /**
     * Obtiene el cliente HTTP configurado.
     */
    private function client(): PendingRequest
    {
        $client = Http::timeout(30)
            ->retry(3, 100)
            ->withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ]);

        if ($this->token) {
            $client->withToken($this->token);
        }

        return $client;
    }

    /**
     * Autentica el punto de venta y obtiene un token.
     */
    public function authenticate(int $puntoDeVentaId, string $secret): array
    {
        try {
            $response = $this->client()->post("{$this->baseUrl}/pos/auth", [
                'punto_de_venta_id' => $puntoDeVentaId,
                'secret' => $secret,
            ]);

            if ($response->successful()) {
                $data = $response->json();

                // Guardar configuración
                Configuracion::set('access_token', $data['token']);
                Configuracion::set('sucursal_id', $data['sucursal_id']);
                Configuracion::set('sucursal_nombre', $data['sucursal_nombre']);
                Configuracion::set('pdv_nombre', $data['pdv_nombre']);
                Configuracion::set('punto_de_venta_id', $puntoDeVentaId);
                Configuracion::set('configurado', '1');

                // Actualizar token en memoria
                $this->token = $data['token'];

                return [
                    'success' => true,
                    'data' => $data,
                ];
            }

            return [
                'success' => false,
                'error' => $response->json('message', 'Error de autenticación'),
            ];
        } catch (\Exception $e) {
            Log::error('Error en autenticación POS', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => 'No se pudo conectar con el servidor. Verifique la URL.',
            ];
        }
    }

    /**
     * Verifica si hay conexión con el manager.
     */
    public function checkConnection(): bool
    {
        try {
            $response = $this->client()->get("{$this->baseUrl}/sync/productos");

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Error verificando conexión', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Sincroniza productos desde el manager.
     */
    public function syncProductos(?string $updatedSince = null): array
    {
        try {
            $params = [];
            if ($updatedSince) {
                $params['updated_since'] = $updatedSince;
            }

            $response = $this->client()->get("{$this->baseUrl}/sync/productos", $params);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json('data', []),
                    'total' => $response->json('total', 0),
                ];
            }

            return [
                'success' => false,
                'error' => $response->json('message', 'Error al sincronizar productos'),
            ];
        } catch (\Exception $e) {
            Log::error('Error sincronizando productos', ['error' => $e->getMessage()]);

            return [
                'success' => false,
                'error' => 'Error de conexión al sincronizar productos',
            ];
        }
    }

    /**
     * Sincroniza precios desde el manager.
     */
    public function syncPrecios(): array
    {
        try {
            $response = $this->client()->get("{$this->baseUrl}/sync/precios");

            if ($response->successful()) {
                return [
                    'success' => true,
                    'listas' => $response->json('listas', []),
                    'precios' => $response->json('precios', []),
                ];
            }

            return [
                'success' => false,
                'error' => $response->json('message', 'Error al sincronizar precios'),
            ];
        } catch (\Exception $e) {
            Log::error('Error sincronizando precios', ['error' => $e->getMessage()]);

            return [
                'success' => false,
                'error' => 'Error de conexión al sincronizar precios',
            ];
        }
    }

    /**
     * Sincroniza stock desde el manager.
     */
    public function syncStock(): array
    {
        try {
            $response = $this->client()->get("{$this->baseUrl}/sync/stock");

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json('data', []),
                ];
            }

            return [
                'success' => false,
                'error' => $response->json('message', 'Error al sincronizar stock'),
            ];
        } catch (\Exception $e) {
            Log::error('Error sincronizando stock', ['error' => $e->getMessage()]);

            return [
                'success' => false,
                'error' => 'Error de conexión al sincronizar stock',
            ];
        }
    }

    /**
     * Envía ventas al manager.
     */
    public function pushVentas(array $ventas): array
    {
        try {
            $response = $this->client()->post("{$this->baseUrl}/sync/ventas", [
                'ventas' => $ventas,
            ]);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'message' => $response->json('message'),
                    'sincronizado_at' => $response->json('sincronizado_at'),
                ];
            }

            return [
                'success' => false,
                'error' => $response->json('message', 'Error al enviar ventas'),
            ];
        } catch (\Exception $e) {
            Log::error('Error enviando ventas', ['error' => $e->getMessage()]);

            return [
                'success' => false,
                'error' => 'Error de conexión al enviar ventas',
            ];
        }
    }

    /**
     * Envía movimientos de stock al manager.
     */
    public function pushMovimientos(array $movimientos): array
    {
        try {
            $response = $this->client()->post("{$this->baseUrl}/sync/movimientos", [
                'movimientos' => $movimientos,
            ]);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'message' => $response->json('message'),
                    'sincronizado_at' => $response->json('sincronizado_at'),
                ];
            }

            return [
                'success' => false,
                'error' => $response->json('message', 'Error al enviar movimientos'),
            ];
        } catch (\Exception $e) {
            Log::error('Error enviando movimientos', ['error' => $e->getMessage()]);

            return [
                'success' => false,
                'error' => 'Error de conexión al enviar movimientos',
            ];
        }
    }

    /**
     * Obtiene el precio de un producto específico.
     */
    public function getPrecioProducto(int $productId): array
    {
        try {
            $response = $this->client()->get("{$this->baseUrl}/precios/{$productId}");

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json(),
                ];
            }

            return [
                'success' => false,
                'error' => $response->json('message', 'Error al obtener precio'),
            ];
        } catch (\Exception $e) {
            Log::error('Error obteniendo precio producto', ['error' => $e->getMessage()]);

            return [
                'success' => false,
                'error' => 'Error de conexión al obtener precio',
            ];
        }
    }
}
