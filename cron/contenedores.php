<?php
/**
 * Consulta en Lucía los contenedores activos y los vincula con la escala de su buque.
 * Uso: php cron/contenedores.php
 * Conviene correrlo después de cron/actualizar.php, para vincular con las escalas recién leídas
 * (cron/tareas.php corre las dos cosas en ese orden).
 */
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

if (Tareas::esPedidoWeb()) {
    http_response_code(403);
    exit;
}

$cfg = config();
$ok = Tareas::contenedores(
    $cfg,
    Db::conectar($cfg['db']),
    fn($linea) => print("$linea\n"),
    fn($linea) => fwrite(defined('STDERR') ? STDERR : fopen('php://stderr', 'w'), "$linea\n"),
);
exit($ok ? 0 : 1);
