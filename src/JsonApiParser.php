<?php
declare(strict_types=1);

/**
 * Lee una respuesta JSON (por ejemplo el line-up de TCP), busca la lista de buques
 * y asigna cada propiedad a un campo según los nombres configurados.
 *
 * Los objetos anidados se aplanan: {"vessel": {"name": "X"}} queda como "vessel.name"
 * y se compara como "vesselname". Así un alias puede ser "vesselName", "vessel.name"
 * o "vessel_name" indistintamente.
 */
final class JsonApiParser
{
    /**
     * @param array<string, string[]> $campos campo => nombres posibles de propiedad
     * @param array<string, mixed> $excluir propiedad => valor; se descartan los elementos que lo tengan
     */
    public function __construct(private array $campos, private array $excluir = [])
    {
    }

    /** @return list<array<string, string>> */
    public function leer(string $json): array
    {
        $datos = json_decode($json, true);
        if (!is_array($datos)) {
            throw new RuntimeException('La respuesta no es JSON válido: ' . mb_substr(trim($json), 0, 120));
        }

        $lista = $this->buscarLista($datos);
        if ($lista === null) {
            throw new RuntimeException('No se encontró una lista de buques en el JSON.');
        }

        $mapa = $this->mapear($lista);
        if (!isset($mapa['buque'])) {
            throw new RuntimeException('No se encontró la propiedad del nombre del buque. Propiedades disponibles: '
                . implode(', ', array_keys($this->aplanar($lista[0]))));
        }

        $resultado = [];
        foreach ($lista as $item) {
            $plano = $this->aplanar($item);
            foreach ($this->excluir as $propiedad => $valor) {
                if (array_key_exists($propiedad, $plano) && $plano[$propiedad] === $valor) {
                    continue 2;
                }
            }
            $registro = [];
            foreach ($mapa as $campo => $propiedad) {
                $registro[$campo] = Texto::limpiar($this->texto($plano[$propiedad] ?? null));
            }
            if ($registro['buque'] !== '') {
                $resultado[] = $registro;
            }
        }
        return $resultado;
    }

    /**
     * Qué propiedad del JSON corresponde a cada campo (útil para diagnóstico).
     * @return array<string, string>
     */
    public function mapear(array $lista): array
    {
        // Se juntan las propiedades de varios elementos por si algunos vienen incompletos.
        $propiedades = [];
        foreach (array_slice($lista, 0, 20) as $item) {
            $propiedades += $this->aplanar($item);
        }
        $normalizadas = [];
        foreach (array_keys($propiedades) as $p) {
            $normalizadas[$p] = self::normalizarClave($p);
        }

        $mapa = [];
        $usadas = [];
        // Primero coincidencias exactas para todos los campos, después las parciales.
        foreach ([true, false] as $exacta) {
            foreach ($this->campos as $campo => $alias) {
                if (isset($mapa[$campo])) {
                    continue;
                }
                foreach ($alias as $a) {
                    $a = self::normalizarClave($a);
                    foreach ($normalizadas as $p => $n) {
                        if (isset($usadas[$p])) {
                            continue;
                        }
                        if ($exacta ? $n === $a : (strlen($a) > 3 && str_ends_with($n, $a))) {
                            $mapa[$campo] = $p;
                            $usadas[$p] = true;
                            continue 3;
                        }
                    }
                }
            }
        }
        return $mapa;
    }

    /** Devuelve la primera lista de objetos que encuentra (la raíz o dentro de data, items, etc.). */
    public function buscarLista(array $datos): ?array
    {
        if (array_is_list($datos)) {
            return ($datos && is_array($datos[0]) && !array_is_list($datos[0])) ? $datos : null;
        }
        $mejor = null;
        foreach ($datos as $valor) {
            if (is_array($valor)) {
                $lista = $this->buscarLista($valor);
                if ($lista !== null && ($mejor === null || count($lista) > count($mejor))) {
                    $mejor = $lista;
                }
            }
        }
        return $mejor;
    }

    /** @return array<string, mixed> */
    public function aplanar(array $item, string $prefijo = ''): array
    {
        $plano = [];
        foreach ($item as $k => $v) {
            $clave = $prefijo === '' ? (string) $k : "$prefijo.$k";
            if (is_array($v) && $v !== [] && !array_is_list($v)) {
                $plano += $this->aplanar($v, $clave);
            } else {
                $plano[$clave] = $v;
            }
        }
        return $plano;
    }

    public static function normalizarClave(string $s): string
    {
        return preg_replace('/[^a-z0-9]/', '', Texto::normalizar($s));
    }

    private function texto(mixed $v): string
    {
        return match (true) {
            $v === null => '',
            is_bool($v) => $v ? 'Sí' : 'No',
            is_array($v) => implode(', ', array_map(fn($x) => is_scalar($x) ? (string) $x : '', $v)),
            default => (string) $v,
        };
    }
}
