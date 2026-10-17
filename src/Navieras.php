<?php
declare(strict_types=1);

/** Lo que dice el tracking de una naviera sobre la llegada de un contenedor al puerto. */
final class ResultadoNaviera
{
    /**
     * @param string $naviera código de config.php (MAERSK, HAPAG, ...)
     * @param ?string $buque buque con el que llega al puerto (puede ser un feeder, no el de origen)
     * @param ?string $fecha llegada al puerto ("Y-m-d H:i", hora local)
     * @param string $tipoFecha ACT (real), EST (estimada) o PLN (planificada)
     * @param list<string> $resumen frases cortas para mostrar
     */
    public function __construct(
        public readonly string $naviera,
        public readonly ?string $buque,
        public readonly ?string $viaje,
        public readonly ?string $fecha,
        public readonly string $tipoFecha = '',
        public readonly array $resumen = [],
    ) {
    }
}

interface ConsultaNaviera
{
    /**
     * @return ?ResultadoNaviera null si ninguna naviera configurada conoce el contenedor
     * @throws RuntimeException si la consulta falla
     */
    public function consultar(string $contenedor): ?ResultadoNaviera;
}

/**
 * Tracking en las APIs oficiales de las navieras que siguen el estándar DCSA Track & Trace
 * (Maersk, Hapag-Lloyd, CMA CGM, ...): GET .../events?equipmentReference=CONTENEDOR.
 * Cada evento trae transportCall con UNLocationCode y vessel.vesselName; se busca la llegada
 * (ARRI) al puerto configurado (Montevideo = UYMVD).
 */
final class Navieras implements ConsultaNaviera
{
    private const PRIORIDAD = ['ACT' => 3, 'EST' => 2, 'PLN' => 1];

    public function __construct(private array $cfg, private array $http = [])
    {
    }

    /** null si no hay ninguna naviera activa. */
    public static function crear(array $cfg, array $http = []): ?self
    {
        foreach ($cfg['lineas'] ?? [] as $linea) {
            if (!empty($linea['activa']) && !empty($linea['url'])) {
                return new self($cfg, $http);
            }
        }
        return null;
    }

    /** Navieras a consultar para un contenedor: la dueña del prefijo y, si se configuró, las demás activas. */
    public function lineasPara(string $contenedor): array
    {
        $prefijo = substr(Validacion::normalizarContenedor($contenedor), 0, 4);
        $propias = [];
        $otras = [];
        foreach ($this->cfg['lineas'] ?? [] as $codigo => $linea) {
            if (empty($linea['activa']) || empty($linea['url'])) {
                continue;
            }
            if (in_array($prefijo, $linea['prefijos'] ?? [], true)) {
                $propias[$codigo] = $linea;
            } else {
                $otras[$codigo] = $linea;
            }
        }
        return $propias ?: (!empty($this->cfg['probar_todas']) ? $otras : []);
    }

    public function consultar(string $contenedor): ?ResultadoNaviera
    {
        $numero = Validacion::normalizarContenedor($contenedor);
        $errores = [];
        foreach ($this->lineasPara($numero) as $codigo => $linea) {
            try {
                $r = $this->pedir($codigo, $linea, $numero);
            } catch (RuntimeException $e) {
                $errores[] = "$codigo: " . $e->getMessage();
                continue;
            }
            if ($r !== null) {
                return $r;
            }
        }
        if ($errores) {
            throw new RuntimeException(implode(' · ', $errores));
        }
        return null;
    }

    /** Respuesta cruda, para el diagnóstico. @return array{codigo: int, cuerpo: string, url: string} */
    public function pedirCrudo(array $linea, string $numero): array
    {
        $url = str_replace(['{contenedor}', '{contenedor_guiones}'], [rawurlencode($numero), rawurlencode(Validacion::formatearContenedor($numero))], $linea['url']);
        $r = Http::pedir($url, null, array_merge(['Accept: application/json'], $linea['encabezados'] ?? []), [
            'timeout' => (int) ($this->http['timeout'] ?? 30),
            'user_agent' => $this->http['user_agent'] ?? 'PuertoUnificado/1.0',
            'ca_bundle' => $this->http['ca_bundle'] ?? '',
        ]);
        return $r + ['url' => $url];
    }

    private function pedir(string $codigo, array $linea, string $numero): ?ResultadoNaviera
    {
        $r = $this->pedirCrudo($linea, $numero);
        if ($r['codigo'] === 404) {
            return null; // la naviera no conoce el contenedor
        }
        if ($r['codigo'] < 200 || $r['codigo'] >= 300) {
            throw new RuntimeException("respondió HTTP {$r['codigo']}" . ($r['codigo'] === 401 || $r['codigo'] === 403 ? ' (revisá la clave de la API en config.php)' : ''));
        }
        return self::interpretarDcsa($codigo, $r['cuerpo'], $this->cfg['puerto'] ?? 'UYMVD');
    }

    /**
     * Interpreta eventos DCSA (lista de eventos, o {"events": [...]} como Maersk).
     * null si no hay ningún evento (la naviera no conoce el contenedor).
     */
    public static function interpretarDcsa(string $naviera, string $json, string $puerto = 'UYMVD'): ?ResultadoNaviera
    {
        $datos = json_decode($json, true);
        if (!is_array($datos)) {
            throw new RuntimeException('la respuesta no es JSON');
        }
        $eventos = array_is_list($datos) ? $datos : ($datos['events'] ?? $datos['eventos'] ?? []);
        $eventos = array_values(array_filter((array) $eventos, 'is_array'));
        if (!$eventos) {
            return null;
        }

        $mejor = null;
        $enPuerto = null; // cualquier evento en el puerto con buque (por ejemplo la descarga), si no hay ARRI
        $ultimo = null;
        foreach ($eventos as $ev) {
            $tc = $ev['transportCall'] ?? [];
            $buque = trim((string) ($tc['vessel']['vesselName'] ?? ''));
            $lugar = strtoupper(trim((string) ($tc['UNLocationCode'] ?? $tc['location']['UNLocationCode'] ?? $ev['eventLocation']['UNLocationCode'] ?? '')));
            $fecha = Texto::fecha($ev['eventDateTime'] ?? null);
            $clase = strtoupper((string) ($ev['eventClassifierCode'] ?? ''));
            if ($fecha !== null && ($ultimo === null || ($ev['eventDateTime'] ?? '') > ($ultimo['eventDateTime'] ?? ''))) {
                $ultimo = $ev;
            }
            if ($buque === '' || $lugar !== strtoupper($puerto)) {
                continue;
            }
            $candidato = [
                'buque' => $buque,
                'viaje' => $tc['importVoyageNumber'] ?? $tc['exportVoyageNumber'] ?? $tc['carrierVoyageNumber'] ?? null,
                'fecha' => $fecha,
                'clase' => $clase,
                'creado' => (string) ($ev['eventCreatedDateTime'] ?? ''),
            ];
            if (($ev['transportEventTypeCode'] ?? '') === 'ARRI') {
                // La más confiable: real > estimada > planificada; a igualdad, la informada más recientemente.
                if ($mejor === null
                    || (self::PRIORIDAD[$clase] ?? 0) > (self::PRIORIDAD[$mejor['clase']] ?? 0)
                    || ((self::PRIORIDAD[$clase] ?? 0) === (self::PRIORIDAD[$mejor['clase']] ?? 0) && $candidato['creado'] > $mejor['creado'])) {
                    $mejor = $candidato;
                }
            } else {
                $enPuerto ??= $candidato;
            }
        }
        $elegido = $mejor ?? $enPuerto;

        $resumen = [];
        if ($elegido) {
            $tipo = ['ACT' => 'llegó', 'EST' => 'llegada estimada', 'PLN' => 'llegada planificada'][$elegido['clase']] ?? 'en el puerto';
            $resumen[] = "Tracking $naviera: {$elegido['buque']}" . ($elegido['viaje'] ? " viaje {$elegido['viaje']}" : '')
                . " · $tipo " . ($elegido['fecha'] ? Texto::mostrarFecha($elegido['fecha']) : '(sin fecha)');
        } elseif ($ultimo) {
            $tc = $ultimo['transportCall'] ?? [];
            $resumen[] = "Tracking $naviera: todavía sin llegada a $puerto · último evento "
                . trim(($ultimo['transportEventTypeCode'] ?? $ultimo['equipmentEventTypeCode'] ?? $ultimo['eventType'] ?? '') . ' '
                . ($tc['UNLocationCode'] ?? '') . ' ' . ($tc['vessel']['vesselName'] ?? '')) . ' · ' . Texto::mostrarFecha(Texto::fecha($ultimo['eventDateTime'] ?? null));
        }

        return new ResultadoNaviera(
            $naviera,
            $elegido['buque'] ?? null,
            isset($elegido['viaje']) ? (string) $elegido['viaje'] : null,
            $elegido['fecha'] ?? null,
            $elegido['clase'] ?? '',
            $resumen,
        );
    }
}
