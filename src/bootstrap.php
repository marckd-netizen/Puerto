<?php
declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Texto.php';
require_once __DIR__ . '/TablaHtmlParser.php';
require_once __DIR__ . '/Lector.php';
require_once __DIR__ . '/Sincronizador.php';

function config(): array
{
    static $config = null;
    if ($config === null) {
        $archivo = getenv('PUERTO_CONFIG') ?: dirname(__DIR__) . '/config.php';
        if (!is_file($archivo)) {
            http_response_code(500);
            exit("Falta config.php: copiá config.example.php como config.php y ajustalo.\n");
        }
        $config = require $archivo;
        date_default_timezone_set($config['timezone'] ?? 'America/Montevideo');
    }
    return $config;
}

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}
