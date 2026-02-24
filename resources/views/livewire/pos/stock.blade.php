<div class="h-full flex flex-col bg-slate-900">
    <!-- Header -->
    <div class="p-4 bg-slate-800 border-b border-slate-700">
        <div class="flex items-center justify-between mb-3">
            <h1 class="text-xl font-bold text-white">Gestión de Stock</h1>

            <div class="flex items-center gap-3">
                <!-- Estadísticas -->
                <div class="flex items-center gap-2 px-3 py-1.5 bg-slate-900 rounded-lg">
                    <span class="text-xs text-slate-400">Movimientos hoy:</span>
                    <span class="text-sm font-bold text-blue-400">{{ $stats['total_hoy'] }}</span>
                </div>

                @if($stats['pendientes'] > 0)
                    <button
                        wire:click="sincronizarMovimientos"
                        class="px-4 py-1.5 bg-orange-600 hover:bg-orange-700 text-white text-sm font-medium rounded-lg transition-colors flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                        </svg>
                        Sincronizar ({{ $stats['pendientes'] }})
                    </button>
                @endif
            </div>
        </div>

        <!-- Búsqueda -->
        <div class="relative">
            <input
                type="text"
                wire:model.live.debounce.300ms="busqueda"
                placeholder="Buscar producto por nombre o código..."
                class="w-full px-4 py-2 pl-10 bg-slate-900 border border-slate-700 rounded-lg text-white placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent text-sm"
            >
            <svg class="absolute left-3 top-1/2 transform -translate-y-1/2 w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
            </svg>
        </div>
    </div>

    <!-- Contenido principal: Grid de 2 columnas -->
    <div class="flex-1 overflow-hidden flex gap-4 p-4">
        <!-- Columna izquierda: Lista de productos -->
        <div class="flex-1 bg-slate-800 rounded-lg overflow-hidden flex flex-col">
            <div class="p-3 border-b border-slate-700">
                <h2 class="text-sm font-semibold text-slate-300">Productos</h2>
            </div>

            <div class="flex-1 overflow-y-auto">
                @if($productos->isEmpty())
                    <div class="flex items-center justify-center h-full">
                        <p class="text-slate-500 text-sm">No se encontraron productos</p>
                    </div>
                @else
                    <div class="divide-y divide-slate-700">
                        @foreach($productos as $producto)
                            <button
                                wire:key="producto-{{ $producto->id }}"
                                wire:click="seleccionarProducto({{ $producto->id }})"
                                class="w-full p-3 hover:bg-slate-700 transition-colors text-left">
                                <div class="flex items-center justify-between">
                                    <div class="flex-1">
                                        <p class="text-sm font-medium text-white">{{ $producto->nombre }}</p>
                                        <p class="text-xs text-slate-400">{{ $producto->codigo_interno ?? $producto->codigo_barras ?? '-' }}</p>
                                    </div>
                                    <div class="text-right">
                                        <p class="text-sm font-bold {{ $producto->stock > 0 ? 'text-green-400' : 'text-red-400' }}">
                                            {{ number_format($producto->stock) }}
                                        </p>
                                        <p class="text-xs text-slate-400">unidades</p>
                                    </div>
                                </div>
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>

            <!-- Paginación -->
            <div class="p-3 border-t border-slate-700">
                {{ $productos->links() }}
            </div>
        </div>

        <!-- Columna derecha: Movimientos recientes -->
        <div class="w-96 bg-slate-800 rounded-lg overflow-hidden flex flex-col">
            <div class="p-3 border-b border-slate-700">
                <h2 class="text-sm font-semibold text-slate-300">Movimientos Recientes</h2>
            </div>

            <div class="flex-1 overflow-y-auto">
                @if($movimientosRecientes->isEmpty())
                    <div class="flex items-center justify-center h-full">
                        <p class="text-slate-500 text-sm">No hay movimientos registrados</p>
                    </div>
                @else
                    <div class="divide-y divide-slate-700">
                        @foreach($movimientosRecientes as $movimiento)
                            <div class="p-3">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-medium text-white truncate">{{ $movimiento->producto->nombre }}</p>
                                        <p class="text-xs text-slate-400 mt-0.5">{{ $movimiento->fecha->format('d/m/Y H:i') }}</p>
                                        @if($movimiento->referencia)
                                            <p class="text-xs text-slate-500 mt-0.5">{{ $movimiento->referencia }}</p>
                                        @endif
                                    </div>
                                    <div class="flex flex-col items-end">
                                        <span class="px-2 py-0.5 rounded text-xs font-medium {{ $movimiento->tipo === 'entrada' ? 'bg-green-900 text-green-400' : ($movimiento->tipo === 'salida' ? 'bg-red-900 text-red-400' : 'bg-blue-900 text-blue-400') }}">
                                            {{ ucfirst($movimiento->tipo) }}
                                        </span>
                                        <p class="text-sm font-bold {{ $movimiento->cantidad > 0 ? 'text-green-400' : 'text-red-400' }} mt-1">
                                            {{ $movimiento->cantidad > 0 ? '+' : '' }}{{ number_format($movimiento->cantidad) }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>

    <!-- Modal de registro de movimiento -->
    @if($showModal)
        @if($productoModal)
        <div wire:key="modal-{{ $productoModal->id }}" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50" wire:click="cerrarModal">
            <div class="bg-slate-800 rounded-xl shadow-xl w-full max-w-md m-4" wire:click.stop>
                <div class="p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-bold text-white">Registrar Movimiento</h3>
                        <button wire:click="cerrarModal" class="text-slate-400 hover:text-white">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>

                    <!-- Información del producto -->
                    <div class="mb-6 p-4 bg-slate-900 rounded-lg">
                        <p class="text-sm font-medium text-white">{{ $productoModal->nombre }}</p>
                        <p class="text-xs text-slate-400 mt-1">{{ $productoModal->codigo_interno ?? $productoModal->codigo_barras ?? '-' }}</p>
                        <p class="text-sm font-bold text-blue-400 mt-2">Stock actual: {{ number_format($productoModal->stock) }}</p>
                    </div>

                    <!-- Formulario -->
                    <div class="space-y-4">
                        <!-- Tipo de movimiento -->
                        <div>
                            <label class="block text-sm font-medium text-slate-300 mb-2">Tipo de Movimiento</label>
                            <div class="grid grid-cols-3 gap-2">
                                <button
                                    type="button"
                                    wire:click="$set('tipo', 'entrada')"
                                    class="px-3 py-2 rounded-lg text-sm font-medium transition-colors {{ $tipo === 'entrada' ? 'bg-green-600 text-white' : 'bg-slate-700 text-slate-300 hover:bg-slate-600' }}">
                                    Entrada
                                </button>
                                <button
                                    type="button"
                                    wire:click="$set('tipo', 'salida')"
                                    class="px-3 py-2 rounded-lg text-sm font-medium transition-colors {{ $tipo === 'salida' ? 'bg-red-600 text-white' : 'bg-slate-700 text-slate-300 hover:bg-slate-600' }}">
                                    Salida
                                </button>
                                <button
                                    type="button"
                                    wire:click="$set('tipo', 'ajuste')"
                                    class="px-3 py-2 rounded-lg text-sm font-medium transition-colors {{ $tipo === 'ajuste' ? 'bg-blue-600 text-white' : 'bg-slate-700 text-slate-300 hover:bg-slate-600' }}">
                                    Ajuste
                                </button>
                            </div>
                        </div>

                        <!-- Cantidad -->
                        <div>
                            <label class="block text-sm font-medium text-slate-300 mb-2">
                                {{ $tipo === 'ajuste' ? 'Stock Final' : 'Cantidad' }}
                            </label>
                            <input
                                type="number"
                                wire:model="cantidad"
                                min="1"
                                class="w-full px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-blue-500 @error('cantidad') border-red-500 @enderror">
                            @error('cantidad')
                                <p class="mt-1 text-xs text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Referencia -->
                        <div>
                            <label class="block text-sm font-medium text-slate-300 mb-2">Referencia (opcional)</label>
                            <input
                                type="text"
                                wire:model="referencia"
                                placeholder="Ej: Recepción de mercadería, Ajuste de inventario..."
                                class="w-full px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>

                        <!-- Botones -->
                        <div class="flex gap-3 pt-2">
                            <button
                                type="button"
                                wire:click="cerrarModal"
                                class="flex-1 px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white rounded-lg transition-colors">
                                Cancelar
                            </button>
                            <button
                                type="button"
                                wire:click="registrarMovimiento"
                                class="flex-1 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors">
                                Registrar
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        @endif
    @endif

    <!-- Notificaciones -->
    <div class="fixed bottom-4 right-4 z-50" x-data="{ show: false, message: '', isError: false }"
         x-on:movimiento-creado.window="show = true; message = $event.detail; isError = false; setTimeout(() => show = false, 3000)"
         x-on:error.window="show = true; message = $event.detail; isError = true; setTimeout(() => show = false, 5000)">
        <div x-show="show"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0 transform translate-y-2"
             x-transition:enter-end="opacity-100 transform translate-y-0"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
             x-bind:class="isError ? 'bg-red-600' : 'bg-green-600'"
             class="text-white px-4 py-3 rounded-lg shadow-lg max-w-md">
            <p x-text="message" class="text-sm"></p>
        </div>
    </div>
</div>
