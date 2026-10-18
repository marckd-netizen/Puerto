<?php
/**
 * Copiar este archivo como config.php y ajustar los valores.
 */
return [
    'db' => [
        // 'mysql' en producción. 'sqlite' sirve para probar en local sin servidor MySQL.
        'driver'   => 'mysql',
        'host'     => 'localhost',
        // 'socket' => '/var/lib/mysql/mysql.sock', // solo si hace falta indicar el socket de MySQL
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

    // Acceso al sitio. Generar cada usuario con: php cron/crear_usuario.php nombre
    'requiere_login' => true,
    // Para instalar en un servidor de internet (hosting), donde no se puede entrar por "localhost":
    // una clave de al menos 12 caracteres permite abrir verificar.php?clave=... y crear el primer
    // usuario desde cualquier lugar. Cuando el sitio ya funcione, volver a dejarla vacía.
    'clave_instalacion' => '',
    'usuarios' => [
        // 'marcelo' => '$2y$10$...',
    ],

    // Consulta de contenedores en Lucía (Aduana).
    'lucia' => [
        // 'soap': webservice real. 'archivo': simulación con un JSON (para pruebas).
        // 'pendiente': sin configurar.
        'modo'    => 'pendiente',
        'url'     => 'https://servicios.aduanas.gub.uy/luciaws/awsdatcont.aspx?wsdl',
        // Método: DATOSCONTFECHAS (rango de fechas, recomendado) o DATOSCONT (solo mira el día actual).
        'metodo'        => 'DATOSCONTFECHAS',
        'dias_atras'    => 60,
        'dias_adelante' => 30,
        // Solo para DATOSCONT: LMF, DUA, STK, MSL, MSI o TOD.
        'quedatos'      => 'TOD',
        // Credenciales: SOLO en config.php del servidor, nunca en el repositorio.
        'autenticacion' => 'basic',
        'usuario'  => '',
        'clave'    => '',
        // Si el certificado de Lucía es de una autoridad interna, ruta al archivo .pem de esa autoridad.
        'ca_bundle' => '',
        // Lucía no informa el buque en los datos del contenedor: se consulta el manifiesto
        // (afMicXmlunif, con la clave que viene en LINMANIF) para obtener el nombre del buque.
        'consultar_manifiestos' => true,
        'url_manifiesto' => 'https://servicios.aduanas.gub.uy/luciaws/afMicXmlunif.aspx',
        // Nombres adicionales de los elementos de la respuesta del manifiesto, si el diagnóstico muestra otros.
        'campos_manifiesto' => ['buque' => [], 'viaje' => [], 'fecha' => []],
        // Si tampoco se obtiene así, se deduce por la terminal y la fecha de descarga.
        // Terminal según el operador de la convalidada (texto contenido en el nombre)...
        'operadores' => ['CUENCA DEL PLATA' => 'TCP', 'MONTECON' => 'MONTECON'],
        // ...o según el depósito del manifiesto. 1650 = TCP (visto en una respuesta real); falta el de Montecon.
        'depositos'  => ['1650' => 'TCP'],
        // Nombres adicionales de los elementos de la respuesta (si el diagnóstico muestra otros).
        'campos' => [
            'buque' => [],
            'viaje' => [],
            'fecha' => [],
        ],
        'archivo' => __DIR__ . '/tests/fixtures/lucia_simulada.json',
        // Cada cuántas horas se vuelve a consultar un mismo contenedor.
        'reconsultar_horas' => 2,
        // Pausa entre consultas para no saturar el servicio.
        'pausa_segundos'    => 1,
        'max_por_ejecucion' => 200,
    ],

    // Servicios que no se muestran de entrada (cada usuario puede cambiarlo desde la página).
    'servicios_ocultos' => ['CRUCERO', 'CIENTIFICO'],

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
            // TCP no publica estado: "Esperado" antes de la ETB, "Operando" entre ETB y ETS
            // y "Zarpado" después de ETS.
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
            // Montecon no publica estado: "Esperado" antes del comienzo de operaciones (ETB),
            // "Operando" hasta la salida y "Zarpado" después.
            'estado_por_fechas' => true,
            // Zona horaria en que Montecon publica sus fechas. Vacío = hora de Uruguay.
            // Si todas las horas aparecen corridas 3 horas, poner 'UTC'.
            'zona_horaria' => '',
            // Igual que la página de Montecon, no se muestran los marcados como ocultos.
            'excluir_si' => ['noMostrarSchedule' => true],
            'columnas' => [
                'buque'    => ['buque'],
                'viaje'    => ['nroViaje'],
                'servicio' => ['servicio'],
                'linea'    => ['armador'],
                'eta'      => ['llegada'],              // llegada (la que muestra Montecon; llegadaRada es antes)
                'etb'      => ['comienzoOperaciones'],  // inicio de operaciones / atraque
                'etd'      => ['salida'],
                'cierre'   => ['cutOff'],
                'estado'   => ['estado', 'status'],
            ],
        ],
    ],

    // Tracking de las navieras (APIs oficiales con el estándar DCSA Track & Trace): dice en qué buque
    // llega un contenedor a Montevideo cuando Lucía todavía no lo sabe (por ejemplo, mientras navega).
    // Cada naviera da la clave de su API al registrarse en su portal de desarrolladores (gratis);
    // la dirección y el nombre del encabezado de la clave figuran en ese portal.
    // Probar con: php cron/naviera_diagnostico.php MSKU1234565
    'navieras' => [
        'puerto' => 'UYMVD',          // código UN/LOCODE de Montevideo
        'probar_todas' => false,      // true: los contenedores de leasing (TGHU, TCNU, ...) se buscan en todas las activas
        'lineas' => [
            'MAERSK' => [             // https://developer.maersk.com
                'activa'      => false,
                'prefijos'    => ['MAEU', 'MSKU', 'MRKU', 'MRSU', 'MSAU', 'SUDU', 'SEAU'],
                'url'         => 'https://api.maersk.com/track-and-trace-private/events?equipmentReference={contenedor}',
                'encabezados' => ['Consumer-Key: PEGAR-AQUI-LA-CLAVE'],
            ],
            'HAPAG' => [              // https://api-portal.hlag.com
                'activa'      => false,
                'prefijos'    => ['HLXU', 'HLBU', 'HLCU', 'HAMU', 'UACU'],
                'url'         => '',  // la de "Track & Trace" del portal, terminada en ?equipmentReference={contenedor}
                'encabezados' => [],  // por ejemplo 'X-IBM-Client-Id: ...', según indique el portal
            ],
            'CMA CGM' => [            // https://api-portal.cma-cgm.com
                'activa'      => false,
                'prefijos'    => ['CMAU', 'CGMU', 'ECMU', 'APHU', 'APZU'],
                'url'         => '',
                'encabezados' => [],
            ],
            'ONE' => [                // Ocean Network Express (miembro de DCSA): pedir acceso a su API de tracking
                'activa'      => false,
                'prefijos'    => ['ONEU', 'NYKU', 'MOLU', 'KKFU'],
                'url'         => '',
                'encabezados' => [],
            ],
            'MSC' => [                // https://developerportal.msc.com (API DCSA; se pide "Direct Integration", puede tener costo)
                'activa'      => false,
                'prefijos'    => ['MSCU', 'MEDU', 'MSDU', 'MSMU'],
                'url'         => '',
                'encabezados' => [],
            ],
            'COSCO' => [              // COSCO no usa el estándar DCSA: con una respuesta real se adapta el lector
                'activa'      => false,
                'formato'     => 'cosco',
                'prefijos'    => ['CCLU', 'CBHU', 'CSNU', 'CSLU'],
                'url'         => '',
                'encabezados' => [],
            ],
        ],
    ],

    // Campos cuyo cambio se registra y se destaca.
    'campos_vigilados' => ['eta', 'etd', 'estado', 'servicio'],

    'http' => [
        'timeout'    => 30,
        'user_agent' => 'Mozilla/5.0 (compatible; PuertoUnificado/1.0)',
        // Lista de certificados para HTTPS, solo si PHP no la encuentra (error "certificate verify failed").
        // Ej. en XAMPP: 'C:\\xampp\\php\\extras\\ssl\\cacert.pem'
        'ca_bundle'  => '',
    ],
];
