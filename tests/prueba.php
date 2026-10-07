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
verificar($t->estadoPorFechas(['etb' => '2026-10-13 11:00', 'etd' => '2026-10-14 11:00']) === 'Atraque confirmado', 'con ETB futura → Atraque confirmado');
verificar($t->estadoPorFechas(['etb' => '2026-10-10 13:00', 'etd' => '2026-10-11 02:00']) === 'Zarpado', 'después de ETS → Zarpado');
verificar($t->estadoPorFechas(['etb' => null, 'etd' => null]) === 'Programado', 'sin ETB → Programado');
$filasTcp = $t->filas($db2->query('SELECT * FROM escalas WHERE activa = 1')->fetchAll(), [], []);
$artemissio = array_values(array_filter($filasTcp, fn($f) => $f['buque'] === 'CAP SAN ARTEMISSIO'))[0];
verificar($artemissio['nivel'] === 'operando' && $artemissio['estados']['TCP'] === 'Operando', 'CAP SAN ARTEMISSIO operando el 12/10 20:00 → verde');

echo "Montecon: schedule en JSON\n";
$m1 = leer($lector, $base, 'MONTECON', 'montecon_schedule_1.json');
verificar(count($m1) === 8, 'JSON: 8 buques (se excluye el marcado noMostrarSchedule)');
$xiamen = array_values(array_filter($m1, fn($f) => $f['buque'] === 'XIAMEN EXPRESS'))[0];
verificar($xiamen['viaje'] === 'NA641R' && $xiamen['servicio'] === 'MSC NORTH EUROPE', 'JSON: nroViaje y servicio');
verificar($xiamen['eta'] === '2026-10-07 03:00' && $xiamen['etb'] === '2026-10-07 07:37' && $xiamen['etd'] === '2026-10-07 21:00', 'JSON: llegadaRada→ETA, comienzoOperaciones→ETB, salida→ETD');
verificar(Texto::fecha('0001-01-01T00:00:00') === null, 'fecha 0001-01-01 = sin dato');

$db3 = Db::conectar(['driver' => 'sqlite', 'sqlite_path' => ':memory:']);
$sync3 = new Sincronizador($db3, $base['campos_vigilados']);
$sync3->sincronizar('MONTECON', $m1, '2026-10-07 08:00:00');
$sync3->sincronizar('TCP', leer($lector, $base, 'TCP', 'tcp_lineup_1.json'), '2026-10-07 08:00:00');
$r = $sync3->sincronizar('MONTECON', leer($lector, $base, 'MONTECON', 'montecon_schedule_2.json'), '2026-10-07 12:00:00');
verificar($r['retiradas'] === 1 && $r['actualizadas'] === 2 && $r['cambios'] === 2, 'Montecon: 1 retirado, 2 buques con cambio de ETD');

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
verificar($buscar('MADELEINE I')['estados']['MONTECON'] === 'Programado', 'MADELEINE I antes de la llegada a rada → Programado');
verificar($buscar('TIGER GAUCHO', '943N')['estados']['MONTECON'] === 'Programado', 'TIGER GAUCHO 943N → Programado');
verificar($buscar('ORION')['estados']['MONTECON'] === 'Cancelado' && $buscar('ORION')['nivel'] !== 'operando', 'ORION 014301/CANCEL → Cancelado');
$inc = $buscar('INCANSABLE', '1326');
verificar($inc !== null && array_keys($inc['terminales']) === ['TCP', 'MONTECON'], 'INCANSABLE figura en Montecon y TCP → una sola fila');
$t2 = new Tablero($base, strtotime('2026-10-07 22:00:00'));
verificar($t2->estadoPorFechas(['eta' => '2026-10-07 20:00', 'etb' => '2026-10-08 07:00', 'etd' => '2026-10-08 12:00'], ['con_etb' => 'Programado']) === 'En rada', 'entre llegada a rada y comienzo de operaciones → En rada');

echo $fallas ? "\n$fallas prueba(s) fallaron\n" : "\nTodas las pruebas pasaron\n";
exit($fallas ? 1 : 0);
