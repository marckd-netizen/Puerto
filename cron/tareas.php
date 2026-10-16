<?php
/*
 * Tarea automática: lee las terminales, después consulta Lucía, y anota todo en data/cron.log.
 * Es lo que hay que programar cada 2 horas (cPanel → Cron Jobs, Linux crontab o Programador de tareas):
 *     php /ruta/al/sitio/cron/tareas.php
 * Si el "php" del cron es una versión vieja, el log lo dice y sugiere cuál usar
 * (verificar.php también muestra el comando exacto para este servidor).
 *
 * El principio está escrito con sintaxis antigua para que cualquier versión de PHP llegue a
 * anotar en el log qué pasa.
 */

if (isset($_SERVER['REQUEST_METHOD'])) {
    header('HTTP/1.1 403 Forbidden');
    exit;
}

$raiz = dirname(dirname(__FILE__));
if (!is_dir($raiz . '/data')) {
    @mkdir($raiz . '/data', 0775, true);
}
define('PUERTO_LOG_CRON', $raiz . '/data/cron.log');

function anotar($linea)
{
    if (@file_put_contents(PUERTO_LOG_CRON, $linea . "\n", FILE_APPEND) === false) {
        // Sin permiso para escribir el log: que al menos salga en el correo del cron.
        echo $linea . "\n";
    }
}

@set_time_limit(0);
anotar('===== ' . date('Y-m-d H:i:s') . ' · PHP ' . PHP_VERSION . ' (' . PHP_SAPI . ', ' . (defined('PHP_BINARY') ? PHP_BINARY : '?') . ')');

if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    $otros = array();
    foreach (array_merge((array) @glob('/opt/cpanel/ea-php8*/root/usr/bin/php'), (array) @glob('/opt/alt/php8*/usr/bin/php'), array('/usr/local/bin/php', '/usr/bin/php')) as $p) {
        if ($p && @is_file($p)) {
            $otros[] = $p;
        }
    }
    anotar('ERROR: este "php" es la versión ' . PHP_VERSION . ' y el sitio necesita 8.1 o más nuevo.');
    anotar('  En el comando del cron, cambiá "php" por la ruta completa de una versión nueva, por ejemplo:');
    foreach ($otros as $p) {
        anotar('  ' . $p . ' ' . __FILE__);
    }
    exit(1);
}

// Errores graves (que cortan PHP) también al log.
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
        anotar('ERROR GRAVE: ' . $e['message'] . ' en ' . $e['file'] . ':' . $e['line']);
    }
});

ob_start(function ($texto) {
    // Lo que el sitio imprima (por ejemplo un error de instalación) también va al log.
    $texto = trim(strip_tags($texto));
    if ($texto !== '') {
        anotar($texto);
    }
    return '';
});

require $raiz . '/src/bootstrap.php';

try {
    $cfg = config();
    $db = Db::conectar($cfg['db']);
    $anotar = function ($linea) {
        anotar($linea);
    };
    $ok = Tareas::actualizar($cfg, $db, $anotar, $anotar);
    $ok = Tareas::contenedores($cfg, $db, $anotar, $anotar) && $ok;
} catch (Throwable $t) {
    anotar('ERROR: ' . $t->getMessage() . ' (' . basename($t->getFile()) . ':' . $t->getLine() . ')');
    $ok = false;
}
ob_end_flush();
exit($ok ? 0 : 1);
