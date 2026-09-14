<?php

use Illuminate\Support\Facades\Schedule;

// Red de seguridad del push: lo normal es que la cola envíe cada venta en segundos,
// esto cubre que el worker haya estado caído o que el job agotara sus reintentos.
Schedule::command('pos:sync --push')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Stock desde el Manager: un GET liviano, sin tocar precios. Es lo que mantiene el
// stock de la caja al día cuando cambia por fuera (otra caja vendió, llegó un remito).
Schedule::command('pos:sync --stock')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// Canal de órdenes: permite reparar esta caja desde el Manager sin ir físicamente.
Schedule::command('pos:comandos')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();
