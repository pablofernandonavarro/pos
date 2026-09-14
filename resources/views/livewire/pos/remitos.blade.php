<div class="h-full overflow-y-auto p-6">
    <div class="max-w-5xl mx-auto space-y-6">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h2 class="text-2xl font-bold text-white">Remitos por recibir</h2>
                <p class="text-sm text-slate-400 mt-1">
                    Mercadería enviada a {{ \App\Models\Configuracion::get('sucursal_nombre') }}. Al recibirla se suma al stock.
                </p>
            </div>
            <button type="button" wire:click="actualizar" wire:loading.attr="disabled" wire:target="actualizar"
                    class="px-4 py-2 rounded-lg text-sm font-medium bg-slate-700 hover:bg-slate-600 text-slate-200 transition-colors disabled:opacity-50">
                <span wire:loading.remove wire:target="actualizar">Buscar remitos nuevos</span>
                <span wire:loading wire:target="actualizar">Consultando…</span>
            </button>
        </div>

        @if($mensaje)
            <div class="p-4 bg-green-900/50 border border-green-700 rounded-lg text-green-200 text-sm">{{ $mensaje }}</div>
        @endif

        @if($error)
            <div class="p-4 bg-red-900/50 border border-red-700 rounded-lg text-red-200 text-sm">{{ $error }}</div>
        @endif

        @forelse($remitos as $remito)
            <div wire:key="remito-{{ $remito->id }}" class="bg-slate-800 border border-amber-500/40 rounded-xl overflow-hidden">
                <div class="flex flex-wrap items-center justify-between gap-4 px-6 py-4 border-b border-slate-700">
                    <div>
                        <div class="flex items-center gap-3">
                            <span class="text-lg font-bold text-white">Remito #{{ $remito->numero }}</span>
                            <span class="px-2 py-0.5 rounded-full text-xs font-semibold bg-amber-500/20 text-amber-300">En camino</span>
                        </div>
                        <p class="text-sm text-slate-400 mt-1">
                            Desde <span class="text-slate-200 font-medium">{{ $remito->origen }}</span>
                            @if($remito->remitido_at)
                                · enviado {{ $remito->remitido_at->timezone(config('pos.zona_horaria'))->format('d/m/Y H:i') }}
                            @endif
                            · {{ count($remito->items) }} artículo(s), {{ number_format($remito->total_unidades) }} unidades
                        </p>
                        @if($remito->observaciones)
                            <p class="text-sm text-slate-300 mt-1">📝 {{ $remito->observaciones }}</p>
                        @endif
                    </div>

                    <button type="button"
                            wire:click="recibir({{ $remito->id }})"
                            wire:confirm="¿Llegó la mercadería del remito #{{ $remito->numero }}? Se van a sumar {{ $remito->total_unidades }} unidades al stock."
                            wire:loading.attr="disabled"
                            wire:target="recibir({{ $remito->id }})"
                            class="px-5 py-3 rounded-xl text-sm font-bold bg-green-600 hover:bg-green-500 text-white transition-colors disabled:opacity-50">
                        <span wire:loading.remove wire:target="recibir({{ $remito->id }})">✔ Recibir mercadería</span>
                        <span wire:loading wire:target="recibir({{ $remito->id }})">Registrando…</span>
                    </button>
                </div>

                <table class="w-full text-sm">
                    <thead class="text-slate-400 text-xs uppercase">
                        <tr>
                            <th class="px-6 py-2 text-left font-medium">Artículo</th>
                            <th class="px-6 py-2 text-left font-medium">Código</th>
                            <th class="px-6 py-2 text-center font-medium">Llegan</th>
                            <th class="px-6 py-2 text-center font-medium">Stock actual</th>
                            <th class="px-6 py-2 text-center font-medium">Queda en</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-700">
                        @foreach($remito->items as $item)
                            @php $producto = $productos[$item['product_id']] ?? null; @endphp
                            <tr>
                                <td class="px-6 py-3 text-white">{{ $producto?->nombre ?? $item['nombre'] ?? 'Artículo '.$item['product_id'] }}</td>
                                <td class="px-6 py-3 text-slate-400 font-mono text-xs">{{ $item['codigo'] ?? '-' }}</td>
                                <td class="px-6 py-3 text-center font-bold text-amber-300">+{{ number_format($item['cantidad']) }}</td>
                                <td class="px-6 py-3 text-center text-slate-300">{{ $producto ? number_format($producto->stock) : '-' }}</td>
                                <td class="px-6 py-3 text-center font-semibold text-green-400">
                                    {{ $producto ? number_format($producto->stock + $item['cantidad']) : '-' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @empty
            <div class="bg-slate-800 border border-slate-700 rounded-xl px-6 py-16 text-center">
                <p class="text-4xl mb-3">📦</p>
                <p class="text-slate-300 font-medium">No hay mercadería en camino</p>
                <p class="text-sm text-slate-500 mt-1">Cuando el Manager te envíe un remito, aparece acá y en el aviso de arriba.</p>
            </div>
        @endforelse
    </div>
</div>
