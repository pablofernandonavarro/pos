<div wire:poll.15s="contarPendientes" class="flex items-center gap-3">
    @if ($remitosPorRecibir > 0)
        <a href="{{ route('pos.remitos') }}"
           class="flex items-center gap-2 px-3 py-1 rounded-md bg-amber-500/20 border border-amber-500/50 text-amber-300 text-sm font-semibold hover:bg-amber-500/30 transition-colors">
            <span class="animate-pulse">📦</span>
            {{ $remitosPorRecibir }} {{ $remitosPorRecibir === 1 ? 'remito por recibir' : 'remitos por recibir' }}
        </a>
    @endif

    @if ($sinConexionDesde)
        {{-- La caja sigue vendiendo: esto es informativo, no un error que bloquee. --}}
        <span class="flex items-center gap-2" title="Las ventas se guardan en esta caja y se envían solas cuando vuelva la conexión">
            <span class="w-2 h-2 rounded-full bg-red-500 animate-pulse"></span>
            <span class="text-sm text-red-300">
                Sin conexión desde {{ $sinConexionDesde }}@if ($pendientes > 0) · {{ $pendientes }} por enviar @endif
            </span>
        </span>
    @elseif ($error)
        <span class="flex items-center gap-2" title="{{ $error }}">
            <span class="w-2 h-2 rounded-full bg-red-500"></span>
            <span class="text-sm text-red-400">Sin conexión</span>
        </span>
    @elseif ($pendientes > 0)
        <span class="flex items-center gap-2">
            <span class="w-2 h-2 rounded-full bg-amber-400 animate-pulse"></span>
            <span class="text-sm text-amber-300">
                {{ $pendientes }} {{ $pendientes === 1 ? 'pendiente' : 'pendientes' }}
            </span>
        </span>
    @else
        <span class="flex items-center gap-2">
            <span class="w-2 h-2 rounded-full bg-green-500"></span>
            <span class="text-sm text-slate-400">Todo sincronizado</span>
        </span>
    @endif

    @if ($pendientes > 0 || $error)
        <button
            type="button"
            wire:click="sincronizarAhora"
            wire:loading.attr="disabled"
            wire:target="sincronizarAhora"
            class="px-3 py-1 rounded-md text-xs font-medium bg-slate-700 hover:bg-slate-600 text-slate-200 transition-colors disabled:opacity-50"
        >
            <span wire:loading.remove wire:target="sincronizarAhora">Sincronizar</span>
            <span wire:loading wire:target="sincronizarAhora">Enviando…</span>
        </button>
    @endif
</div>
