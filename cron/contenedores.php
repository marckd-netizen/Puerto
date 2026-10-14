<?php
/**
 * Consulta en Lucía los contenedores activos y los vincula con la escala de su buque.
 * Uso: php cron/contenedores.php
 * Conviene correrlo después de cron/actualizar.php, para vincular con las escalas recién leídas.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$cfg = config();
$db = Db::conectar($cfg['db']);
$luciaCfg = $cfg['lucia'] ?? [];
$ahora = date('Y-m-d H:i:s');

try {
    $lucia = Lucia::crear($luciaCfg);
    $r = (new Contenedores($db))->consultarPendientes($lucia, $luciaCfg, null, fn($linea) => print("  $linea\n"));
} catch (Throwable $t) {
    fwrite(STDERR, "[$ahora] LUCIA: ERROR {$t->getMessage()}\n");
    exit(1);
}

echo "[$ahora] LUCIA: {$r['consultados']} consultados, {$r['encontrados']} encontrados, {$r['vinculados']} vinculados a un buque\n";
foreach ($r['errores'] as $error) {
    fwrite(STDERR, "[$ahora] LUCIA: ERROR $error\n");
}
exit($r['errores'] ? 1 : 0);
