<?php

use App\Livewire\Configuracion\Inicial;
use App\Livewire\Pos\Productos;
use App\Livewire\Pos\Sincronizacion;
use App\Livewire\Pos\Stock;
use App\Livewire\Pos\Venta;
use App\Models\Configuracion;
use Illuminate\Support\Facades\Route;

// Ruta de configuración inicial (siempre accesible)
Route::get('/configuracion', Inicial::class)->name('pos.configuracion');

// Dashboard / POS principal
Route::get('/', Venta::class)->name('pos.venta');

// Productos
Route::get('/productos', Productos::class)->name('pos.productos');

// Stock
Route::get('/stock', Stock::class)->name('pos.stock');

// Sincronización
Route::get('/sync', Sincronizacion::class)->name('pos.sync');
