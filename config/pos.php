<?php

return [
    // Solo como valor sugerido al instalar una caja. En runtime la URL se lee de la
    // tabla `configuracion`. Pasa por config y no por env() directo porque la app de
    // escritorio se compila con la config cacheada, y ahí env() devuelve null.
    'manager_api_url' => env('MANAGER_API_URL', ''),

    // Para mostrar fechas que llegan del Manager. La app guarda y trabaja en UTC.
    'zona_horaria' => env('POS_ZONA_HORARIA', 'America/Argentina/Buenos_Aires'),
];
