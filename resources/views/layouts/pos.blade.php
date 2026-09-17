<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'POS' }} - {{ config('app.name') }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="antialiased bg-slate-900 text-white">
    <div class="flex flex-col h-screen">
        <!-- Header -->
        <header class="bg-slate-800 border-b border-slate-700 px-6 py-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-6">
                    <div class="flex items-center gap-2">
                        <svg class="w-8 h-8 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"></path>
                        </svg>
                        <h1 class="text-2xl font-bold text-white">POS System</h1>
                    </div>

                    @if(\App\Models\Configuracion::isConfigured())
                        <div class="text-sm text-slate-400">
                            <span class="font-semibold text-slate-300">{{ \App\Models\Configuracion::get('pdv_nombre') ?? 'POS' }}</span>
                            <span class="mx-2">•</span>
                            <span>{{ \App\Models\Configuracion::get('sucursal_nombre') ?? 'Sin configurar' }}</span>
                        </div>

                        <!-- Navegación -->
                        <nav class="flex items-center gap-2">
                            <a href="{{ route('pos.venta') }}" class="px-4 py-2 rounded-lg text-sm font-medium transition-colors {{ request()->routeIs('pos.venta') ? 'bg-blue-600 text-white' : 'text-slate-400 hover:bg-slate-700' }}">
                                Venta
                            </a>
                            <a href="{{ route('pos.ventas') }}" class="px-4 py-2 rounded-lg text-sm font-medium transition-colors {{ request()->routeIs('pos.ventas') ? 'bg-blue-600 text-white' : 'text-slate-400 hover:bg-slate-700' }}">
                                Ventas
                            </a>
                            <a href="{{ route('pos.caja') }}" class="px-4 py-2 rounded-lg text-sm font-medium transition-colors {{ request()->routeIs('pos.caja*') ? 'bg-blue-600 text-white' : 'text-slate-400 hover:bg-slate-700' }}">
                                Caja
                            </a>
                            <a href="{{ route('pos.productos') }}" class="px-4 py-2 rounded-lg text-sm font-medium transition-colors {{ request()->routeIs('pos.productos') ? 'bg-blue-600 text-white' : 'text-slate-400 hover:bg-slate-700' }}">
                                Productos
                            </a>
                            <a href="{{ route('pos.stock') }}" class="px-4 py-2 rounded-lg text-sm font-medium transition-colors {{ request()->routeIs('pos.stock') ? 'bg-blue-600 text-white' : 'text-slate-400 hover:bg-slate-700' }}">
                                Stock
                            </a>
                            <div x-data="{ open: false }" @click.away="open = false" class="relative">
                                <button @click="open = !open" type="button" class="px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center gap-1.5 {{ request()->routeIs('pos.remitos*') ? 'bg-blue-600 text-white' : 'text-slate-400 hover:bg-slate-700' }}">
                                    Remitos
                                    <svg class="w-3.5 h-3.5 transition-transform" :class="{ 'rotate-180': open }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                    </svg>
                                </button>

                                <div x-show="open" x-cloak @click="open = false"
                                     class="absolute left-0 top-full mt-1 w-52 bg-slate-800 border border-slate-700 rounded-lg shadow-lg py-1 z-50">
                                    <a href="{{ route('pos.remitos') }}" class="block px-4 py-2 text-sm transition-colors {{ request()->routeIs('pos.remitos') ? 'bg-blue-600 text-white' : 'text-slate-300 hover:bg-slate-700' }}">
                                        📥 Remitos por recibir
                                    </a>
                                    <a href="{{ route('pos.remitos.nuevo') }}" class="block px-4 py-2 text-sm transition-colors {{ request()->routeIs('pos.remitos.nuevo') ? 'bg-blue-600 text-white' : 'text-slate-300 hover:bg-slate-700' }}">
                                        ➕ Nuevo remito
                                    </a>
                                </div>
                            </div>
                        </nav>
                    @endif
                </div>

                <div class="flex items-center gap-4">
                    <!-- Estado real de sincronización -->
                    @if (\App\Models\Configuracion::isConfigured())
                        @livewire('pos.estado-sync')
                    @endif

                    <!-- Reloj -->
                    <div class="text-sm text-slate-400" x-data="{ time: '' }" x-init="setInterval(() => time = new Date().toLocaleTimeString('es-AR', { hour12: false }), 1000)" x-text="time"></div>

                    <!-- Menú de opciones -->
                    <div x-data="{ open: false }" class="relative">
                        <button @click="open = !open" class="p-2 rounded-lg hover:bg-slate-700 transition-colors">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z"></path>
                            </svg>
                        </button>

                        <div x-show="open" @click.away="open = false" x-cloak class="absolute right-0 mt-2 w-48 bg-slate-800 rounded-lg shadow-xl border border-slate-700 py-2 z-50">
                            <a href="{{ route('pos.sync') }}" class="block px-4 py-2 text-sm hover:bg-slate-700 transition-colors">
                                🔄 Sincronizar
                            </a>
                            <a href="{{ route('pos.ajustes') }}" class="block px-4 py-2 text-sm hover:bg-slate-700 transition-colors">
                                🖨 Impresora y ticket
                            </a>
                            <a href="{{ route('pos.configuracion') }}" class="block px-4 py-2 text-sm hover:bg-slate-700 transition-colors">
                                ⚙️ Configuración
                            </a>
                            <hr class="my-2 border-slate-700">
                            <div class="px-4 py-2 text-xs text-slate-500 border-t border-slate-700">
                                POS v{{ \App\Support\VersionPos::actual() }}
                            </div>
                            <hr class="my-2 border-slate-700">
                            <div x-data="{
                                confirmando: false,
                                bloqueado: false,
                                salir() {
                                    window.close();
                                    // Chrome solo deja cerrar pestañas abiertas por script. Si sigue viva
                                    // después de intentarlo, avisamos en vez de fallar en silencio.
                                    setTimeout(() => { this.confirmando = false; this.bloqueado = true; }, 300);
                                }
                            }">
                                <button type="button" x-show="!confirmando && !bloqueado" @click="confirmando = true"
                                        class="w-full text-left px-4 py-2 text-sm text-red-400 hover:bg-slate-700 transition-colors">
                                    🚪 Salir
                                </button>

                                <div x-show="confirmando" x-cloak class="px-4 py-2">
                                    <p class="text-xs text-slate-400 mb-2">¿Cerrar el POS?</p>
                                    <div class="flex gap-2">
                                        <button type="button" @click="salir()"
                                                class="flex-1 px-2 py-1 rounded text-xs font-medium bg-red-600 hover:bg-red-500 text-white transition-colors">
                                            Sí, salir
                                        </button>
                                        <button type="button" @click="confirmando = false"
                                                class="flex-1 px-2 py-1 rounded text-xs font-medium bg-slate-700 hover:bg-slate-600 text-slate-200 transition-colors">
                                            Cancelar
                                        </button>
                                    </div>
                                </div>

                                <p x-show="bloqueado" x-cloak class="px-4 py-2 text-xs text-amber-300 leading-snug">
                                    El navegador no permite cerrar esta pestaña desde la página. Cerrala con Ctrl+W.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <main class="flex-1 overflow-hidden">
            {{ $slot }}
        </main>
    </div>

    {{-- Livewire 4 ya incluye Alpine. No cargarlo aparte desde un CDN: duplica la instancia
         y mete una dependencia de internet en una app que tiene que andar offline. --}}
    @livewireScripts

    <style>
        [x-cloak] { display: none !important; }
    </style>
</body>
</html>
