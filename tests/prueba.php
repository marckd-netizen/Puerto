<?php
/**
 * Prueba de punta a punta sin internet ni MySQL: lee las páginas de ejemplo de
 * tests/fixtures, simula dos lecturas y verifica los cambios detectados.
 * Uso: php tests/prueba.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
date_default_timezone_set('America/Montevideo');

$base = require dirname(__DIR__) . '/config.example.php';
$db = Db::conectar(['driver' => 'sqlite', 'sqlite_path' => ':memory:']);
$db->exec(file_get_contents(dirname(__DIR__) . '/sql/schema.sqlite.sql'));
$lector = new Lector([]);
$sync = new Sincronizador($db, $base['campos_vigilados']);
$fallas = 0;

function verificar(bool $cond, string $desc): void
{
    global $fallas;
    echo ($cond ? '  ok   ' : '  FALLA ') . $desc . "\n";
    $fallas += $cond ? 0 : 1;
}

const TERMINAL_HTML = [
    'parser' => 'tabla_html',
    'columnas' => [
        'buque'    => ['buque', 'nave', 'vessel'],
        'viaje'    => ['viaje', 'voyage'],
        'linea'    => ['linea', 'naviera'],
        'agencia'  => ['agencia'],
        'muelle'   => ['muelle', 'sitio'],
        'servicio' => ['servicio', 'service'],
        'eta'      => ['eta', 'arribo'],
        'etb'      => ['etb', 'atraque'],
        'etd'      => ['etd', 'zarpe'],
        'estado'   => ['estado', 'status'],
        'cierre'   => ['cierre'],
    ],
];

function leer(Lector $lector, array $base, string $codigo, string $archivo): array
{
    $t = $base['terminales'][$codigo];
    if (str_ends_with($archivo, '.html')) {
        // Las páginas HTML de ejemplo prueban el lector de tablas, aunque las terminales reales usen JSON.
        $t = TERMINAL_HTML;
    }
    $t['url'] = __DIR__ . "/fixtures/$archivo";
    return $lector->leerTerminal($t);
}

echo "Fechas\n";
verificar(Texto::fecha('07/10/2026 14:00') === '2026-10-07 14:00', 'dd/mm/aaaa hh:mm');
verificar(Texto::fecha('7-10-26 9:05') === '2026-10-07 09:05', 'd-m-aa h:mm');
verificar(Texto::fecha('2026-10-07T14:30:00') === '2026-10-07 14:30', 'ISO');
verificar(Texto::fecha('a confirmar') === 'a confirmar', 'texto no reconocido se conserva');

echo "Lectura de tablas\n";
$tcp = leer($lector, $base, 'TCP', 'tabla_a_1.html');
$mon = leer($lector, $base, 'MONTECON', 'tabla_b_1.html');
verificar(count($tcp) === 4, 'TCP: 4 buques');
verificar($tcp[1]['buque'] === 'CAP SAN LORENZO' && $tcp[1]['eta'] === '2026-10-09 06:00', 'TCP: buque y ETA');
verificar($tcp[1]['servicio'] === 'SAEC' && $tcp[1]['estado'] === 'Programado', 'TCP: servicio y estado');
verificar(count($mon) === 3, 'Montecon: 3 buques (ignora menú y título)');
verificar($mon[0]['viaje'] === 'GBR0425' && $mon[0]['servicio'] === 'ESA', 'Montecon: Voyage→viaje, Service→servicio');
verificar(str_starts_with((string) $mon[0]['eta'], '2026-10-08'), 'Montecon: fecha sin año');

echo "Primera lectura\n";
$r1 = $sync->sincronizar('TCP', $tcp, '2026-10-07 06:00:00');
$r2 = $sync->sincronizar('MONTECON', $mon, '2026-10-07 06:00:00');
verificar($r1['nuevas'] === 4 && $r2['nuevas'] === 3, '7 escalas nuevas');
verificar((int) $db->query('SELECT COUNT(*) FROM cambios')->fetchColumn() === 0, 'sin cambios');

echo "Segunda lectura (con cambios)\n";
$r1 = $sync->sincronizar('TCP', leer($lector, $base, 'TCP', 'tabla_a_2.html'), '2026-10-07 08:00:00');
$r2 = $sync->sincronizar('MONTECON', leer($lector, $base, 'MONTECON', 'tabla_b_2.html'), '2026-10-07 09:30:00');
verificar($r1['retiradas'] === 1, 'TCP: MSC ARIANE retirado de la lista');
verificar($r1['actualizadas'] === 2, 'TCP: 2 buques con cambios');
verificar($r2['cambios'] === 2, 'Montecon: ETA y ETD cambiadas');

$c = $db->query("SELECT c.campo, c.valor_anterior, c.valor_nuevo FROM cambios c JOIN escalas e ON e.id = c.escala_id WHERE e.buque = 'CAP SAN LORENZO' ORDER BY c.campo")->fetchAll();
verificar(array_column($c, 'campo') === ['eta'], 'CAP SAN LORENZO (TCP): cambia la ETA');
verificar($c[0]['valor_anterior'] === '2026-10-09 06:00' && $c[0]['valor_nuevo'] === '2026-10-10 02:00', 'CAP SAN LORENZO: ETA antes/después');
$e = $db->query("SELECT eta_original, eta FROM escalas WHERE buque = 'CAP SAN LORENZO'")->fetch();
verificar($e['eta_original'] === '2026-10-09 06:00', 'se conserva la ETA original');

echo "Tercera lectura (sin cambios)\n";
$r1 = $sync->sincronizar('TCP', leer($lector, $base, 'TCP', 'tabla_a_2.html'), '2026-10-07 10:00:00');
verificar($r1['cambios'] === 0 && $r1['nuevas'] === 0, 'no se duplican cambios');

echo "Tablero (una fila por buque y colores)\n";
$escalas = $db->query('SELECT * FROM escalas WHERE activa = 1')->fetchAll();
$ultimos = [];
foreach ($db->query('SELECT * FROM cambios ORDER BY id')->fetchAll() as $cm) {
    $ultimos[(int) $cm['escala_id']][] = $cm;
}
$tablero = new Tablero($base, strtotime('2026-10-07 10:00:00'));
$filas = $tablero->filas($escalas, $ultimos, []);
$porBuque = [];
foreach ($filas as $f) {
    $porBuque[$f['buque']] = $f;
}
verificar(count($filas) === 5, '6 escalas → 5 filas (CAP SAN LORENZO está en las dos terminales)');
$cap = $porBuque['CAP SAN LORENZO'] ?? $porBuque['Cap San Lorenzo'];
verificar(array_keys($cap['terminales']) === ['TCP', 'MONTECON'], 'CAP SAN LORENZO: datos de TCP y de Montecon en la misma fila');
verificar($porBuque['MAERSK LIMA']['nivel'] === 'operando', 'MAERSK LIMA operando → verde');
verificar($porBuque['MAERSK LIMA']['nivel_cambio'] === 'cambio', 'MAERSK LIMA: su cambio de hace 2 h queda marcado en la celda');
verificar($porBuque['GRANDE BRASILE']['nivel'] === 'reciente', 'GRANDE BRASILE: cambio hace 30 min → naranja');
verificar($cap['nivel'] === 'cambio', 'CAP SAN LORENZO: cambio hace 2 h → naranja claro');
verificar($porBuque['CMA CGM BAHIA']['nivel'] === '', 'CMA CGM BAHIA: sin cambios → sin resaltar');
$manana = new Tablero($base, strtotime('2026-10-08 10:00:00'));
$f2 = $manana->filas($escalas, $ultimos, []);
verificar(!in_array('cambio', array_column($f2, 'nivel'), true) && !in_array('reciente', array_column($f2, 'nivel'), true), '24 h después: ya no se resalta ningún cambio');
verificar($tablero->esOperando('En Operación') && !$tablero->esOperando('Programado'), 'estados de operación');

echo "TCP: line-up en JSON\n";
$db2 = Db::conectar(['driver' => 'sqlite', 'sqlite_path' => ':memory:']);
$sync2 = new Sincronizador($db2, $base['campos_vigilados']);
$j1 = leer($lector, $base, 'TCP', 'tcp_lineup_1.json');
verificar(count($j1) === 25, 'JSON: 25 buques');
$silje = array_values(array_filter($j1, fn($f) => $f['buque'] === 'AS SILJE'));
verificar(count($silje) === 2 && $silje[0]['viaje'] === 'Sem. 41', 'JSON: AS SILJE en dos semanas distintas');
verificar($silje[0]['etd'] === '2026-10-14 11:00' && $silje[0]['servicio'] === 'PATAGONIA 01', 'JSON: ETS→ETD y servicio');
verificar(Texto::fecha('2026-10-07T17:00:00Z') === '2026-10-07 14:00', 'JSON: fecha UTC pasada a hora de Montevideo');
$r = $sync2->sincronizar('TCP', $j1, '2026-10-07 08:00:00');
verificar($r['nuevas'] === 25, 'JSON: 25 escalas nuevas (buque + semana)');
$r = $sync2->sincronizar('TCP', leer($lector, $base, 'TCP', 'tcp_lineup_2.json'), '2026-10-07 10:00:00');
verificar($r['retiradas'] === 1 && $r['actualizadas'] === 2, 'JSON: 1 retirado y 2 con cambios');
$labrea = $db2->query("SELECT c.campo FROM cambios c JOIN escalas e ON e.id = c.escala_id WHERE e.buque = 'MAERSK LABREA' ORDER BY c.campo")->fetchAll(PDO::FETCH_COLUMN);
verificar($labrea === ['eta', 'etd'], 'JSON: MAERSK LABREA cambia ETA y ETD');

$t = new Tablero($base, strtotime('2026-10-12 20:00:00'));
verificar($t->estadoPorFechas(['etb' => '2026-10-12 13:00', 'etd' => '2026-10-13 08:00']) === 'Operando', 'entre ETB y ETS → Operando');
verificar($t->estadoPorFechas(['etb' => '2026-10-13 11:00', 'etd' => '2026-10-14 11:00']) === 'Esperado', 'con ETB futura → Esperado');
verificar($t->estadoPorFechas(['etb' => '2026-10-10 13:00', 'etd' => '2026-10-11 02:00']) === 'Zarpado', 'después de ETS → Zarpado');
verificar($t->estadoPorFechas(['etb' => null, 'etd' => null]) === 'Esperado', 'sin ETB → Esperado');
$filasTcp = $t->filas($db2->query('SELECT * FROM escalas WHERE activa = 1')->fetchAll(), [], []);
$artemissio = array_values(array_filter($filasTcp, fn($f) => $f['buque'] === 'CAP SAN ARTEMISSIO'))[0];
verificar($artemissio['nivel'] === 'operando' && $artemissio['estados']['TCP'] === 'Operando', 'CAP SAN ARTEMISSIO operando el 12/10 20:00 → verde');

echo "Montecon: schedule en JSON\n";
$m1 = leer($lector, $base, 'MONTECON', 'montecon_schedule_1.json');
verificar(count($m1) === 8, 'JSON: 8 buques (se excluye el marcado noMostrarSchedule)');
$xiamen = array_values(array_filter($m1, fn($f) => $f['buque'] === 'XIAMEN EXPRESS'))[0];
verificar($xiamen['viaje'] === 'NA641R' && $xiamen['servicio'] === 'MSC NORTH EUROPE', 'JSON: nroViaje y servicio');
verificar($xiamen['eta'] === '2026-10-07 07:37' && $xiamen['etb'] === '2026-10-07 07:37' && $xiamen['etd'] === '2026-10-07 21:00', 'JSON: llegada→ETA, comienzoOperaciones→ETB, salida→ETD');
verificar(Texto::fecha('0001-01-01T00:00:00') === null, 'fecha 0001-01-01 = sin dato');

$db3 = Db::conectar(['driver' => 'sqlite', 'sqlite_path' => ':memory:']);
$sync3 = new Sincronizador($db3, $base['campos_vigilados']);
$sync3->sincronizar('MONTECON', $m1, '2026-10-07 08:00:00');
$sync3->sincronizar('TCP', leer($lector, $base, 'TCP', 'tcp_lineup_1.json'), '2026-10-07 08:00:00');
$r = $sync3->sincronizar('MONTECON', leer($lector, $base, 'MONTECON', 'montecon_schedule_2.json'), '2026-10-07 12:00:00');
verificar($r['retiradas'] === 1 && $r['actualizadas'] === 2 && $r['cambios'] === 2, 'Montecon: 1 retirado, 2 buques con cambio de ETD');

// --sin-cambios: actualiza los datos pero no anota cambios y toma la ETA nueva como original.
$db3b = Db::conectar(['driver' => 'sqlite', 'sqlite_path' => ':memory:']);
$sync3b = new Sincronizador($db3b, $base['campos_vigilados']);
$sync3b->sincronizar('MONTECON', $m1, '2026-10-07 08:00:00');
$db3b->exec("UPDATE escalas SET eta = '2026-10-01 00:00', eta_original = '2026-10-01 00:00' WHERE buque = 'XIAMEN EXPRESS'");
$r = $sync3b->sincronizar('MONTECON', $m1, '2026-10-07 10:00:00', false);
$x = $db3b->query("SELECT eta, eta_original FROM escalas WHERE buque = 'XIAMEN EXPRESS'")->fetch();
verificar($r['cambios'] === 0 && (int) $db3b->query('SELECT COUNT(*) FROM cambios')->fetchColumn() === 0
    && $x['eta'] === '2026-10-07 07:37' && $x['eta_original'] === '2026-10-07 07:37', 'sin registrar cambios: corrige la ETA sin anotar cambios ni resaltar');

$t = new Tablero($base, strtotime('2026-10-07 12:30:00'));
$filas = $t->filas($db3->query('SELECT * FROM escalas WHERE activa = 1')->fetchAll(), [], []);
$buscar = function (string $buque, ?string $viaje = null) use ($filas) {
    foreach ($filas as $f) {
        if ($f['buque'] === $buque && ($viaje === null || in_array($viaje, array_column($f['terminales'], 'viaje'), true))) {
            return $f;
        }
    }
    return null;
};
verificar($buscar('XIAMEN EXPRESS')['estados']['MONTECON'] === 'Operando' && $buscar('XIAMEN EXPRESS')['nivel'] === 'operando', 'XIAMEN EXPRESS operando a las 12:30 → verde');
verificar($buscar('MADELEINE I')['estados']['MONTECON'] === 'Esperado', 'MADELEINE I antes de la llegada → Esperado');
verificar($buscar('TIGER GAUCHO', '942N')['estados']['MONTECON'] === 'Esperado', 'TIGER GAUCHO 942N en rada → Esperado');
verificar($buscar('ORION')['estados']['MONTECON'] === 'Cancelado' && $buscar('ORION')['nivel'] !== 'operando', 'ORION 014301/CANCEL → Cancelado');
$inc = $buscar('INCANSABLE', '1326');
verificar($inc !== null && array_keys($inc['terminales']) === ['TCP', 'MONTECON'], 'INCANSABLE figura en Montecon y TCP → una sola fila');
$estadosPosibles = ['Operando', 'Esperado', 'Cancelado', 'Zarpado'];
$todos = [];
foreach ($filas as $f) {
    $todos = array_merge($todos, array_values($f['estados']));
}
verificar(array_diff($todos, $estadosPosibles) === [], 'solo se usan los estados Operando, Esperado, Cancelado y Zarpado');

echo "Filtro de servicios\n";
$ocultos = $base['servicios_ocultos'];
verificar($ocultos === ['CRUCERO', 'CIENTIFICO'], 'por defecto se ocultan CRUCERO y CIENTIFICO');
verificar(Tablero::ocultaPorServicio($buscar('SILVER WIND'), $ocultos), 'SILVER WIND (CRUCERO) se oculta');
verificar(Tablero::ocultaPorServicio($buscar('ORION'), ['crucero']), 'sin importar mayúsculas');
verificar(!Tablero::ocultaPorServicio($buscar('XIAMEN EXPRESS'), $ocultos), 'XIAMEN EXPRESS (MSC NORTH EUROPE) se muestra');
verificar(!Tablero::ocultaPorServicio($inc, ['FLUVIAL']), 'INCANSABLE (FLUVIAL / PARAGUAY FEEDER) se muestra si solo se oculta uno de sus servicios');
verificar(Tablero::ocultaPorServicio($inc, ['FLUVIAL', 'Paraguay Feeder']), 'INCANSABLE se oculta si se ocultan sus dos servicios');
verificar(!Tablero::ocultaPorServicio(['servicios' => []], $ocultos), 'un buque sin servicio nunca se oculta');

echo "Validaciones\n";
verificar(Validacion::normalizarContenedor(' csqu 305438-3 ') === 'CSQU3054383', 'contenedor: se normaliza "csqu 305438-3"');
verificar(Validacion::digitoContenedorCorrecto('CSQU3054383'), 'contenedor: dígito verificador correcto (ejemplo ISO 6346)');
verificar(Validacion::formatearContenedor('csqu3054383') === 'CSQU-305438-3' && Validacion::formatearContenedor('CSQU-305438-3') === 'CSQU-305438-3', 'contenedor: se muestra como AAAA-123456-7, tenga o no guiones');
verificar(!Validacion::digitoContenedorCorrecto('CSQU3054384'), 'contenedor: detecta dígito verificador incorrecto');
verificar(!Validacion::formatoContenedor('CSQ3054383') && !Validacion::formatoContenedor('CSQU305438'), 'contenedor: rechaza formatos que no son AAAAnnnnnnn');
$rutBase = '21123456001';
$sumaRut = 0;
foreach ([4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2] as $i => $peso) {
    $sumaRut += (int) $rutBase[$i] * $peso;
}
$rutOk = $rutBase . ((11 - $sumaRut % 11) % 11);
verificar(Validacion::digitoRutCorrecto($rutOk), "RUT: dígito verificador correcto ($rutOk)");
verificar(Validacion::normalizarRut('21.123.456-0012') === '211234560012' && !Validacion::formatoRut('12345'), 'RUT: normaliza y exige 12 dígitos');

echo "Clientes y contenedores\n";
$cont = new Contenedores($db3);
$r = $cont->importarClientes("RUT\tNombre\n$rutOk\tEMPRESA EJEMPLO S.A.\n210000000019\tOTRO CLIENTE SRL\n123\tMAL RUT\n");
verificar($r['ok'] === 2 && count($r['errores']) === 1, 'importa 2 clientes desde Excel (ignora encabezado y rechaza RUT inválido)');
$r = $cont->importarClientes("$rutOk;EMPRESA EJEMPLO SOCIEDAD ANONIMA");
verificar($r['ok'] === 1 && $db3->query("SELECT nombre FROM clientes WHERE rut = '$rutOk'")->fetchColumn() === 'EMPRESA EJEMPLO SOCIEDAD ANONIMA', 'reimportar un RUT actualiza el nombre (separador ;)');
$r = $cont->importarContenedores("Contenedor\tCarpeta\tRUT\nCSQU3054383\t2026-001\t$rutOk\nmsku 907032-3\t2026-002\t$rutOk\nTGHU8785330\t2026-003\nMSCU1234565\t2026-004\t210000000019\nABC123\t2026-005\nCSQU3054383\t2026-006\t999999999999\n");
verificar($r['ok'] === 4 && count($r['errores']) === 2, 'importa 4 contenedores; rechaza formato inválido y cliente inexistente');
verificar(count($r['avisos']) === 1 && str_contains($r['avisos'][0], 'MSCU-123456-5'), 'avisa el dígito verificador incorrecto de MSCU1234565');
$r = $cont->guardarContenedor('CSQU3054383', '2026-001', $rutOk);
verificar($r['ok'] && (int) $db3->query('SELECT COUNT(*) FROM contenedores')->fetchColumn() === 4, 'el mismo contenedor y carpeta no se duplica');

echo "Lucía y vinculación con buques\n";
$ahoraLucia = strtotime('2026-10-07 12:30:00');
$noConf = $cont->consultarPendientes(new LuciaNoConfigurada(), ['pausa_segundos' => 0], $ahoraLucia);
verificar($noConf['consultados'] === 1 && count($noConf['errores']) === 1, 'sin configurar: se detiene en el primer contenedor con un error claro');
$db3->exec('UPDATE contenedores SET lucia_consulta = NULL, lucia_estado = \'pendiente\'');
$lucia = new LuciaArchivo(__DIR__ . '/fixtures/lucia_simulada.json');
$r = $cont->consultarPendientes($lucia, ['pausa_segundos' => 0, 'reconsultar_horas' => 2], $ahoraLucia);
verificar($r['consultados'] === 4 && $r['encontrados'] === 4 && $r['vinculados'] === 3, 'consulta 4: los 4 están en Lucía y 3 se vinculan a un buque');
$vinc = $db3->query('SELECT c.numero, e.terminal, e.buque, e.viaje FROM contenedores c LEFT JOIN escalas e ON e.id = c.escala_id ORDER BY c.numero')->fetchAll(PDO::FETCH_UNIQUE);
verificar(($vinc['CSQU3054383']['buque'] ?? '') === 'XIAMEN EXPRESS', 'CSQU3054383 → XIAMEN EXPRESS (mismo viaje)');
verificar(($vinc['TGHU8785330']['viaje'] ?? '') === '943N', 'TGHU8785330 → TIGER GAUCHO viaje 943N, no el 942N');
verificar(($vinc['MSKU9070323']['buque'] ?? '') === 'INCANSABLE' && $vinc['MSKU9070323']['viaje'] === 'Sem. 41', 'MSKU9070323 → INCANSABLE de la escala con ETA más cercana a la fecha de Lucía (TCP, semana 41)');
verificar($vinc['MSCU1234565']['buque'] === null && str_contains((string) $db3->query("SELECT lucia_mensaje FROM contenedores WHERE numero = 'MSCU1234565'")->fetchColumn(), 'no figura'), 'MSCU1234565: buque desconocido queda sin vincular, con aviso');
$r = $cont->consultarPendientes($lucia, ['pausa_segundos' => 0, 'reconsultar_horas' => 2], $ahoraLucia + 3600);
verificar($r['consultados'] === 0, 'no se vuelve a consultar antes de 2 horas');
$r = $cont->consultarPendientes($lucia, ['pausa_segundos' => 0, 'reconsultar_horas' => 2], $ahoraLucia + 2 * 3600);
verificar($r['consultados'] === 4, 'a las 2 horas se vuelven a consultar');
$porEscala = $cont->porEscala();
verificar(array_sum(array_map('count', $porEscala)) === 3, 'el tablero recibe los 3 contenedores vinculados, agrupados por escala');

echo "Lucía por webservice (respuesta supuesta)\n";
$soap = new LuciaSoap(['url' => 'http://ejemplo/awsdatcont.aspx?wsdl']);
$sobre = $soap->sobre('CSQU3054383', 'LMF');
verificar(str_contains($sobre, '<WSDatCont.DATOSCONT xmlns="LUCIAx">') && str_contains($sobre, '<Contenedor>CSQU-305438-3</Contenedor>') && str_contains($sobre, '<Quedatos>LMF</Quedatos>'), 'arma el sobre SOAP de DATOSCONT, con el contenedor en formato AAAA-123456-7');
verificar(str_contains($soap->sobre('csqu3054383', 'TOD'), '<Contenedor>CSQU-305438-3</Contenedor>'), 'aunque se cargue sin guiones, a Lucía se envía con guiones');
$r = $soap->interpretar(file_get_contents(__DIR__ . '/fixtures/lucia_respuesta_supuesta.xml'));
verificar($r->encontrado && $r->buque === 'CAP SAN ARTEMISSIO' && $r->viaje === '641W' && $r->fecha === '2026-10-12 11:00', 'extrae buque, viaje y fecha (el registro más reciente de dos)');
$vacia = $soap->interpretar(file_get_contents(__DIR__ . '/fixtures/lucia_respuesta_vacia_real.xml'));
verificar(!$vacia->encontrado, 'respuesta real sin datos (todas las secciones vacías) → no encontrado');
$sinBuque = $soap->interpretar(str_replace('<DUAS />', '<DUAS><Dua><ADUANA>001</ADUANA><ANIO>2026</ANIO><NUMERO>123456</NUMERO><ESTADO>NUM</ESTADO></Dua></DUAS>', file_get_contents(__DIR__ . '/fixtures/lucia_respuesta_vacia_real.xml')));
verificar($sinBuque->encontrado && $sinBuque->buque === null && $sinBuque->detalle['secciones_con_datos'] === ['DUAS'], 'con datos pero sin buque → encontrado, sin buque, indicando la sección');
verificar($soap->metodo() === 'DATOSCONTFECHAS', 'por defecto usa DATOSCONTFECHAS');
verificar($soap->rango(strtotime('2026-10-14 12:00')) === ['2026-08-15', '2026-11-13'], 'rango por defecto: 60 días atrás y 30 adelante');
$sobreF = $soap->sobreFechas('MRSU6111179', '2026-08-15', '2026-11-13');
verificar(str_contains($sobreF, '<Contenedor>MRSU-611117-9</Contenedor>') && str_contains($sobreF, '<WSDatCont.DATOSCONTFECHAS xmlns="LUCIAx">') && str_contains($sobreF, '<Fechainicial>2026-08-15</Fechainicial>') && str_contains($sobreF, '<Fechafinal>2026-11-13</Fechafinal>'), 'arma el sobre de DATOSCONTFECHAS');
$otros = $soap->interpretar('<Envelope><Body><R><Item><NomBarco>MSC ADELE</NomBarco><NroVje>NA646R</NroVje></Item></R></Body></Envelope>', ['buque' => ['NomBarco'], 'viaje' => ['NroVje']]);
verificar($otros->buque === 'MSC ADELE' && $otros->viaje === 'NA646R', 'acepta nombres de elementos configurados');
try {
    $soap->interpretar('<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><soap:Fault><faultcode>soap:Server</faultcode><faultstring>Contenedor inválido</faultstring></soap:Fault></soap:Body></soap:Envelope>');
    verificar(false, 'un SOAP Fault se informa como error');
} catch (RuntimeException $e) {
    verificar(str_contains($e->getMessage(), 'Contenedor inválido'), 'un SOAP Fault se informa como error');
}
try {
    new LuciaSoap(['url' => '']);
    verificar(false, 'sin URL → error de configuración');
} catch (LuciaSinConfigurar) {
    verificar(true, 'sin URL → error de configuración');
}

// Prueba de punta a punta contra un falso servidor de Lucía con usuario y contraseña
$puerto = 18765;
$proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$puerto", '-t', __DIR__ . '/lucia_mock'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $puerto); $i++) {
    usleep(100000);
}
try {
    $url = "http://127.0.0.1:$puerto/index.php?wsdl";
    $real = new LuciaSoap(['url' => $url, 'usuario' => 'usuario-prueba', 'clave' => 'clave-prueba']);
    $r = $real->consultar('CSQU3054383');
    verificar($r->encontrado && $r->buque === 'CAP SAN ARTEMISSIO', 'consulta por la red con usuario y contraseña');
    verificar(!$real->consultar('MSKU9070323')->encontrado, 'contenedor sin datos → no encontrado');
    try {
        (new LuciaSoap(['url' => $url, 'usuario' => 'usuario-prueba', 'clave' => 'mala']))->consultar('CSQU3054383');
        verificar(false, 'clave incorrecta → error claro que detiene las consultas');
    } catch (LuciaSinConfigurar $e) {
        verificar(str_contains($e->getMessage(), 'contraseña'), 'clave incorrecta → error claro que detiene las consultas');
    }
    // Y el vínculo con el tablero: CSQU3054383 viene en CAP SAN ARTEMISSIO (TCP)
    $db3->exec("UPDATE contenedores SET lucia_consulta = NULL WHERE numero = 'CSQU3054383'");
    $cont->consultarPendientes($real, ['pausa_segundos' => 0], $ahoraLucia + 5 * 3600);
    $b = $db3->query("SELECT e.buque FROM contenedores c JOIN escalas e ON e.id = c.escala_id WHERE c.numero = 'CSQU3054383'")->fetchColumn();
    verificar($b === 'CAP SAN ARTEMISSIO', 'CSQU3054383 queda vinculado a CAP SAN ARTEMISSIO');

    // Lo mismo sin la extensión curl (funciones de PHP)
    Http::$sinCurl = true;
    $r = (new LuciaSoap(['url' => $url, 'usuario' => 'usuario-prueba', 'clave' => 'clave-prueba']))->consultar('CSQU3054383');
    verificar($r->encontrado && $r->buque === 'CAP SAN ARTEMISSIO', 'sin curl: consulta por la red con usuario y contraseña');
    try {
        (new LuciaSoap(['url' => $url, 'usuario' => 'usuario-prueba', 'clave' => 'mala']))->consultar('CSQU3054383');
        verificar(false, 'sin curl: clave incorrecta → error claro');
    } catch (LuciaSinConfigurar $e) {
        verificar(str_contains($e->getMessage(), 'HTTP 401'), 'sin curl: clave incorrecta → error claro');
    }
    $get = Http::pedir("http://127.0.0.1:$puerto/index.php", null, [], ['usuario' => 'usuario-prueba', 'clave' => 'clave-prueba']);
    verificar($get['codigo'] === 200 && str_contains($get['cuerpo'], 'Envelope'), 'sin curl: pedido GET con autenticación (como el del manifiesto)');
    try {
        Http::pedir('http://127.0.0.1:1/', null, [], ['timeout' => 2]);
        verificar(false, 'sin curl: servidor inaccesible → error claro');
    } catch (RuntimeException $e) {
        verificar(str_contains($e->getMessage(), 'No se pudo conectar'), 'sin curl: servidor inaccesible → error claro');
    }
    Http::$sinCurl = false;
} finally {
    Http::$sinCurl = false;
    proc_terminate($proc);
}

echo "Lucía: respuesta real con datos (HAMU-464008-4)\n";
$soapReal = new LuciaSoap(['url' => 'http://ejemplo/']);
$hamu = $soapReal->interpretar(file_get_contents(__DIR__ . '/fixtures/lucia_respuesta_con_datos.xml'), [], strtotime('2026-10-14 12:00'));
verificar($hamu->encontrado && $hamu->buque === null, 'encontrado; Lucía no informa el buque');
verificar($hamu->terminal === 'TCP', 'terminal TCP, por el operador de la convalidada');
verificar($hamu->fecha === '2026-10-03 20:28', 'fecha = descarga de la convalidada (03/10 20:28)');
verificar(($hamu->detalle['manifiesto'] ?? '') === '291201', 'manifiesto 291201');
verificar(count(array_diff(['DUAS', 'LINMANIF', 'MOVIMSTOCK', 'MS', 'MOVIMVIAJE', 'CONVALIDADA'], $hamu->detalle['secciones_con_datos'])) === 0, 'detecta las 6 secciones con datos');
$res = implode("\n", $hamu->resumen);
verificar(str_contains($res, 'Descargado 03/10 20:28 en TERMINAL CUENCA DEL PLATA S A · US HOU → UY MVD · 40GP'), 'resumen: descarga, origen y tamaño');
verificar(str_contains($res, 'DUA 001-2026-203685 · estado DES · numerado 13/10'), 'resumen: DUA');
verificar(str_contains($res, 'Manifiesto 291201 línea 1 · estado CER · liberado 02/10 15:09'), 'resumen: línea de manifiesto liberada');
verificar($hamu->detalle['stock'] === ['estado' => 'salio', 'deposito' => '1694', 'fecha' => '2026-10-14'], 'stock: entró a 1644 y a 1694, salió de los dos → salió el 14/10 de 1694');
$mov = fn(string $dep, string $tipo, string $fecha) => ['DEPOSITO' => $dep, 'TIPO' => $tipo, 'FECHA' => $fecha];
verificar(LuciaSoap::situacionStock([$mov('1644', 'ENT', '2026-10-03'), $mov('1644', 'SAL', '2026-10-05'), $mov('1694', 'ENT', '2026-10-06')])
    === ['estado' => 'en_stock', 'deposito' => '1694', 'fecha' => '2026-10-06'], 'stock: entrada sin salida posterior → en stock en ese depósito');
verificar(LuciaSoap::situacionStock([])['estado'] === 'sin_stock', 'stock: sin movimientos → sin stock todavía');
$detalleViejo = $hamu->detalle;
unset($detalleViejo['stock']);
verificar(LuciaSoap::stockDeDetalle($detalleViejo) === $hamu->detalle['stock'], 'stock: contenedores consultados antes se calculan con la respuesta guardada');
verificar(LuciaSoap::stockDeDetalle(['buque' => 'X']) === null, 'stock: sin respuesta de Lucía no se informa');
require_once dirname(__DIR__) . '/src/vistas.php';
$cHamu = ['lucia_estado' => 'encontrado', 'lucia_detalle' => json_encode($hamu->detalle)];
verificar(stockContenedor($cHamu, $base)['texto'] === 'Salió del stock 14/10 en depósito 1694', 'stock: texto para mostrar');
verificar(stockContenedor(['lucia_estado' => 'pendiente', 'lucia_detalle' => null], $base) === null, 'stock: contenedor sin consultar no muestra nada');
verificar(str_contains($res, 'Tránsito: viaje 8319011 · matrícula AAA0000 · 14/10'), 'resumen: tránsito');

// Sin convalidada: terminal por depósito del manifiesto; la fecha de relleno 20260101 se descarta
$soloManif = preg_replace('#<CONVALIDADA>.*</CONVALIDADA>#s', '<CONVALIDADA />', file_get_contents(__DIR__ . '/fixtures/lucia_respuesta_con_datos.xml'));
$m = $soapReal->interpretar($soloManif, [], strtotime('2026-10-14 12:00'));
verificar($m->terminal === 'TCP' && $m->fecha === null, 'sin convalidada: TCP por depósito 1650; la fecha 2026-01-01 se descarta por ser de relleno');
$m2 = $soapReal->interpretar(str_replace('<FECHAARRIB>20260101</FECHAARRIB>', '<FECHAARRIB>20261020</FECHAARRIB>', $soloManif), [], strtotime('2026-10-14 12:00'));
verificar($m2->fecha === '2026-10-20 00:00', 'una fecha de arribo plausible del manifiesto sí se usa');

echo "Vinculación por terminal y fecha de descarga\n";
$db4 = Db::conectar(['driver' => 'sqlite', 'sqlite_path' => ':memory:']);
$ins = $db4->prepare('INSERT INTO escalas (terminal, clave, buque, viaje, eta, etb, etd, primera_vez, ultima_vez, activa) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
foreach ([
    ['TCP', 'a', 'BUQUE ANTERIOR', 'Sem. 39', '2026-09-28 06:00', '2026-09-28 10:00', '2026-09-29 08:00', 0],
    ['TCP', 'b', 'BUQUE DE LA DESCARGA', 'Sem. 40', '2026-10-03 02:00', '2026-10-03 08:00', '2026-10-04 06:00', 0],
    ['TCP', 'c', 'BUQUE SIGUIENTE', 'Sem. 40', '2026-10-04 20:00', '2026-10-05 02:00', '2026-10-05 20:00', 1],
    ['MONTECON', 'd', 'OTRA TERMINAL', '1', '2026-10-03 06:00', '2026-10-03 07:00', '2026-10-04 07:00', 1],
] as [$term, $clave, $buque, $viaje, $eta, $etb, $etd, $activa]) {
    $ins->execute([$term, $clave, $buque, $viaje, $eta, $etb, $etd, '2026-09-20 00:00:00', '2026-10-01 00:00:00', $activa]);
}
$cont4 = new Contenedores($db4);
$idEsc = $cont4->buscarEscalaPorTerminal('TCP', '2026-10-03 20:28');
verificar($db4->query("SELECT buque FROM escalas WHERE id = $idEsc")->fetchColumn() === 'BUQUE DE LA DESCARGA', 'descarga 03/10 20:28 en TCP → el buque que operaba entonces (aunque ya salió del line-up)');
verificar($cont4->buscarEscalaPorTerminal('TCP', '2026-10-15 10:00') === null, 'sin buque cerca de esa fecha → no se vincula');
verificar($db4->query('SELECT buque FROM escalas WHERE id = ' . $cont4->buscarEscalaPorTerminal('MONTECON', '2026-10-03 20:28'))->fetchColumn() === 'OTRA TERMINAL', 'respeta la terminal');

$cont4->guardarContenedor('HAMU4640084', '2026-500');
$luciaFija = new class (file_get_contents(__DIR__ . '/fixtures/lucia_respuesta_con_datos.xml')) implements ConsultaLucia {
    public function __construct(private string $xml) {}
    public function consultar(string $contenedor): ResultadoLucia
    {
        return (new LuciaSoap(['url' => 'http://ejemplo/']))->interpretar($this->xml, [], strtotime('2026-10-14 12:00'));
    }
};
$r = $cont4->consultarPendientes($luciaFija, ['pausa_segundos' => 0], strtotime('2026-10-14 12:00'));
$fila = $db4->query("SELECT c.lucia_mensaje, c.lucia_detalle, e.buque FROM contenedores c LEFT JOIN escalas e ON e.id = c.escala_id WHERE c.numero = 'HAMU4640084'")->fetch();
verificar($r['vinculados'] === 1 && $fila['buque'] === 'BUQUE DE LA DESCARGA', 'HAMU-464008-4 queda vinculado al buque que descargaba en TCP');
verificar(str_contains((string) $fila['lucia_mensaje'], 'deducido por terminal (TCP)'), 'se aclara que el buque es deducido');
verificar(count(json_decode($fila['lucia_detalle'], true)['resumen']) >= 5, 'se guarda el resumen para mostrar');
verificar($db4->query("SELECT lucia_estado FROM contenedores WHERE numero = 'HAMU4640084'")->fetchColumn() === 'en_stock', 'ya ingresó a stock → queda marcado');
$r = $cont4->consultarPendientes($luciaFija, ['pausa_segundos' => 0, 'reconsultar_horas' => 2], strtotime('2026-10-15 12:00'));
verificar($r['consultados'] === 0, 'un contenedor que ya ingresó a stock no se vuelve a consultar');
// Consultado antes de esta regla (quedó "encontrado"): se marca con la respuesta guardada, sin consultar.
$db4->exec("UPDATE contenedores SET lucia_estado = 'encontrado' WHERE numero = 'HAMU4640084'");
$r = $cont4->consultarPendientes($luciaFija, ['pausa_segundos' => 0, 'reconsultar_horas' => 2], strtotime('2026-10-15 12:00'));
verificar($r['consultados'] === 0 && $db4->query("SELECT lucia_estado FROM contenedores WHERE numero = 'HAMU4640084'")->fetchColumn() === 'en_stock', 'los ya consultados con stock se marcan sin volver a consultar');

echo "Buque por número de manifiesto (afMicXmlunif)\n";
verificar($hamu->detalle['claves_manifiesto'] === [['1', '0', '291201', '1650', '20260101']], 'la consulta del contenedor obtiene la clave del manifiesto (LINMANIF y CONVALIDADA coinciden)');
$lm = new LuciaManifiesto([]);
verificar($lm->url(['1', '0', '291201 ', '1650', '20260101']) === 'https://servicios.aduanas.gub.uy/luciaws/afMicXmlunif.aspx?1,0,291201,1650,20260101', 'arma la URL del manifiesto como en la documentación');
$rm = $lm->interpretar(file_get_contents(__DIR__ . '/fixtures/lucia_manifiesto_supuesto.xml'));
verificar($rm->buque === 'BUQUE DE LA DESCARGA' && $rm->viaje === '641W' && $rm->fecha === '2026-10-03 06:00', 'lee buque, viaje y fecha del manifiesto (respuesta supuesta)');
verificar((new LuciaManifiesto(['campos_manifiesto' => ['buque' => ['Embarcacion']]]))->interpretar('<R><Embarcacion>CAP X</Embarcacion></R>')->buque === 'CAP X', 'acepta nombres de elementos configurados');
verificar((new LuciaManifiesto([]))->interpretar('<R><Buque>12345</Buque></R>')->buque === null, 'un código numérico no se toma como nombre de buque');

// De punta a punta: contenedor → manifiesto → buque por nombre (con caché del manifiesto)
$manifFalso = new class implements BuscadorManifiesto {
    public int $llamadas = 0;
    public function consultar(array $clave): ResultadoManifiesto
    {
        $this->llamadas++;
        return (new LuciaManifiesto([]))->interpretar(file_get_contents(__DIR__ . '/fixtures/lucia_manifiesto_supuesto.xml'));
    }
};
// Escala por nombre en Montecon (otra terminal y fecha que la deducción por TCP) para comprobar que manda el manifiesto
$ins->execute(['MONTECON', 'e', 'BUQUE DE LA DESCARGA', '641W', '2026-10-03 06:00', '2026-10-03 07:00', '2026-10-04 07:00', 1]);
$idPorNombre = (int) $db4->lastInsertId();
$cont4->usarManifiestos($manifFalso);
$db4->exec("UPDATE contenedores SET lucia_consulta = NULL, lucia_estado = 'pendiente'");
$cont4->guardarContenedor('CSQU3054383', '2026-501');
$cont4->consultarPendientes($luciaFija, ['pausa_segundos' => 0], strtotime('2026-10-14 12:00'));
$filas4 = $db4->query('SELECT numero, escala_id, lucia_buque, lucia_mensaje FROM contenedores ORDER BY numero')->fetchAll(PDO::FETCH_UNIQUE);
verificar((int) $filas4['HAMU4640084']['escala_id'] === $idPorNombre && $filas4['HAMU4640084']['lucia_buque'] === 'BUQUE DE LA DESCARGA', 'el contenedor queda vinculado al buque del manifiesto, por nombre y viaje');
verificar(str_contains((string) $filas4['HAMU4640084']['lucia_mensaje'], 'según manifiesto 291201'), 'se aclara que el buque sale del manifiesto');
verificar($manifFalso->llamadas === 1, 'dos contenedores del mismo manifiesto → una sola consulta (queda guardado)');

$manifFalla = new class implements BuscadorManifiesto {
    public function consultar(array $clave): ResultadoManifiesto { throw new RuntimeException('servicio caído'); }
};
$db4->exec('DELETE FROM manifiestos');
$db4->exec("UPDATE contenedores SET lucia_consulta = NULL, lucia_estado = 'pendiente'");
$cont4->usarManifiestos($manifFalla);
$r = $cont4->consultarPendientes($luciaFija, ['pausa_segundos' => 0], strtotime('2026-10-14 12:00'));
$f = $db4->query("SELECT e.buque, c.lucia_mensaje FROM contenedores c JOIN escalas e ON e.id = c.escala_id WHERE c.numero = 'HAMU4640084'")->fetch();
verificar($r['errores'] === [] && $f['buque'] === 'BUQUE DE LA DESCARGA' && $f['lucia_mensaje'] !== null && str_contains($f['lucia_mensaje'], 'servicio caído'), 'si falla el manifiesto, se usa terminal + fecha y se avisa');

echo "Manifiesto real (afMicXmlunif, 277301) → buque del line-up de TCP\n";
$manifReal = (new LuciaManifiesto([]))->interpretar(file_get_contents(__DIR__ . '/fixtures/lucia_manifiesto_real_reducido.xml'));
verificar($manifReal->buque === 'CAP SAN ARTEMISSIO' && $manifReal->fecha === '2026-10-12 00:00', 'estructura real: buque (CGNomNave) y llegada (CGFchLleg)');
verificar($manifReal->detalle['cabecera']['AgeTraRSoc'] === 'MAERSK URUGUAY S A' && $manifReal->detalle['cabecera']['CGLugPart'] === 'CN SHA', 'cabecera: agencia y origen');
verificar(($manifReal->detalle['contenedores']['TGHU8785330']['conocimiento'] ?? '') === 'CONOCIMIENTO0002', 'cada contenedor con su conocimiento');

$db5 = Db::conectar(['driver' => 'sqlite', 'sqlite_path' => ':memory:']);
(new Sincronizador($db5, $base['campos_vigilados']))->sincronizar('TCP', leer($lector, $base, 'TCP', 'tcp_lineup_1.json'), '2026-10-07 08:00:00');
$cont5 = new Contenedores($db5);
$cont5->guardarContenedor('HAMU-464008-4', '2026-900');
// Contenedor todavía no descargado: solo línea de manifiesto 277301, sin convalidada
$respSinDescarga = preg_replace('#<CONVALIDADA>.*</CONVALIDADA>#s', '<CONVALIDADA />', file_get_contents(__DIR__ . '/fixtures/lucia_respuesta_con_datos.xml'));
$respSinDescarga = str_replace('<NROMIC>291201     </NROMIC>', '<NROMIC>277301     </NROMIC>', $respSinDescarga);
$luciaSinDescarga = new class ($respSinDescarga) implements ConsultaLucia {
    public function __construct(private string $xml) {}
    public function consultar(string $contenedor): ResultadoLucia
    {
        return (new LuciaSoap(['url' => 'http://x/']))->interpretar($this->xml, [], strtotime('2026-10-10 12:00'));
    }
};
$manif5 = new class implements BuscadorManifiesto {
    public array $claves = [];
    public function consultar(array $clave): ResultadoManifiesto
    {
        $this->claves[] = implode(',', $clave);
        return (new LuciaManifiesto([]))->interpretar(file_get_contents(__DIR__ . '/fixtures/lucia_manifiesto_real_reducido.xml'));
    }
};
$cont5->usarManifiestos($manif5);
$cont5->consultarPendientes($luciaSinDescarga, ['pausa_segundos' => 0], strtotime('2026-10-10 12:00'));
verificar($manif5->claves === ['1,0,277301,1650,20260101'], 'consulta el manifiesto con la clave de LINMANIF');
$f5 = $db5->query("SELECT e.terminal, e.buque, e.viaje, c.lucia_mensaje, c.lucia_detalle FROM contenedores c JOIN escalas e ON e.id = c.escala_id")->fetch();
verificar($f5 && $f5['terminal'] === 'TCP' && $f5['buque'] === 'CAP SAN ARTEMISSIO' && $f5['viaje'] === 'Sem. 42', 'HAMU-464008-4 queda vinculado a CAP SAN ARTEMISSIO (TCP, semana 42) antes de llegar');
verificar(str_contains((string) $f5['lucia_mensaje'], 'según manifiesto 277301'), 'se aclara que el buque sale del manifiesto');
$primera = json_decode($f5['lucia_detalle'], true)['resumen'][0] ?? '';
verificar($primera === 'Manifiesto 277301: CAP SAN ARTEMISSIO · llegada 12/10 · agencia MAERSK URUGUAY S A · desde CN SHA · conocimiento CONOCIMIENTO0001 (CONSIGNATARIO UNO)', 'resumen: buque, llegada, agencia, origen y conocimiento');
$guardado = json_decode((string) $db5->query('SELECT detalle FROM manifiestos')->fetchColumn(), true);
verificar(isset($guardado['cabecera'], $guardado['contenedores']) && !isset($guardado['respuesta']), 'del manifiesto se guarda solo cabecera y contenedores, no la respuesta entera');

echo "Usuarios\n";
$db6 = Db::conectar(['driver' => 'sqlite', 'sqlite_path' => ':memory:']);
$us = new Usuarios($db6, []);
verificar(!$us->hayAlguno(), 'instalación nueva: no hay usuarios');
verificar($us->crear('marcelo', 'corta', 'corta') !== null, 'rechaza contraseña de menos de 8 caracteres');
verificar($us->crear('marcelo', 'clave-larga-1', 'otra-cosa-1') !== null, 'rechaza contraseñas que no coinciden');
verificar($us->crear('con espacio', 'clave-larga-1', 'clave-larga-1') !== null, 'rechaza nombres con espacios');
verificar($us->crear('marcelo', 'clave-larga-1', 'clave-larga-1') === null && $us->hayAlguno(), 'crea el usuario');
verificar($us->crear('marcelo', 'clave-larga-2', 'clave-larga-2') !== null, 'no duplica el nombre');
verificar($us->verificar('marcelo', 'clave-larga-1') && !$us->verificar('marcelo', 'mala-clave'), 'verifica la contraseña');
$idM = (int) $db6->query("SELECT id FROM usuarios WHERE nombre = 'marcelo'")->fetchColumn();
verificar($us->borrar($idM) !== null, 'no deja borrar el único usuario');
verificar($us->cambiarClave($idM, 'nueva-clave-1', 'nueva-clave-1') === null && $us->verificar('marcelo', 'nueva-clave-1'), 'cambia la contraseña');
$us->crear('ana', 'clave-de-ana', 'clave-de-ana');
verificar($us->borrar($idM) === null && !$us->verificar('marcelo', 'nueva-clave-1'), 'con otro usuario, sí se puede borrar');
$conCfg = new Usuarios($db6, ['usuarios' => ['admin' => password_hash('desde-config', PASSWORD_DEFAULT)]]);
verificar($conCfg->verificar('admin', 'desde-config') && count($conCfg->lista()) === 2, 'también valen los usuarios de config.php');

echo "Contenedores por usuario\n";
// Base creada con la versión anterior (sin la columna usuario): se agrega sola al conectar.
$archivoViejo = tempnam(sys_get_temp_dir(), 'puerto');
$vieja = new PDO('sqlite:' . $archivoViejo);
$vieja->exec(preg_replace('/^\s*usuario\s+VARCHAR\(40\)\s+NULL,\n/m', '', file_get_contents(dirname(__DIR__) . '/sql/schema.sqlite.sql')));
$vieja->exec("INSERT INTO contenedores (numero, carpeta, creado) VALUES ('CSQU3054383', '1', '2026-10-01 00:00:00')");
unset($vieja);
$db6 = Db::conectar(['driver' => 'sqlite', 'sqlite_path' => $archivoViejo]);
verificar($db6->query('SELECT usuario FROM contenedores')->fetchColumn() === null, 'una base anterior recibe la columna usuario sin perder datos');
$cont6 = new Contenedores($db6);
$cont6->guardarContenedor('CSQU3054383', '1', null, 'ana');
$cont6->guardarContenedor('MSKU9070323', '2', null, 'beto');
$cont6->guardarContenedor('MSKU9070323', '2', null, 'ana');
$duenos = $db6->query('SELECT numero, usuario FROM contenedores ORDER BY numero')->fetchAll(PDO::FETCH_KEY_PAIR);
verificar($duenos === ['CSQU3054383' => 'ana', 'MSKU9070323' => 'beto'], 'el contenedor sin usuario pasa a quien lo vuelve a cargar; uno con dueño lo conserva');
$cont6->importarContenedores("TGHU8785330\t3", 'beto');
$db6->exec('UPDATE contenedores SET escala_id = NULL');
$db6->exec("INSERT INTO escalas (terminal, clave, buque, primera_vez, ultima_vez) VALUES ('TCP', 'k', 'B', '2026-10-01', '2026-10-01')");
$db6->exec('UPDATE contenedores SET escala_id = ' . (int) $db6->lastInsertId());
verificar(count($cont6->porEscala('beto')[1] ?? []) === 2 && count($cont6->porEscala()[1] ?? []) === 3, 'tablero: los contenedores de un usuario o los de todos');
verificar($db6->query("SELECT tipo FROM contenedores WHERE numero = 'CSQU3054383'")->fetchColumn() === 'entero', 'una base anterior recibe la columna tipo; los existentes quedan como enteros');
verificar(Contenedores::tipoDe('Apertura') === 'apertura' && Contenedores::tipoDe(' a ') === 'apertura' && Contenedores::tipoDe('ENTERO') === 'entero' && Contenedores::tipoDe('xx') === null, 'tipo: reconoce Entero / Apertura y abreviaturas');
$r = $cont6->importarContenedores("Contenedor\tCarpeta\tRUT\tTipo\nMSCU1234565\t4\t\tApertura\nCSQU3054383\t1\t\t\nHAMU4640084\t5\t\tmedio\n", 'ana');
$tipos = $db6->query('SELECT numero, tipo FROM contenedores ORDER BY numero')->fetchAll(PDO::FETCH_KEY_PAIR);
verificar($r['ok'] === 2 && count($r['errores']) === 1 && $tipos['MSCU1234565'] === 'apertura' && $tipos['CSQU3054383'] === 'entero', 'importa el tipo desde Excel; sin tipo queda entero; un tipo desconocido se rechaza');
$cont6->guardarContenedor('CSQU3054383', '1', null, 'ana', 'apertura');
$cont6->importarContenedores("CSQU3054383\t1", 'ana');
verificar($db6->query("SELECT tipo FROM contenedores WHERE numero = 'CSQU3054383'")->fetchColumn() === 'apertura', 'reimportar sin tipo no pisa una apertura');
unset($db6, $cont6);
@unlink($archivoViejo);

echo "Tracking de navieras (DCSA)\n";
$dcsa = file_get_contents(__DIR__ . '/fixtures/naviera_dcsa_supuesta.json');
$nav = Navieras::interpretarDcsa('MAERSK', $dcsa);
verificar($nav->buque === 'SAN CHRISTOBAL' && $nav->viaje === '642S', 'buque y viaje de la llegada a Montevideo (el feeder, no el buque de origen)');
verificar($nav->fecha === '2026-10-21 10:00' && $nav->tipoFecha === 'EST', 'llegada: la estimada más reciente gana a la planificada, en hora de Uruguay');
verificar(str_contains(implode(' ', $nav->resumen), 'Tracking MAERSK: SAN CHRISTOBAL viaje 642S · llegada estimada 21/10 10:00'), 'resumen para mostrar');
$soloArray = json_encode(json_decode($dcsa, true)['events']);
verificar(Navieras::interpretarDcsa('HAPAG', $soloArray)->buque === 'SAN CHRISTOBAL', 'acepta la lista de eventos sin "events" (DCSA estándar)');
$sinMvd = Navieras::interpretarDcsa('MAERSK', json_encode(['events' => array_slice(json_decode($dcsa, true)['events'], 0, 2)]));
verificar($sinMvd->buque === null && str_contains($sinMvd->resumen[0], 'todavía sin llegada a UYMVD'), 'sin llegada a Montevideo: sin buque, con el último evento');
verificar(Navieras::interpretarDcsa('MAERSK', '{"events": []}') === null, 'sin eventos: la naviera no conoce el contenedor');
$navCfg = ['lineas' => [
    'MAERSK' => ['activa' => true, 'prefijos' => ['MSKU'], 'url' => 'http://x/{contenedor}'],
    'HAPAG' => ['activa' => true, 'prefijos' => ['HLXU'], 'url' => 'http://y/{contenedor}'],
    'CMA CGM' => ['activa' => false, 'prefijos' => ['CMAU'], 'url' => 'http://z/{contenedor}'],
]];
verificar(array_keys((new Navieras($navCfg))->lineasPara('MSKU9070323')) === ['MAERSK'], 'por el prefijo se consulta solo la naviera dueña');
verificar((new Navieras($navCfg))->lineasPara('TGHU8785330') === [] && array_keys((new Navieras($navCfg + ['probar_todas' => true]))->lineasPara('TGHU8785330')) === ['MAERSK', 'HAPAG'], 'leasing: ninguna, o todas las activas con probar_todas');
verificar(Navieras::crear(['lineas' => ['X' => ['activa' => false, 'url' => 'u']]]) === null, 'sin navieras activas no se consulta nada');

// De punta a punta: Lucía todavía no lo tiene → tracking → escala del tablero
$db7 = Db::conectar(['driver' => 'sqlite', 'sqlite_path' => ':memory:']);
$insE = $db7->prepare("INSERT INTO escalas (terminal, clave, buque, viaje, eta, etd, primera_vez, ultima_vez, activa) VALUES (?, ?, ?, ?, ?, ?, '2026-10-01', '2026-10-01', 1)");
$insE->execute(['TCP', 'v1', 'SAN CHRISTOBAL', 'Sem. 39', '2026-09-25 08:00', '2026-09-26 08:00']);
$insE->execute(['TCP', 'v2', 'SAN CHRISTOBAL', 'Sem. 43', '2026-10-21 09:00', '2026-10-22 08:00']);
$idSan = (int) $db7->lastInsertId();
$cont7 = new Contenedores($db7);
$cont7->guardarContenedor('MSKU9070323', 'N1');
$navFija = new class ($dcsa) implements ConsultaNaviera {
    public int $llamadas = 0;
    public function __construct(private string $json) {}
    public function consultar(string $c): ?ResultadoNaviera { $this->llamadas++; return Navieras::interpretarDcsa('MAERSK', $this->json); }
};
$cont7->usarNavieras($navFija);
$luciaVacia = new class implements ConsultaLucia {
    public function consultar(string $c): ResultadoLucia { return new ResultadoLucia(false); }
};
$r = $cont7->consultarPendientes($luciaVacia, ['pausa_segundos' => 0], strtotime('2026-10-17 10:00'));
$f7 = $db7->query("SELECT * FROM contenedores WHERE numero = 'MSKU9070323'")->fetch();
verificar((int) $f7['escala_id'] === $idSan && $f7['lucia_estado'] === 'no_encontrado' && $r['vinculados'] === 1, 'sin datos en Lucía, se vincula al buque que da el tracking (la escala de la fecha de llegada)');
verificar(str_contains($f7['lucia_mensaje'], 'tracking de MAERSK') && str_contains(implode(' ', json_decode($f7['lucia_detalle'], true)['resumen']), 'SAN CHRISTOBAL'), 'se aclara que el buque sale del tracking');
$db7->exec('UPDATE contenedores SET lucia_consulta = NULL');
$r = $cont7->consultarPendientes($luciaVacia, ['pausa_segundos' => 0], strtotime('2026-10-20 10:00'));
$f7 = $db7->query("SELECT * FROM contenedores WHERE numero = 'MSKU9070323'")->fetch();
verificar($navFija->llamadas === 1 && (int) $f7['escala_id'] === $idSan && $r['vinculados'] === 1, 'antes de una semana no se vuelve a consultar la naviera: se usa el resultado guardado');
$navFalla = new class implements ConsultaNaviera {
    public function consultar(string $c): ?ResultadoNaviera { throw new RuntimeException('HTTP 401'); }
};
$cont7->usarNavieras($navFalla);
$db7->exec('UPDATE contenedores SET lucia_consulta = NULL');
$r = $cont7->consultarPendientes($luciaVacia, ['pausa_segundos' => 0], strtotime('2026-10-25 10:00'));
$f7 = $db7->query("SELECT * FROM contenedores WHERE numero = 'MSKU9070323'")->fetch();
verificar($r['errores'] === [] && (int) $f7['escala_id'] === $idSan, 'pasada una semana se consulta de nuevo; si falla, sigue con el resultado anterior');
// Ya manifestado en Lucía (sin buque en los datos del contenedor): no se consulta más la naviera.
$navContador = new class implements ConsultaNaviera {
    public int $llamadas = 0;
    public function consultar(string $c): ?ResultadoNaviera { $this->llamadas++; return null; }
};
$cont7->usarNavieras($navContador);
$luciaManif = new class implements ConsultaLucia {
    public function consultar(string $c): ResultadoLucia { return new ResultadoLucia(true, null, null, '2026-10-21 10:00', ['manifiesto' => '291201', 'claves_manifiesto' => [['1', '0', '291201', '1650', '20261021']]], 'TCP'); }
};
$db7->exec("UPDATE contenedores SET lucia_consulta = NULL, naviera_consulta = NULL");
$cont7->consultarPendientes($luciaManif, ['pausa_segundos' => 0], strtotime('2026-11-05 10:00'));
verificar($navContador->llamadas === 0, 'manifestado en Lucía: la naviera no se consulta más');
$luciaSinManif = new class implements ConsultaLucia {
    public function consultar(string $c): ResultadoLucia { return new ResultadoLucia(true, null, null, null, ['claves_manifiesto' => []]); }
};
$db7->exec("UPDATE contenedores SET lucia_consulta = NULL, lucia_estado = 'pendiente'");
$cont7->consultarPendientes($luciaSinManif, ['pausa_segundos' => 0], strtotime('2026-11-05 12:00'));
verificar($navContador->llamadas === 1, 'en Lucía pero sin manifiesto todavía: se consulta la naviera');

echo "Tracking unificado (FindTEU, respuestas supuestas)\n";
$fa = Navieras::interpretar('FINDTEU', 'findteu', file_get_contents(__DIR__ . '/fixtures/findteu_supuesta_a.json'));
verificar($fa->buque === 'SAN CHRISTOBAL' && $fa->viaje === '642S' && $fa->fecha === '2026-10-21 10:00' && $fa->tipoFecha === 'EST', 'eventos con lugar y buque: toma la llegada a Montevideo (feeder), estimada');
$fb = Navieras::interpretar('FINDTEU', 'findteu', file_get_contents(__DIR__ . '/fixtures/findteu_supuesta_b.json'));
verificar($fb->buque === 'SAN CHRISTOBAL' && $fb->viaje === '642S' && $fb->fecha === '2026-10-21 10:00', 'datos planos (vessel_name, pod, eta)');
verificar(Navieras::interpretar('FINDTEU', 'findteu', '{"status": "processing"}') === null, 'todavía procesando: se reintenta en la próxima pasada');
$fc = Navieras::interpretar('FINDTEU', 'findteu', '{"events": [{"location": {"locode": "CNSHA"}, "date": "2026-09-20", "vessel": "X"}]}');
verificar($fc->buque === null && str_contains($fc->resumen[0], 'sin llegada'), 'sin evento en Montevideo: sin buque');
verificar(array_keys((new Navieras(['lineas' => ['FINDTEU' => ['activa' => true, 'prefijos' => ['*'], 'url' => 'u']]]))->lineasPara('TGHU8785330')) === ['FINDTEU'], 'prefijo "*": cualquier contenedor, también los de leasing');

echo "Zona horaria de una terminal\n";
verificar(Texto::desdeZona('2026-10-07 10:37', 'UTC') === '2026-10-07 07:37', 'UTC → hora de Uruguay (−3)');
verificar(Texto::desdeZona('2026-10-08 01:00', 'UTC') === '2026-10-07 22:00', 'cruza el cambio de día');
verificar(Texto::desdeZona(null, 'UTC') === null && Texto::desdeZona('a confirmar', 'UTC') === 'a confirmar', 'sin fecha o texto: sin cambios');
$tMon = $base['terminales']['MONTECON'];
$tMon['url'] = __DIR__ . '/fixtures/montecon_schedule_1.json';
$tMon['zona_horaria'] = 'UTC';
$xiamenUtc = array_values(array_filter($lector->leerTerminal($tMon), fn($f) => $f['buque'] === 'XIAMEN EXPRESS'))[0];
verificar($xiamenUtc['etb'] === '2026-10-07 04:37' && $xiamenUtc['eta'] === '2026-10-07 04:37', "con 'zona_horaria' => 'UTC' las horas de Montecon se corren 3 horas");

echo $fallas ? "\n$fallas prueba(s) fallaron\n" : "\nTodas las pruebas pasaron\n";
exit($fallas ? 1 : 0);
