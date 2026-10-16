<?php
/**
 * Crea un usuario del sitio desde la consola (también se puede desde la página Usuarios,
 * y el primero desde la pantalla de ingreso).
 * Uso: php cron/crear_usuario.php nombre
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Este script es para la consola. Para crear usuarios desde el navegador, entrá al sitio:\n"
        . "si todavía no hay usuarios, la pantalla de ingreso permite crear el primero.");
}

require dirname(__DIR__) . '/src/bootstrap.php';

$nombre = $argv[1] ?? '';
fwrite(STDOUT, "Contraseña para $nombre: ");
@shell_exec('stty -echo');
$clave = rtrim((string) fgets(STDIN), "\r\n");
fwrite(STDOUT, "\nRepetir contraseña: ");
$clave2 = rtrim((string) fgets(STDIN), "\r\n");
@shell_exec('stty echo');
fwrite(STDOUT, "\n");

$cfg = config();
$error = (new Usuarios(Db::conectar($cfg['db']), $cfg))->crear($nombre, $clave, $clave2);
exit($error === null ? "Usuario $nombre creado.\n" : "No se creó: $error\n");
