<?php

use Illuminate\Support\Facades\Schedule;

// withoutOverlapping() siempre con vencimiento en minutos: el default es 24 horas, y si la
// caja se apaga (o se cierra la app de escritorio) con una tarea corriendo, el candado
// queda puesto y la tarea no vuelve a correr hasta el día siguiente. Pasó: una caja estuvo
// 11 horas sin bajar stock. Los tiempos cubren el peor caso de ManagerApiService
// (timeout 30s × 3 intentos por llamada).

// Red de seguridad del push: lo normal es que la cola envíe cada venta en segundos,
// esto cubre que el worker haya estado caído o que el job agotara sus reintentos.
Schedule::command('pos:sync --push')
    ->everyFiveMinutes()
    ->withoutOverlapping(15)
    ->runInBackground();

// Stock desde el Manager: un GET liviano, sin tocar precios. Es lo que mantiene el
// stock de la caja al día cuando cambia por fuera (otra caja vendió, llegó un remito).
Schedule::command('pos:sync --stock')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->runInBackground();

// Canal de órdenes: permite reparar esta caja desde el Manager sin ir físicamente.
Schedule::command('pos:comandos')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->runInBackground();
