<div class="flex h-full">
    <!-- Panel Izquierdo: Búsqueda y Catálogo -->
    <div class="flex-1 flex flex-col bg-slate-900 p-6">
        <!-- Barra de búsqueda -->
        <div class="mb-6">
            <div class="relative">
                <input
                    type="text"
                    wire:model.live.debounce.300ms="busqueda"
                    placeholder="Buscar por nombre, código o escanear..."
                    class="w-full px-4 py-4 pl-12 bg-slate-800 border border-slate-700 rounded-xl text-white placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent text-lg"
                    autofocus
                >
                <svg class="absolute left-4 top-1/2 transform -translate-y-1/2 w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                </svg>
            </div>

            <!-- Resultados de búsqueda -->
            @if(!empty($resultadosBusqueda) && !$modalBusqueda)
                <div class="absolute z-50 mt-2 w-full max-w-2xl bg-slate-800 rounded-lg shadow-2xl border border-slate-700 max-h-96 overflow-y-auto">
                    @foreach($resultadosBusqueda as $resultado)
                        <button
                            wire:click="agregarAlCarrito({{ $resultado['id'] }})"
                            class="w-full px-4 py-3 flex items-center gap-4 hover:bg-slate-700 transition-colors border-b border-slate-700 last:border-0"
                        >
                            <div class="w-12 h-12 bg-slate-700 rounded-lg flex items-center justify-center overflow-hidden">
                                @if($resultado['imagen'])
                                    <img src="{{ $resultado['imagen'] }}" alt="{{ $resultado['nombre'] }}" class="w-full h-full object-cover">
                                @else
                                    <svg class="w-6 h-6 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                                    </svg>
                                @endif
                            </div>
                            <div class="flex-1 text-left">
                                <div class="text-white font-medium">{{ $resultado['nombre'] }}</div>
                                <div class="text-sm text-slate-400">{{ $resultado['codigo'] }} • Stock: {{ $resultado['stock'] }}</div>
                            </div>
                            <div class="text-xl font-bold text-green-400">
                                ${{ number_format($resultado['precio'], 2) }}
                            </div>
                        </button>
                    @endforeach
                </div>
            @endif
        </div>

        <!-- Carrito vacío / Sugerencias -->
        @if(empty($carrito))
            <div class="flex-1 flex items-center justify-center">
                <div class="text-center text-slate-500">
                    <svg class="w-24 h-24 mx-auto mb-4 text-slate-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"></path>
                    </svg>
                    <p class="text-xl mb-2">Carrito vacío</p>
                    <p class="text-sm">Busca productos para comenzar una venta</p>
                </div>
            </div>
        @endif
    </div>

    <!-- Panel Derecho: Carrito y Checkout -->
    <div class="w-[480px] bg-slate-800 border-l border-slate-700 flex flex-col">
        <!-- Header del carrito -->
        <div class="p-6 border-b border-slate-700">
            <h2 class="text-xl font-bold text-white mb-2">Carrito de Venta</h2>
            <p class="text-sm text-slate-400">{{ count($carrito) }} producto(s)</p>
        </div>

        <!-- Items del carrito -->
        <div class="flex-1 overflow-y-auto p-6 space-y-4">
            @foreach($carrito as $index => $item)
                <div class="bg-slate-900 rounded-lg p-4 border border-slate-700">
                    <div class="flex items-start justify-between mb-3">
                        <div class="flex-1">
                            <h3 class="text-white font-medium mb-1">{{ $item['nombre'] }}</h3>
                            <p class="text-sm text-slate-400">{{ $item['codigo'] }}</p>
                        </div>
                        <button
                            wire:click="eliminarItem({{ $index }})"
                            class="text-red-400 hover:text-red-300 transition-colors"
                        >
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                            </svg>
                        </button>
                    </div>

                    <div class="flex items-center justify-between">
                        <!-- Controles de cantidad -->
                        <div class="flex items-center gap-2">
                            <button
                                wire:click="decrementarCantidad({{ $index }})"
                                class="w-8 h-8 flex items-center justify-center bg-slate-800 hover:bg-slate-700 rounded-lg transition-colors"
                            >
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4"></path>
                                </svg>
                            </button>
                            <span class="w-12 text-center text-white font-medium">{{ $item['cantidad'] }}</span>
                            <button
                                wire:click="incrementarCantidad({{ $index }})"
                                class="w-8 h-8 flex items-center justify-center bg-slate-800 hover:bg-slate-700 rounded-lg transition-colors"
                            >
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                                </svg>
                            </button>
                        </div>

                        <!-- Precio -->
                        <div class="text-right">
                            <div class="text-sm text-slate-400">${{ number_format($item['precio_unitario'], 2) }} c/u</div>
                            <div class="text-lg font-bold text-green-400">${{ number_format($item['subtotal'], 2) }}</div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <!-- Footer: Totales y Checkout -->
        @if(!empty($carrito))
            <div class="p-6 border-t border-slate-700 space-y-4">
                <!-- Información del cliente (opcional) -->
                <div class="space-y-2">
                    <input
                        type="text"
                        wire:model="clienteNombre"
                        placeholder="Nombre del cliente (opcional)"
                        class="w-full px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-white placeholder-slate-500 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                    >
                    <input
                        type="text"
                        wire:model="clienteDocumento"
                        placeholder="Documento (opcional)"
                        class="w-full px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-white placeholder-slate-500 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                    >
                </div>

                <!-- Método de pago -->
                <div class="flex gap-2">
                    <button
                        wire:click="$set('metodoPago', 'efectivo')"
                        class="flex-1 px-4 py-2 rounded-lg text-sm font-medium transition-colors {{ $metodoPago === 'efectivo' ? 'bg-blue-600 text-white' : 'bg-slate-900 text-slate-400 hover:bg-slate-700' }}"
                    >
                        💵 Efectivo
                    </button>
                    <button
                        wire:click="$set('metodoPago', 'tarjeta')"
                        class="flex-1 px-4 py-2 rounded-lg text-sm font-medium transition-colors {{ $metodoPago === 'tarjeta' ? 'bg-blue-600 text-white' : 'bg-slate-900 text-slate-400 hover:bg-slate-700' }}"
                    >
                        💳 Tarjeta
                    </button>
                    <button
                        wire:click="$set('metodoPago', 'transferencia')"
                        class="flex-1 px-4 py-2 rounded-lg text-sm font-medium transition-colors {{ $metodoPago === 'transferencia' ? 'bg-blue-600 text-white' : 'bg-slate-900 text-slate-400 hover:bg-slate-700' }}"
                    >
                        🏦 Transfer.
                    </button>
                </div>

                <!-- Totales -->
                <div class="space-y-2">
                    <div class="flex justify-between text-slate-400">
                        <span>Subtotal</span>
                        <span>${{ number_format($subtotal, 2) }}</span>
                    </div>
                    @if($descuento > 0)
                        <div class="flex justify-between text-orange-400">
                            <span>Descuento</span>
                            <span>-${{ number_format($descuento, 2) }}</span>
                        </div>
                    @endif
                    <div class="flex justify-between text-2xl font-bold text-white pt-2 border-t border-slate-700">
                        <span>Total</span>
                        <span class="text-green-400">${{ number_format($total, 2) }}</span>
                    </div>
                </div>

                <!-- Botones de acción -->
                <div class="flex gap-3">
                    <button
                        wire:click="resetearVenta"
                        class="flex-1 px-6 py-4 bg-slate-900 hover:bg-slate-700 text-white rounded-xl font-medium transition-colors"
                    >
                        Cancelar
                    </button>
                    <button
                        wire:click="finalizarVenta"
                        class="flex-[2] px-6 py-4 bg-gradient-to-r from-blue-600 to-blue-500 hover:from-blue-500 hover:to-blue-400 text-white rounded-xl font-bold text-lg transition-all transform hover:scale-105 shadow-lg"
                    >
                        Finalizar Venta
                    </button>
                </div>
            </div>
        @endif
    </div>

    <!-- Notificaciones -->
    <div x-data="{ show: false, message: '' }" x-show="show" x-cloak class="fixed top-4 right-4 bg-red-500 text-white px-6 py-4 rounded-lg shadow-lg z-50" x-transition>
        <p x-text="message"></p>
    </div>

    <div x-data="{ show: false, ventaId: '', numeroVenta: '' }" x-show="show" x-cloak class="fixed top-4 right-4 bg-green-500 text-white px-6 py-4 rounded-lg shadow-lg z-50" x-transition>
        <p class="font-bold">Venta realizada con exito</p>
        <p class="text-sm" x-text="'Numero: ' + numeroVenta"></p>
    </div>
</div>
