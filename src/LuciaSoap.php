<?php
declare(strict_types=1);

/**
 * Cliente del webservice de Lucía (awsdatcont, GeneXus).
 * Por defecto usa DATOSCONTFECHAS con un rango amplio de fechas: DATOSCONT solo mira el día
 * actual (la respuesta real trae FECHAINI = FECHAFIN = hoy).
 *
 * Respuesta real conocida (contenedor sin datos):
 *   WSDatCont.*Response > Mje, Datoscont > CONTENEDOR, FECHAINI, FECHAFIN, TIPODOCUMENTO, ANIO,
 *   NUMERO, DUAS, LINMANIF, MOVIMSTOCK, MS, INCAUTACIONES, MOVIMVIAJE, CONVALIDADA
 *
 * No se usa la extensión SOAP de PHP: el sobre se arma a mano y la respuesta se recorre
 * buscando el buque, el viaje y la fecha por nombre de elemento (configurable), porque
 * los nombres exactos de la respuesta se ajustan con cron/lucia_diagnostico.php.
 */
final class LuciaSoap implements ConsultaLucia
{
    private const ALIAS = [
        'buque' => ['Buque', 'NombreBuque', 'BuqueNombre', 'BuqNom', 'Nave', 'NombreNave', 'Vessel', 'Barco'],
        'viaje' => ['Viaje', 'NroViaje', 'ViajeNro', 'NumeroViaje', 'ViaNro', 'Voyage'],
        'fecha' => ['FechaArribo', 'FechaLlegada', 'FechaEstimadaArribo', 'FechaArriboEstimada', 'Arribo', 'ETA', 'FechaViaje'],
    ];

    /** Última consulta y respuesta, para diagnóstico. */
    public string $ultimoPedido = '';
    public string $ultimaRespuesta = '';

    public function __construct(private array $cfg)
    {
        if (empty($cfg['url'])) {
            throw new LuciaSinConfigurar('Falta la dirección del webservice de Lucía (config.php → lucia → url).');
        }
    }

    /** Secciones de Datoscont que traen información del contenedor (no los datos del pedido). */
    private const SECCIONES = ['DUAS', 'LINMANIF', 'MOVIMSTOCK', 'MS', 'INCAUTACIONES', 'MOVIMVIAJE', 'CONVALIDADA'];

    public function consultar(string $contenedor, ?int $ahora = null): ResultadoLucia
    {
        $metodo = $this->metodo();
        $sobre = $metodo === 'DATOSCONTFECHAS'
            ? $this->sobreFechas($contenedor, ...$this->rango($ahora ?? time()))
            : $this->sobre($contenedor, $this->cfg['quedatos'] ?? 'TOD');
        $xml = $this->llamar($sobre, 'LUCIAxaction/AWSDATCONT.' . $metodo);
        return $this->interpretar($xml, $this->cfg['campos'] ?? []);
    }

    public function metodo(): string
    {
        return strtoupper($this->cfg['metodo'] ?? 'DATOSCONTFECHAS');
    }

    /** @return array{0: string, 1: string} fecha inicial y final (Y-m-d) */
    public function rango(int $ahora): array
    {
        return [
            date('Y-m-d', $ahora - (int) ($this->cfg['dias_atras'] ?? 60) * 86400),
            date('Y-m-d', $ahora + (int) ($this->cfg['dias_adelante'] ?? 30) * 86400),
        ];
    }

    /** Número como lo espera Lucía: AAAA-123456-7 ('guiones', por defecto) o AAAA1234567 ('continuo'). */
    public function numeroParaLucia(string $contenedor): string
    {
        return ($this->cfg['formato_contenedor'] ?? 'guiones') === 'continuo'
            ? Validacion::normalizarContenedor($contenedor)
            : Validacion::formatearContenedor($contenedor);
    }

    public function sobreFechas(string $contenedor, string $desde, string $hasta): string
    {
        $c = htmlspecialchars($this->numeroParaLucia($contenedor), ENT_XML1);
        return <<<XML
            <?xml version="1.0" encoding="utf-8"?>
            <Envelope xmlns="http://schemas.xmlsoap.org/soap/envelope/">
                <Body>
                    <WSDatCont.DATOSCONTFECHAS xmlns="LUCIAx">
                        <Fechainicial>$desde</Fechainicial>
                        <Fechafinal>$hasta</Fechafinal>
                        <Contenedor>$c</Contenedor>
                    </WSDatCont.DATOSCONTFECHAS>
                </Body>
            </Envelope>
            XML;
    }

    public function sobre(string $contenedor, string $quedatos): string
    {
        $c = htmlspecialchars($this->numeroParaLucia($contenedor), ENT_XML1);
        $q = htmlspecialchars($quedatos, ENT_XML1);
        return <<<XML
            <?xml version="1.0" encoding="utf-8"?>
            <Envelope xmlns="http://schemas.xmlsoap.org/soap/envelope/">
                <Body>
                    <WSDatCont.DATOSCONT xmlns="LUCIAx">
                        <Contenedor>$c</Contenedor>
                        <Quedatos>$q</Quedatos>
                    </WSDatCont.DATOSCONT>
                </Body>
            </Envelope>
            XML;
    }

    private function llamar(string $sobre, string $accion): string
    {
        // La dirección del servicio es la del WSDL sin "?wsdl".
        $url = preg_replace('/\?wsdl$/i', '', $this->cfg['url']);
        $this->ultimoPedido = $sobre;

        $ch = curl_init($url);
        $opciones = [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $sobre,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->cfg['timeout'] ?? 30,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: text/xml; charset=utf-8',
                'SOAPAction: "' . $accion . '"',
            ],
        ];
        if (($this->cfg['autenticacion'] ?? 'basic') === 'basic' && ($this->cfg['usuario'] ?? '') !== '') {
            $opciones[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
            $opciones[CURLOPT_USERPWD] = $this->cfg['usuario'] . ':' . ($this->cfg['clave'] ?? '');
        }
        if (!empty($this->cfg['ca_bundle'])) {
            $opciones[CURLOPT_CAINFO] = $this->cfg['ca_bundle'];
        }
        curl_setopt_array($ch, $opciones);

        $respuesta = curl_exec($ch);
        $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        $this->ultimaRespuesta = (string) $respuesta;

        if ($respuesta === false) {
            throw new RuntimeException("No se pudo conectar con Lucía: $error");
        }
        if ($codigo === 401 || $codigo === 403) {
            throw new LuciaSinConfigurar("Lucía rechazó el usuario o la contraseña (HTTP $codigo).");
        }
        if ($codigo >= 400 && !str_contains($respuesta, 'Fault')) {
            throw new RuntimeException("Lucía respondió HTTP $codigo");
        }
        return $respuesta;
    }

    /**
     * Busca en la respuesta los registros que traen buque y se queda con el de fecha más reciente.
     * @param array<string, string[]> $campos alias adicionales por campo (buque, viaje, fecha)
     */
    public function interpretar(string $xml, array $campos = []): ResultadoLucia
    {
        $datos = self::xmlComoArray($xml);
        if ($datos === null) {
            throw new RuntimeException('La respuesta de Lucía no es XML válido: ' . mb_substr(trim($xml), 0, 150));
        }
        $falla = self::buscarClave($datos, ['Fault', 'faultstring']);
        if ($falla !== null) {
            throw new RuntimeException('Lucía devolvió un error: ' . (is_array($falla) ? json_encode($falla, JSON_UNESCAPED_UNICODE) : $falla));
        }

        $alias = [];
        foreach (self::ALIAS as $campo => $lista) {
            $alias[$campo] = array_map('strtolower', array_merge($campos[$campo] ?? [], $lista));
        }

        $mensaje = self::buscarClave($datos, ['Mje']);
        $mensaje = is_string($mensaje) && $mensaje !== '' ? $mensaje : null;

        $registros = [];
        self::registrosConBuque($datos, $alias['buque'], $registros);
        if (!$registros) {
            // Sin buque: igual cuenta como encontrado si alguna sección trae datos.
            $conDatos = array_values(array_filter(self::SECCIONES, function ($sec) use ($datos) {
                $v = self::buscarClave($datos, [$sec]);
                return $v !== null && $v !== '' && $v !== [];
            }));
            return new ResultadoLucia((bool) $conDatos, detalle: ['secciones_con_datos' => $conDatos, 'mensaje' => $mensaje, 'respuesta' => $datos]);
        }

        $mejor = null;
        foreach ($registros as $r) {
            $fila = [
                'buque' => self::valor($r, $alias['buque']),
                'viaje' => self::valor($r, $alias['viaje']),
                'fecha' => Texto::fecha(self::valor($r, $alias['fecha'])),
                'registro' => $r,
            ];
            if ($mejor === null || (string) $fila['fecha'] > (string) $mejor['fecha']) {
                $mejor = $fila;
            }
        }
        return new ResultadoLucia(true, $mejor['buque'], $mejor['viaje'], $mejor['fecha'], $mejor['registro']);
    }

    /** XML a arreglo, sin espacios de nombres. Los elementos repetidos quedan como lista. */
    public static function xmlComoArray(string $xml): ?array
    {
        $previo = libxml_use_internal_errors(true);
        $doc = simplexml_load_string(preg_replace('/(<\/?)[\w.-]+:/', '$1', $xml), options: LIBXML_NONET | LIBXML_NOCDATA);
        libxml_use_internal_errors($previo);
        if ($doc === false) {
            return null;
        }
        return [$doc->getName() => self::nodo($doc)];
    }

    private static function nodo(SimpleXMLElement $n): array|string
    {
        if ($n->count() === 0) {
            return trim((string) $n);
        }
        $res = [];
        foreach ($n->children() as $nombre => $hijo) {
            $valor = self::nodo($hijo);
            if (array_key_exists($nombre, $res)) {
                if (!is_array($res[$nombre]) || !array_is_list($res[$nombre])) {
                    $res[$nombre] = [$res[$nombre]];
                }
                $res[$nombre][] = $valor;
            } else {
                $res[$nombre] = $valor;
            }
        }
        return $res;
    }

    /** Junta todos los nodos que tienen, como hijo directo, un elemento de buque no vacío. */
    private static function registrosConBuque(array $nodo, array $aliasBuque, array &$encontrados): void
    {
        if (!array_is_list($nodo) && self::valor($nodo, $aliasBuque) !== null) {
            $encontrados[] = $nodo;
            return;
        }
        foreach ($nodo as $hijo) {
            if (is_array($hijo)) {
                self::registrosConBuque($hijo, $aliasBuque, $encontrados);
            }
        }
    }

    private static function valor(array $nodo, array $alias): ?string
    {
        foreach ($nodo as $k => $v) {
            if (is_string($v) && $v !== '' && in_array(strtolower((string) $k), $alias, true)) {
                return $v;
            }
        }
        return null;
    }

    private static function buscarClave(array $nodo, array $claves): mixed
    {
        foreach ($nodo as $k => $v) {
            if (in_array($k, $claves, true)) {
                return $v;
            }
            if (is_array($v) && ($r = self::buscarClave($v, $claves)) !== null) {
                return $r;
            }
        }
        return null;
    }
}
