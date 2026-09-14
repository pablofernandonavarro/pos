@php
    use App\Models\Devolucion;
    use App\Models\PagoVenta;
    use App\Support\Dinero;
    $zona = config('pos.zona_horaria');
@endphp

<div class="h-full overflow-y-auto p-6">
    <div class="max-w-6xl mx-auto space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h2 class="text-2xl font-bold text-white">Ventas</h2>
                <p class="text-sm text-slate-400 mt-1">Consultá, reimprimí tickets y registrá devoluciones o anulaciones.</p>
            </div>
            <div class="flex gap-3">
                <select wire:model.live="alcance" class="px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm">
                    <option value="turno">Turno actual</option>
                    <option value="hoy">Hoy</option>
                    <option value="7dias">Últimos 7 días</option>
                    <option value="todas">Todas</option>
                </select>
                <input type="text" wire:model.live.debounce.400ms="buscar" placeholder="N° de venta, cliente o DNI"
                       class="px-3 py-2 bg-slate-800 border border-slate-700 rounded-lg text-white text-sm">
            </div>
        </div>

        @if($mensaje)
            <div class="p-4 bg-green-900/50 border border-green-700 rounded-lg text-green-200 flex items-center justify-between gap-4">
                <span>{{ $mensaje }}</span>
                @if($ultimaDevolucionId)
                    @if($imprimeDirecto)
                        <button type="button" wire:click="imprimirDevolucion({{ $ultimaDevolucionId }})" class="px-3 py-1.5 rounded bg-green-700 hover:bg-green-600 text-white text-sm font-semibold">Imprimir comprobante</button>
                    @else
                        <a href="{{ route('pos.devolucion.comprobante', $ultimaDevolucionId) }}" target="_blank" class="px-3 py-1.5 rounded bg-green-700 hover:bg-green-600 text-white text-sm font-semibold">Imprimir comprobante</a>
                    @endif
                @endif
            </div>
        @endif
        @if($error && !$detalle)
            <div class="p-4 bg-red-900/50 border border-red-700 rounded-lg text-red-200">{{ $error }}</div>
        @endif

        <div class="bg-slate-800 border border-slate-700 rounded-xl overflow-hidden">
            <table class="w-full text-sm">
                <thead class="text-xs text-slate-400 uppercase border-b border-slate-700">
                    <tr>
                        <th class="px-5 py-3 text-left">Venta</th>
                        <th class="px-5 py-3 text-left">Fecha</th>
                        <th class="px-5 py-3 text-left">Cajero</th>
                        <th class="px-5 py-3 text-left">Cobro</th>
                        <th class="px-5 py-3 text-right">Total</th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-700">
                    @forelse($ventas as $v)
                        <tr wire:key="venta-{{ $v->id }}" class="hover:bg-slate-700/40">
                            <td class="px-5 py-3 text-white font-medium">
                                {{ $v->numero_venta }}
                                @if($v->devuelto > 0)
                                    <span class="ml-1 px-1.5 py-0.5 rounded text-xs {{ Dinero::centavos($v->devuelto) >= Dinero::centavos($v->total) ? 'bg-red-500/20 text-red-300' : 'bg-amber-500/20 text-amber-300' }}">
                                        {{ Dinero::centavos($v->devuelto) >= Dinero::centavos($v->total) ? 'Anulada' : 'Con devolución' }}
                                    </span>
                                @endif
                                @if($v->facturaAutorizada())
                                    <span class="ml-1 px-1.5 py-0.5 rounded text-xs bg-green-500/20 text-green-300" title="CAE {{ $v->comprobante['cae'] }}">{{ $v->comprobante['letra'] }} {{ $v->comprobante['numero'] }}</span>
                                @elseif($v->comprobante_estado === 'pendiente')
                                    <span class="ml-1 px-1.5 py-0.5 rounded text-xs bg-amber-500/20 text-amber-300">Factura pendiente</span>
                                @elseif($v->comprobante_estado === 'rechazado')
                                    <span class="ml-1 px-1.5 py-0.5 rounded text-xs bg-red-500/20 text-red-300">Factura rechazada</span>
                                @endif
                                @if($v->cliente_nombre)<div class="text-xs text-slate-400">{{ $v->cliente_nombre }}</div>@endif
                            </td>
                            <td class="px-5 py-3 text-slate-300">{{ $v->fecha->timezone($zona)->format('d/m H:i') }}</td>
                            <td class="px-5 py-3 text-slate-300">{{ $v->cajero ?? '—' }}</td>
                            <td class="px-5 py-3 text-slate-400">{{ $v->pagos->map(fn ($p) => PagoVenta::MEDIOS[$p->medio] ?? $p->medio)->unique()->implode(' + ') ?: '—' }}</td>
                            <td class="px-5 py-3 text-right text-white">{{ Dinero::formato($v->total) }}</td>
                            <td class="px-5 py-3 text-right whitespace-nowrap">
                                <button type="button" wire:click="verDetalle({{ $v->id }})" class="text-blue-400 hover:text-blue-300">Ver</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-5 py-10 text-center text-slate-500">No hay ventas con este filtro.</td></tr>
                    @endforelse
                </tbody>
            </table>
            @if($ventas->hasPages())
                <div class="px-5 py-3 border-t border-slate-700">{{ $ventas->links() }}</div>
            @endif
        </div>
    </div>

    @if($detalle)
        <div class="fixed inset-0 z-50 bg-black/70 flex items-start justify-center p-6 overflow-y-auto" wire:click.self="cerrarDetalle">
            <div class="w-full max-w-3xl bg-slate-800 border border-slate-700 rounded-2xl shadow-2xl">
                <div class="flex items-center justify-between px-6 py-4 border-b border-slate-700">
                    <div>
                        <h3 class="text-lg font-bold text-white">Venta {{ $detalle->numero_venta }}</h3>
                        <p class="text-sm text-slate-400">{{ $detalle->fecha->timezone($zona)->format('d/m/Y H:i') }} · {{ $detalle->cajero }}</p>
                    </div>
                    <button type="button" wire:click="cerrarDetalle" class="text-slate-400 hover:text-white text-xl">✕</button>
                </div>

                <div class="px-6 py-5 space-y-5">
                    @if($error)
                        <div class="p-3 bg-red-900/50 border border-red-700 rounded-lg text-red-200 text-sm">{{ $error }}</div>
                    @endif

                    @if($detalle->facturar)
                        <div class="p-3 rounded-lg border text-sm flex flex-wrap items-center justify-between gap-2
                                    {{ $detalle->facturaAutorizada() ? 'border-green-700 bg-green-900/20 text-green-200' : ($detalle->comprobante_estado === 'rechazado' ? 'border-red-700 bg-red-900/20 text-red-200' : 'border-amber-700 bg-amber-900/20 text-amber-200') }}">
                            <span>
                                @if($detalle->facturaAutorizada())
                                    {{ $detalle->comprobante['nombre_tipo'] }} {{ $detalle->comprobante['numero'] }} · CAE {{ $detalle->comprobante['cae'] }}
                                @elseif($detalle->comprobante_estado === 'rechazado')
                                    Factura rechazada: {{ $detalle->comprobante['error'] ?? 'sin detalle' }}
                                @else
                                    Factura pendiente de CAE @if($detalle->comprobante['error'] ?? null)· {{ $detalle->comprobante['error'] }}@endif
                                @endif
                            </span>
                            @if($detalle->comprobante_estado === 'pendiente')
                                <button type="button" wire:click="facturar({{ $detalle->id }})" wire:loading.attr="disabled" wire:target="facturar"
                                        class="px-3 py-1 rounded bg-amber-600 hover:bg-amber-500 text-white text-xs font-semibold disabled:opacity-50">
                                    <span wire:loading.remove wire:target="facturar">Pedir factura ahora</span>
                                    <span wire:loading wire:target="facturar">Consultando…</span>
                                </button>
                            @endif
                        </div>
                    @endif

                    <table class="w-full text-sm">
                        <thead class="text-xs text-slate-400 uppercase">
                            <tr>
                                <th class="py-2 text-left">Artículo</th>
                                <th class="py-2 text-center">Vendidas</th>
                                <th class="py-2 text-center">Devueltas</th>
                                <th class="py-2 text-right">Importe</th>
                                @if($devolviendo)<th class="py-2 text-center w-28">Devolver</th>@endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-700">
                            @foreach($detalle->detalles as $linea)
                                @php $devueltas = (int) $linea->devoluciones->sum('cantidad'); @endphp
                                <tr wire:key="linea-{{ $linea->id }}">
                                    <td class="py-2 text-white">{{ $linea->producto?->nombre ?? 'Artículo '.$linea->product_id }}</td>
                                    <td class="py-2 text-center text-slate-300">{{ $linea->cantidad }}</td>
                                    <td class="py-2 text-center {{ $devueltas > 0 ? 'text-amber-300' : 'text-slate-500' }}">{{ $devueltas }}</td>
                                    <td class="py-2 text-right text-slate-300">{{ Dinero::formato($linea->subtotal) }}</td>
                                    @if($devolviendo)
                                        <td class="py-2 text-center">
                                            <input type="number" min="0" max="{{ $linea->cantidad - $devueltas }}" wire:model.live.debounce.300ms="cantidades.{{ $linea->id }}" @disabled($linea->cantidad - $devueltas === 0)
                                                   class="w-20 px-2 py-1 bg-slate-900 border border-slate-700 rounded text-white text-center disabled:opacity-40">
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    <div class="grid sm:grid-cols-2 gap-4 text-sm">
                        <div class="space-y-1">
                            @foreach($detalle->pagos as $pago)
                                <div class="flex justify-between text-slate-300">
                                    <span>{{ PagoVenta::MEDIOS[$pago->medio] ?? $pago->medio }} @if($pago->tarjeta)· {{ PagoVenta::TARJETAS[$pago->tarjeta] ?? $pago->tarjeta }}@endif @if($pago->promocion_nombre)<span class="text-amber-300 text-xs">({{ $pago->promocion_nombre }})</span>@endif</span>
                                    <span>{{ Dinero::formato($pago->importe) }}</span>
                                </div>
                            @endforeach
                        </div>
                        <div class="space-y-1">
                            @if((float) $detalle->descuento > 0)
                                <div class="flex justify-between text-amber-300"><span>Descuentos</span><span>−{{ Dinero::formato($detalle->descuento) }}</span></div>
                            @endif
                            <div class="flex justify-between text-white font-bold text-lg"><span>Total</span><span>{{ Dinero::formato($detalle->total) }}</span></div>
                            @foreach($detalle->devoluciones as $dev)
                                <div class="flex justify-between text-red-300">
                                    <span>{{ $dev->numero }} · {{ $dev->motivo }}</span>
                                    <span>−{{ Dinero::formato($dev->total) }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    @if($devolviendo)
                        <div class="border-t border-slate-700 pt-4 space-y-3">
                            <div class="flex items-center justify-between">
                                <h4 class="font-semibold text-white">Devolución</h4>
                                <button type="button" wire:click="devolverTodo" class="text-xs text-slate-400 hover:text-white">Marcar todo (anular)</button>
                            </div>
                            @php
                                $aReintegrar = 0;
                                foreach ($detalle->detalles as $linea) {
                                    $aReintegrar += $devolucionesService->importeUnitarioCentavos($linea, $detalle) * max(0, (int) ($cantidades[$linea->id] ?? 0));
                                }
                            @endphp
                            <div class="grid sm:grid-cols-2 gap-3">
                                <input type="text" wire:model="motivo" maxlength="200" placeholder="Motivo (ej: talle equivocado, falla)"
                                       class="px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-white text-sm">
                                <select wire:model="reintegro" class="px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-white text-sm">
                                    @foreach(Devolucion::REINTEGROS as $valor => $etiqueta)
                                        <option value="{{ $valor }}">{{ $etiqueta }}</option>
                                    @endforeach
                                </select>
                                <select wire:model="supervisorId" class="px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-white text-sm">
                                    <option value="">— Supervisor que autoriza —</option>
                                    @foreach($supervisores as $s)
                                        <option value="{{ $s->id }}">{{ $s->nombre }}</option>
                                    @endforeach
                                </select>
                                <input type="password" wire:model="supervisorPin" inputmode="numeric" maxlength="6" placeholder="PIN del supervisor" autocomplete="off"
                                       class="px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-white text-sm tracking-widest">
                            </div>
                            @if($supervisores->isEmpty())
                                <p class="text-xs text-amber-300">No hay supervisores cargados. Se crean en el Manager → Cajeros.</p>
                            @endif
                            <div class="flex items-center justify-between">
                                <span class="text-slate-300">A reintegrar (aprox.): <strong class="text-white">{{ Dinero::formato(Dinero::pesos((int) round($aReintegrar))) }}</strong></span>
                                <div class="flex gap-3">
                                    <button type="button" wire:click="$set('devolviendo', false)" class="px-4 py-2 text-slate-300 hover:text-white">Cancelar</button>
                                    <button type="button" wire:click="registrarDevolucion" wire:loading.attr="disabled"
                                            class="px-5 py-2 rounded-lg bg-red-600 hover:bg-red-500 text-white font-bold disabled:opacity-50">Confirmar devolución</button>
                                </div>
                            </div>
                        </div>
                    @else
                        <div class="flex justify-end gap-3 border-t border-slate-700 pt-4">
                            @if($imprimeDirecto)
                                <button type="button" wire:click="imprimirTicket({{ $detalle->id }})" class="px-4 py-2 rounded-lg bg-slate-700 hover:bg-slate-600 text-white text-sm">🖨 Reimprimir {{ $detalle->facturaAutorizada() ? 'factura' : 'ticket' }}</button>
                            @else
                                <a href="{{ route('pos.ticket', $detalle) }}" target="_blank" class="px-4 py-2 rounded-lg bg-slate-700 hover:bg-slate-600 text-white text-sm">🖨 Imprimir {{ $detalle->facturaAutorizada() ? 'factura' : 'ticket' }}</a>
                            @endif
                            @if(Dinero::centavos($detalle->devoluciones->sum('total')) < Dinero::centavos($detalle->total))
                                <button type="button" wire:click="iniciarDevolucion" class="px-4 py-2 rounded-lg bg-red-600/80 hover:bg-red-600 text-white text-sm font-semibold">Devolver / anular</button>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
