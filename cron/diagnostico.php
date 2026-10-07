<?php
/**
 * Prueba la lectura de una terminal sin guardar nada en la base.
 * Uso: php cron/diagnostico.php TCP [--crudo]   (--crudo muestra también la respuesta tal cual)
 */
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$cfg = config();
$codigo = strtoupper($argv[1] ?? '');
if (!isset($cfg['terminales'][$codigo])) {
    exit('Uso: php cron/diagnostico.php ' . implode('|', array_keys($cfg['terminales'])) . "\n");
}
$terminal = $cfg['terminales'][$codigo];
$lector = new Lector($cfg['http'] ?? []);

echo "Leyendo {$terminal['url']}\n";
try {
    $contenido = $lector->descargar($terminal['url'], $terminal['encabezados'] ?? [], $terminal['cuerpo_post'] ?? null);
    echo 'Recibidos ' . strlen($contenido) . " bytes\n";
    if (in_array('--crudo', $argv, true)) {
        echo "\nRespuesta (primeros 3000 caracteres):\n" . mb_substr($contenido, 0, 3000) . "\n";
    }

    if (($terminal['parser'] ?? '') === 'json_api') {
        $p = new JsonApiParser($terminal['columnas']);
        $lista = $p->buscarLista(json_decode($contenido, true) ?? []);
        if ($lista) {
            echo "\nPropiedades encontradas: " . implode(', ', array_keys($p->aplanar($lista[0]))) . "\n";
            echo "\nCampo → propiedad usada:\n";
            foreach ($p->mapear($lista) as $campo => $prop) {
                echo "  $campo → $prop\n";
            }
        }
    }

    $filas = $lector->leerTerminal($terminal);
    echo "\n" . count($filas) . " buques leídos. Primeros 5:\n";
    foreach (array_slice($filas, 0, 5) as $f) {
        echo '  ' . json_encode($f, JSON_UNESCAPED_UNICODE) . "\n";
    }
} catch (Throwable $t) {
    echo 'ERROR: ' . $t->getMessage() . "\n";
    exit(1);
}
