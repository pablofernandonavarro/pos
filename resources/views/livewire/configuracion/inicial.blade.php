<div class="min-h-screen bg-gradient-to-br from-slate-900 via-blue-900 to-slate-900 flex items-center justify-center p-6">
    <div class="max-w-md w-full">
        <!-- Logo y Header -->
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-20 h-20 bg-blue-600 rounded-2xl mb-4 shadow-lg">
                <svg class="w-10 h-10 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"></path>
                </svg>
            </div>
            <h1 class="text-3xl font-bold text-white mb-2">Configuración Inicial</h1>
            <p class="text-slate-300">Configura tu Punto de Venta</p>
        </div>

        <!-- Card Principal -->
        <div class="bg-slate-800 rounded-2xl shadow-2xl border border-slate-700 overflow-hidden">
            @if(!$configurado)
                <!-- Formulario de Configuración -->
                <div class="p-8">
                    <form wire:submit="conectar" class="space-y-6">
                        <!-- URL del Manager -->
                        <div>
                            <label class="block text-sm font-medium text-slate-300 mb-2">
                                URL del Servidor Manager
                            </label>
                            <input
                                type="url"
                                wire:model="managerApiUrl"
                                placeholder="http://localhost:8000/api"
                                class="w-full px-4 py-3 bg-slate-900 border border-slate-700 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                required
                            >
                            @error('managerApiUrl')
                                <p class="mt-1 text-sm text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- ID del Punto de Venta -->
                        <div>
                            <label class="block text-sm font-medium text-slate-300 mb-2">
                                ID del Punto de Venta
                            </label>
                            <input
                                type="number"
                                wire:model="puntoDeVentaId"
                                placeholder="Ej: 1"
                                class="w-full px-4 py-3 bg-slate-900 border border-slate-700 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                required
                                min="1"
                            >
                            @error('puntoDeVentaId')
                                <p class="mt-1 text-sm text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Clave Secreta -->
                        <div>
                            <label class="block text-sm font-medium text-slate-300 mb-2">
                                Clave Secreta
                            </label>
                            <input
                                type="password"
                                wire:model="secret"
                                placeholder="Ingrese la clave secreta"
                                class="w-full px-4 py-3 bg-slate-900 border border-slate-700 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                required
                                minlength="8"
                            >
                            @error('secret')
                                <p class="mt-1 text-sm text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Mensajes -->
                        @if($error)
                            <div class="p-4 bg-red-900/50 border border-red-700 rounded-lg text-red-200 text-sm">
                                {{ $error }}
                            </div>
                        @endif

                        @if($mensaje)
                            <div class="p-4 bg-green-900/50 border border-green-700 rounded-lg text-green-200 text-sm">
                                {{ $mensaje }}
                            </div>
                        @endif

                        <!-- Botón Conectar -->
                        <button
                            type="submit"
                            wire:loading.attr="disabled"
                            class="w-full px-6 py-4 bg-gradient-to-r from-blue-600 to-blue-500 hover:from-blue-500 hover:to-blue-400 text-white rounded-xl font-bold text-lg transition-all transform hover:scale-105 shadow-lg disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none"
                        >
                            <span wire:loading.remove wire:target="conectar">
                                🔌 Conectar y Configurar
                            </span>
                            <span wire:loading wire:target="conectar">
                                <svg class="inline w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                Conectando...
                            </span>
                        </button>
                    </form>
                </div>
            @else
                <!-- Panel de Sincronización -->
                <div class="p-8">
                    <div class="text-center mb-6">
                        <div class="inline-flex items-center justify-center w-16 h-16 bg-green-600 rounded-full mb-4">
                            <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                        </div>
                        <h2 class="text-xl font-bold text-white mb-2">¡Configuración Exitosa!</h2>
                        <p class="text-slate-300 text-sm mb-4">
                            Conectado como: <span class="font-semibold">{{ \App\Models\Configuracion::get('pdv_nombre') }}</span>
                        </p>
                        <p class="text-slate-400 text-sm">
                            Sucursal: {{ \App\Models\Configuracion::get('sucursal_nombre') }}
                        </p>
                    </div>

                    <!-- Mensajes -->
                    @if($error)
                        <div class="mb-4 p-4 bg-red-900/50 border border-red-700 rounded-lg text-red-200 text-sm">
                            {{ $error }}
                        </div>
                    @endif

                    @if($mensaje)
                        <div class="mb-4 p-4 bg-green-900/50 border border-green-700 rounded-lg text-green-200 text-sm">
                            {{ $mensaje }}
                        </div>
                    @endif

                    <!-- Botón Sincronizar -->
                    <button
                        wire:click="sincronizar"
                        wire:loading.attr="disabled"
                        class="w-full px-6 py-4 bg-gradient-to-r from-green-600 to-green-500 hover:from-green-500 hover:to-green-400 text-white rounded-xl font-bold text-lg transition-all transform hover:scale-105 shadow-lg disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none"
                    >
                        <span wire:loading.remove wire:target="sincronizar">
                            🔄 Sincronizar Catálogo Inicial
                        </span>
                        <span wire:loading wire:target="sincronizar">
                            <svg class="inline w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            Sincronizando...
                        </span>
                    </button>

                    <a
                        href="{{ route('pos.venta') }}"
                        class="block mt-4 text-center text-slate-400 hover:text-white transition-colors text-sm"
                    >
                        Ir al POS →
                    </a>
                </div>
            @endif
        </div>

        <!-- Info adicional -->
        <div class="mt-6 text-center text-sm text-slate-400">
            <p>¿Necesitas ayuda? Contacta al administrador</p>
        </div>
    </div>

    <!-- Script para redirección automática -->
    <script>
        document.addEventListener('livewire:init', () => {
            Livewire.on('sync-completo', () => {
                setTimeout(() => {
                    window.location.href = '{{ route('pos.venta') }}';
                }, 2000);
            });
        });
    </script>
</div>
