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
            // TCP no publica estado: "Operando" entre ETB y ETS, "Zarpado" después de ETS,
            // "En rada" entre ETA y ETB, "Atraque confirmado" si ya tiene ETB y si no "Programado".
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
            // Consulta que usa la página pública https://online2.montecon.com.uy/ (Schedule).
            'url'    => 'https://api-online2.montecon.com.uy/api/query/schedule',
            'parser' => 'json_api',
            'cuerpo_post' => '{}',
            'encabezados' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json, text/plain, */*',
                'Origin'       => 'https://online2.montecon.com.uy',
                'Referer'      => 'https://online2.montecon.com.uy/',
            ],
            'color'  => '#d29922',
            // Montecon no publica estado: se deduce de llegada a rada (ETA), comienzo de
            // operaciones (ETB) y salida. Como siempre publica ETB, antes de la llegada es "Programado".
            'estado_por_fechas' => ['con_etb' => 'Programado'],
            // Igual que la página de Montecon, no se muestran los marcados como ocultos.
            'excluir_si' => ['noMostrarSchedule' => true],
            'columnas' => [
                'buque'    => ['buque'],
                'viaje'    => ['nroViaje'],
                'servicio' => ['servicio'],
                'linea'    => ['armador'],
                'eta'      => ['llegadaRada'],          // llegada a rada
                'etb'      => ['comienzoOperaciones'],  // inicio de operaciones / atraque
                'etd'      => ['salida'],
                'cierre'   => ['cutOff'],
                'estado'   => ['estado', 'status'],
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
