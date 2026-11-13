<?php

/*
| Portal en la nube (solo lectura para comensales).
|
| El mismo API corre en dos modos:
|  - "local":  servidor de la pensión (opera todo). Si PORTAL_SYNC_URL está
|              definido, envía cada 5 minutos una copia de los datos de los
|              comensales al portal.
|  - "portal": VPS en la nube. Solo acepta comensales y solo rutas de consulta
|              (XP, insignias, retos, racha, mis pedidos). Recibe la copia en
|              POST /api/portal/sync, protegida con PORTAL_SYNC_TOKEN.
*/

return [
    'modo' => env('APP_MODE', 'local'),

    'sync_url' => env('PORTAL_SYNC_URL'),       // p. ej. https://203.0.113.25.sslip.io
    'sync_token' => env('PORTAL_SYNC_TOKEN'),   // secreto compartido local ↔ portal

    // Rutas disponibles en modo portal (todo lo demás responde 404).
    'rutas_portal' => [
        'api/ping',
        'api/login',
        'api/logout',
        'api/me',
        'api/loyalty',
        'api/loyalty/streak',
        'api/badges',
        'api/badges/user',
        'api/challenges',
        'api/challenges/user',
        'api/orders/my-orders',
        'api/portal/sync',
    ],
];
