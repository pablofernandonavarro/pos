@php
    use App\Models\PagoVenta;
    use App\Services\FacturacionService;
    use App\Support\Dinero;
@endphp

<div class="flex h-full relative"
     x-data
     @keydown.window.f2.prevent="$wire.abrirCobro()"
     @keydown.window.escape="$wire.cobrando && $wire.cancelarCobro()">

    {{-- ===================== Caja cerrada: apertura ===================== --}}
    @if(!$turno)
        <div class="absolute inset-0 z-40 bg-slate-900/95 flex items-center justify-center p-6">
            <form wire:submit="abrirCaja" class="w-full max-w-md bg-slate-800 border border-slate-700 rounded-2xl p-8 space-y-5 shadow-2xl">
                <div class="text-center">
                    <p class="text-4xl mb-2">🔒</p>
                    <h2 class="text-2xl font-bold text-white">La caja está cerrada</h2>
                    <p class="text-sm text-slate-400 mt-1">Abrila con el efectivo que hay en el cajón para empezar a vender.</p>
                </div>

                @if($cajeros->isNotEmpty())
                    <div class="grid grid-cols-5 gap-3">
                        <div class="col-span-3">
                            <label class="block text-sm font-medium text-slate-300 mb-1">Cajero</label>
                            <select wire:model="aperturaCajeroId" autofocus
                                    class="w-full px-4 py-3 bg-slate-900 border border-slate-700 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">— Elegí —</option>
                                @foreach($cajeros as $c)
                                    <option value="{{ $c->id }}">{{ $c->nombre }}{{ $c->rol === 'supervisor' ? ' (supervisor)' : '' }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-span-2">
                            <label class="block text-sm font-medium text-slate-300 mb-1">PIN</label>
                            <input type="password" wire:model="aperturaPin" inputmode="numeric" maxlength="6" autocomplete="off"
                                   class="w-full px-4 py-3 bg-slate-900 border border-slate-700 rounded-lg text-white tracking-widest focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                    </div>
                @else
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-1">Cajero</label>
                        <input type="text" wire:model="aperturaCajero" autofocus maxlength="100" placeholder="Nombre de quien abre"
                               class="w-full px-4 py-3 bg-slate-900 border border-slate-700 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <p class="mt-1 text-xs text-slate-500">Cuando se carguen cajeros en el Manager, la caja se va a abrir con PIN.</p>
                    </div>
                @endif
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-1">Fondo inicial (efectivo en caja)</label>
                    <input type="number" step="0.01" min="0" wire:model="aperturaFondo" placeholder="0,00"
                           class="w-full px-4 py-3 bg-slate-900 border border-slate-700 rounded-lg text-white text-xl focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>

                @if($error)
                    <p class="p-3 bg-red-900/50 border border-red-700 rounded-lg text-red-200 text-sm">{{ $error }}</p>
                @endif

                <button type="submit" wire:loading.attr="disabled"
                        class="w-full py-4 bg-green-600 hover:bg-green-500 text-white rounded-xl font-bold text-lg transition-colors disabled:opacity-50">
                    Abrir caja
                </button>
            </form>
        </div>
    @endif

    {{-- ===================== Panel izquierdo: búsqueda ===================== --}}
    <div class="flex-1 flex flex-col bg-slate-900 p-6">
        <div class="mb-6">
            <div class="relative">
                <input
                    type="text"
                    wire:model.live.debounce.300ms="busqueda"
                    wire:keydown.enter.prevent="confirmarBusqueda($event.target.value)"
                    placeholder="Buscar por nombre o código · Enter para agregar"
                    class="w-full px-4 py-4 pl-12 bg-slate-800 border border-slate-700 rounded-xl text-white placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent text-lg"
                    @disabled($cobrando || !$turno)
                    autofocus
                >
                <svg class="absolute left-4 top-1/2 transform -translate-y-1/2 w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                </svg>
            </div>

            @if(!empty($resultadosBusqueda))
                <div class="absolute z-30 mt-2 w-full max-w-2xl bg-slate-800 rounded-lg shadow-2xl border border-slate-700 max-h-96 overflow-y-auto">
                    @foreach($resultadosBusqueda as $resultado)
                        <button
                            wire:key="resultado-{{ $resultado['id'] }}"
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
                            <div class="text-xl font-bold text-green-400">{{ Dinero::formato($resultado['precio']) }}</div>
                        </button>
                    @endforeach
                </div>
            @endif
        </div>

        @if($turno)
            <div class="mb-4 flex items-center gap-3 text-sm text-slate-400">
                <span class="w-2 h-2 rounded-full bg-green-500"></span>
                Caja abierta · turno #{{ $turno->numero }} · {{ $turno->cajero }}
                <span class="ml-auto flex items-center gap-4">
                    @if($ultimaVentaId)
                        @if($imprimeDirecto)
                            <button type="button" wire:click="imprimirUltimoTicket" class="text-slate-300 hover:text-white">🖨 Reimprimir último ticket</button>
                        @else
                            <a href="{{ route('pos.ticket', $ultimaVentaId) }}" target="_blank" class="text-slate-300 hover:text-white">🖨 Imprimir último ticket</a>
                        @endif
                    @endif
                    <a href="{{ route('pos.caja') }}" class="text-blue-400 hover:text-blue-300">Caja y cierre →</a>
                </span>
            </div>
        @endif

        @if($exito)
            <div wire:key="exito-{{ md5($exito) }}" x-data x-init="setTimeout(() => $wire.set('exito', null), 6000)"
                 class="mb-4 p-4 bg-green-900/50 border border-green-600 rounded-xl text-green-100 text-lg font-semibold">
                ✔ {{ $exito }}
            </div>
        @endif

        @if($aviso)
            <div class="mb-4 p-4 bg-amber-900/40 border border-amber-600 rounded-xl text-amber-100">{{ $aviso }}</div>
        @endif

        @if($error && !$cobrando && $turno)
            <div class="mb-4 p-4 bg-red-900/50 border border-red-700 rounded-xl text-red-200">{{ $error }}</div>
        @endif

        @if(empty($carrito))
            <div class="flex-1 flex items-center justify-center">
                <div class="text-center text-slate-500">
                    <svg class="w-24 h-24 mx-auto mb-4 text-slate-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"></path>
                    </svg>
                    <p class="text-xl mb-2">Carrito vacío</p>
                    <p class="text-sm">Buscá o escaneá productos para empezar una venta</p>
                </div>
            </div>
        @endif
    </div>

    {{-- ===================== Panel derecho: carrito ===================== --}}
    <div class="w-[480px] bg-slate-800 border-l border-slate-700 flex flex-col">
        <div class="p-6 border-b border-slate-700">
            <h2 class="text-xl font-bold text-white mb-1">Carrito de venta</h2>
            <p class="text-sm text-slate-400">{{ count($carrito) }} producto(s)</p>
        </div>

        <div class="flex-1 overflow-y-auto p-6 space-y-4">
            @foreach($carrito as $index => $item)
                <div wire:key="item-{{ $item['product_id'] }}" class="bg-slate-900 rounded-lg p-4 border border-slate-700">
                    <div class="flex items-start justify-between mb-3">
                        <div class="flex-1">
                            <h3 class="text-white font-medium mb-1">{{ $item['nombre'] }}</h3>
                            <p class="text-sm text-slate-400">{{ $item['codigo'] }}</p>
                        </div>
                        <button wire:click="eliminarItem({{ $index }})" class="text-red-400 hover:text-red-300 transition-colors" title="Quitar">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                        </button>
                    </div>
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <button wire:click="decrementarCantidad({{ $index }})" class="w-8 h-8 flex items-center justify-center bg-slate-800 hover:bg-slate-700 rounded-lg transition-colors">−</button>
                            <span class="w-12 text-center text-white font-medium">{{ $item['cantidad'] }}</span>
                            <button wire:click="incrementarCantidad({{ $index }})" class="w-8 h-8 flex items-center justify-center bg-slate-800 hover:bg-slate-700 rounded-lg transition-colors">+</button>
                        </div>
                        <div class="text-right">
                            <div class="text-sm text-slate-400">{{ Dinero::formato($item['precio_unitario']) }} c/u</div>
                            <div class="text-lg font-bold text-green-400">{{ Dinero::formato($item['subtotal']) }}</div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        @if(!empty($carrito))
            <div class="p-6 border-t border-slate-700 space-y-4">
                @if($letraFactura)
                    <div class="flex items-center gap-2">
                        <span class="shrink-0 w-9 h-9 flex items-center justify-center rounded-lg bg-white text-slate-900 text-xl font-black" title="Tipo de factura">{{ $letraFactura }}</span>
                        <select wire:model.live="clienteCondicionIva"
                                class="flex-1 px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-white text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                            @foreach(FacturacionService::CONDICIONES_IVA as $codigo => $etiqueta)
                                <option value="{{ $codigo }}">{{ $etiqueta }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <div class="grid grid-cols-2 gap-2">
                    <input type="text" wire:model="clienteNombre" maxlength="150"
                           placeholder="{{ $letraFactura && $clienteCondicionIva !== '5' ? 'Razón social' : 'Cliente (opcional)' }}"
                           class="w-full px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-white placeholder-slate-500 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <input type="text" wire:model="clienteDocumento" maxlength="30" inputmode="numeric"
                           placeholder="{{ $letraFactura && $clienteCondicionIva !== '5' ? 'CUIT' : 'DNI / CUIT (opcional)' }}"
                           class="w-full px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-white placeholder-slate-500 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>

                <div class="flex justify-between text-2xl font-bold text-white pt-2 border-t border-slate-700">
                    <span>Total</span>
                    <span class="text-green-400">{{ Dinero::formato($total) }}</span>
                </div>

                <div class="flex gap-3">
                    <button wire:click="resetearVenta" wire:confirm="¿Vaciar el carrito?"
                            class="flex-1 px-6 py-4 bg-slate-900 hover:bg-slate-700 text-white rounded-xl font-medium transition-colors">
                        Cancelar
                    </button>
                    <button wire:click="abrirCobro"
                            class="flex-[2] px-6 py-4 bg-gradient-to-r from-blue-600 to-blue-500 hover:from-blue-500 hover:to-blue-400 text-white rounded-xl font-bold text-lg transition-all shadow-lg">
                        Cobrar <span class="text-sm font-normal opacity-75">(F2)</span>
                    </button>
                </div>
            </div>
        @endif
    </div>

    {{-- ===================== Modal de cobro ===================== --}}
    @if($cobrando)
        <div class="absolute inset-0 z-50 bg-black/70 flex items-center justify-center p-6">
            <div class="w-full max-w-4xl bg-slate-800 border border-slate-700 rounded-2xl shadow-2xl flex max-h-full overflow-hidden">

                {{-- Formulario del pago --}}
                <div class="flex-1 p-6 space-y-4 overflow-y-auto">
                    <div class="flex items-center justify-between">
                        <h2 class="text-xl font-bold text-white">Cobro</h2>
                        <span class="text-xs text-slate-500">Esc para volver</span>
                    </div>

                    {{-- Descuento manual --}}
                    <div x-data="{ abierto: false }" class="rounded-lg border border-slate-700">
                        @if($descuentoManualCentavos > 0)
                            <div class="flex items-center justify-between px-3 py-2 text-sm">
                                <span class="text-amber-300">
                                    Descuento manual −{{ Dinero::formato(Dinero::pesos($descuentoManualCentavos)) }}
                                    @if($descuentoAutorizadoPor) <span class="text-slate-400">· autorizó {{ $descuentoAutorizadoPor }}</span> @endif
                                </span>
                                @if(empty($pagos))
                                    <button type="button" wire:click="quitarDescuento" class="text-xs text-slate-400 hover:text-white">Quitar</button>
                                @endif
                            </div>
                        @else
                            <button type="button" @click="abierto = !abierto" class="w-full px-3 py-2 text-left text-sm text-slate-400 hover:text-white">
                                + Aplicar descuento <span class="text-xs">(hasta {{ rtrim(rtrim(number_format($limiteDescuento, 2, ',', ''), '0'), ',') }}% sin supervisor)</span>
                            </button>
                            <div x-show="abierto" x-cloak class="px-3 pb-3 grid grid-cols-6 gap-2 items-end">
                                <select wire:model="descuentoTipo" class="col-span-1 px-2 py-2 bg-slate-900 border border-slate-700 rounded-lg text-white text-sm">
                                    <option value="porcentaje">%</option>
                                    <option value="monto">$</option>
                                </select>
                                <input type="number" step="0.01" min="0" wire:model="descuentoValor" placeholder="Valor"
                                       class="col-span-1 px-2 py-2 bg-slate-900 border border-slate-700 rounded-lg text-white text-sm">
                                <select wire:model="descuentoSupervisorId" class="col-span-2 px-2 py-2 bg-slate-900 border border-slate-700 rounded-lg text-white text-sm">
                                    <option value="">Supervisor (si supera el límite)</option>
                                    @foreach($supervisores as $s)
                                        <option value="{{ $s->id }}">{{ $s->nombre }}</option>
                                    @endforeach
                                </select>
                                <input type="password" wire:model="descuentoPin" inputmode="numeric" maxlength="6" placeholder="PIN" autocomplete="off"
                                       class="col-span-1 px-2 py-2 bg-slate-900 border border-slate-700 rounded-lg text-white text-sm tracking-widest">
                                <button type="button" wire:click="aplicarDescuento" class="col-span-1 px-2 py-2 rounded-lg bg-amber-600 hover:bg-amber-500 text-white text-sm font-semibold">Aplicar</button>
                            </div>
                        @endif
                    </div>

                    <div class="grid grid-cols-5 gap-2">
                        @foreach(PagoVenta::MEDIOS as $medio => $etiqueta)
                            <button type="button" wire:click="elegirMedio('{{ $medio }}')" wire:key="medio-{{ $medio }}"
                                    class="px-2 py-3 rounded-lg text-sm font-semibold transition-colors {{ $pagoMedio === $medio ? 'bg-blue-600 text-white' : 'bg-slate-900 text-slate-300 hover:bg-slate-700' }}">
                                {{ $etiqueta }}
                            </button>
                        @endforeach
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-slate-400 mb-1">Monto a cubrir</label>
                            <input type="number" step="0.01" min="0" wire:model.live.debounce.400ms="pagoMonto"
                                   class="w-full px-3 py-3 bg-slate-900 border border-slate-700 rounded-lg text-white text-xl focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        @if($pagoMedio === 'efectivo')
                            <div>
                                <label class="block text-xs font-medium text-slate-400 mb-1">Recibe (para calcular vuelto)</label>
                                <input type="number" step="0.01" min="0" wire:model.live.debounce.400ms="pagoRecibido" placeholder="Opcional"
                                       class="w-full px-3 py-3 bg-slate-900 border border-slate-700 rounded-lg text-white text-xl focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>
                        @else
                            <div>
                                <label class="block text-xs font-medium text-slate-400 mb-1">Referencia / cupón (opcional)</label>
                                <input type="text" wire:model="pagoReferencia" maxlength="60"
                                       class="w-full px-3 py-3 bg-slate-900 border border-slate-700 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>
                        @endif
                    </div>

                    @if(in_array($pagoMedio, PagoVenta::CON_TARJETA, true) || $pagoMedio === 'qr')
                        <div class="grid grid-cols-3 gap-3">
                            @if(in_array($pagoMedio, PagoVenta::CON_TARJETA, true))
                                <div>
                                    <label class="block text-xs font-medium text-slate-400 mb-1">Tarjeta</label>
                                    <select wire:model.live="pagoTarjeta" class="w-full px-3 py-2.5 bg-slate-900 border border-slate-700 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                                        <option value="">—</option>
                                        @foreach(PagoVenta::TARJETAS as $valor => $etiqueta)
                                            <option value="{{ $valor }}">{{ $etiqueta }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endif
                            <div class="{{ $pagoMedio === 'qr' ? 'col-span-2' : '' }}">
                                <label class="block text-xs font-medium text-slate-400 mb-1">{{ $pagoMedio === 'qr' ? 'Banco / billetera' : 'Banco' }}</label>
                                <input type="text" wire:model.live.debounce.400ms="pagoBanco" list="bancos-conocidos" maxlength="80"
                                       class="w-full px-3 py-2.5 bg-slate-900 border border-slate-700 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <datalist id="bancos-conocidos">
                                    @foreach($bancosConocidos as $banco)
                                        <option value="{{ $banco }}">
                                    @endforeach
                                </datalist>
                            </div>
                            @if($pagoMedio === 'credito')
                                <div>
                                    <label class="block text-xs font-medium text-slate-400 mb-1">Cuotas</label>
                                    <input type="number" min="1" max="99" wire:model.live="pagoCuotas"
                                           class="w-full px-3 py-2.5 bg-slate-900 border border-slate-700 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>
                            @endif
                        </div>
                    @endif

                    @if($promocionesAplicables->isNotEmpty())
                        <div class="space-y-2">
                            <p class="text-xs font-semibold text-amber-300 uppercase tracking-wide">Promociones que aplican</p>
                            @foreach($promocionesAplicables as $item)
                                <label wire:key="promo-{{ $item['promo']->id }}"
                                       class="flex items-center gap-3 p-3 rounded-lg border cursor-pointer transition-colors {{ $pagoPromocionId === $item['promo']->id ? 'border-amber-400 bg-amber-500/10' : 'border-slate-700 hover:bg-slate-700/50' }}">
                                    <input type="radio" wire:model.live="pagoPromocionId" value="{{ $item['promo']->id }}" class="text-amber-500">
                                    <span class="flex-1">
                                        <span class="block text-white font-medium">{{ $item['promo']->nombre }}</span>
                                        <span class="block text-xs text-slate-400">{{ $item['promo']->beneficio() }}</span>
                                    </span>
                                    @if($item['descuento'] > 0)
                                        <span class="text-amber-300 font-bold">−{{ Dinero::formato(Dinero::pesos($item['descuento'])) }}</span>
                                    @endif
                                </label>
                            @endforeach
                            @if($pagoPromocionId)
                                <button type="button" wire:click="$set('pagoPromocionId', null)" class="text-xs text-slate-400 hover:text-white">Sin promoción</button>
                            @endif
                        </div>
                    @endif

                    @if($calculoPago)
                        <div class="p-3 bg-slate-900 rounded-lg text-sm text-slate-300 flex flex-wrap gap-x-4 gap-y-1">
                            <span>Cubre {{ Dinero::formato(Dinero::pesos($calculoPago['monto'])) }}</span>
                            @if($calculoPago['descuento'] > 0)
                                <span class="text-amber-300">Descuento −{{ Dinero::formato(Dinero::pesos($calculoPago['descuento'])) }}</span>
                            @endif
                            <span class="text-white font-semibold">Se cobra {{ Dinero::formato(Dinero::pesos($calculoPago['importe'])) }}</span>
                            @if($calculoPago['vuelto'] !== null)
                                <span class="text-green-400 font-semibold">Vuelto {{ Dinero::formato(Dinero::pesos($calculoPago['vuelto'])) }}</span>
                            @endif
                        </div>
                    @endif

                    @if($error)
                        <p class="p-3 bg-red-900/50 border border-red-700 rounded-lg text-red-200 text-sm">{{ $error }}</p>
                    @endif

                    <button type="button" wire:click="agregarPago" @disabled($falta <= 0)
                            class="w-full py-3 bg-slate-700 hover:bg-slate-600 text-white rounded-lg font-semibold transition-colors disabled:opacity-40">
                        + Agregar este pago y seguir (pago dividido)
                    </button>
                </div>

                {{-- Resumen del cobro --}}
                <div class="w-80 bg-slate-900 border-l border-slate-700 p-6 flex flex-col">
                    <div class="space-y-1 mb-4">
                        <div class="flex justify-between text-slate-400"><span>Total</span><span>{{ Dinero::formato($total) }}</span></div>
                        @if($descuentoManualCentavos > 0)
                            <div class="flex justify-between text-amber-300"><span>Descuento manual</span><span>−{{ Dinero::formato(Dinero::pesos($descuentoManualCentavos)) }}</span></div>
                        @endif
                        @if($descuentoTotal > 0)
                            <div class="flex justify-between text-amber-300"><span>Descuentos</span><span>−{{ Dinero::formato($descuentoTotal) }}</span></div>
                        @endif
                        <div class="flex justify-between text-2xl font-bold {{ $falta > 0 ? 'text-white' : 'text-green-400' }}">
                            <span>{{ $falta > 0 ? 'Falta' : 'Cubierto' }}</span>
                            <span>{{ Dinero::formato($falta) }}</span>
                        </div>
                    </div>

                    <div class="flex-1 space-y-2 overflow-y-auto">
                        @foreach($pagos as $i => $pago)
                            {{-- $pagos es una propiedad pública: se completa con defaults para que un
                                 valor alterado desde el navegador no rompa la pantalla. El registro
                                 igual lo recalcula todo VentaService. --}}
                            @php $c = array_merge(['medio' => 'efectivo', 'tarjeta' => null, 'cuotas' => null, 'importe' => 0, 'descuento' => 0, 'promocion_nombre' => null, 'vuelto' => null], (array) ($pago['calculo'] ?? [])); @endphp
                            <div wire:key="pago-{{ $i }}" class="p-3 bg-slate-800 rounded-lg border border-slate-700 text-sm">
                                <div class="flex justify-between items-start">
                                    <span class="text-white font-semibold">
                                        {{ PagoVenta::MEDIOS[$c['medio']] ?? $c['medio'] }}
                                        @if($c['tarjeta']) · {{ PagoVenta::TARJETAS[$c['tarjeta']] ?? $c['tarjeta'] }} @endif
                                        @if($c['cuotas'] && $c['cuotas'] > 1) · {{ $c['cuotas'] }}c @endif
                                    </span>
                                    <button type="button" wire:click="quitarPago({{ $i }})" class="text-slate-500 hover:text-red-400" title="Quitar">✕</button>
                                </div>
                                <div class="text-slate-300">{{ Dinero::formato(Dinero::pesos((int) $c['importe'])) }}
                                    @if($c['descuento'] > 0) <span class="text-amber-300 text-xs">(−{{ Dinero::formato(Dinero::pesos((int) $c['descuento'])) }})</span> @endif
                                </div>
                                @if($c['promocion_nombre']) <div class="text-xs text-amber-300">{{ $c['promocion_nombre'] }}</div> @endif
                                @if($c['vuelto']) <div class="text-xs text-green-400">Vuelto {{ Dinero::formato(Dinero::pesos((int) $c['vuelto'])) }}</div> @endif
                            </div>
                        @endforeach
                    </div>

                    <div class="pt-4 space-y-2">
                        @if($letraFactura)
                            <div class="text-sm text-slate-300">
                                Factura {{ $letraFactura }} · {{ FacturacionService::CONDICIONES_IVA[(int) $clienteCondicionIva] ?? '' }}
                                @if($clienteDocumento !== '') <span class="text-slate-500">· {{ $clienteDocumento }}</span> @endif
                            </div>
                        @endif
                        <button type="button" wire:click="finalizarVenta" wire:loading.attr="disabled" wire:target="finalizarVenta"
                                class="w-full py-4 bg-green-600 hover:bg-green-500 text-white rounded-xl font-bold text-lg transition-colors disabled:opacity-50">
                            <span wire:loading.remove wire:target="finalizarVenta">Finalizar venta</span>
                            <span wire:loading wire:target="finalizarVenta">{{ $letraFactura ? 'Registrando y facturando…' : 'Registrando…' }}</span>
                        </button>
                        <button type="button" wire:click="cancelarCobro" class="w-full py-2 text-slate-400 hover:text-white text-sm">Volver al carrito</button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
