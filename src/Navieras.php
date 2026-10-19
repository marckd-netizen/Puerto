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
            // '*' = servicio que unifica todas las navieras (por ejemplo FindTEU): sirve para cualquier contenedor.
            if (in_array($prefijo, $linea['prefijos'] ?? [], true) || in_array('*', $linea['prefijos'] ?? [], true)) {
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
        $r = $this->pedirUnaVez($linea, $numero);
        if ($r['codigo'] === 405) {
            // Método no permitido: probar con el otro (GET ↔ POST).
            $linea['metodo'] = strtoupper($linea['metodo'] ?? 'GET') === 'POST' ? 'GET' : 'POST';
            $r = $this->pedirUnaVez($linea, $numero);
        }
        return $r;
    }

    private function pedirUnaVez(array $linea, string $numero): array
    {
        $url = str_replace(['{contenedor}', '{contenedor_guiones}'], [rawurlencode($numero), rawurlencode(Validacion::formatearContenedor($numero))], $linea['url']);
        $encabezados = array_merge(['Accept: application/json'], $linea['encabezados'] ?? []);
        $cuerpo = null;
        if (strtoupper($linea['metodo'] ?? 'GET') === 'POST') {
            $cuerpo = http_build_query($linea['formulario'] ?? []);
            $encabezados[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        $r = Http::pedir($url, $cuerpo, $encabezados, [
            'timeout' => (int) ($this->http['timeout'] ?? 30),
            'user_agent' => $this->http['user_agent'] ?? 'PuertoUnificado/1.0',
            'ca_bundle' => $this->http['ca_bundle'] ?? '',
        ]);
        return $r + ['url' => $url, 'metodo' => $cuerpo === null ? 'GET' : 'POST'];
    }

    private function pedir(string $codigo, array $linea, string $numero): ?ResultadoNaviera
    {
        $r = $this->pedirCrudo($linea, $numero);
        if ($r['codigo'] === 404 || $r['codigo'] === 202) {
            return null; // no conoce el contenedor, o lo está buscando (se reintenta en la próxima pasada)
        }
        if ($r['codigo'] < 200 || $r['codigo'] >= 300) {
            throw new RuntimeException("respondió HTTP {$r['codigo']}" . ($r['codigo'] === 401 || $r['codigo'] === 403 ? ' (revisá la clave de la API en config.php)' : ''));
        }
        return self::interpretar($codigo, $linea['formato'] ?? 'dcsa', $r['cuerpo'], $this->cfg['puerto'] ?? 'UYMVD');
    }

    public static function interpretar(string $codigo, string $formato, string $cuerpo, string $puerto = 'UYMVD'): ?ResultadoNaviera
    {
        return match ($formato) {
            'dcsa' => self::interpretarDcsa($codigo, $cuerpo, $puerto),
            'findteu' => self::interpretarFindteu($codigo, $cuerpo, $puerto),
            'generico' => self::interpretarGenerico($codigo, $cuerpo, $puerto),
            default => throw new RuntimeException("el formato \"$formato\" todavía no se sabe leer: mandá la respuesta de cron/naviera_diagnostico.php para adaptarlo"),
        };
    }

    /**
     * Respuesta de FindTEU: {"success": true, "data": {"scac", "pol", "pod": {"port", "iso_code", "eta_date"},
     * "container": {"completed"}, "events": [{"event_date", "location": {"port", "iso_code", "terminal"},
     * "action": {"action_name"}, "mode": {"transport_mode", "vessel": {"vessel_name", "voyage_nr"}}, "event_type"}]}}.
     * Buque: el del evento de llegada/descarga en el puerto; si todavía no lo hay y el puerto de descarga (pod)
     * es el nuestro, el del último evento con buque (el que lo trae), con la ETA del pod.
     */
    public static function interpretarFindteu(string $servicio, string $json, string $puerto = 'UYMVD'): ?ResultadoNaviera
    {
        $r = json_decode($json, true);
        if (!is_array($r)) {
            throw new RuntimeException('la respuesta no es JSON');
        }
        if (isset($r['success']) && !$r['success']) {
            $texto = trim((string) ($r['error']['text'] ?? ''));
            // Contenedor que todavía no encuentra: no es un error del sitio, se reintenta más adelante.
            if ($texto === '' || preg_match('/not found|no data|processing|queued/i', $texto)) {
                return null;
            }
            throw new RuntimeException($texto);
        }
        $d = $r['data'] ?? null;
        if (!is_array($d) || (empty($d['events']) && empty($d['pod']))) {
            return null;
        }
        $puerto = strtoupper($puerto);
        $linea = trim((string) ($d['scac'] ?? ''));
        $nombre = $servicio . ($linea !== '' ? " ($linea)" : '');
        $esPuerto = fn($l) => strtoupper(trim((string) ($l['iso_code'] ?? ''))) === $puerto
            || ($puerto === 'UYMVD' && stripos((string) ($l['port'] ?? ''), 'montevideo') !== false);

        $conBuque = [];
        $enPuerto = null;
        foreach ($d['events'] ?? [] as $ev) {
            $buque = trim((string) ($ev['mode']['vessel']['vessel_name'] ?? ''));
            $fecha = Texto::fecha($ev['event_date'] ?? null);
            if ($buque === '') {
                continue;
            }
            $e = [
                'buque' => $buque,
                'viaje' => trim((string) ($ev['mode']['vessel']['voyage_nr'] ?? '')) ?: null,
                'fecha' => $fecha,
                'real' => strtolower((string) ($ev['event_type'] ?? '')) === 'actual',
                'accion' => (string) ($ev['action']['action_name'] ?? ''),
            ];
            $conBuque[] = $e;
            if ($esPuerto($ev['location'] ?? []) && preg_match('/arriv|discharg|unload|berth/i', $e['accion'])) {
                // La llegada/descarga real gana a la estimada; entre iguales, la última de la lista.
                if ($enPuerto === null || $e['real'] || !$enPuerto['real']) {
                    $enPuerto = $e;
                }
            }
        }

        $pod = $d['pod'] ?? [];
        $elegido = null;
        if ($enPuerto) {
            $elegido = $enPuerto;
        } elseif ($esPuerto($pod) && $conBuque) {
            $ultimo = end($conBuque);
            $elegido = ['buque' => $ultimo['buque'], 'viaje' => $ultimo['viaje'], 'fecha' => Texto::fecha($pod['eta_date'] ?? null), 'real' => false];
        }

        if ($elegido === null) {
            $destino = trim(($pod['port'] ?? '') . (($pod['iso_code'] ?? '') !== '' ? " ({$pod['iso_code']})" : ''));
            return new ResultadoNaviera($nombre, null, null, null, '', [
                "Tracking $nombre: " . ($esPuerto($pod) ? 'todavía sin buque asignado' : 'no viene a Montevideo' . ($destino !== '' ? " · destino $destino" : ''))
                . (!empty($pod['eta_date']) ? ' · ETA ' . substr((string) Texto::mostrarFecha(Texto::fecha($pod['eta_date'])), 0, 5) : ''),
            ]);
        }
        $fecha = $elegido['fecha'];
        return new ResultadoNaviera($nombre, $elegido['buque'], $elegido['viaje'], $fecha, $elegido['real'] ? 'ACT' : 'EST', [
            "Tracking $nombre: {$elegido['buque']}" . ($elegido['viaje'] ? " viaje {$elegido['viaje']}" : '')
            . ' · ' . ($elegido['real'] ? 'llegó' : 'llegada estimada') . ' ' . ($fecha ? substr((string) Texto::mostrarFecha($fecha), 0, 5) : '(sin fecha)'),
        ]);
    }

    /**
     * Lector flexible para servicios que unifican navieras (FindTEU y similares) mientras no se conozca
     * su formato exacto: recorre el JSON buscando un tramo o evento en el puerto (UYMVD o "Montevideo")
     * con nombre de buque y fecha de llegada. El buque y el viaje se heredan del tramo que los contiene.
     * Entre los candidatos prefiere los que hablan de llegada/ETA/descarga y la fecha real sobre la estimada.
     */
    public static function interpretarGenerico(string $servicio, string $json, string $puerto = 'UYMVD'): ?ResultadoNaviera
    {
        $datos = json_decode($json, true);
        if (!is_array($datos)) {
            throw new RuntimeException('la respuesta no es JSON');
        }
        if (!$datos || self::sinDatos($datos)) {
            return null;
        }
        $candidatos = [];
        self::recorrer($datos, ['buque' => null, 'viaje' => null], strtoupper($puerto), $candidatos, '');
        usort($candidatos, fn($a, $b) => $b['puntaje'] <=> $a['puntaje']);
        $c = $candidatos[0] ?? null;
        if ($c === null) {
            return new ResultadoNaviera($servicio, null, null, null, '', ["Tracking $servicio: todavía sin llegada a $puerto"]);
        }
        $tipo = $c['real'] ? 'ACT' : 'EST';
        return new ResultadoNaviera($servicio, $c['buque'], $c['viaje'], $c['fecha'], $tipo, [
            "Tracking $servicio: " . ($c['buque'] ?? '(buque sin informar)') . ($c['viaje'] ? " viaje {$c['viaje']}" : '')
            . ' · ' . ($c['real'] ? 'llegó' : 'llegada estimada') . ' ' . ($c['fecha'] ? Texto::mostrarFecha($c['fecha']) : '(sin fecha)'),
        ]);
    }

    /** Respuestas tipo {"status": "processing"} o {"error": ...} sin datos todavía. */
    private static function sinDatos(array $d): bool
    {
        $estado = strtolower((string) ($d['status'] ?? $d['state'] ?? ''));
        return in_array($estado, ['queued', 'processing', 'pending', 'in_progress', 'not_found'], true) && count($d) <= 3;
    }

    private static function recorrer(array $nodo, array $contexto, string $puerto, array &$candidatos, string $camino): void
    {
        // Buque y viaje de este nivel (pasan a los niveles de adentro).
        foreach ($nodo as $k => $v) {
            $clave = strtolower((string) $k);
            if (preg_match('/^(vessel|vessel_?name|ship|ship_?name|buque)$/', $clave)) {
                $nombre = is_array($v) ? ($v['name'] ?? $v['vessel_name'] ?? $v['vesselName'] ?? null) : $v;
                if (is_string($nombre) && trim($nombre) !== '' && !ctype_digit(trim($nombre))) {
                    $contexto['buque'] = trim($nombre);
                }
                if (is_array($v) && isset($v['voyage'])) {
                    $contexto['viaje'] = (string) $v['voyage'];
                }
            } elseif (preg_match('/^(voyage|voyage_?(no|number)|viaje)$/', $clave) && is_scalar($v) && trim((string) $v) !== '') {
                $contexto['viaje'] = trim((string) $v);
            }
        }

        // ¿Este nodo es el puerto? (código UN/LOCODE o nombre, propio o en un "location"/"port" de adentro)
        $lugar = '';
        foreach ($nodo as $k => $v) {
            if (is_string($v) && preg_match('/(locode|location|port|place|city|name|pod|destination)/i', (string) $k)) {
                $lugar .= ' ' . $v;
            } elseif (is_array($v) && preg_match('/(location|port|place|pod|destination)/i', (string) $k)) {
                foreach ($v as $vv) {
                    $lugar .= is_string($vv) ? ' ' . $vv : '';
                }
            }
        }
        $lugar = strtoupper($lugar);
        $esPuerto = str_contains($lugar, $puerto) || ($puerto === 'UYMVD' && str_contains($lugar, 'MONTEVIDEO'));

        if ($esPuerto) {
            foreach ($nodo as $k => $v) {
                if (!is_string($v) || !preg_match('/(eta|ata|arriv|date|time|discharg)/i', (string) $k)) {
                    continue;
                }
                $fecha = Texto::fecha($v);
                if ($fecha === null || !preg_match('/^\d{4}-\d{2}-\d{2}/', $fecha)) {
                    continue;
                }
                $texto = strtolower($camino . ' ' . $k . ' ' . implode(' ', array_filter($nodo, 'is_string')));
                $llegada = (bool) preg_match('/(arriv|eta|ata|discharg|descarg|berth|pod)/', $texto);
                $real = (bool) preg_match('/(actual|\bata\b|arrived|^ata|_ata|is_actual"?\s*true)/', $texto) || ($nodo['actual'] ?? $nodo['is_actual'] ?? false) === true;
                $candidatos[] = [
                    'buque' => $contexto['buque'], 'viaje' => $contexto['viaje'], 'fecha' => $fecha, 'real' => $real,
                    'puntaje' => ($contexto['buque'] ? 4 : 0) + ($llegada ? 2 : 0) + ($real ? 1 : 0),
                ];
            }
        }

        foreach ($nodo as $k => $v) {
            if (is_array($v)) {
                self::recorrer($v, $contexto, $puerto, $candidatos, $camino . '/' . $k);
            }
        }
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
