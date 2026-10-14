<?php
// Falso servidor de Lucía para las pruebas: exige usuario/clave y devuelve la respuesta supuesta.
if (($_SERVER['PHP_AUTH_USER'] ?? '') !== 'usuario-prueba' || ($_SERVER['PHP_AUTH_PW'] ?? '') !== 'clave-prueba') {
    http_response_code(401);
    exit;
}
$pedido = file_get_contents('php://input');
header('Content-Type: text/xml; charset=utf-8');
if (!str_contains($pedido, '<Contenedor>CSQU3054383</Contenedor>') || !str_contains($pedido, '<Quedatos>LMF</Quedatos>')) {
    echo '<Envelope xmlns="http://schemas.xmlsoap.org/soap/envelope/"><Body><WSDatCont.DATOSCONTResponse xmlns="LUCIAx"><Sdtdatoscont/></WSDatCont.DATOSCONTResponse></Body></Envelope>';
    exit;
}
readfile(dirname(__DIR__) . '/fixtures/lucia_respuesta_supuesta.xml');
