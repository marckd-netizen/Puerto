<?php
/*
 * Verificación de la instalación. Solo responde desde la misma PC (localhost).
 *
 * Está escrito a propósito con sintaxis antigua (array(), sin tipos ni "??") para que
 * funcione incluso con versiones de PHP que el sitio no soporta y pueda decir qué falta.
 */

$raiz = dirname(dirname(__FILE__));
$remota = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
if (!in_array($remota, array('127.0.0.1', '::1'))) {
    // En un hosting: se permite con la clave de instalación de config.php (verificar.php?clave=...).
    $claveConfig = '';
    $archivo = getenv('PUERTO_CONFIG') ? getenv('PUERTO_CONFIG') : $raiz . DIRECTORY_SEPARATOR . 'config.php';
    if (is_file($archivo)) {
        $c = @include $archivo;
        if (is_array($c) && isset($c['clave_instalacion'])) {
            $claveConfig = (string) $c['clave_instalacion'];
        }
    }
    $clave = isset($_GET['clave']) ? (string) $_GET['clave'] : '';
    $ok = strlen($claveConfig) >= 12 && (function_exists('hash_equals') ? hash_equals($claveConfig, $clave) : $claveConfig === $clave);
    if (!$ok) {
        header('HTTP/1.1 403 Forbidden');
        header('Content-Type: text/html; charset=utf-8');
        exit('Esta página solo se puede abrir desde el mismo equipo (localhost).<br><br>Si el sitio está en un hosting: '
            . 'poné una clave de al menos 12 caracteres en <b>\'clave_instalacion\'</b> de config.php y abrí '
            . '<b>verificar.php?clave=</b><i>esa clave</i>. Cuando el sitio funcione, volvé a dejarla vacía.');
    }
}
header('Content-Type: text/html; charset=utf-8');

$filas = array();
function fila(&$filas, $ok, $que, $detalle)
{
    $filas[] = array($ok, $que, $detalle);
}

// 1. Versión de PHP
$versionOk = version_compare(PHP_VERSION, '8.1.0', '>=');
fila($filas, $versionOk, 'Versión de PHP', 'Tenés PHP ' . PHP_VERSION . ($versionOk ? '' : '. Hace falta 8.1 o más nuevo (por ejemplo, XAMPP con PHP 8.2).'));

// 2. Extensiones
$extensiones = array('pdo', 'pdo_mysql', 'mbstring', 'dom', 'simplexml', 'openssl');
foreach ($extensiones as $ext) {
    $ok = extension_loaded($ext);
    fila($filas, $ok, 'Extensión ' . $ext, $ok ? 'Activa' : 'No está activa: en php.ini quitá el ";" de la línea "extension=' . $ext . '" y reiniciá Apache.');
}
// curl es opcional: sin ella el sitio usa las funciones de PHP (necesitan openssl para HTTPS).
fila($filas, extension_loaded('curl') || extension_loaded('openssl'), 'Extensión curl (opcional)', extension_loaded('curl')
    ? 'Activa'
    : (extension_loaded('openssl')
        ? 'No está activa. No hace falta: el sitio se conecta con las funciones de PHP y openssl.'
        : 'No está activa, y tampoco openssl: hace falta al menos openssl para conectarse por HTTPS.'));

// 2b. Certificados para HTTPS (en Windows PHP no suele tener la lista configurada)
$caIni = ini_get('openssl.cafile') ? ini_get('openssl.cafile') : ini_get('curl.cainfo');
$caPropia = $raiz . DIRECTORY_SEPARATOR . 'certificados' . DIRECTORY_SEPARATOR . 'cacert.pem';
if ($caIni) {
    fila($filas, is_file($caIni), 'Certificados HTTPS', is_file($caIni) ? 'php.ini indica ' . $caIni : 'php.ini indica ' . $caIni . ', pero ese archivo no existe.');
} elseif (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    fila($filas, is_file($caPropia), 'Certificados HTTPS', is_file($caPropia)
        ? 'Se usa la lista incluida en el sitio (certificados/cacert.pem).'
        : 'Falta certificados/cacert.pem: sin esa lista no se puede conectar con las terminales ni con Lucía.');
} else {
    fila($filas, true, 'Certificados HTTPS', 'Se usan los del sistema.');
}

// 3. config.php
$archivoConfig = getenv('PUERTO_CONFIG') ? getenv('PUERTO_CONFIG') : $raiz . DIRECTORY_SEPARATOR . 'config.php';
$cfg = null;
if (!is_file($archivoConfig)) {
    fila($filas, false, 'config.php', 'No existe ' . $archivoConfig . '. Copiá config.example.php con ese nombre y completalo.');
} elseif (!$versionOk) {
    fila($filas, false, 'config.php', 'Existe, pero no se revisa hasta tener PHP 8.1 o más nuevo.');
} else {
    $cfg = include $archivoConfig;
    fila($filas, is_array($cfg), 'config.php', is_array($cfg) ? 'Se leyó bien.' : 'No devuelve la configuración: revisá que termine con "];".');
}

// 4. Base de datos
if (is_array($cfg) && isset($cfg['db'])) {
    $db = $cfg['db'];
    $driver = isset($db['driver']) ? $db['driver'] : 'mysql';
    try {
        if ($driver === 'sqlite') {
            $pdo = new PDO('sqlite:' . $db['sqlite_path']);
        } else {
            $puerto = isset($db['port']) ? $db['port'] : 3306;
            $pdo = new PDO('mysql:host=' . $db['host'] . ';port=' . $puerto . ';dbname=' . $db['database'] . ';charset=utf8mb4', $db['user'], $db['password']);
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        fila($filas, true, 'Conexión a la base', 'Conectado (' . $driver . ').');

        $faltanTablas = array();
        foreach (array('escalas', 'cambios', 'lecturas', 'clientes', 'contenedores', 'manifiestos', 'usuarios') as $tabla) {
            try {
                $pdo->query('SELECT 1 FROM ' . $tabla . ' LIMIT 1');
            } catch (Exception $e) {
                $faltanTablas[] = $tabla;
            }
        }
        fila($filas, !$faltanTablas, 'Tablas', $faltanTablas
            ? 'Faltan: ' . implode(', ', $faltanTablas) . '. Cargá sql/schema.mysql.sql (por ejemplo desde phpMyAdmin → Importar).'
            : 'Están todas.');
    } catch (Exception $e) {
        fila($filas, false, 'Conexión a la base', $e->getMessage() . ' — Revisá servidor, nombre de la base, usuario y clave en config.php, y que MySQL esté iniciado.');
    }
}

// 5. Usuarios para entrar
if (is_array($cfg)) {
    $enConfig = isset($cfg['usuarios']) ? count($cfg['usuarios']) : 0;
    $enBase = 0;
    if (isset($pdo)) {
        try {
            $enBase = (int) $pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();
        } catch (Exception $e) {
        }
    }
    fila($filas, true, 'Usuarios', ($enConfig + $enBase) > 0
        ? 'Hay ' . ($enConfig + $enBase) . ' usuario(s).'
        : 'Todavía no hay usuarios: al entrar al sitio se pide crear el primero (desde esta misma PC).');
}

// 6. Carpeta pública
$publica = basename(dirname(__FILE__)) === 'public';
fila($filas, $publica, 'Carpeta del sitio', $publica
    ? 'El servidor web apunta a la carpeta public (correcto).'
    : 'Este archivo no está en la carpeta public.');

$todoOk = true;
foreach ($filas as $f) {
    $todoOk = $todoOk && $f[0];
}
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Verificar instalación</title>
<style>
    body { font: 15px/1.4 system-ui, -apple-system, "Segoe UI", sans-serif; background: #f5f6f8; color: #1d2330; margin: 0; padding: 24px 16px; }
    main { max-width: 860px; margin: 0 auto; }
    table { border-collapse: collapse; width: 100%; background: #fff; border: 1px solid #e3e6ec; }
    td { padding: 8px 10px; border-bottom: 1px solid #e3e6ec; vertical-align: top; }
    .ok { color: #1e8e3e; font-weight: 600; } .mal { color: #c0392b; font-weight: 600; }
    .resumen { padding: 10px 14px; border-radius: 6px; margin-bottom: 16px; }
</style>
</head>
<body>
<main>
<h1>Verificar instalación</h1>
<p class="resumen" style="background:<?php echo $todoOk ? '#e6f4ea' : '#fdecea'; ?>">
    <?php echo $todoOk ? 'Todo en orden. <a href="index.php">Ir al sitio</a>.' : 'Hay puntos para corregir (en rojo).'; ?>
</p>
<table>
<?php foreach ($filas as $f): ?>
    <tr>
        <td class="<?php echo $f[0] ? 'ok' : 'mal'; ?>"><?php echo $f[0] ? '✔' : '✘'; ?></td>
        <td><strong><?php echo htmlspecialchars($f[1], ENT_QUOTES, 'UTF-8'); ?></strong></td>
        <td><?php echo htmlspecialchars($f[2], ENT_QUOTES, 'UTF-8'); ?></td>
    </tr>
<?php endforeach; ?>
</table>
</main>
</body>
</html>
