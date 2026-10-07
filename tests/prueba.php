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
verificar(count($tcp) === 3, 'TCP: 3 buques');
verificar($tcp[0]['buque'] === 'MSC ARIANE' && $tcp[0]['eta'] === '2026-10-07 14:00', 'TCP: buque y ETA');
verificar($tcp[0]['cierre'] === '2026-10-06 12:00', 'TCP: "Cierre Documental" reconocido como cierre');
verificar(count($mon) === 2, 'Montecon: 2 buques (ignora menú y título)');
verificar($mon[0]['muelle'] === 'Muelle C' && $mon[0]['viaje'] === 'GBR0425', 'Montecon: Sitio→muelle, Voyage→viaje');
verificar(str_starts_with((string) $mon[0]['eta'], '2026-10-08'), 'Montecon: fecha sin año');

echo "Primera lectura\n";
$r1 = $sync->sincronizar('TCP', $tcp, '2026-10-07 10:00:00');
$r2 = $sync->sincronizar('MONTECON', $mon, '2026-10-07 10:00:00');
verificar($r1['nuevas'] === 3 && $r2['nuevas'] === 2, '5 escalas nuevas');
verificar((int) $db->query('SELECT COUNT(*) FROM cambios')->fetchColumn() === 0, 'sin cambios');

echo "Segunda lectura (con cambios)\n";
$r1 = $sync->sincronizar('TCP', leer($lector, $base, 'TCP', 'tcp_2.html'), '2026-10-07 10:10:00');
$r2 = $sync->sincronizar('MONTECON', leer($lector, $base, 'MONTECON', 'montecon_2.html'), '2026-10-07 10:10:00');
verificar($r1['retiradas'] === 1, 'TCP: MSC ARIANE retirado de la lista');
verificar($r1['actualizadas'] === 2, 'TCP: 2 buques con cambios');
verificar($r2['cambios'] === 2, 'Montecon: muelle y estado cambiados');

$c = $db->query("SELECT c.campo, c.valor_anterior, c.valor_nuevo FROM cambios c JOIN escalas e ON e.id = c.escala_id WHERE e.buque = 'CAP SAN LORENZO' ORDER BY c.campo")->fetchAll();
verificar(array_column($c, 'campo') === ['eta', 'etb'], 'CAP SAN LORENZO: cambian ETA y ETB');
verificar($c[0]['valor_anterior'] === '2026-10-09 06:00' && $c[0]['valor_nuevo'] === '2026-10-10 02:00', 'CAP SAN LORENZO: ETA antes/después');
$e = $db->query("SELECT eta_original, eta FROM escalas WHERE buque = 'CAP SAN LORENZO'")->fetch();
verificar($e['eta_original'] === '2026-10-09 06:00', 'se conserva la ETA original');

echo "Tercera lectura (sin cambios)\n";
$r1 = $sync->sincronizar('TCP', leer($lector, $base, 'TCP', 'tcp_2.html'), '2026-10-07 10:20:00');
verificar($r1['cambios'] === 0 && $r1['nuevas'] === 0, 'no se duplican cambios');

echo $fallas ? "\n$fallas prueba(s) fallaron\n" : "\nTodas las pruebas pasaron\n";
exit($fallas ? 1 : 0);
