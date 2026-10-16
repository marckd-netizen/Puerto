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
        return $this->interpretar($xml, $this->cfg['campos'] ?? [], $ahora);
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

    /** Lucía solo encuentra el contenedor con guiones: AAAA-123456-7 (sin guiones no devuelve nada). */
    public function numeroParaLucia(string $contenedor): string
    {
        return Validacion::formatearContenedor($contenedor);
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
        return self::peticion($this->cfg, $url, $sobre, [
            'Content-Type: text/xml; charset=utf-8',
            'SOAPAction: "' . $accion . '"',
        ], $this->ultimaRespuesta);
    }

    /**
     * Pedido HTTP a Lucía (POST si hay cuerpo, GET si no) con la autenticación de config.php.
     * Lo usan este cliente y el de manifiestos.
     */
    public static function peticion(array $cfg, string $url, ?string $cuerpo, array $encabezados, ?string &$respuestaCruda = null): string
    {
        $r = Http::pedir($url, $cuerpo, $encabezados, [
            'timeout'   => $cfg['timeout'] ?? 30,
            'usuario'   => ($cfg['autenticacion'] ?? 'basic') === 'basic' ? ($cfg['usuario'] ?? '') : '',
            'clave'     => $cfg['clave'] ?? '',
            'ca_bundle' => $cfg['ca_bundle'] ?? '',
        ]);
        $respuesta = $r['cuerpo'];
        $codigo = $r['codigo'];
        $respuestaCruda = $respuesta;

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
    public function interpretar(string $xml, array $campos = [], ?int $ahora = null): ResultadoLucia
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
            // Lo habitual: Lucía no informa el buque. Se usan la terminal y la fecha de descarga.
            return $this->interpretarDatoscont($datos, $mensaje, $ahora ?? time());
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

    /**
     * Lectura de Datoscont según la estructura real del servicio (ver WSDL):
     * DUAS/Dua, LINMANIF/Linea, MOVIMSTOCK/Movimiento, MS/Ms, INCAUTACIONES/Incaut,
     * MOVIMVIAJE/Movimviaje, CONVALIDADA/Convalidada. Ninguna trae el nombre del buque:
     * se informa la terminal (operador de la convalidada o depósito del manifiesto) y la fecha
     * de descarga (o de arribo del manifiesto) para ubicar el buque en el tablero.
     */
    private function interpretarDatoscont(array $datos, ?string $mensaje, int $ahora): ResultadoLucia
    {
        $sec = fn(string $seccion, string $item) => self::items($datos, $seccion, $item);
        $duas = $sec('DUAS', 'Dua');
        $lineas = $sec('LINMANIF', 'Linea');
        $stock = $sec('MOVIMSTOCK', 'Movimiento');
        $ms = $sec('MS', 'Ms');
        $incautaciones = $sec('INCAUTACIONES', 'Incaut');
        $viajes = $sec('MOVIMVIAJE', 'Movimviaje');
        $convalidadas = $sec('CONVALIDADA', 'Convalidada');

        $conDatos = array_keys(array_filter([
            'DUAS' => $duas, 'LINMANIF' => $lineas, 'MOVIMSTOCK' => $stock, 'MS' => $ms,
            'INCAUTACIONES' => $incautaciones, 'MOVIMVIAJE' => $viajes, 'CONVALIDADA' => $convalidadas,
        ]));
        if (!$conDatos) {
            return new ResultadoLucia(false, detalle: ['mensaje' => $mensaje, 'respuesta' => $datos]);
        }

        // Terminal y fecha: la convalidada (descarga real) manda; si no hay, la línea de manifiesto.
        $conv = self::masReciente($convalidadas, 'DESCARGA');
        $linea = $lineas ? $lineas[0] : null;
        $terminal = $this->terminalDe($conv['OPERADOR'] ?? null, $conv['DEPOSITO'] ?? $linea['DEPOSITO'] ?? null);
        $fecha = Texto::fecha($conv['DESCARGA'] ?? null);
        if ($fecha === null) {
            $fecha = self::fechaPlausible(self::fechaAmd($linea['FECHAARRIB'] ?? null), $ahora);
        }
        $manifiesto = $conv['MANIFIESTO'] ?? $linea['NROMIC'] ?? null;

        // Claves de manifiesto para consultar el buque en afMicXmlunif:
        // TipoTransporte, TipoMic, NroMic, Deposito, FechaArribo (AAAAMMDD, parte de la clave).
        $claves = [];
        foreach ($lineas as $l) {
            $claves[] = [$l['TPOTRANSP'] ?? '', $l['TIPOMIC'] ?? '0', $l['NROMIC'] ?? '', $l['DEPOSITO'] ?? '', $l['FECHAARRIB'] ?? ''];
        }
        foreach ($convalidadas as $cv) {
            $claves[] = [$cv['TPOTRANSP'] ?? '', '0', $cv['MANIFIESTO'] ?? '', $cv['DEPOSITO'] ?? '', $cv['FCHARRIBO'] ?? ''];
        }
        $claves = array_values(array_unique(
            array_filter(array_map(fn($c) => array_map('trim', $c), $claves), fn($c) => $c[2] !== '' && $c[4] !== ''),
            SORT_REGULAR
        ));

        $resumen = [];
        if ($conv) {
            $resumen[] = 'Descargado ' . (Texto::mostrarFecha(Texto::fecha($conv['DESCARGA'] ?? null)) ?: '(sin fecha)')
                . ($conv['OPERADOR'] ?? '' ? ' en ' . $conv['OPERADOR'] : '')
                . ($conv['ORIGEN'] ?? '' ? " · {$conv['ORIGEN']} → " . ($conv['DESTINO'] ?? '') : '')
                . ($conv['TAMANO'] ?? '' ? " · {$conv['TAMANO']}" : '');
        }
        foreach ($lineas as $l) {
            $resumen[] = "Manifiesto {$l['NROMIC']} línea " . ($l['NROLINEA'] ?? '?') . ' · estado ' . ($l['ESTADO'] ?? '?')
                . (($l['LIBERADO'] ?? '') === 'S' ? ' · liberado ' . Texto::mostrarFecha(Texto::fecha($l['FECHALIB'] ?? null)) : ' · no liberado');
        }
        foreach ($duas as $d) {
            $resumen[] = "DUA {$d['ADUANA']}-{$d['ANIO']}-{$d['NUMERO']} · estado {$d['ESTADO']}"
                . (($f = self::fechaAmd($d['FECHANUME'] ?? null)) ? ' · numerado ' . substr(Texto::mostrarFecha($f), 0, 5) : '');
        }
        foreach ($ms as $m) {
            $resumen[] = "Mensaje simplificado {$m['ADUANA']}-{$m['ANO']}-{$m['NUMERO']} · estado {$m['ESTADO']}";
        }
        $situacionStock = self::situacionStock($stock);
        if ($ultimo = self::masReciente($viajes, 'FECHA')) {
            $resumen[] = "Tránsito: viaje {$ultimo['VIAJE']} · matrícula {$ultimo['MATRICULA']} · "
                . substr(Texto::mostrarFecha(Texto::fecha($ultimo['FECHA'] ?? null)), 0, 5) . " · estado {$ultimo['ESTADO']}";
        }
        foreach ($incautaciones as $i) {
            $resumen[] = "⚠ Incautación {$i['ADUANA']}-{$i['ANIO']}-{$i['NUMERO']} · estado {$i['ESTADO']}";
        }
        if ($mensaje) {
            $resumen[] = "Lucía: $mensaje";
        }

        return new ResultadoLucia(
            true,
            null,
            null,
            $fecha,
            ['secciones_con_datos' => $conDatos, 'stock' => $situacionStock, 'manifiesto' => $manifiesto, 'claves_manifiesto' => $claves, 'mensaje' => $mensaje, 'resumen' => $resumen, 'respuesta' => $datos],
            $terminal,
            $resumen,
        );
    }

    /**
     * Si el contenedor está en stock según los movimientos de MOVIMSTOCK: en cada depósito se compara
     * la última entrada (ENT) con la última salida (SAL). Queda en stock en el depósito cuya entrada
     * no tiene una salida posterior (si la salida es del mismo día, se toma como que ya salió).
     *
     * @return array{estado: 'en_stock'|'salio'|'sin_stock', deposito: ?string, fecha: ?string} fecha "Y-m-d"
     */
    public static function situacionStock(array $movimientos): array
    {
        $porDeposito = [];
        foreach ($movimientos as $m) {
            $tipo = strtoupper(trim((string) ($m['TIPO'] ?? '')));
            $fecha = substr(trim((string) ($m['FECHA'] ?? '')), 0, 10);
            $dep = trim((string) ($m['DEPOSITO'] ?? ''));
            if (!in_array($tipo, ['ENT', 'SAL'], true) || $fecha === '') {
                continue;
            }
            if ($fecha > ($porDeposito[$dep][$tipo] ?? '')) {
                $porDeposito[$dep][$tipo] = $fecha;
            }
        }
        $enStock = null;
        $salida = null;
        foreach ($porDeposito as $dep => $f) {
            $dep = (string) $dep;
            if (isset($f['ENT']) && (!isset($f['SAL']) || $f['SAL'] < $f['ENT'])) {
                if ($enStock === null || $f['ENT'] > $enStock['fecha']) {
                    $enStock = ['estado' => 'en_stock', 'deposito' => $dep, 'fecha' => $f['ENT']];
                }
            } elseif (isset($f['SAL']) && ($salida === null || $f['SAL'] > $salida['fecha'])) {
                $salida = ['estado' => 'salio', 'deposito' => $dep, 'fecha' => $f['SAL']];
            }
        }
        return $enStock ?? $salida ?? ['estado' => 'sin_stock', 'deposito' => null, 'fecha' => null];
    }

    /**
     * Situación de stock guardada en el detalle de un contenedor. Los consultados antes de que existiera
     * se calculan con la respuesta completa de Lucía, que también se guarda. null si no hay datos de Lucía.
     */
    public static function stockDeDetalle(array $detalle): ?array
    {
        if (isset($detalle['stock']['estado'])) {
            return $detalle['stock'];
        }
        if (!isset($detalle['respuesta']) || !is_array($detalle['respuesta']) || !isset($detalle['secciones_con_datos'])) {
            return null;
        }
        return self::situacionStock(self::items($detalle['respuesta'], 'MOVIMSTOCK', 'Movimiento'));
    }

    /** Código de terminal (TCP, MONTECON) según el operador o el depósito, con las equivalencias de config.php. */
    private function terminalDe(?string $operador, ?string $deposito): ?string
    {
        $operadores = $this->cfg['operadores'] ?? ['CUENCA DEL PLATA' => 'TCP', 'MONTECON' => 'MONTECON'];
        foreach ($operadores as $texto => $codigo) {
            if ($operador !== null && str_contains(Texto::normalizar($operador), Texto::normalizar($texto))) {
                return $codigo;
            }
        }
        $depositos = $this->cfg['depositos'] ?? ['1650' => 'TCP'];
        return $deposito !== null ? ($depositos[trim($deposito)] ?? null) : null;
    }

    /** Elementos de una sección de Datoscont, siempre como lista. */
    private static function items(array $datos, string $seccion, string $item): array
    {
        $s = self::buscarClave($datos, [$seccion]);
        if (!is_array($s) || !isset($s[$item])) {
            return [];
        }
        $v = $s[$item];
        return is_array($v) && array_is_list($v) ? $v : [$v];
    }

    private static function masReciente(array $items, string $campoFecha): ?array
    {
        $mejor = null;
        foreach ($items as $i) {
            if (is_array($i) && ($mejor === null || (string) ($i[$campoFecha] ?? '') > (string) ($mejor[$campoFecha] ?? ''))) {
                $mejor = $i;
            }
        }
        return $mejor;
    }

    /** "20261013" → "2026-10-13 00:00" */
    private static function fechaAmd(?string $s): ?string
    {
        return ($s !== null && preg_match('/^(\d{4})(\d{2})(\d{2})$/', trim($s), $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]))
            ? "$m[1]-$m[2]-$m[3] 00:00" : null;
    }

    /** Descarta fechas de relleno (por ejemplo 20260101 en un manifiesto de octubre). */
    private static function fechaPlausible(?string $fecha, int $ahora): ?string
    {
        return ($fecha !== null && abs(strtotime($fecha) - $ahora) <= 90 * 86400) ? $fecha : null;
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
