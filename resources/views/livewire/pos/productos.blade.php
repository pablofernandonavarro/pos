<div class="h-full flex flex-col bg-slate-900">
    <!-- Header compacto con estadísticas y búsqueda -->
    <div class="p-4 bg-slate-800 border-b border-slate-700">
        <div class="flex items-center gap-4 mb-3">
            <h1 class="text-xl font-bold text-white">Productos</h1>

            <!-- Estadísticas compactas -->
            <div class="flex items-center gap-4 ml-4">
                <div class="flex items-center gap-2">
                    <span class="text-xs text-slate-400">Total:</span>
                    <span class="text-sm font-bold text-white">{{ number_format($stats['total']) }}</span>
                </div>
                <div class="flex items-center gap-2">
                    <span class="text-xs text-slate-400">Con stock:</span>
                    <span class="text-sm font-bold text-green-400">{{ number_format($stats['con_stock']) }}</span>
                </div>
                <div class="flex items-center gap-2">
                    <span class="text-xs text-slate-400">Sin stock:</span>
                    <span class="text-sm font-bold text-orange-400">{{ number_format($stats['sin_stock']) }}</span>
                </div>
                <div class="flex items-center gap-2">
                    <span class="text-xs text-slate-400">Valor:</span>
                    <span class="text-sm font-bold text-blue-400">${{ number_format($stats['valor_total'], 2) }}</span>
                </div>
            </div>
        </div>

        <!-- Barra de búsqueda y filtros -->
        <div class="flex gap-3">
            <!-- Búsqueda -->
            <div class="flex-1 relative">
                <input
                    type="text"
                    wire:model.live.debounce.300ms="busqueda"
                    placeholder="Buscar por nombre, código o código de barras..."
                    class="w-full px-4 py-2 pl-10 bg-slate-900 border border-slate-700 rounded-lg text-white placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent text-sm"
                >
                <svg class="absolute left-3 top-1/2 transform -translate-y-1/2 w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                </svg>
            </div>

            <!-- Filtro de stock -->
            <button
                wire:click="$toggle('soloConStock')"
                class="px-3 py-2 rounded-lg text-sm font-medium transition-colors {{ $soloConStock ? 'bg-blue-600 text-white' : 'bg-slate-900 text-slate-400 hover:bg-slate-700 border border-slate-700' }}"
            >
                <div class="flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4h13M3 8h9m-9 4h6m4 0l4-4m0 0l4 4m-4-4v12"></path>
                    </svg>
                    Solo con stock
                </div>
            </button>

            <!-- Ordenar -->
            <select
                wire:model.live="ordenar"
                class="px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-white text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            >
                <option value="nombre">Por Nombre</option>
                <option value="codigo_interno">Por Código</option>
                <option value="precio">Por Precio</option>
                <option value="stock">Por Stock</option>
            </select>
        </div>
    </div>

    <!-- Lista de productos tipo tabla -->
    <div class="flex-1 overflow-y-auto overflow-x-auto">
        @if($productos->isEmpty())
            <div class="flex items-center justify-center h-full">
                <div class="text-center text-slate-500">
                    <svg class="w-20 h-20 mx-auto mb-3 text-slate-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                    </svg>
                    <p class="text-lg mb-1">No se encontraron productos</p>
                    <p class="text-sm">Intenta ajustar los filtros de búsqueda</p>
                </div>
            </div>
        @else
            <table class="w-full text-sm">
                <thead class="bg-slate-800 sticky top-0 z-10">
                    <tr class="border-b border-slate-700">
                        <th class="px-3 py-2 text-left text-xs font-semibold text-slate-400 uppercase tracking-wider w-20">Imagen</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold text-slate-400 uppercase tracking-wider w-28">Código</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold text-slate-400 uppercase tracking-wider">Nombre</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold text-slate-400 uppercase tracking-wider w-36">Marca</th>
                        <th class="px-3 py-2 text-left text-xs font-semibold text-slate-400 uppercase tracking-wider w-36">Grupo</th>
                        <th class="px-3 py-2 text-right text-xs font-semibold text-slate-400 uppercase tracking-wider w-24">Stock</th>
                        <th class="px-3 py-2 text-right text-xs font-semibold text-slate-400 uppercase tracking-wider w-32">Precio</th>
                        <th class="px-3 py-2 text-right text-xs font-semibold text-slate-400 uppercase tracking-wider w-32">Valor Total</th>
                    </tr>
                </thead>
                <tbody class="bg-slate-900 divide-y divide-slate-800">
                    @foreach($productos as $producto)
                        <tr class="hover:bg-slate-800 transition-colors">
                            <!-- Imagen -->
                            <td class="px-3 py-2">
                                @php
                                    $colors = [
                                        ['bg' => 'bg-blue-900', 'text' => 'text-blue-400'],
                                        ['bg' => 'bg-green-900', 'text' => 'text-green-400'],
                                        ['bg' => 'bg-amber-900', 'text' => 'text-amber-400'],
                                        ['bg' => 'bg-red-900', 'text' => 'text-red-400'],
                                        ['bg' => 'bg-purple-900', 'text' => 'text-purple-400'],
                                        ['bg' => 'bg-pink-900', 'text' => 'text-pink-400'],
                                        ['bg' => 'bg-cyan-900', 'text' => 'text-cyan-400'],
                                    ];
                                    $colorIndex = $producto->id % count($colors);
                                    $colorSet = $colors[$colorIndex];
                                    $inicial = strtoupper(substr($producto->nombre, 0, 1));
                                @endphp
                                <div class="w-16 h-16 {{ $colorSet['bg'] }} rounded flex items-center justify-center border border-slate-600 overflow-hidden relative">
                                    @if($producto->imagen_url)
                                        <img
                                            src="{{ $producto->imagen_url }}"
                                            alt="{{ $producto->nombre }}"
                                            class="absolute inset-0 w-full h-full object-cover z-10"
                                            onerror="this.src='{{ asset('images/product-placeholder.svg') }}'; this.classList.remove('z-10');"
                                        >
                                    @else
                                        <img src="{{ asset('images/product-placeholder.svg') }}" alt="{{ $producto->nombre }}" class="w-full h-full object-cover">
                                    @endif
                                </div>
                            </td>
                            <!-- Código -->
                            <td class="px-3 py-2 text-slate-300 font-mono text-xs">
                                {{ $producto->codigo_interno ?? $producto->codigo_barras ?? '-' }}
                            </td>
                            <!-- Nombre -->
                            <td class="px-3 py-2 text-white">
                                {{ $producto->nombre }}
                            </td>
                            <!-- Marca -->
                            <td class="px-3 py-2 text-slate-400">
                                {{ $producto->marca ?? '-' }}
                            </td>
                            <!-- Grupo -->
                            <td class="px-3 py-2 text-slate-400">
                                {{ $producto->n_grupo ?? '-' }}
                            </td>
                            <!-- Stock -->
                            <td class="px-3 py-2 text-right font-semibold {{ $producto->stock > 0 ? 'text-green-400' : 'text-red-400' }}">
                                {{ number_format($producto->stock) }}
                            </td>
                            <!-- Precio -->
                            <td class="px-3 py-2 text-right font-semibold text-blue-400">
                                ${{ number_format($producto->getPrecioEfectivo(), 0, ',', '.') }}
                            </td>
                            <!-- Valor Total -->
                            <td class="px-3 py-2 text-right font-semibold text-slate-300">
                                ${{ number_format($producto->stock * $producto->getPrecioEfectivo(), 0, ',', '.') }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <!-- Paginación -->
            <div class="p-4 bg-slate-800 border-t border-slate-700">
                {{ $productos->links() }}
            </div>
        @endif
    </div>
</div>
