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

function leer(Lector $lector, array $base, string $codigo, string $archivo): array
{
    $t = $base['terminales'][$codigo];
    if (str_ends_with($archivo, '.html') && $t['parser'] !== 'tabla_html') {
        // Las páginas HTML de ejemplo prueban el lector de tablas, aunque la terminal real use otro.
        $t = $base['terminales']['MONTECON'];
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
$tcp = leer($lector, $base, 'TCP', 'tcp_1.html');
$mon = leer($lector, $base, 'MONTECON', 'montecon_1.html');
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
$r1 = $sync->sincronizar('TCP', leer($lector, $base, 'TCP', 'tcp_2.html'), '2026-10-07 08:00:00');
$r2 = $sync->sincronizar('MONTECON', leer($lector, $base, 'MONTECON', 'montecon_2.html'), '2026-10-07 09:30:00');
verificar($r1['retiradas'] === 1, 'TCP: MSC ARIANE retirado de la lista');
verificar($r1['actualizadas'] === 2, 'TCP: 2 buques con cambios');
verificar($r2['cambios'] === 2, 'Montecon: ETA y ETD cambiadas');

$c = $db->query("SELECT c.campo, c.valor_anterior, c.valor_nuevo FROM cambios c JOIN escalas e ON e.id = c.escala_id WHERE e.buque = 'CAP SAN LORENZO' ORDER BY c.campo")->fetchAll();
verificar(array_column($c, 'campo') === ['eta'], 'CAP SAN LORENZO (TCP): cambia la ETA');
verificar($c[0]['valor_anterior'] === '2026-10-09 06:00' && $c[0]['valor_nuevo'] === '2026-10-10 02:00', 'CAP SAN LORENZO: ETA antes/después');
$e = $db->query("SELECT eta_original, eta FROM escalas WHERE buque = 'CAP SAN LORENZO'")->fetch();
verificar($e['eta_original'] === '2026-10-09 06:00', 'se conserva la ETA original');

echo "Tercera lectura (sin cambios)\n";
$r1 = $sync->sincronizar('TCP', leer($lector, $base, 'TCP', 'tcp_2.html'), '2026-10-07 10:00:00');
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

echo $fallas ? "\n$fallas prueba(s) fallaron\n" : "\nTodas las pruebas pasaron\n";
exit($fallas ? 1 : 0);
