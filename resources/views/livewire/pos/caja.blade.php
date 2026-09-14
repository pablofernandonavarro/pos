@php
    use App\Models\MovimientoCaja;
    use App\Models\PagoVenta;
    use App\Support\Dinero;
    $zona = config('pos.zona_horaria');
@endphp

<div class="h-full overflow-y-auto p-6">
    <div class="max-w-6xl mx-auto space-y-6">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h2 class="text-2xl font-bold text-white">Caja</h2>
                <p class="text-sm text-slate-400 mt-1">
                    @if($turno)
                        Turno #{{ $turno->numero }} · {{ $turno->cajero }} · abierto {{ $turno->abierto_at->timezone($zona)->format('d/m H:i') }}
                    @else
                        No hay caja abierta. Se abre desde la pantalla de Venta.
                    @endif
                </p>
            </div>
            @if($turno && !$cerrando)
                <div class="flex gap-3">
                    @if($imprimeDirecto)
                        <button type="button" wire:click="imprimirInforme({{ $turno->id }})"
                                class="px-4 py-2 rounded-lg text-sm font-medium bg-slate-700 hover:bg-slate-600 text-slate-200 transition-colors">
                            Imprimir informe X
                        </button>
                    @else
                        <a href="{{ route('pos.caja.informe', $turno) }}" target="_blank"
                           class="px-4 py-2 rounded-lg text-sm font-medium bg-slate-700 hover:bg-slate-600 text-slate-200 transition-colors">
                            Imprimir informe X
                        </a>
                    @endif
                    <button type="button" wire:click="iniciarCierre"
                            class="px-4 py-2 rounded-lg text-sm font-bold bg-red-600 hover:bg-red-500 text-white transition-colors">
                        Cerrar caja (Z)
                    </button>
                </div>
            @endif
        </div>

        @if($exito)
            <div class="p-4 bg-green-900/50 border border-green-700 rounded-lg text-green-200 flex items-center justify-between gap-4">
                <span>{{ $exito }}</span>
                @if($cerradoId)
                    @if($imprimeDirecto)
                        <button type="button" wire:click="imprimirInforme({{ $cerradoId }})" class="px-3 py-1.5 rounded bg-green-700 hover:bg-green-600 text-white text-sm font-semibold">Imprimir Z</button>
                    @else
                        <a href="{{ route('pos.caja.informe', $cerradoId) }}" target="_blank" class="px-3 py-1.5 rounded bg-green-700 hover:bg-green-600 text-white text-sm font-semibold">Imprimir Z</a>
                    @endif
                @endif
            </div>
        @endif

        @if($error)
            <div class="p-4 bg-red-900/50 border border-red-700 rounded-lg text-red-200">{{ $error }}</div>
        @endif

        @if($turno && $resumen)
            {{-- Indicadores --}}
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="bg-slate-800 border border-slate-700 rounded-xl p-4">
                    <div class="text-xs text-slate-400">Ventas</div>
                    <div class="text-2xl font-bold text-white">{{ $resumen['ventas']['cantidad'] }}</div>
                    <div class="text-xs text-slate-500">{{ $resumen['ventas']['unidades'] }} unidades</div>
                </div>
                <div class="bg-slate-800 border border-slate-700 rounded-xl p-4">
                    <div class="text-xs text-slate-400">Total cobrado</div>
                    <div class="text-2xl font-bold text-green-400">{{ Dinero::formato($resumen['ventas']['total']) }}</div>
                    <div class="text-xs text-slate-500">Ticket promedio {{ Dinero::formato($resumen['ventas']['ticket_promedio']) }}</div>
                </div>
                <div class="bg-slate-800 border border-slate-700 rounded-xl p-4">
                    <div class="text-xs text-slate-400">Descuentos · devoluciones</div>
                    <div class="text-2xl font-bold text-amber-300">{{ Dinero::formato($resumen['ventas']['descuentos']) }}</div>
                    <div class="text-xs text-slate-500">
                        Devuelto {{ Dinero::formato($resumen['devoluciones']['total']) }} · neto {{ Dinero::formato($resumen['ventas']['neto']) }}
                    </div>
                </div>
                <div class="bg-slate-800 border border-slate-700 rounded-xl p-4">
                    <div class="text-xs text-slate-400">Efectivo que debería haber</div>
                    <div class="text-2xl font-bold text-white">{{ Dinero::formato($resumen['efectivo']['esperado']) }}</div>
                </div>
            </div>

            @if($cerrando)
                {{-- Arqueo y cierre Z --}}
                <form wire:submit="cerrarCaja" class="bg-slate-800 border border-red-500/40 rounded-xl p-6 space-y-4">
                    <h3 class="text-lg font-bold text-white">Cierre Z · arqueo</h3>
                    <p class="text-sm text-slate-400">Contá el efectivo del cajón (billetes y monedas) y cargá el total. Después de cerrar, el turno no se puede modificar.</p>
                    <div class="grid md:grid-cols-3 gap-4 items-end">
                        <div>
                            <label class="block text-xs font-medium text-slate-400 mb-1">Efectivo contado</label>
                            <input type="number" step="0.01" min="0" wire:model.live.debounce.400ms="efectivoContado" autofocus
                                   class="w-full px-3 py-3 bg-slate-900 border border-slate-700 rounded-lg text-white text-xl focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div class="text-lg">
                            @if($diferenciaPrevia !== null)
                                @if($diferenciaPrevia == 0)
                                    <span class="text-green-400 font-bold">Sin diferencia</span>
                                @elseif($diferenciaPrevia < 0)
                                    <span class="text-red-400 font-bold">Faltan {{ Dinero::formato(abs($diferenciaPrevia)) }}</span>
                                @else
                                    <span class="text-amber-300 font-bold">Sobran {{ Dinero::formato($diferenciaPrevia) }}</span>
                                @endif
                            @endif
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-400 mb-1">Observaciones</label>
                            <input type="text" wire:model="observaciones" maxlength="1000" placeholder="Opcional"
                                   class="w-full px-3 py-3 bg-slate-900 border border-slate-700 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                    </div>
                    <div class="flex justify-end gap-3">
                        <button type="button" wire:click="cancelarCierre" class="px-4 py-2 text-slate-300 hover:text-white">Cancelar</button>
                        <button type="submit" wire:confirm="¿Cerrar la caja? El turno no se va a poder modificar." wire:loading.attr="disabled"
                                class="px-6 py-3 rounded-lg font-bold bg-red-600 hover:bg-red-500 text-white disabled:opacity-50">
                            Confirmar cierre Z
                        </button>
                    </div>
                </form>
            @endif

            <div class="grid lg:grid-cols-2 gap-6">
                {{-- Por medio de pago --}}
                <div class="bg-slate-800 border border-slate-700 rounded-xl p-5">
                    <h3 class="font-semibold text-white mb-3">Por medio de pago</h3>
                    <table class="w-full text-sm">
                        @forelse($resumen['por_medio'] as $medio => $importe)
                            <tr class="border-b border-slate-700/60">
                                <td class="py-2 text-slate-300">{{ PagoVenta::MEDIOS[$medio] ?? $medio }}</td>
                                <td class="py-2 text-right text-white font-medium">{{ Dinero::formato($importe) }}</td>
                            </tr>
                        @empty
                            <tr><td class="py-2 text-slate-500">Todavía no hay ventas en este turno.</td></tr>
                        @endforelse
                    </table>

                    @if(!empty($resumen['tarjetas']))
                        <h4 class="text-xs font-semibold text-slate-400 uppercase tracking-wide mt-4 mb-2">Tarjetas (para conciliar con el posnet)</h4>
                        <table class="w-full text-sm">
                            @foreach($resumen['tarjetas'] as $t)
                                <tr class="border-b border-slate-700/60">
                                    <td class="py-1.5 text-slate-300">{{ PagoVenta::MEDIOS[$t['medio']] }} · {{ PagoVenta::TARJETAS[$t['tarjeta']] ?? 'Sin especificar' }}@if($t['cuotas'] > 1) · {{ $t['cuotas'] }} cuotas @endif</td>
                                    <td class="py-1.5 text-right text-slate-400">×{{ $t['cantidad'] }}</td>
                                    <td class="py-1.5 text-right text-white">{{ Dinero::formato($t['importe']) }}</td>
                                </tr>
                            @endforeach
                        </table>
                    @endif

                    @if(!empty($resumen['promociones']))
                        <h4 class="text-xs font-semibold text-slate-400 uppercase tracking-wide mt-4 mb-2">Promociones aplicadas</h4>
                        <table class="w-full text-sm">
                            @foreach($resumen['promociones'] as $p)
                                <tr class="border-b border-slate-700/60">
                                    <td class="py-1.5 text-slate-300">{{ $p['nombre'] }}</td>
                                    <td class="py-1.5 text-right text-slate-400">×{{ $p['cantidad'] }}</td>
                                    <td class="py-1.5 text-right text-amber-300">−{{ Dinero::formato($p['descuento']) }}</td>
                                </tr>
                            @endforeach
                        </table>
                    @endif
                </div>

                {{-- Efectivo y movimientos --}}
                <div class="bg-slate-800 border border-slate-700 rounded-xl p-5 space-y-4">
                    <h3 class="font-semibold text-white">Efectivo</h3>
                    <table class="w-full text-sm">
                        <tr class="border-b border-slate-700/60"><td class="py-1.5 text-slate-300">Fondo inicial</td><td class="py-1.5 text-right text-white">{{ Dinero::formato($resumen['efectivo']['fondo_inicial']) }}</td></tr>
                        <tr class="border-b border-slate-700/60"><td class="py-1.5 text-slate-300">Ventas en efectivo</td><td class="py-1.5 text-right text-white">{{ Dinero::formato($resumen['efectivo']['ventas']) }}</td></tr>
                        @if(($resumen['efectivo']['cobros_cuenta_corriente'] ?? 0) > 0)
                            <tr class="border-b border-slate-700/60"><td class="py-1.5 text-slate-300">Cobros de cuenta corriente</td><td class="py-1.5 text-right text-white">{{ Dinero::formato($resumen['efectivo']['cobros_cuenta_corriente']) }}</td></tr>
                        @endif
                        <tr class="border-b border-slate-700/60"><td class="py-1.5 text-slate-300">Ingresos</td><td class="py-1.5 text-right text-white">{{ Dinero::formato($resumen['efectivo']['ingresos']) }}</td></tr>
                        <tr class="border-b border-slate-700/60"><td class="py-1.5 text-slate-300">Retiros</td><td class="py-1.5 text-right text-red-300">−{{ Dinero::formato($resumen['efectivo']['retiros']) }}</td></tr>
                        <tr class="border-b border-slate-700/60"><td class="py-1.5 text-slate-300">Gastos</td><td class="py-1.5 text-right text-red-300">−{{ Dinero::formato($resumen['efectivo']['gastos']) }}</td></tr>
                        @if(($resumen['efectivo']['devoluciones'] ?? 0) > 0)
                            <tr class="border-b border-slate-700/60"><td class="py-1.5 text-slate-300">Devoluciones en efectivo</td><td class="py-1.5 text-right text-red-300">−{{ Dinero::formato($resumen['efectivo']['devoluciones']) }}</td></tr>
                        @endif
                        <tr><td class="py-2 font-bold text-white">Debería haber</td><td class="py-2 text-right font-bold text-white">{{ Dinero::formato($resumen['efectivo']['esperado']) }}</td></tr>
                    </table>

                    <form wire:submit="registrarMovimiento" class="border-t border-slate-700 pt-4 space-y-3">
                        <p class="text-sm font-medium text-slate-300">Registrar movimiento de efectivo</p>
                        <div class="grid grid-cols-3 gap-2">
                            <select wire:model="movimientoTipo" class="px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-white text-sm">
                                @foreach(MovimientoCaja::TIPOS as $valor => $etiqueta)
                                    <option value="{{ $valor }}">{{ $etiqueta }}</option>
                                @endforeach
                            </select>
                            <input type="number" step="0.01" min="0" wire:model="movimientoMonto" placeholder="Monto"
                                   class="px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-white text-sm">
                            <button type="submit" class="px-3 py-2 rounded-lg bg-blue-600 hover:bg-blue-500 text-white text-sm font-semibold">Registrar</button>
                        </div>
                        <input type="text" wire:model="movimientoMotivo" maxlength="200" placeholder="Motivo (ej: depósito en banco, pago a proveedor, cambio)"
                               class="w-full px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-white text-sm">
                    </form>

                    @if($cajonHabilitado)
                        <form wire:submit="abrirCajon" class="border-t border-slate-700 pt-4 flex gap-2">
                            <input type="text" wire:model="motivoCajon" maxlength="200" placeholder="Motivo para abrir el cajón sin venta (ej: dar cambio)"
                                   class="flex-1 px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-white text-sm">
                            <button type="submit" class="px-3 py-2 rounded-lg bg-slate-700 hover:bg-slate-600 text-white text-sm font-semibold whitespace-nowrap">Abrir cajón</button>
                        </form>
                        @if(!empty($resumen['aperturas_cajon']))
                            <p class="text-xs text-slate-400">Aperturas sin venta en este turno: {{ count($resumen['aperturas_cajon']) }}</p>
                        @endif
                    @endif

                    @if(!empty($resumen['movimientos']))
                        <table class="w-full text-sm">
                            @foreach($resumen['movimientos'] as $m)
                                <tr class="border-b border-slate-700/60">
                                    <td class="py-1.5 text-slate-400">{{ \Illuminate\Support\Carbon::parse($m['fecha'])->timezone($zona)->format('H:i') }}</td>
                                    <td class="py-1.5 text-slate-300">{{ MovimientoCaja::TIPOS[$m['tipo']] }} · {{ $m['motivo'] }}</td>
                                    <td class="py-1.5 text-right {{ $m['tipo'] === 'ingreso' ? 'text-white' : 'text-red-300' }}">{{ $m['tipo'] === 'ingreso' ? '' : '−' }}{{ Dinero::formato($m['monto']) }}</td>
                                </tr>
                            @endforeach
                        </table>
                    @endif
                </div>
            </div>

            {{-- Cobro de cuenta corriente --}}
            <div class="bg-slate-800 border border-slate-700 rounded-xl p-5 space-y-4">
                <div class="flex items-center justify-between">
                    <h3 class="font-semibold text-white">Cobrar cuenta corriente</h3>
                    @if($ultimoCobroId)
                        <a href="{{ route('pos.cobro.recibo', $ultimoCobroId) }}" target="_blank" class="text-sm text-slate-300 hover:text-white">🖨 Imprimir último recibo</a>
                    @endif
                </div>

                @if($clienteCobro)
                    <div class="flex flex-wrap items-center justify-between gap-3 p-3 rounded-lg bg-slate-900 border border-slate-700">
                        <div>
                            <div class="text-white font-medium">{{ $clienteCobro->nombre }}</div>
                            <div class="text-xs text-slate-400">{{ $clienteCobro->documentoFormateado() }}</div>
                        </div>
                        <div class="text-right">
                            <div class="text-xs text-slate-400">Debe</div>
                            <div class="text-xl font-bold {{ $saldoCobro > 0 ? 'text-amber-300' : 'text-green-400' }}">{{ Dinero::formato($saldoCobro) }}</div>
                        </div>
                        <button type="button" wire:click="quitarClienteCobro" class="text-xs text-slate-400 hover:text-white">Cambiar</button>
                    </div>

                    @if($saldoCobro > 0)
                        <form wire:submit="cobrarCuenta" class="grid sm:grid-cols-4 gap-2">
                            <input type="number" step="0.01" min="0" wire:model="cobroMonto" placeholder="Importe"
                                   class="px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-white text-sm">
                            <select wire:model="cobroMedio" class="px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-white text-sm">
                                @foreach(\App\Models\CobroCuentaCorriente::MEDIOS as $medio)
                                    <option value="{{ $medio }}">{{ PagoVenta::MEDIOS[$medio] }}</option>
                                @endforeach
                            </select>
                            <button type="button" wire:click="$set('cobroMonto', '{{ $saldoCobro }}')" class="px-3 py-2 rounded-lg bg-slate-700 hover:bg-slate-600 text-white text-sm">Todo</button>
                            <button type="submit" class="px-3 py-2 rounded-lg bg-green-600 hover:bg-green-500 text-white text-sm font-semibold">Cobrar</button>
                        </form>
                    @endif
                @else
                    <div class="relative">
                        <input type="text" wire:model.live.debounce.300ms="clienteBusquedaCobro" placeholder="Buscar cliente por nombre, CUIT o DNI..."
                               class="w-full px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-white text-sm">
                        @if($resultadosCobro->isNotEmpty())
                            <div class="absolute z-10 mt-1 w-full bg-slate-900 border border-slate-700 rounded-lg overflow-hidden">
                                @foreach($resultadosCobro as $c)
                                    <button type="button" wire:click="elegirClienteCobro({{ $c->id }})" wire:key="cobro-cliente-{{ $c->id }}"
                                            class="w-full px-3 py-2 text-left text-sm text-slate-200 hover:bg-slate-700">
                                        {{ $c->nombre }} <span class="text-slate-500">{{ $c->documentoFormateado() }}</span>
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif

                @if(!empty($resumen['cuenta_corriente']['detalle']))
                    <table class="w-full text-sm">
                        @foreach($resumen['cuenta_corriente']['detalle'] as $cobro)
                            <tr class="border-b border-slate-700/60">
                                <td class="py-1.5 text-slate-400">{{ $cobro['numero'] }}</td>
                                <td class="py-1.5 text-slate-300">{{ $cobro['cliente'] }} · {{ PagoVenta::MEDIOS[$cobro['medio']] ?? $cobro['medio'] }}</td>
                                <td class="py-1.5 text-right text-white">{{ Dinero::formato($cobro['importe']) }}</td>
                            </tr>
                        @endforeach
                    </table>
                @endif
            </div>
        @endif

        {{-- Historial de cierres --}}
        <div class="bg-slate-800 border border-slate-700 rounded-xl overflow-hidden">
            <h3 class="font-semibold text-white px-5 py-4 border-b border-slate-700">Últimos cierres Z</h3>
            <table class="w-full text-sm">
                <thead class="text-xs text-slate-400 uppercase">
                    <tr>
                        <th class="px-5 py-2 text-left">Z</th>
                        <th class="px-5 py-2 text-left">Cajero</th>
                        <th class="px-5 py-2 text-left">Cierre</th>
                        <th class="px-5 py-2 text-right">Total</th>
                        <th class="px-5 py-2 text-right">Diferencia</th>
                        <th class="px-5 py-2 text-center">Manager</th>
                        <th class="px-5 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-700">
                    @forelse($cierres as $z)
                        @php $dif = (float) ($z->resumen['efectivo']['diferencia'] ?? 0); @endphp
                        <tr wire:key="z-{{ $z->id }}">
                            <td class="px-5 py-2 text-white">#{{ $z->numero }}</td>
                            <td class="px-5 py-2 text-slate-300">{{ $z->cajero }}</td>
                            <td class="px-5 py-2 text-slate-400">{{ $z->cerrado_at->timezone($zona)->format('d/m/Y H:i') }}</td>
                            <td class="px-5 py-2 text-right text-white">{{ Dinero::formato($z->resumen['ventas']['total'] ?? 0) }}</td>
                            <td class="px-5 py-2 text-right {{ $dif < 0 ? 'text-red-400' : ($dif > 0 ? 'text-amber-300' : 'text-green-400') }}">{{ Dinero::formato($dif) }}</td>
                            <td class="px-5 py-2 text-center">{{ $z->sincronizado ? '✔' : '⏳' }}</td>
                            <td class="px-5 py-2 text-right">
                                @if($imprimeDirecto)
                                    <button type="button" wire:click="imprimirInforme({{ $z->id }})" class="text-blue-400 hover:text-blue-300">Imprimir</button>
                                @else
                                    <a href="{{ route('pos.caja.informe', $z) }}" target="_blank" class="text-blue-400 hover:text-blue-300">Imprimir</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-5 py-6 text-center text-slate-500">Todavía no hay cierres.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
