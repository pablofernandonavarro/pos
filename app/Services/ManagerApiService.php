<?php

namespace App\Services;

use App\Models\Configuracion;
use App\Support\VersionPos;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
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
        // connectTimeout corto: sin él, con el servidor caído o la red cortada a mitad de
        // camino, cada intento espera los 30s enteros (≈90s con los reintentos). 5s alcanzan
        // para conectar por internet; el timeout largo queda para la respuesta.
        $client = Http::connectTimeout(5)
            ->timeout(30)
            ->retry(3, 100)
            ->withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                // El Manager muestra en Puntos de venta qué versión corre cada caja.
                ...VersionPos::cabeceras(),
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
            // limit=1: sin él el Manager responde el catálogo entero.
            $response = $this->client()->get("{$this->baseUrl}/sync/productos", ['limit' => 1]);

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
                    'synced_at' => $response->json('synced_at'),
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
     * Una página de la sincronización incremental (Manager con cursor): productos, stock o
     * precios. El Manager devuelve `next_cursor` (null en la última) y `synced_at`, la marca
     * para el próximo `updated_since`. Un Manager anterior ignora limit/cursor y devuelve todo
     * en una sola respuesta sin `next_cursor`: se toma como página única.
     *
     * `status` viaja en los errores HTTP: 422 = cursor que el Manager ya no acepta.
     *
     * @param  array<string, mixed>  $params
     * @return array{success: bool, json?: array<string, mixed>, error?: string, status?: int}
     */
    public function pagina(string $recurso, array $params): array
    {
        try {
            // Páginas de hasta 5000 filas: más margen que los 30 s del resto.
            $response = $this->client()->timeout(120)
                ->get("{$this->baseUrl}/sync/{$recurso}", array_filter($params, fn ($v) => $v !== null));

            if ($response->successful()) {
                return ['success' => true, 'json' => $response->json() ?? []];
            }

            return ['success' => false, 'status' => $response->status(), 'error' => $response->json('message', "Error al sincronizar {$recurso}")];
        } catch (RequestException $e) {
            return ['success' => false, 'status' => $e->response->status(), 'error' => $e->response->json('message') ?? "Error al sincronizar {$recurso}"];
        } catch (\Exception $e) {
            Log::error("Error sincronizando {$recurso}", ['error' => $e->getMessage(), 'params' => $params]);

            return ['success' => false, 'error' => "Error de conexión al sincronizar {$recurso}"];
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
                    'resultados' => $response->json('resultados', []),
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
                    'resultados' => $response->json('resultados', []),
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
     * Envía turnos de caja (abiertos y cerrados) con sus movimientos.
     */
    public function pushTurnos(array $turnos): array
    {
        try {
            $response = $this->client()->post("{$this->baseUrl}/sync/turnos", ['turnos' => $turnos]);

            if ($response->successful()) {
                return ['success' => true, 'resultados' => $response->json('resultados', [])];
            }

            return ['success' => false, 'error' => $response->json('message', 'Error al enviar turnos de caja')];
        } catch (\Exception $e) {
            Log::error('Error enviando turnos de caja', ['error' => $e->getMessage()]);

            return ['success' => false, 'error' => 'Error de conexión al enviar turnos de caja'];
        }
    }

    /**
     * Envía comprobantes de devolución.
     */
    public function pushDevoluciones(array $devoluciones): array
    {
        try {
            $response = $this->client()->post("{$this->baseUrl}/sync/devoluciones", ['devoluciones' => $devoluciones]);

            if ($response->successful()) {
                return ['success' => true, 'resultados' => $response->json('resultados', [])];
            }

            return ['success' => false, 'error' => $response->json('message', 'Error al enviar devoluciones')];
        } catch (\Exception $e) {
            Log::error('Error enviando devoluciones', ['error' => $e->getMessage()]);

            return ['success' => false, 'error' => 'Error de conexión al enviar devoluciones'];
        }
    }

    /**
     * Cajeros habilitados en la sucursal de esta caja (con el PIN hasheado).
     */
    public function syncCajeros(): array
    {
        try {
            $response = $this->client()->get("{$this->baseUrl}/sync/cajeros");

            if ($response->successful()) {
                return ['success' => true, 'data' => $response->json('data', [])];
            }

            return ['success' => false, 'error' => $response->json('message', 'Error al traer cajeros')];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => 'Sin conexión con el Manager'];
        }
    }

    /**
     * Promociones bancarias vigentes para la sucursal de esta caja.
     */
    public function syncPromociones(): array
    {
        try {
            $response = $this->client()->get("{$this->baseUrl}/sync/promociones");

            if ($response->successful()) {
                return ['success' => true, 'data' => $response->json('data', [])];
            }

            return ['success' => false, 'error' => $response->json('message', 'Error al traer promociones')];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => 'Sin conexión con el Manager'];
        }
    }

    /**
     * Clientes activos con su saldo.
     */
    public function syncClientes(): array
    {
        try {
            $response = $this->client()->get("{$this->baseUrl}/sync/clientes");

            if ($response->successful()) {
                return ['success' => true, 'data' => $response->json('data', [])];
            }

            return ['success' => false, 'error' => $response->json('message', 'Error al traer clientes')];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => 'Sin conexión con el Manager'];
        }
    }

    /**
     * Cobros de cuenta corriente hechos en esta caja.
     *
     * @param  array<int, array<string, mixed>>  $cobros
     */
    public function pushCobrosCuentaCorriente(array $cobros): array
    {
        try {
            $response = $this->client()->post("{$this->baseUrl}/sync/cobros-cuenta-corriente", ['cobros' => $cobros]);

            if ($response->successful()) {
                return ['success' => true, 'resultados' => $response->json('resultados', [])];
            }

            return ['success' => false, 'error' => $response->json('message', 'Error al enviar cobros de cuenta corriente')];
        } catch (\Exception $e) {
            Log::error('Error enviando cobros de cuenta corriente', ['error' => $e->getMessage()]);

            return ['success' => false, 'error' => 'Error de conexión al enviar cobros de cuenta corriente'];
        }
    }

    /**
     * Estado de la caja para el panel de salud del Manager. Corto y sin reintentos: se
     * manda cada minuto y si uno se pierde no importa.
     */
    public function reportarEstado(array $estado): array
    {
        try {
            $response = Http::connectTimeout(5)
                ->timeout(10)
                ->acceptJson()
                ->withHeaders(VersionPos::cabeceras())
                ->withToken((string) $this->token)
                ->post("{$this->baseUrl}/pos/estado", $estado);

            return ['success' => $response->successful()];
        } catch (\Exception $e) {
            return ['success' => false];
        }
    }

    /**
     * Datos del emisor y si esta caja factura.
     */
    public function emisorFacturacion(): array
    {
        try {
            $response = $this->client()->get("{$this->baseUrl}/pos/facturacion");

            if ($response->successful()) {
                return ['success' => true, 'data' => $response->json()];
            }

            return ['success' => false, 'error' => $response->json('message', 'Error al traer los datos de facturación')];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => 'Sin conexión con el Manager'];
        }
    }

    /**
     * Manda la venta y pide la factura en el momento del cobro.
     *
     * No usa client(): esto corre con el cliente adelante. Sin reintentos y con un tope
     * corto; si no alcanza, la venta ya está registrada y la factura sale por la cola.
     */
    public function facturarVenta(array $venta): array
    {
        try {
            $response = Http::connectTimeout(3)
                ->timeout(20)
                ->acceptJson()
                ->withHeaders(VersionPos::cabeceras())
                ->withToken((string) $this->token)
                ->post("{$this->baseUrl}/pos/facturas", $venta);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'venta' => $response->json('venta'),
                    'comprobante' => $response->json('comprobante'),
                ];
            }

            return ['success' => false, 'error' => $response->json('message', 'El Manager no pudo facturar ('.$response->status().')')];
        } catch (\Exception $e) {
            Log::warning('No se pudo facturar en el momento', ['error' => $e->getMessage()]);

            return ['success' => false, 'error' => 'Sin conexión con el Manager'];
        }
    }

    /**
     * Estado de los comprobantes pendientes de esta caja.
     *
     * @param  array<int, string>  $ventas
     * @param  array<int, string>  $devoluciones
     */
    public function estadoComprobantes(array $ventas, array $devoluciones): array
    {
        try {
            $response = $this->client()->post("{$this->baseUrl}/pos/comprobantes/estado", [
                'ventas' => $ventas,
                'devoluciones' => $devoluciones,
            ]);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'ventas' => (array) $response->json('ventas', []),
                    'devoluciones' => (array) $response->json('devoluciones', []),
                ];
            }

            return ['success' => false, 'error' => $response->json('message', 'Error al consultar comprobantes')];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => 'Sin conexión con el Manager'];
        }
    }

    /**
     * Órdenes que el Manager dejó para esta caja.
     */
    public function obtenerComandos(): array
    {
        try {
            $response = $this->client()->get("{$this->baseUrl}/pos/comandos");

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json('data', []),
                ];
            }

            return [
                'success' => false,
                'error' => $response->json('message', 'Error al consultar órdenes'),
            ];
        } catch (\Exception $e) {
            // Sin conexión no es un error digno de log: la caja funciona offline por diseño
            // y este endpoint se consulta cada minuto.
            return [
                'success' => false,
                'error' => 'Sin conexión con el Manager',
            ];
        }
    }

    /**
     * Informa al Manager cómo terminó una orden.
     */
    public function reportarComando(int $comandoId, bool $exito, ?string $resultado = null): array
    {
        try {
            $response = $this->client()->post("{$this->baseUrl}/pos/comandos/{$comandoId}/resultado", [
                'exito' => $exito,
                'resultado' => $resultado ? mb_substr($resultado, 0, 2000) : null,
            ]);

            return ['success' => $response->successful()];
        } catch (\Exception $e) {
            Log::error('Error reportando comando', ['comando' => $comandoId, 'error' => $e->getMessage()]);

            return ['success' => false];
        }
    }

    /**
     * Remitos en camino a la sucursal de esta caja.
     */
    public function obtenerRemitos(): array
    {
        try {
            $response = $this->client()->get("{$this->baseUrl}/pos/remitos");

            if ($response->successful()) {
                return ['success' => true, 'data' => $response->json('data', [])];
            }

            return ['success' => false, 'error' => $response->json('message', 'Error al consultar remitos')];
        } catch (\Exception $e) {
            // Se consulta cada minuto y la caja funciona offline por diseño: no se loguea.
            return ['success' => false, 'error' => 'Sin conexión con el Manager'];
        }
    }

    /**
     * Da por recibido un remito en el Manager.
     *
     * No usa client(): su retry() convierte cualquier 4xx en excepción, y acá hace falta
     * distinguir "el remito fue cancelado" (409) o "no es para esta sucursal" (404) de
     * "no hay red". Solo se reintenta ante fallas de conexión; el Manager es idempotente
     * para esta operación, así que un reintento nunca suma dos veces.
     *
     * @return array{success: bool, status?: string, stock?: array, error?: string, codigo?: int}
     */
    public function recibirRemito(int $remitoId, ?array $cantidadesRecibidas = null, ?int $destinoRechazadosId = null): array
    {
        try {
            $data = array_filter([
                'cantidades_recibidas' => $cantidadesRecibidas,
                'destino_rechazados_id' => $destinoRechazadosId,
            ]);

            $response = Http::connectTimeout(5)
                ->timeout(30)
                ->acceptJson()
                ->withHeaders(VersionPos::cabeceras())
                ->withToken((string) $this->token)
                ->retry(3, 300, fn ($e) => $e instanceof ConnectionException, throw: false)
                ->post("{$this->baseUrl}/pos/remitos/{$remitoId}/recibir", $data);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'status' => $response->json('status'),
                    'stock' => $response->json('stock', []),
                    'remito_hijo' => $response->json('remito_hijo'),
                ];
            }

            return [
                'success' => false,
                'codigo' => $response->status(),
                'error' => $response->json('message', 'El Manager rechazó la recepción ('.$response->status().')'),
            ];
        } catch (\Exception $e) {
            Log::warning('No se pudo recibir el remito', ['remito' => $remitoId, 'error' => $e->getMessage()]);

            return ['success' => false, 'error' => 'Sin conexión con el Manager. La mercadería no se dio por recibida: reintentá cuando vuelva la conexión.'];
        }
    }

    public function crearRemito(int $destinoSucursalId, array $items, ?string $observaciones = null): array
    {
        try {
            $response = Http::connectTimeout(5)
                ->timeout(30)
                ->acceptJson()
                ->withHeaders(VersionPos::cabeceras())
                ->withToken((string) $this->token)
                ->retry(3, 300, fn ($e) => $e instanceof ConnectionException, throw: false)
                ->post("{$this->baseUrl}/pos/remitos", [
                    'destino_sucursal_id' => $destinoSucursalId,
                    'items' => $items,
                    'observaciones' => $observaciones,
                ]);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json('data', []),
                ];
            }

            return [
                'success' => false,
                'error' => $response->json('message', 'El Manager rechazó el remito ('.$response->status().')'),
            ];
        } catch (\Exception $e) {
            Log::warning('No se pudo crear el remito', ['error' => $e->getMessage()]);

            return ['success' => false, 'error' => 'Sin conexión con el Manager. No se pudo crear el remito.'];
        }
    }

    public function obtenerConfiguracionRemitos(): array
    {
        try {
            $response = Http::connectTimeout(5)
                ->timeout(30)
                ->acceptJson()
                ->withHeaders(VersionPos::cabeceras())
                ->withToken((string) $this->token)
                ->retry(3, 300, fn ($e) => $e instanceof ConnectionException, throw: false)
                ->get("{$this->baseUrl}/pos/remitos/configuracion");

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json(),
                ];
            }

            return [
                'success' => false,
                'error' => 'No se pudo obtener la configuración de remitos',
            ];
        } catch (\Exception $e) {
            Log::warning('No se pudo obtener configuración remitos', ['error' => $e->getMessage()]);

            return ['success' => false, 'error' => 'Sin conexión con el Manager'];
        }
    }

    public function obtenerSucursales(): array
    {
        try {
            $response = Http::connectTimeout(5)
                ->timeout(30)
                ->acceptJson()
                ->withHeaders(VersionPos::cabeceras())
                ->withToken((string) $this->token)
                ->retry(3, 300, fn ($e) => $e instanceof ConnectionException, throw: false)
                ->get("{$this->baseUrl}/sync/sucursales");

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json('data', []),
                ];
            }

            return [
                'success' => false,
                'error' => 'No se pudieron obtener las sucursales',
            ];
        } catch (\Exception $e) {
            Log::warning('No se pudieron obtener sucursales', ['error' => $e->getMessage()]);

            return ['success' => false, 'error' => 'Sin conexión con el Manager'];
        }
    }

    /**
     * Versión del POS que el Manager tiene publicada.
     */
    public function obtenerVersion(): array
    {
        try {
            $response = $this->client()->get("{$this->baseUrl}/pos/version");

            if ($response->successful()) {
                return ['success' => true, 'data' => $response->json()];
            }

            return [
                'success' => false,
                'error' => $response->json('message', 'No hay versión publicada'),
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => 'Sin conexión con el Manager'];
        }
    }

    /**
     * Baja el paquete de actualización al disco.
     *
     * Se usa sink() para escribir directo al archivo: el zip pesa varios MB y cargarlo
     * entero en memoria en una caja modesta es pedir problemas.
     */
    public function descargarPaquete(string $destino): array
    {
        // El handle se abre y cierra acá a propósito: pasarle una ruta a sink() deja el
        // archivo tomado por este proceso, y después nada externo puede leerlo
        // (el instalador lo descomprime con PowerShell y fallaba por archivo bloqueado).
        $handle = fopen($destino, 'w');

        if ($handle === false) {
            return ['success' => false, 'error' => "No se pudo escribir en {$destino}"];
        }

        try {
            $response = Http::timeout(300)
                ->withHeaders($this->token ? ['Authorization' => "Bearer {$this->token}"] : [])
                ->sink($handle)
                ->get("{$this->baseUrl}/pos/paquete");

            fclose($handle);

            if ($response->successful()) {
                return ['success' => true];
            }

            return ['success' => false, 'error' => 'El Manager rechazó la descarga ('.$response->status().')'];
        } catch (\Exception $e) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            Log::error('Error descargando paquete', ['error' => $e->getMessage()]);

            return ['success' => false, 'error' => 'Falló la descarga del paquete'];
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
