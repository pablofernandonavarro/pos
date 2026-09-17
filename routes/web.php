<?php

use App\Http\Controllers\InformeCajaController;
use App\Http\Middleware\RequiereCajaConfigurada;
use App\Livewire\Configuracion\Inicial;
use App\Livewire\Pos\Ajustes;
use App\Livewire\Pos\Caja;
use App\Livewire\Pos\Productos;
use App\Livewire\Pos\RemitoNuevo;
use App\Livewire\Pos\Remitos;
use App\Livewire\Pos\RemitosEnviados;
use App\Livewire\Pos\Sincronizacion;
use App\Livewire\Pos\Stock;
use App\Livewire\Pos\Venta;
use App\Livewire\Pos\Ventas;
use Illuminate\Support\Facades\Route;

// Ruta de configuración inicial (siempre accesible)
Route::get('/configuracion', Inicial::class)->name('pos.configuracion');

Route::middleware(RequiereCajaConfigurada::class)->group(function () {
    // Dashboard / POS principal
    Route::get('/', Venta::class)->name('pos.venta');

    // Productos
    Route::get('/productos', Productos::class)->name('pos.productos');

    // Stock
    Route::get('/stock', Stock::class)->name('pos.stock');

    // Caja: turno, movimientos, cierre Z e informes imprimibles
    Route::get('/caja', Caja::class)->name('pos.caja');
    Route::get('/caja/turnos/{turno}/informe', InformeCajaController::class)->name('pos.caja.informe');
    Route::get('/ventas', Ventas::class)->name('pos.ventas');
    Route::get('/ventas/{venta}/ticket', [InformeCajaController::class, 'ticket'])->name('pos.ticket');
    Route::get('/devoluciones/{devolucion}/comprobante', [InformeCajaController::class, 'devolucion'])->name('pos.devolucion.comprobante');
    Route::get('/cobros/{cobro}/recibo', [InformeCajaController::class, 'cobro'])->name('pos.cobro.recibo');
    Route::get('/ajustes', Ajustes::class)->name('pos.ajustes');

    // Remitos
    Route::get('/remitos', Remitos::class)->name('pos.remitos');
    Route::get('/remitos/nuevo', RemitoNuevo::class)->name('pos.remitos.nuevo');
    Route::get('/remitos/enviados', RemitosEnviados::class)->name('pos.remitos.enviados');
    Route::get('/remitos/enviados/{remito}/comprobante', [InformeCajaController::class, 'remito'])->name('pos.remito.comprobante');

    // Sincronización
    Route::get('/sync', Sincronizacion::class)->name('pos.sync');
});
