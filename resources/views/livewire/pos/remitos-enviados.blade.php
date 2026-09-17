@php
    $colorEstado = fn (string $estado) => match ($estado) {
        'confirmado' => 'bg-green-500/20 text-green-300',
        'cancelado' => 'bg-red-500/20 text-red-300',
        default => 'bg-amber-500/20 text-amber-300',
    };
    $labelEstado = fn (string $estado) => match ($estado) {
        'confirmado' => 'Confirmado',
        'cancelado' => 'Cancelado',
        default => 'En camino',
    };
@endphp
<div class="h-full overflow-y-auto p-6">
    <div class="max-w-5xl mx-auto space-y-6">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h2 class="text-2xl font-bold text-white">Remitos enviados</h2>
                <p class="text-sm text-slate-400 mt-1">Historial de mercadería que mandaste a otras sucursales.</p>
            </div>
            <button type="button" wire:click="actualizar" wire:loading.attr="disabled" wire:target="actualizar"
                    class="px-4 py-2 rounded-lg text-sm font-medium bg-slate-700 hover:bg-slate-600 text-slate-200 transition-colors disabled:opacity-50">
                <span wire:loading.remove wire:target="actualizar">Actualizar estados</span>
                <span wire:loading wire:target="actualizar">Consultando…</span>
            </button>
        </div>

        <!-- Filtros -->
        <div class="flex flex-wrap gap-3 bg-slate-800 border border-slate-700 rounded-xl p-4">
            <div class="flex-1 min-w-40">
                <label class="block text-xs font-medium text-slate-400 mb-1">Sucursal destino</label>
                <select wire:model.live="sucursalId" class="w-full px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-white text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">Todas</option>
                    @foreach($sucursales as $s)
                        <option value="{{ $s->id }}">{{ $s->nombre }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex-1 min-w-40">
                <label class="block text-xs font-medium text-slate-400 mb-1">Estado</label>
                <select wire:model.live="estado" class="w-full px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-white text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">Todos</option>
                    <option value="remitido">En camino</option>
                    <option value="confirmado">Confirmado</option>
                    <option value="cancelado">Cancelado</option>
                </select>
            </div>
        </div>

        @if($mensaje)
            <div class="p-4 bg-green-900/50 border border-green-700 rounded-lg text-green-200 text-sm">{{ $mensaje }}</div>
        @endif

        @if($error)
            <div class="p-4 bg-red-900/50 border border-red-700 rounded-lg text-red-200 text-sm">{{ $error }}</div>
        @endif

        @forelse($remitos as $remito)
            <div wire:key="remito-saliente-{{ $remito->id }}" class="bg-slate-800 border border-slate-700 rounded-xl overflow-hidden">
                <div class="flex flex-wrap items-center justify-between gap-4 px-6 py-4 border-b border-slate-700">
                    <div>
                        <div class="flex items-center gap-3">
                            <span class="text-lg font-bold text-white">Remito #{{ $remito->numero }}</span>
                            <span class="px-2 py-0.5 rounded-full text-xs font-semibold {{ $colorEstado($remito->estado) }}">{{ $labelEstado($remito->estado) }}</span>
                        </div>
                        <p class="text-sm text-slate-400 mt-1">
                            Hacia <span class="text-slate-200 font-medium">{{ $remito->destino_nombre }}</span>
                            · enviado {{ $remito->enviado_at->timezone(config('pos.zona_horaria'))->format('d/m/Y H:i') }}
                            @if($remito->confirmado_at)
                                · recibido {{ $remito->confirmado_at->timezone(config('pos.zona_horaria'))->format('d/m/Y H:i') }}
                            @endif
                            · {{ count($remito->items) }} artículo(s), {{ number_format($remito->total_unidades) }} unidades
                        </p>
                        @if($remito->observaciones)
                            <p class="text-sm text-slate-300 mt-1">📝 {{ $remito->observaciones }}</p>
                        @endif
                    </div>

                    @if($imprimeDirecto)
                        <button type="button" wire:click="imprimir({{ $remito->id }})"
                                class="px-4 py-2 rounded-lg text-sm font-medium bg-slate-700 hover:bg-slate-600 text-white transition-colors">
                            🖨 Imprimir
                        </button>
                    @else
                        <a href="{{ route('pos.remito.comprobante', $remito) }}" target="_blank"
                           class="px-4 py-2 rounded-lg text-sm font-medium bg-slate-700 hover:bg-slate-600 text-white transition-colors">
                            🖨 Imprimir
                        </a>
                    @endif
                </div>

                <table class="w-full text-sm">
                    <thead class="text-slate-400 text-xs uppercase">
                        <tr>
                            <th class="px-6 py-2 text-left font-medium">Artículo</th>
                            <th class="px-6 py-2 text-left font-medium">Código</th>
                            <th class="px-6 py-2 text-center font-medium">Cantidad</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-700">
                        @foreach($remito->items as $item)
                            <tr>
                                <td class="px-6 py-3 text-white">{{ $item['nombre'] }}</td>
                                <td class="px-6 py-3 text-slate-400 font-mono text-xs">{{ $item['codigo'] ?? '-' }}</td>
                                <td class="px-6 py-3 text-center text-slate-300">{{ number_format($item['cantidad']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @empty
            <div class="bg-slate-800 border border-slate-700 rounded-xl px-6 py-16 text-center">
                <p class="text-4xl mb-3">📤</p>
                <p class="text-slate-300 font-medium">
                    @if($sucursalId || $estado)
                        No hay remitos con esos filtros
                    @else
                        Todavía no enviaste ningún remito
                    @endif
                </p>
            </div>
        @endforelse

        <div class="px-1">{{ $remitos->links() }}</div>
    </div>
</div>
