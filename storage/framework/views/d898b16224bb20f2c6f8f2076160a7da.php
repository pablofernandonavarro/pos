<!DOCTYPE html>
<html lang="<?php echo e(str_replace('_', '-', app()->getLocale())); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($title ?? 'POS'); ?> - <?php echo e(config('app.name')); ?></title>

    <?php echo app('Illuminate\Foundation\Vite')(['resources/css/app.css', 'resources/js/app.js']); ?>
    <?php echo \Livewire\Mechanisms\FrontendAssets\FrontendAssets::styles(); ?>

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

                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(\App\Models\Configuracion::isConfigured()): ?>
                        <div class="text-sm text-slate-400">
                            <span class="font-semibold text-slate-300"><?php echo e(\App\Models\Configuracion::get('pdv_nombre') ?? 'POS'); ?></span>
                            <span class="mx-2">•</span>
                            <span><?php echo e(\App\Models\Configuracion::get('sucursal_nombre') ?? 'Sin configurar'); ?></span>
                        </div>

                        <!-- Navegación -->
                        <nav class="flex items-center gap-2">
                            <a href="<?php echo e(route('pos.venta')); ?>" class="px-4 py-2 rounded-lg text-sm font-medium transition-colors <?php echo e(request()->routeIs('pos.venta') ? 'bg-blue-600 text-white' : 'text-slate-400 hover:bg-slate-700'); ?>">
                                Venta
                            </a>
                            <a href="<?php echo e(route('pos.productos')); ?>" class="px-4 py-2 rounded-lg text-sm font-medium transition-colors <?php echo e(request()->routeIs('pos.productos') ? 'bg-blue-600 text-white' : 'text-slate-400 hover:bg-slate-700'); ?>">
                                Productos
                            </a>
                            <a href="<?php echo e(route('pos.stock')); ?>" class="px-4 py-2 rounded-lg text-sm font-medium transition-colors <?php echo e(request()->routeIs('pos.stock') ? 'bg-blue-600 text-white' : 'text-slate-400 hover:bg-slate-700'); ?>">
                                Stock
                            </a>
                        </nav>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>

                <div class="flex items-center gap-4">
                    <!-- Status de conexión -->
                    <div class="flex items-center gap-2">
                        <div class="w-2 h-2 rounded-full bg-green-500 animate-pulse"></div>
                        <span class="text-sm text-slate-400">En línea</span>
                    </div>

                    <!-- Reloj -->
                    <div class="text-sm text-slate-400" x-data="{ time: '' }" x-init="setInterval(() => time = new Date().toLocaleTimeString('es-AR'), 1000)" x-text="time"></div>

                    <!-- Menú de opciones -->
                    <div x-data="{ open: false }" class="relative">
                        <button @click="open = !open" class="p-2 rounded-lg hover:bg-slate-700 transition-colors">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z"></path>
                            </svg>
                        </button>

                        <div x-show="open" @click.away="open = false" x-cloak class="absolute right-0 mt-2 w-48 bg-slate-800 rounded-lg shadow-xl border border-slate-700 py-2 z-50">
                            <a href="<?php echo e(route('pos.sync')); ?>" class="block px-4 py-2 text-sm hover:bg-slate-700 transition-colors">
                                🔄 Sincronizar
                            </a>
                            <a href="<?php echo e(route('pos.configuracion')); ?>" class="block px-4 py-2 text-sm hover:bg-slate-700 transition-colors">
                                ⚙️ Configuración
                            </a>
                            <hr class="my-2 border-slate-700">
                            <button class="w-full text-left px-4 py-2 text-sm text-red-400 hover:bg-slate-700 transition-colors">
                                🚪 Salir
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <main class="flex-1 overflow-hidden">
            <?php echo e($slot); ?>

        </main>
    </div>

    <?php echo \Livewire\Mechanisms\FrontendAssets\FrontendAssets::scripts(); ?>

    <script src="//unpkg.com/alpinejs" defer></script>

    <style>
        [x-cloak] { display: none !important; }
    </style>
</body>
</html>
<?php /**PATH C:\MisLaravel\pos\resources\views/layouts/pos.blade.php ENDPATH**/ ?>