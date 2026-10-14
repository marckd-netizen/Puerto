<?php
// Falso servidor de Lucía para las pruebas: exige usuario/clave y devuelve la respuesta supuesta.
if (($_SERVER['PHP_AUTH_USER'] ?? '') !== 'usuario-prueba' || ($_SERVER['PHP_AUTH_PW'] ?? '') !== 'clave-prueba') {
    http_response_code(401);
    exit;
}
$pedido = file_get_contents('php://input');
header('Content-Type: text/xml; charset=utf-8');
$fechas = str_contains($pedido, '<WSDatCont.DATOSCONTFECHAS') && str_contains($_SERVER['HTTP_SOAPACTION'] ?? '', 'DATOSCONTFECHAS')
    && preg_match('#<Fechainicial>\d{4}-\d{2}-\d{2}</Fechainicial>#', $pedido);
if (!$fechas || !str_contains($pedido, '<Contenedor>CSQU-305438-3</Contenedor>')) {
    // Igual que la respuesta real para un contenedor sin datos
    readfile(dirname(__DIR__) . '/fixtures/lucia_respuesta_vacia_real.xml');
    exit;
}
readfile(dirname(__DIR__) . '/fixtures/lucia_respuesta_supuesta.xml');
