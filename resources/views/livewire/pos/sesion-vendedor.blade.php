<div wire:poll.60s="$refresh"
     @click.window.throttle.30000ms="$wire.registrarActividad()"
     @keydown.window.throttle.30000ms="$wire.registrarActividad()">

    {{-- Indicador en la barra de navegación --}}
    <div x-data="{ open: false }" @click.away="open = false" class="relative text-sm">
        @if($vendedorActivoNombre)
            <button @click="open = !open" type="button" class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-slate-300 hover:bg-slate-700 transition-colors">
                <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span>
                {{ $vendedorActivoNombre }}
            </button>
            <div x-show="open" x-cloak class="absolute right-0 top-full mt-1 w-40 bg-slate-800 border border-slate-700 rounded-lg shadow-lg py-1 z-50">
                <button type="button" wire:click="logout" class="w-full text-left px-3 py-2 text-sm text-red-400 hover:bg-slate-700">
                    Cerrar sesión
                </button>
            </div>
        @endif
    </div>

    {{-- Bloqueo: sin vendedor identificado no se puede usar ninguna pantalla --}}
    @if($requiereLogin)
        <div class="fixed lg:absolute inset-0 z-40 bg-slate-900/95 flex items-center justify-center p-6">
            <form wire:submit="login" class="w-full max-w-sm bg-slate-800 border border-slate-700 rounded-2xl p-8 space-y-5 shadow-2xl">
                <div class="text-center">
                    <p class="text-4xl mb-2">👤</p>
                    <h2 class="text-2xl font-bold text-white">¿Quién sos?</h2>
                    <p class="text-sm text-slate-400 mt-1">Identificate para que las ventas queden a tu nombre.</p>
                </div>

                @if($error)
                    <p class="p-3 bg-red-900/50 border border-red-700 rounded-lg text-red-200 text-sm">{{ $error }}</p>
                @endif

                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-1.5">Vendedor</label>
                    <select wire:model="vendedorSelectId" autofocus
                            class="w-full px-3 py-2.5 bg-slate-900 border border-slate-700 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="">-- Elegí tu usuario --</option>
                        @foreach($cajeros as $c)
                            <option value="{{ $c->id }}">{{ $c->nombre }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-1.5">PIN</label>
                    <input type="password" wire:model="pin" inputmode="numeric" maxlength="6" placeholder="••••" autocomplete="off"
                           class="w-full px-3 py-2.5 bg-slate-900 border border-slate-700 rounded-lg text-white text-center text-2xl tracking-[0.5em] focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>

                <button type="submit" class="w-full px-6 py-3 bg-gradient-to-r from-blue-600 to-blue-500 hover:from-blue-500 hover:to-blue-400 text-white rounded-xl font-bold transition-all shadow-lg">
                    Ingresar
                </button>
            </form>
        </div>
    @endif
</div>
