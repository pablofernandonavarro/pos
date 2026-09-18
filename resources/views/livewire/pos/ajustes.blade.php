<div class="h-full overflow-y-auto p-6">
    <div class="max-w-3xl mx-auto space-y-6">
        <div>
            <h2 class="text-2xl font-bold text-white">Ajustes de esta caja</h2>
            <p class="text-sm text-slate-400 mt-1">Impresora de tickets y datos del comercio que salen impresos.</p>
        </div>

        @if($mensaje)
            <div class="p-4 bg-green-900/50 border border-green-700 rounded-lg text-green-200">{{ $mensaje }}</div>
        @endif
        @if($error)
            <div class="p-4 bg-red-900/50 border border-red-700 rounded-lg text-red-200">{{ $error }}</div>
        @endif

        <form wire:submit="guardar" class="space-y-6">
            <div class="bg-slate-800 border border-slate-700 rounded-xl p-6 space-y-4">
                <h3 class="font-semibold text-white">Impresora</h3>

                @if($imprimeDirecto)
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-1">Impresora de tickets</label>
                        <select wire:model="impresora" class="w-full px-3 py-2.5 bg-slate-900 border border-slate-700 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">— Elegí una —</option>
                            @foreach($impresorasDisponibles as $nombre)
                                <option value="{{ $nombre }}">{{ $nombre }}</option>
                            @endforeach
                            @if($impresora !== '' && !in_array($impresora, $impresorasDisponibles, true))
                                <option value="{{ $impresora }}">{{ $impresora }} (no detectada)</option>
                            @endif
                        </select>
                        <p class="mt-1 text-xs text-slate-500">Para la Epson TM-T20: en Windows instalá el driver de Epson (APD); en Mac agregala en Ajustes del Sistema → Impresoras. Después elegila acá.</p>
                    </div>

                    <label class="flex items-center gap-3 text-slate-300">
                        <input type="checkbox" wire:model="ticketAutomatico" class="rounded border-slate-600 bg-slate-900 text-blue-600">
                        Imprimir el ticket automáticamente al finalizar cada venta
                    </label>

                    <button type="button" wire:click="imprimirPrueba" @disabled($impresora === '')
                            class="px-4 py-2 rounded-lg text-sm font-medium bg-slate-700 hover:bg-slate-600 text-slate-200 transition-colors disabled:opacity-40">
                        Imprimir prueba
                    </button>
                @else
                    <p class="text-sm text-slate-400">
                        Esta instalación corre en el navegador: los tickets se imprimen con el diálogo de impresión.
                        La impresión directa y automática está en la app de escritorio.
                    </p>
                @endif
            </div>

            <div class="bg-slate-800 border border-slate-700 rounded-xl p-6 space-y-4">
                <h3 class="font-semibold text-white">Cajón de dinero</h3>
                @if($cajonDisponible)
                    <p class="text-sm text-slate-400">Conectado a la impresora de tickets (puerto DK de la TM-T20). Se abre por la impresora {{ $imprimeDirecto ? 'elegida arriba' : 'con este nombre' }}.</p>
                    @unless($imprimeDirecto)
                        <div>
                            <label class="block text-sm font-medium text-slate-300 mb-1">Nombre de la impresora en el sistema</label>
                            <input type="text" wire:model="impresora" maxlength="200" placeholder="EPSON TM-T20"
                                   class="w-full px-3 py-2.5 bg-slate-900 border border-slate-700 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                    @endunless
                    <label class="flex items-center gap-3 text-slate-300">
                        <input type="checkbox" wire:model.live="cajonHabilitado" class="rounded border-slate-600 bg-slate-900 text-blue-600">
                        Esta caja tiene cajón de dinero
                    </label>
                    @if($cajonHabilitado)
                        <label class="flex items-center gap-3 text-slate-300">
                            <input type="checkbox" wire:model="cajonAutomatico" class="rounded border-slate-600 bg-slate-900 text-blue-600">
                            Abrirlo solo al cobrar, dar vuelto o devolver en efectivo
                        </label>
                        <button type="button" wire:click="probarCajon" @disabled($impresora === '')
                                class="px-4 py-2 rounded-lg text-sm font-medium bg-slate-700 hover:bg-slate-600 text-slate-200 transition-colors disabled:opacity-40">
                            Probar cajón
                        </button>
                    @endif
                @else
                    <p class="text-sm text-slate-400">La apertura del cajón desde la caja funciona en Windows y en Mac.</p>
                @endif
            </div>

            <div class="bg-slate-800 border border-slate-700 rounded-xl p-6 space-y-4">
                <h3 class="font-semibold text-white">Encabezado y pie del ticket</h3>
                <div class="grid sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-1">Nombre del comercio</label>
                        <input type="text" wire:model="nombreComercio" maxlength="60" placeholder="Si queda vacío, el de la sucursal"
                               class="w-full px-3 py-2.5 bg-slate-900 border border-slate-700 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                        @error('nombreComercio') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-300 mb-1">CUIT</label>
                        <input type="text" wire:model="cuit" maxlength="13" placeholder="30-12345678-9"
                               class="w-full px-3 py-2.5 bg-slate-900 border border-slate-700 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                        @error('cuit') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-sm font-medium text-slate-300 mb-1">Dirección</label>
                        <input type="text" wire:model="direccion" maxlength="80"
                               class="w-full px-3 py-2.5 bg-slate-900 border border-slate-700 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-sm font-medium text-slate-300 mb-1">Pie del ticket</label>
                        <input type="text" wire:model="pie" maxlength="120" placeholder="Ej: Cambios dentro de los 30 días con ticket"
                               class="w-full px-3 py-2.5 bg-slate-900 border border-slate-700 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                </div>
            </div>

            <div class="bg-slate-800 border border-slate-700 rounded-xl p-6 space-y-4">
                <h3 class="font-semibold text-white">Descuentos</h3>
                <div class="max-w-xs">
                    <label class="block text-sm font-medium text-slate-300 mb-1">Descuento máximo sin supervisor (%)</label>
                    <input type="number" step="0.5" min="0" max="100" wire:model="limiteDescuento"
                           class="w-full px-3 py-2.5 bg-slate-900 border border-slate-700 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                    @error('limiteDescuento') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
                    <p class="mt-1 text-xs text-slate-500">0 = todo descuento manual lo autoriza un supervisor.</p>
                </div>
            </div>

            <div class="flex flex-wrap items-end justify-end gap-3">
                @if($supervisores->isNotEmpty())
                    <div>
                        <label class="block text-xs font-medium text-slate-400 mb-1">Autoriza</label>
                        <select wire:model="supervisorId" class="px-3 py-2.5 bg-slate-900 border border-slate-700 rounded-lg text-white text-sm">
                            <option value="">— Supervisor —</option>
                            @foreach($supervisores as $s)
                                <option value="{{ $s->id }}">{{ $s->nombre }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-400 mb-1">PIN</label>
                        <input type="password" wire:model="supervisorPin" inputmode="numeric" maxlength="6" autocomplete="off"
                               class="w-28 px-3 py-2.5 bg-slate-900 border border-slate-700 rounded-lg text-white text-sm tracking-widest">
                    </div>
                @endif
                <button type="submit" class="px-6 py-3 rounded-lg font-bold bg-blue-600 hover:bg-blue-500 text-white">Guardar ajustes</button>
            </div>
        </form>
    </div>
</div>
