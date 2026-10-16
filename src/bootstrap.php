<?php
declare(strict_types=1);

// Este bloque está escrito para que PHP viejo (7.x / 8.0) llegue a leerlo y muestre un
// mensaje claro, en lugar de un error 500 sin explicación al cargar el resto del código.
if (PHP_VERSION_ID < 80100) {
    falla_inicial('Este sitio necesita PHP 8.1 o más nuevo. Esta instalación tiene PHP ' . PHP_VERSION . '.');
}
// curl no es obligatoria: sin ella los pedidos se hacen con las funciones de PHP, que para HTTPS necesitan openssl.
$faltan = array_values(array_filter(['pdo', 'mbstring', 'dom', 'simplexml', extension_loaded('curl') ? 'pdo' : 'openssl'], function ($e) {
    return !extension_loaded($e);
}));
if ($faltan) {
    falla_inicial('Faltan extensiones de PHP: ' . implode(', ', array_unique($faltan)) . '. Activalas en php.ini (quitando el ";" de la línea "extension=...") y reiniciá Apache.');
}

function falla_inicial($mensaje)
{
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $mensaje . "\n");
        exit(1);
    }
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Error de instalación</title>'
        . '<div style="font:16px system-ui,sans-serif;max-width:640px;margin:40px auto;padding:20px;border:2px solid #c0392b;border-radius:8px">'
        . '<h2 style="margin-top:0;color:#c0392b">El sitio no puede funcionar todavía</h2><p>'
        . htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8') . '</p><p>Para revisar la instalación completa, abrí <a href="verificar.php">verificar.php</a>.</p></div>';
    exit;
}

require_once __DIR__ . '/Http.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Texto.php';
require_once __DIR__ . '/TablaHtmlParser.php';
require_once __DIR__ . '/JsonApiParser.php';
require_once __DIR__ . '/Lector.php';
require_once __DIR__ . '/Sincronizador.php';
require_once __DIR__ . '/Tablero.php';
require_once __DIR__ . '/Validacion.php';
require_once __DIR__ . '/Lucia.php';
require_once __DIR__ . '/LuciaSoap.php';
require_once __DIR__ . '/LuciaManifiesto.php';
require_once __DIR__ . '/Contenedores.php';
require_once __DIR__ . '/Usuarios.php';
require_once __DIR__ . '/Acceso.php';

/**
 * Errores no previstos: en lugar de un "HTTP ERROR 500" en blanco, se muestra una página
 * con el error. El detalle técnico solo se ve desde la misma PC (localhost) o con
 * 'mostrar_errores' => true en config.php; siempre queda en el registro de errores de PHP.
 */
set_exception_handler(function (Throwable $t): void {
    error_log('Puerto: ' . $t);
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'ERROR: ' . $t->getMessage() . "\n" . $t->getFile() . ':' . $t->getLine() . "\n");
        exit(1);
    }
    $local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
    $mostrar = $local || !empty($GLOBALS['__config_puerto']['mostrar_errores']);
    $mensaje = $t->getMessage();
    if ($t instanceof PDOException) {
        $mensaje = 'No se pudo usar la base de datos: ' . $mensaje
            . ' — Revisá en config.php los datos de "db" (servidor, base, usuario y clave), que MySQL esté iniciado'
            . ' y que se haya cargado el esquema (sql/schema.mysql.sql).';
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!doctype html><meta charset="utf-8"><title>Error</title>'
        . '<div style="font:16px system-ui,sans-serif;max-width:760px;margin:40px auto;padding:20px;border:2px solid #c0392b;border-radius:8px">'
        . '<h2 style="margin-top:0;color:#c0392b">Ocurrió un error</h2>'
        . ($mostrar
            ? '<p>' . htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8') . '</p><p style="color:#667085;font-size:13px">'
              . htmlspecialchars(basename($t->getFile()) . ':' . $t->getLine(), ENT_QUOTES, 'UTF-8') . '</p>'
            : '<p>El detalle quedó registrado en el log de errores del servidor.</p>')
        . '<p><a href="verificar.php">Revisar la instalación</a></p></div>';
});

function config(): array
{
    static $config = null;
    if ($config === null) {
        $archivo = getenv('PUERTO_CONFIG') ?: dirname(__DIR__) . '/config.php';
        if (!is_file($archivo)) {
            falla_inicial('Falta config.php: copiá config.example.php como config.php (en la carpeta principal del sitio, no en public) y completá los datos de la base.');
        }
        $config = require $archivo;
        $GLOBALS['__config_puerto'] = $config;
        date_default_timezone_set($config['timezone'] ?? 'America/Montevideo');
    }
    return $config;
}

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}
