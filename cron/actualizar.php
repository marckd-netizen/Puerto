<?php
/**
 * Lee las páginas de las terminales y actualiza la base.
 * Uso:   php cron/actualizar.php            (todas las terminales)
 *        php cron/actualizar.php TCP        (solo una)
 *        php cron/actualizar.php MONTECON --sin-cambios
 *              (actualiza sin registrar cambios ni resaltar filas: usar una vez después de
 *               corregir en config.php qué columna corresponde a cada fecha)
 */
// Cron sugerido (cada 2 horas):
//   0 */2 * * * php /ruta/a/Puerto/cron/actualizar.php >> /ruta/a/Puerto/data/cron.log 2>&1
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$cfg = config();
$db = Db::conectar($cfg['db']);
$lector = new Lector($cfg['http'] ?? []);
$sync = new Sincronizador($db, $cfg['campos_vigilados']);
$opciones = array_slice($argv, 1);
$sinCambios = in_array('--sin-cambios', $opciones, true);
$solo = array_values(array_filter($opciones, fn($o) => !str_starts_with($o, '--')))[0] ?? null;
$huboError = false;

foreach ($cfg['terminales'] as $codigo => $terminal) {
    if ($solo !== null && strcasecmp($solo, $codigo) !== 0) {
        continue;
    }
    $ahora = date('Y-m-d H:i:s');
    try {
        $filas = $lector->leerTerminal($terminal);
        if (!$filas) {
            // Nunca vaciar la lista por una lectura vacía: suele ser un problema de la página.
            throw new RuntimeException('La página no devolvió ningún buque');
        }
        $r = $sync->sincronizar($codigo, $filas, $ahora, !$sinCambios);
        $msg = sprintf('%d buques, %d nuevos, %d con cambios (%d campos), %d retirados',
            count($filas), $r['nuevas'], $r['actualizadas'], $r['cambios'], $r['retiradas'])
            . ($sinCambios ? ' (sin registrar cambios)' : '');
        registrar($db, $codigo, $ahora, true, count($filas), $msg);
        echo "[$ahora] $codigo: $msg\n";
    } catch (Throwable $t) {
        $huboError = true;
        registrar($db, $codigo, $ahora, false, 0, $t->getMessage());
        fwrite(STDERR, "[$ahora] $codigo: ERROR " . $t->getMessage() . "\n");
    }
}

exit($huboError ? 1 : 0);

function registrar(PDO $db, string $terminal, string $fecha, bool $ok, int $filas, string $mensaje): void
{
    $db->prepare('INSERT INTO lecturas (terminal, fecha, ok, filas, mensaje) VALUES (?, ?, ?, ?, ?)')
       ->execute([$terminal, $fecha, $ok ? 1 : 0, $filas, mb_substr($mensaje, 0, 500)]);
}
