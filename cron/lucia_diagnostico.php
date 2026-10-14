<?php
/**
 * Prueba la consulta a Lucía con un contenedor, sin guardar nada.
 * Uso: php cron/lucia_diagnostico.php MSKU1234567            (método configurado, por defecto DATOSCONTFECHAS)
 *      php cron/lucia_diagnostico.php MSKU1234567 TOD        (DATOSCONT con ese Quedatos)
 *
 * Muestra el pedido, la respuesta completa y lo que el sistema entiende de ella.
 * ATENCIÓN: la respuesta puede traer datos de clientes; tachalos antes de compartirla.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$contenedor = Validacion::normalizarContenedor($argv[1] ?? '');
if (!Validacion::formatoContenedor($contenedor)) {
    exit("Uso: php cron/lucia_diagnostico.php AAAAnnnnnnn [LMF|TOD|DUA|STK|MSL|MSI]\n");
}
$cfg = config()['lucia'] ?? [];
if (isset($argv[2])) {
    $cfg['metodo'] = 'DATOSCONT';
    $cfg['quedatos'] = strtoupper($argv[2]);
}

$lucia = new LuciaSoap($cfg);
echo "Consultando $contenedor con " . $lucia->metodo() . ($lucia->metodo() === 'DATOSCONT'
    ? ' (Quedatos = ' . ($cfg['quedatos'] ?? 'TOD') . ')'
    : ' (del ' . implode(' al ', $lucia->rango(time())) . ')') . " en {$cfg['url']}\n\n";
try {
    $r = $lucia->consultar($contenedor);
} catch (Throwable $t) {
    echo "ERROR: {$t->getMessage()}\n";
}

echo "---- Pedido ----\n{$lucia->ultimoPedido}\n\n";
echo "---- Respuesta ----\n";
$doc = new DOMDocument();
$doc->preserveWhiteSpace = false;
$doc->formatOutput = true;
echo (@$doc->loadXML($lucia->ultimaRespuesta, LIBXML_NONET) ? $doc->saveXML() : $lucia->ultimaRespuesta) . "\n";

if (isset($r)) {
    echo "---- Interpretación ----\n";
    if (!$r->encontrado) {
        echo "No se encontró ningún registro con buque. Si la respuesta trae el buque con otro nombre de\n"
           . "elemento, agregalo en config.php → lucia → campos → buque.\n";
    } elseif ($r->buque === null) {
        echo "Lucía tiene datos (" . implode(', ', $r->detalle['secciones_con_datos'] ?? []) . ") pero no se reconoció el buque.\n"
           . "Pasame la respuesta para ajustar los nombres de los elementos.\n";
    } else {
        echo "Buque: {$r->buque}\nViaje: " . ($r->viaje ?? '(no informado)') . "\nFecha: " . ($r->fecha ?? '(no informada)') . "\n";
    }
}
