<?php
/**
 * Prueba el tracking de las navieras con un contenedor: muestra qué naviera se consulta,
 * la respuesta cruda y lo que el sitio entiende de ella (buque y llegada a Montevideo).
 * Uso: php cron/naviera_diagnostico.php MSKU1234565
 */
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

if (Tareas::esPedidoWeb()) {
    http_response_code(403);
    exit;
}

$numero = Validacion::normalizarContenedor($argv[1] ?? '');
if (!Validacion::formatoContenedor($numero)) {
    exit("Uso: php cron/naviera_diagnostico.php AAAA1234567\n");
}
$cfg = config();
$navCfg = $cfg['navieras'] ?? [];
$navieras = new Navieras($navCfg, $cfg['http'] ?? []);
$lineas = $navieras->lineasPara($numero);
if (!$lineas) {
    exit("Ninguna naviera activa corresponde al prefijo " . substr($numero, 0, 4) . ".\n"
        . "Activala en config.php → navieras → lineas (activa, url y clave), agregá el prefijo o usá 'probar_todas' => true.\n");
}
foreach ($lineas as $codigo => $linea) {
    echo "=== $codigo\n";
    try {
        $r = $navieras->pedirCrudo($linea, $numero);
    } catch (Throwable $t) {
        echo "ERROR: {$t->getMessage()}\n";
        continue;
    }
    echo "{$r['metodo']} {$r['url']}\nHTTP {$r['codigo']}\n";
    $archivo = dirname(__DIR__) . '/data/naviera_' . preg_replace('/\W+/', '_', $codigo) . '_' . $numero . '.json';
    @file_put_contents($archivo, $r['cuerpo']);
    echo 'Respuesta guardada en ' . $archivo . "\n" . mb_substr($r['cuerpo'], 0, 1500) . (strlen($r['cuerpo']) > 1500 ? "\n..." : '') . "\n\n";
    if ($r['codigo'] >= 200 && $r['codigo'] < 300) {
        try {
            $res = Navieras::interpretar($codigo, $linea['formato'] ?? 'dcsa', $r['cuerpo'], $navCfg['puerto'] ?? 'UYMVD');
            echo $res === null ? "Sin eventos para este contenedor.\n" : "Buque: " . ($res->buque ?? '(sin llegada al puerto todavía)') . "\nViaje: " . ($res->viaje ?? '-')
                . "\nLlegada: " . ($res->fecha ?? '-') . " ({$res->tipoFecha})\n" . implode("\n", $res->resumen) . "\n";
        } catch (Throwable $t) {
            echo "No se pudo interpretar: {$t->getMessage()}\n";
        }
    }
}
