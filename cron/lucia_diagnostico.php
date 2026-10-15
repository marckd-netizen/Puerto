<?php
/**
 * Prueba la consulta a Lucía con un contenedor, sin guardar nada.
 * Uso: php cron/lucia_diagnostico.php MSKU1234567            (método configurado, por defecto DATOSCONTFECHAS)
 *      php cron/lucia_diagnostico.php MSKU1234567 TOD        (DATOSCONT con ese Quedatos)
 *      php cron/lucia_diagnostico.php --manifiesto 1,0,291201,1650,20260101
 *                                                            (solo el manifiesto: TipoTransporte,TipoMic,NroMic,Deposito,FechaArribo)
 * Después de consultar un contenedor, consulta también sus manifiestos para obtener el buque.
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

function mostrarXml(string $xml): string
{
    $doc = new DOMDocument();
    $doc->preserveWhiteSpace = false;
    $doc->formatOutput = true;
    return @$doc->loadXML($xml, LIBXML_NONET) ? $doc->saveXML() : $xml;
}

function diagnosticarManifiesto(array $cfg, array $clave): void
{
    $m = new LuciaManifiesto($cfg);
    echo "\n==== Manifiesto " . implode(',', $clave) . " ====\n" . $m->url($clave) . "\n\n";
    try {
        $r = $m->consultar($clave);
    } catch (Throwable $t) {
        echo "ERROR: {$t->getMessage()}\n";
    }
    echo "---- Respuesta del manifiesto ----\n" . mostrarXml($m->ultimaRespuesta) . "\n";
    if (isset($r)) {
        echo "---- Interpretación ----\n";
        echo $r->buque !== null
            ? "Buque: {$r->buque}\nViaje: " . ($r->viaje ?? '(no informado)') . "\nFecha: " . ($r->fecha ?? '(no informada)') . "\n"
            : "No se reconoció el nombre del buque. Pasame la respuesta para ajustar los nombres de los elementos\n"
              . "(o agregalos en config.php → lucia → campos_manifiesto → buque).\n";
    }
}

if (($argv[1] ?? '') === '--manifiesto') {
    $clave = array_map('trim', explode(',', $argv[2] ?? ''));
    if (count($clave) < 5) {
        exit("Uso: php cron/lucia_diagnostico.php --manifiesto TipoTransporte,TipoMic,NroMic,Deposito,FechaArribo\n");
    }
    diagnosticarManifiesto(config()['lucia'] ?? [], $clave);
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
echo "---- Respuesta ----\n" . mostrarXml($lucia->ultimaRespuesta) . "\n";

if (isset($r)) {
    echo "---- Interpretación ----\n";
    if (!$r->encontrado) {
        echo "No se encontró ningún registro con buque. Si la respuesta trae el buque con otro nombre de\n"
           . "elemento, agregalo en config.php → lucia → campos → buque.\n";
    } elseif ($r->buque === null) {
        echo "Lucía tiene datos (" . implode(', ', $r->detalle['secciones_con_datos'] ?? []) . "), sin nombre de buque.\n"
           . 'Terminal: ' . ($r->terminal ?? '(no se sabe)') . ' · fecha de descarga o arribo: ' . ($r->fecha ?? '(no se sabe)') . "\n"
           . implode("\n", array_map(fn($l) => "  · $l", $r->resumen)) . "\n"
           . ($r->detalle['claves_manifiesto'] ?? [] ? "El buque se busca en el manifiesto (abajo).\n" : "No hay clave de manifiesto para buscar el buque.\n");
    } else {
        echo "Buque: {$r->buque}\nViaje: " . ($r->viaje ?? '(no informado)') . "\nFecha: " . ($r->fecha ?? '(no informada)') . "\n";
    }
}

foreach (isset($r) ? ($r->detalle['claves_manifiesto'] ?? []) : [] as $clave) {
    diagnosticarManifiesto($cfg, $clave);
}
