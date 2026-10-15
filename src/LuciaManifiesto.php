<?php
declare(strict_types=1);

/** Lo que interesa de un manifiesto: el buque que lo trae. */
final class ResultadoManifiesto
{
    public function __construct(
        public readonly ?string $buque,
        public readonly ?string $viaje = null,
        public readonly ?string $fecha = null,
        public readonly array $detalle = [],
    ) {
    }
}

/** Fuente del buque de un manifiesto (Lucía real o simulada en las pruebas). */
interface BuscadorManifiesto
{
    /**
     * @param list<string> $clave TipoTransporte, TipoMic, NroMic, Deposito, FechaArribo (AAAAMMDD)
     * @throws RuntimeException si la consulta falla
     */
    public function consultar(array $clave): ResultadoManifiesto;
}

/**
 * Consulta de manifiestos de Lucía (afMicXmlunif.aspx), documentada en la wiki de la Aduana:
 *   https://servicios.aduanas.gub.uy/luciaws/afMicXmlunif.aspx?TipoTransporte,TipoMic,NroMic,Deposito,FechaArribo,...
 * Devuelve datos de manifiesto, conocimientos y líneas. Como todavía no se vio una respuesta real,
 * el buque se busca por varios nombres posibles de elemento (ampliables en config.php → lucia → campos_manifiesto).
 */
final class LuciaManifiesto implements BuscadorManifiesto
{
    private const ALIAS = [
        'buque' => ['NOMBREBUQUE', 'NOMBUQUE', 'BUQUENOMBRE', 'BUQUE', 'NOMBRENAVE', 'NAVE', 'NOMBREBARCO', 'BARCO',
                    'NOMBREMEDIOTRANSPORTE', 'NOMMEDIOTRANSPORTE', 'MEDIOTRANSPORTENOMBRE', 'NOMBRETRANSPORTE', 'VESSEL'],
        'viaje' => ['NROVIAJE', 'NUMEROVIAJE', 'VIAJENRO', 'VIAJE'],
        'fecha' => ['FECHAARRIBOREAL', 'FECHAARRIBO', 'FECHALLEGADA', 'FECHAARRIBOESTIMADA', 'FECHAARRIB'],
    ];

    public string $ultimaUrl = '';
    public string $ultimaRespuesta = '';

    public function __construct(private array $cfg)
    {
    }

    public function url(array $clave): string
    {
        $base = $this->cfg['url_manifiesto'] ?? 'https://servicios.aduanas.gub.uy/luciaws/afMicXmlunif.aspx';
        return $base . '?' . implode(',', array_map(fn($v) => rawurlencode(trim((string) $v)), $clave));
    }

    public function consultar(array $clave): ResultadoManifiesto
    {
        $this->ultimaUrl = $this->url($clave);
        $xml = LuciaSoap::peticion($this->cfg, $this->ultimaUrl, null, ['Accept: text/xml, application/xml'], $this->ultimaRespuesta);
        return $this->interpretar($xml);
    }

    public function interpretar(string $xml): ResultadoManifiesto
    {
        $real = $this->interpretarMicXml($xml);
        if ($real !== null) {
            return $real;
        }
        // Otra estructura: búsqueda genérica por nombres de elemento.
        $datos = LuciaSoap::xmlComoArray($xml);
        if ($datos === null) {
            throw new RuntimeException('La respuesta del manifiesto no es XML válido: ' . mb_substr(trim(strip_tags($xml)), 0, 150));
        }
        $extra = $this->cfg['campos_manifiesto'] ?? [];
        $buscar = function (string $campo) use ($datos, $extra) {
            $alias = array_map(fn($a) => self::clave($a), array_merge($extra[$campo] ?? [], self::ALIAS[$campo]));
            return self::primerValor($datos, $alias, $campo === 'buque');
        };
        return new ResultadoManifiesto($buscar('buque'), $buscar('viaje'), Texto::fecha(self::fechaLucia($buscar('fecha'))), $datos);
    }

    /**
     * Estructura real de afMicXmlunif (respuesta vista en octubre 2026, ISO-8859-1):
     *   MANIFIESTO > FILA_MANIFIESTO (cabecera: CGNomNave, CGFchLleg, CGMatNave, AgeTraRSoc, CGLugPart, CGMICEst...)
     *     > CONOCIMIENTO > FILA_CONOCIMIENTO (CGNroCon, CGRSocConsig...)
     *       > LINEA_CONOCIMIENTO > FILA_LINEA_CONOCIMIENTO (CGDesc...) > LINEA_CONTENEDORES > FILA_LINEA_CONTENEDORES (CGNroConLin)
     *   MANIFIESTO > CONTENEDOR > FILA_CONTENEDOR (CGNroConte, CGCodTamId...)
     * La respuesta trae el manifiesto entero (puede pesar varios MB): se guarda solo la cabecera y,
     * por contenedor, su conocimiento.
     */
    private function interpretarMicXml(string $xml): ?ResultadoManifiesto
    {
        $previo = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, options: LIBXML_NONET | LIBXML_NOCDATA | LIBXML_PARSEHUGE);
        libxml_use_internal_errors($previo);
        if ($doc === false || !isset($doc->FILA_MANIFIESTO)) {
            return null;
        }

        $cab = [];
        foreach ($doc->FILA_MANIFIESTO->children() as $nombre => $valor) {
            if ($valor->count() === 0) {
                $cab[$nombre] = trim((string) $valor);
            }
        }

        $contenedores = [];
        foreach ($doc->FILA_MANIFIESTO->CONOCIMIENTO->FILA_CONOCIMIENTO ?? [] as $con) {
            foreach ($con->LINEA_CONOCIMIENTO->FILA_LINEA_CONOCIMIENTO ?? [] as $linea) {
                foreach ($linea->LINEA_CONTENEDORES->FILA_LINEA_CONTENEDORES ?? [] as $lc) {
                    $numero = Validacion::normalizarContenedor((string) $lc->CGNroConLin);
                    if ($numero !== '') {
                        $contenedores[$numero] ??= [
                            'conocimiento'  => trim((string) $con->CGNroCon),
                            'consignatario' => trim((string) $con->CGRSocConsig),
                            'descripcion'   => trim((string) $linea->CGDesc),
                        ];
                    }
                }
            }
        }

        $buque = ($cab['CGNomNave'] ?? '') !== '' ? $cab['CGNomNave'] : null;
        $llegada = self::fechaLucia($cab['CGFchLleg'] ?? null);
        return new ResultadoManifiesto(
            $buque,
            null, // Lucía no informa el número de viaje del buque (CGMatNave es la matrícula)
            ($llegada && $llegada !== '0') ? Texto::fecha($llegada) : null,
            ['cabecera' => $cab, 'contenedores' => $contenedores],
        );
    }

    /** Primer valor no vacío de un elemento con alguno de esos nombres (por orden de preferencia del alias). */
    private static function primerValor(array $datos, array $alias, bool $esNombre): ?string
    {
        foreach ($alias as $a) {
            $v = self::buscar($datos, $a, $esNombre);
            if ($v !== null) {
                return $v;
            }
        }
        return null;
    }

    private static function buscar(array $nodo, string $alias, bool $esNombre): ?string
    {
        foreach ($nodo as $k => $v) {
            if (is_string($v) && self::clave((string) $k) === $alias && trim($v) !== ''
                && (!$esNombre || preg_match('/[A-Za-z]/', $v))) { // un nombre de buque no es solo un código numérico
                return trim($v);
            }
            if (is_array($v) && ($r = self::buscar($v, $alias, $esNombre)) !== null) {
                return $r;
            }
        }
        return null;
    }

    private static function clave(string $s): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper($s));
    }

    /** Lucía usa fechas AAAAMMDD además de ISO. */
    private static function fechaLucia(?string $s): ?string
    {
        return ($s !== null && preg_match('/^(\d{4})(\d{2})(\d{2})$/', $s, $m)) ? "$m[1]-$m[2]-$m[3]" : $s;
    }
}
