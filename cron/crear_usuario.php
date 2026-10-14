<?php
/**
 * Genera la línea para agregar un usuario en config.php → 'usuarios'.
 * Uso: php cron/crear_usuario.php nombre
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
$usuario = $argv[1] ?? '';
if (!preg_match('/^[a-zA-Z0-9._-]{2,40}$/', $usuario)) {
    exit("Uso: php cron/crear_usuario.php nombre   (letras, números, punto, guion)\n");
}

fwrite(STDOUT, "Contraseña para $usuario: ");
@shell_exec('stty -echo');
$clave = rtrim((string) fgets(STDIN), "\r\n");
@shell_exec('stty echo');
fwrite(STDOUT, "\n");
if (strlen($clave) < 8) {
    exit("La contraseña debe tener al menos 8 caracteres.\n");
}

echo "\nAgregá esta línea en config.php, dentro de 'usuarios' => [ ... ]:\n\n";
echo "        " . var_export($usuario, true) . " => " . var_export(password_hash($clave, PASSWORD_DEFAULT), true) . ",\n\n";
