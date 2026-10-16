<?php
/**
 * Lee las páginas de las terminales y actualiza la base.
 * Uso:   php cron/actualizar.php            (todas las terminales)
 *        php cron/actualizar.php TCP        (solo una)
 *        php cron/actualizar.php MONTECON --sin-cambios
 *              (actualiza sin registrar cambios ni resaltar filas: usar una vez después de
 *               corregir en config.php qué columna corresponde a cada fecha)
 * Para el cron conviene cron/tareas.php, que además consulta Lucía y anota todo en data/cron.log.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

if (Tareas::esPedidoWeb()) {
    http_response_code(403);
    exit;
}

$cfg = config();
$opciones = array_slice($argv ?? [], 1);
$solo = array_values(array_filter($opciones, fn($o) => !str_starts_with($o, '--')))[0] ?? null;
$ok = Tareas::actualizar(
    $cfg,
    Db::conectar($cfg['db']),
    fn($linea) => print("$linea\n"),
    fn($linea) => fwrite(defined('STDERR') ? STDERR : fopen('php://stderr', 'w'), "$linea\n"),
    $solo,
    in_array('--sin-cambios', $opciones, true),
);
exit($ok ? 0 : 1);
