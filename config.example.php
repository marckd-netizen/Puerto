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

    // Resaltado de filas según la antigüedad del último cambio:
    // naranja hasta 'horas_cambio_reciente', naranja claro hasta 'horas_destacado',
    // y sin resaltar después.
    'horas_cambio_reciente' => 2,
    'horas_destacado' => 24,

    // Si el ESTADO contiene alguno de estos textos, el buque se considera operando
    // y la fila se pinta de verde (sin importar mayúsculas ni tildes).
    'estados_operando' => ['operando', 'en operacion', 'trabajando', 'atracado', 'working', 'berthed', 'alongside'],

    // Segundos entre recargas automáticas de la página.
    'auto_refresh' => 300,

    // Cada terminal: de dónde se lee y cómo se interpretan las columnas.
    // parser 'tabla_html': busca en la página la tabla cuyos encabezados coinciden
    // mejor con 'columnas' (cada campo acepta varios textos posibles, sin importar
    // mayúsculas ni tildes).
    'terminales' => [
        'TCP' => [
            'nombre' => 'TCP - Terminal Cuenca del Plata',
            'url'    => 'https://mitcp.katoennatie.com.uy/', // PENDIENTE: ajustar a la pantalla/consulta de buques
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
                'servicio'  => ['servicio', 'service', 'ruta', 'loop'],
                'operativa' => ['operativa', 'operacion', 'operación'],
                'estado'    => ['estado', 'status', 'situacion'],
                'cierre'    => ['cierre', 'cut off', 'cutoff', 'stacking'],
            ],
        ],
        'MONTECON' => [
            'nombre' => 'Montecon',
            'url'    => 'https://online2.montecon.com.uy/', // PENDIENTE: ajustar a la pantalla/consulta de buques
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
                'servicio'  => ['servicio', 'service', 'ruta', 'loop'],
                'operativa' => ['operativa', 'operacion', 'operación'],
                'estado'    => ['estado', 'status', 'situacion'],
                'cierre'    => ['cierre', 'cut off', 'cutoff', 'stacking'],
            ],
        ],
    ],

    // Campos cuyo cambio se registra y se destaca.
    'campos_vigilados' => ['eta', 'etd', 'estado', 'servicio'],

    'http' => [
        'timeout'    => 30,
        'user_agent' => 'Mozilla/5.0 (compatible; PuertoUnificado/1.0)',
    ],
];
