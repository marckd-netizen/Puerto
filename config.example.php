<?php
/**
 * Copiar este archivo como config.php y ajustar los valores.
 */
return [
    'db' => [
        // 'mysql' en producción. 'sqlite' sirve para probar en local sin servidor MySQL.
        'driver'   => 'mysql',
        'host'     => 'localhost',
        'port'     => 3306,
        'database' => 'puerto',
        'user'     => 'puerto',
        'password' => 'cambiar',
        'sqlite_path' => __DIR__ . '/data/puerto.sqlite',
    ],

    'timezone' => 'America/Montevideo',

    // Horas durante las que un cambio se sigue destacando en la pantalla principal.
    'horas_destacado' => 24,

    // Segundos entre recargas automáticas de la página.
    'auto_refresh' => 300,

    // Cada terminal: de dónde se lee y cómo se interpretan las columnas.
    // parser 'tabla_html': busca en la página la tabla cuyos encabezados coinciden
    // mejor con 'columnas' (cada campo acepta varios textos posibles, sin importar
    // mayúsculas ni tildes).
    'terminales' => [
        'TCP' => [
            'nombre' => 'TCP - Terminal Cuenca del Plata',
            'url'    => 'https://www.tcp.com.uy/',          // AJUSTAR: página de arribos/programación
            'parser' => 'tabla_html',
            'color'  => '#1f6feb',
            'columnas' => [
                'buque'     => ['buque', 'nave', 'vessel', 'barco'],
                'viaje'     => ['viaje', 'voyage', 'voy'],
                'linea'     => ['linea', 'naviera', 'line', 'operador'],
                'agencia'   => ['agencia', 'agente', 'agent'],
                'muelle'    => ['muelle', 'sitio', 'berth', 'puesto'],
                'eta'       => ['eta', 'arribo', 'llegada', 'arribo estimado'],
                'etb'       => ['etb', 'atraque'],
                'etd'       => ['etd', 'salida', 'zarpe'],
                'operativa' => ['operativa', 'operacion', 'operación', 'servicio'],
                'estado'    => ['estado', 'status', 'situacion'],
                'cierre'    => ['cierre', 'cut off', 'cutoff', 'stacking'],
            ],
        ],
        'MONTECON' => [
            'nombre' => 'Montecon',
            'url'    => 'https://www.montecon.com.uy/',     // AJUSTAR: página de arribos/programación
            'parser' => 'tabla_html',
            'color'  => '#d29922',
            'columnas' => [
                'buque'     => ['buque', 'nave', 'vessel', 'barco'],
                'viaje'     => ['viaje', 'voyage', 'voy'],
                'linea'     => ['linea', 'naviera', 'line', 'operador'],
                'agencia'   => ['agencia', 'agente', 'agent'],
                'muelle'    => ['muelle', 'sitio', 'berth', 'puesto'],
                'eta'       => ['eta', 'arribo', 'llegada'],
                'etb'       => ['etb', 'atraque'],
                'etd'       => ['etd', 'salida', 'zarpe'],
                'operativa' => ['operativa', 'operacion', 'operación', 'servicio'],
                'estado'    => ['estado', 'status', 'situacion'],
                'cierre'    => ['cierre', 'cut off', 'cutoff', 'stacking'],
            ],
        ],
    ],

    // Campos cuyo cambio se registra y se destaca.
    'campos_vigilados' => ['eta', 'etb', 'etd', 'muelle', 'operativa', 'estado', 'cierre'],

    'http' => [
        'timeout'    => 30,
        'user_agent' => 'Mozilla/5.0 (compatible; PuertoUnificado/1.0)',
    ],
];
