<div class="h-full flex flex-col bg-slate-900">
    <!-- Header -->
    <div class="p-4 bg-slate-800 border-b border-slate-700">
        <div class="flex items-center justify-between mb-4">
            <h1 class="text-xl font-bold text-white">Nuevo Remito</h1>
        </div>

        <!-- Selector de destino -->
        <div class="mb-4">
            <label class="block text-sm font-medium text-slate-300 mb-2">Sucursal Destino</label>
            <select
                wire:model="destinoSucursalId"
                class="w-full px-4 py-2 bg-slate-900 border border-slate-700 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                <option value="0">-- Selecciona una sucursal --</option>
                @foreach($sucursales as $s)
                    <option value="{{ $s->id }}">{{ $s->nombre }}@if($s->is_central) (Central)@endif</option>
                @endforeach
            </select>
            @if($rutaDirecta === false)
                <p class="text-xs text-amber-300 mt-1">📍 Solo puedes enviar a la sucursal Central según la configuración.</p>
            @endif
        </div>

        <!-- Búsqueda de productos -->
        <div class="relative">
            <input
                type="text"
                wire:model.live.debounce.300ms="busqueda"
                placeholder="Buscar producto..."
                class="w-full px-4 py-2 pl-10 bg-slate-900 border border-slate-700 rounded-lg text-white placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm"
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
                <h2 class="text-sm font-semibold text-slate-300">Productos Disponibles</h2>
            </div>

            <div class="flex-1 overflow-y-auto">
                @forelse($productos as $p)
                    <div class="p-3 border-b border-slate-700 hover:bg-slate-700 transition cursor-pointer">
                        <div class="flex justify-between items-start gap-3">
                            <div class="flex-1 min-w-0">
                                <h3 class="text-sm font-medium text-white truncate">{{ $p->nombre }}</h3>
                                <p class="text-xs text-slate-400">Stock: {{ $p->stock }}</p>
                            </div>
                            <div class="flex items-center gap-2">
                                <input
                                    type="number"
                                    min="1"
                                    placeholder="0"
                                    wire:keydown.enter="agregarProducto({{ $p->id }}, $event.target.value)"
                                    class="w-12 px-2 py-1 bg-slate-900 border border-slate-600 rounded text-white text-xs focus:outline-none focus:ring-1 focus:ring-blue-500"
                                >
                                <button
                                    wire:click="agregarProducto({{ $p->id }}, $event.currentTarget.previousElementSibling.value)"
                                    class="px-2 py-1 bg-blue-600 hover:bg-blue-700 text-white text-xs font-medium rounded transition">
                                    +
                                </button>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="p-4 text-center text-slate-400">
                        @if($busqueda)
                            No hay resultados
                        @else
                            Escribe para buscar productos
                        @endif
                    </div>
                @endforelse
            </div>
        </div>

        <!-- Columna derecha: Items del remito -->
        <div class="w-80 bg-slate-800 rounded-lg overflow-hidden flex flex-col">
            <div class="p-3 border-b border-slate-700">
                <h2 class="text-sm font-semibold text-slate-300">Items del Remito</h2>
            </div>

            <div class="flex-1 overflow-y-auto p-3 space-y-2">
                @forelse($productosAñadidos as $p)
                    <div class="flex justify-between items-center p-2 bg-slate-900 rounded">
                        <div class="flex-1 min-w-0">
                            <p class="text-sm text-white truncate">{{ $p->nombre }}</p>
                            <p class="text-xs text-slate-400">Cant: {{ $items[$p->id] ?? 0 }}</p>
                        </div>
                        <button
                            wire:click="quitarProducto({{ $p->id }})"
                            class="px-2 py-1 bg-red-600 hover:bg-red-700 text-white text-xs rounded transition">
                            ✕
                        </button>
                    </div>
                @empty
                    <div class="text-center text-slate-400 text-sm py-8">
                        Sin items
                    </div>
                @endforelse
            </div>

            <!-- Observaciones -->
            <div class="p-3 border-t border-slate-700">
                <label class="block text-xs font-medium text-slate-300 mb-1">Observaciones</label>
                <textarea
                    wire:model="observaciones"
                    placeholder="Notas opcionales..."
                    class="w-full px-2 py-1 bg-slate-900 border border-slate-700 rounded text-white text-xs placeholder-slate-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                    rows="2"></textarea>
            </div>

            <!-- Botones -->
            <div class="p-3 border-t border-slate-700 space-y-2">
                @if($mensaje)
                    <div class="p-2 bg-green-900 border border-green-700 text-green-300 text-xs rounded">
                        ✓ {{ $mensaje }}
                    </div>
                @endif

                @if($error)
                    <div class="p-2 bg-red-900 border border-red-700 text-red-300 text-xs rounded">
                        ✕ {{ $error }}
                    </div>
                @endif

                <button
                    wire:click="crear"
                    class="w-full px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-medium rounded transition">
                    Crear Remito
                </button>

                <a href="/pos/remitos"
                    class="block text-center px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white text-sm font-medium rounded transition">
                    Cancelar
                </a>
            </div>
        </div>
    </div>
</div>
