<?php
declare(strict_types=1);

/**
 * Arma las filas de la página principal: una por buque, juntando lo que publica
 * cada terminal, con su último cambio y el nivel de resaltado.
 */
final class Tablero
{
    /** Escalas del mismo buque con ETA a más de estos días se tratan como visitas distintas. */
    private const DIAS_MISMA_ESCALA = 7;

    public function __construct(private array $cfg, private int $ahora)
    {
    }

    /**
     * @param list<array> $escalas  filas de la tabla escalas
     * @param array<int, list<array>> $ultimos  últimos cambios por escala (los del mismo momento)
     * @param array<int, array<string, array>> $recientes  cambios de las últimas horas por escala y campo
     * @return list<array>
     */
    public function filas(array $escalas, array $ultimos, array $recientes): array
    {
        usort($escalas, fn($a, $b) => [$a['eta'] === null, $a['eta']] <=> [$b['eta'] === null, $b['eta']]);

        $filas = [];
        foreach ($escalas as $e) {
            $nombre = Texto::normalizar($e['buque']);
            $destino = null;
            foreach ($filas as $i => $f) {
                if ($f['clave'] === $nombre && !isset($f['terminales'][$e['terminal']]) && $this->cerca($f, $e)) {
                    $destino = $i;
                    break;
                }
            }
            if ($destino === null) {
                $filas[] = ['clave' => $nombre, 'buque' => $e['buque'], 'terminales' => []];
                $destino = array_key_last($filas);
            }
            $filas[$destino]['terminales'][$e['terminal']] = $e;
        }

        foreach ($filas as &$f) {
            $this->completar($f, $ultimos, $recientes);
        }
        unset($f);

        usort($filas, fn($a, $b) => [$a['orden'] === null, $a['orden']] <=> [$b['orden'] === null, $b['orden']]);
        return $filas;
    }

    private function cerca(array $fila, array $escala): bool
    {
        foreach ($fila['terminales'] as $otra) {
            $h = $this->horasEntre($otra['eta'], $escala['eta']);
            if ($h !== null && abs($h) > self::DIAS_MISMA_ESCALA * 24) {
                return false;
            }
        }
        return true;
    }

    private function completar(array &$f, array $ultimos, array $recientes): void
    {
        $etas = array_filter(array_column($f['terminales'], 'eta'), fn($v) => $v !== null);
        $f['orden'] = $etas ? min($etas) : null;

        $estados = [];
        $servicios = [];
        $f['operando'] = false;
        $f['recientes'] = [];
        $ultimo = null;
        $detalle = [];

        foreach ($f['terminales'] as $cod => &$e) {
            if (($e['estado'] ?? '') === '' && !empty($this->cfg['terminales'][$cod]['estado_por_fechas'])) {
                $e['estado'] = $this->estadoPorFechas($e);
            }
            if ($e['estado'] !== null && $e['estado'] !== '') {
                $estados[$cod] = $e['estado'];
                $f['operando'] = $f['operando'] || $this->esOperando($e['estado']);
            }
            if ($e['servicio'] !== null && $e['servicio'] !== '') {
                $servicios[Texto::normalizar($e['servicio'])] = $e['servicio'];
            }
            $id = (int) $e['id'];
            $f['recientes'][$cod] = $recientes[$id] ?? [];

            foreach ($ultimos[$id] ?? [] as $c) {
                if ($ultimo === null || $c['detectado'] > $ultimo) {
                    $ultimo = $c['detectado'];
                    $detalle = [];
                }
                if ($c['detectado'] === $ultimo) {
                    $detalle[] = $c + ['terminal' => $cod];
                }
            }
        }
        unset($e);

        $f['estados'] = $estados;
        $f['servicio'] = implode(' / ', $servicios);
        $f['ultimo_cambio'] = $ultimo;
        $f['detalle_cambio'] = $detalle;
        $f['nivel'] = $this->nivel($f['operando'], $ultimo);
        $f['nivel_cambio'] = $this->nivel(false, $ultimo);
    }

    /** 'operando' (verde), 'reciente' (naranja), 'cambio' (naranja claro) o '' (sin resaltar). */
    private function nivel(bool $operando, ?string $ultimoCambio): string
    {
        if ($operando) {
            return 'operando';
        }
        if ($ultimoCambio === null) {
            return '';
        }
        $horas = ($this->ahora - strtotime($ultimoCambio)) / 3600;
        return match (true) {
            $horas < ($this->cfg['horas_cambio_reciente'] ?? 2) => 'reciente',
            $horas < ($this->cfg['horas_destacado'] ?? 24) => 'cambio',
            default => '',
        };
    }

    /** Para terminales que no publican estado: se deduce de ETB y ETD/ETS. */
    public function estadoPorFechas(array $e): ?string
    {
        $etb = $this->timestamp($e['etb'] ?? null);
        $etd = $this->timestamp($e['etd'] ?? null);
        return match (true) {
            $etd !== null && $this->ahora >= $etd => 'Zarpado',
            $etb !== null && $this->ahora >= $etb => 'Operando',
            $etb !== null => 'Atraque confirmado',
            default => 'Programado',
        };
    }

    private function timestamp(?string $fecha): ?int
    {
        $d = $fecha ? DateTime::createFromFormat('Y-m-d H:i', $fecha) : false;
        return $d ? $d->getTimestamp() : null;
    }

    public function esOperando(?string $estado): bool
    {
        $estado = Texto::normalizar($estado);
        if ($estado === '') {
            return false;
        }
        foreach ($this->cfg['estados_operando'] ?? [] as $palabra) {
            if (str_contains($estado, Texto::normalizar($palabra))) {
                return true;
            }
        }
        return false;
    }

    private function horasEntre(?string $a, ?string $b): ?float
    {
        $ta = $this->timestamp($a);
        $tb = $this->timestamp($b);
        return ($ta !== null && $tb !== null) ? ($tb - $ta) / 3600 : null;
    }
}
