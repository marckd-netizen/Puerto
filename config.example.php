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
            // Consulta que usa la página pública https://mitcp.katoennatie.com.uy/ (Line-up).
            // La api-key es la que la propia página envía desde cualquier navegador.
            'url'    => 'https://api.katoennatie.com.uy/public/tcp/mitcp/frontend/v1/api/line-up',
            'parser' => 'json_api',
            'encabezados' => [
                'api-key' => '80413d2ebbc8429b9680d09d566f9927',
                'Referer' => 'https://mitcp.katoennatie.com.uy/',
                'Accept'  => 'application/json, text/plain, */*',
            ],
            'color'  => '#1f6feb',
            // TCP no publica estado: se muestra "Operando" entre ETB y ETS,
            // "Zarpado" después de ETS y "Atraque confirmado" si ya tiene ETB.
            'estado_por_fechas' => true,
            // TCP no publica número de viaje: se usa la semana de la escala.
            'formato' => ['viaje' => 'Sem. %s'],
            'columnas' => [
                'buque'     => ['vessel', 'vesselName', 'buque'],
                'viaje'     => ['voyage', 'week'],
                'servicio'  => ['service', 'servicio'],
                'eta'       => ['eta'],
                'etb'       => ['etb'],
                'etd'       => ['ets', 'etd'],
                'estado'    => ['status', 'estado'],
                'operativa' => ['notes'],
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
