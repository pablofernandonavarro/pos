<div class="h-full flex items-center justify-center bg-slate-900" x-data="{ redirect: false }" @sync-completed.window="setTimeout(() => { redirect = true; window.location.href = '{{ route('pos.productos') }}' }, 2000)">
    <div class="max-w-2xl w-full mx-auto p-8">
        <div class="bg-slate-800 rounded-xl shadow-2xl border border-slate-700 p-8">
            <!-- Header -->
            <div class="text-center mb-8">
                <div class="inline-flex items-center justify-center w-20 h-20 bg-blue-600 rounded-full mb-4">
                    @if($sincronizando)
                        <svg class="w-10 h-10 text-white animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                        </svg>
                    @elseif($completado && !$error)
                        <svg class="w-10 h-10 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </svg>
                    @else
                        <svg class="w-10 h-10 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    @endif
                </div>
                <h2 class="text-2xl font-bold text-white mb-2">
                    @if($sincronizando)
                        Sincronizando...
                    @elseif($completado && !$error)
                        Sincronización Completada
                    @else
                        Error en Sincronización
                    @endif
                </h2>
                <p class="text-slate-400">
                    @if($sincronizando)
                        Por favor espera mientras sincronizamos los datos
                    @elseif($completado && !$error)
                        Todos los datos se sincronizaron correctamente
                    @else
                        Ocurrió un error durante la sincronización
                    @endif
                </p>
            </div>

            <!-- Resultados -->
            @if($completado && !empty($resultados))
                <div class="space-y-4">
                    <!-- Productos -->
                    @if(isset($resultados['pull']['resultados']['productos']))
                        <div class="flex items-center justify-between p-4 bg-slate-900 rounded-lg border border-slate-700">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 bg-blue-600 rounded-lg flex items-center justify-center">
                                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                                    </svg>
                                </div>
                                <div>
                                    <div class="text-white font-medium">Productos</div>
                                    <div class="text-sm text-slate-400">Catálogo actualizado</div>
                                </div>
                            </div>
                            <div class="text-2xl font-bold text-blue-400">
                                {{ $resultados['pull']['resultados']['productos']['cantidad'] ?? 0 }}
                            </div>
                        </div>
                    @endif

                    <!-- Stock -->
                    @if(isset($resultados['pull']['resultados']['stock']))
                        <div class="flex items-center justify-between p-4 bg-slate-900 rounded-lg border border-slate-700">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 bg-green-600 rounded-lg flex items-center justify-center">
                                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4h13M3 8h9m-9 4h6m4 0l4-4m0 0l4 4m-4-4v12"></path>
                                    </svg>
                                </div>
                                <div>
                                    <div class="text-white font-medium">Stock</div>
                                    <div class="text-sm text-slate-400">Inventario sincronizado</div>
                                </div>
                            </div>
                            <div class="text-2xl font-bold text-green-400">
                                {{ $resultados['pull']['resultados']['stock']['cantidad'] ?? 0 }}
                            </div>
                        </div>
                    @endif

                    <!-- Precios -->
                    @if(isset($resultados['pull']['resultados']['precios']))
                        <div class="flex items-center justify-between p-4 bg-slate-900 rounded-lg border border-slate-700">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 bg-purple-600 rounded-lg flex items-center justify-center">
                                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                    </svg>
                                </div>
                                <div>
                                    <div class="text-white font-medium">Precios</div>
                                    <div class="text-sm text-slate-400">Listas de precios</div>
                                </div>
                            </div>
                            <div class="text-2xl font-bold text-purple-400">
                                {{ $resultados['pull']['resultados']['precios']['listas'] ?? 0 }}
                            </div>
                        </div>
                    @endif
                </div>

                <!-- Mensaje de redirección -->
                <div class="mt-6 text-center">
                    <p class="text-slate-400 text-sm">Redirigiendo a productos en 2 segundos...</p>
                </div>
            @endif

            <!-- Error -->
            @if($error)
                <div class="p-4 bg-red-900/50 border border-red-700 rounded-lg">
                    <p class="text-red-300">{{ $error }}</p>
                </div>
            @endif

            <!-- Loader -->
            @if($sincronizando)
                <div class="flex justify-center">
                    <div class="flex gap-2">
                        <div class="w-3 h-3 bg-blue-500 rounded-full animate-bounce" style="animation-delay: 0ms"></div>
                        <div class="w-3 h-3 bg-blue-500 rounded-full animate-bounce" style="animation-delay: 150ms"></div>
                        <div class="w-3 h-3 bg-blue-500 rounded-full animate-bounce" style="animation-delay: 300ms"></div>
                    </div>
                </div>
            @endif

            <!-- Botones de acción -->
            @if($completado || $error)
                <div class="mt-6 flex gap-3">
                    @if($error)
                        <button wire:click="sincronizar" class="flex-1 px-6 py-3 bg-blue-600 hover:bg-blue-500 text-white rounded-lg font-medium transition-colors">
                            Reintentar
                        </button>
                    @endif
                    <a href="{{ route('pos.productos') }}" class="flex-1 px-6 py-3 bg-slate-700 hover:bg-slate-600 text-white rounded-lg font-medium transition-colors text-center">
                        Ir a Productos
                    </a>
                </div>
            @endif
        </div>
    </div>
</div>
