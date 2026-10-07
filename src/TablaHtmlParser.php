<?php
declare(strict_types=1);

/**
 * Busca en una página HTML la tabla cuyos encabezados coinciden mejor con las
 * columnas configuradas y devuelve una fila por buque con los campos conocidos.
 */
final class TablaHtmlParser
{
    /** @param array<string, string[]> $columnas campo => textos posibles de encabezado */
    public function __construct(private array $columnas)
    {
    }

    /** @return list<array<string, string>> */
    public function leer(string $html): array
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        $mejor = null;
        $mejorMapa = [];
        foreach ($doc->getElementsByTagName('table') as $tabla) {
            [$filaEnc, $mapa] = $this->mapearEncabezados($tabla);
            if (isset($mapa['buque']) && count($mapa) > count($mejorMapa)) {
                $mejor = [$tabla, $filaEnc];
                $mejorMapa = $mapa;
            }
        }
        if ($mejor === null) {
            throw new RuntimeException('No se encontró una tabla con columna de buque. Revisar URL o nombres de columnas en config.php.');
        }

        [$tabla, $filaEnc] = $mejor;
        $resultado = [];
        $despuesDelEncabezado = false;
        foreach ($this->filas($tabla) as $fila) {
            if ($fila === $filaEnc) {
                $despuesDelEncabezado = true;
                continue;
            }
            $celdas = $this->celdas($fila);
            // Se ignoran títulos previos al encabezado y filas de una sola celda que ocupan todo el ancho.
            if (!$despuesDelEncabezado || count(array_unique($celdas)) < 2) {
                continue;
            }
            $registro = [];
            foreach ($mejorMapa as $campo => $indice) {
                $registro[$campo] = Texto::limpiar($celdas[$indice] ?? '');
            }
            // Fila vacía o encabezado repetido (algunas páginas lo repiten cada tantas filas).
            if ($registro['buque'] === '' || Texto::normalizar($registro['buque']) === Texto::normalizar($this->textoEncabezado($filaEnc, $mejorMapa['buque']))) {
                continue;
            }
            $resultado[] = $registro;
        }
        return $resultado;
    }

    /** @return array{0: ?DOMElement, 1: array<string,int>} */
    private function mapearEncabezados(DOMElement $tabla): array
    {
        // Revisa las primeras filas: algunas tablas tienen un título antes de los encabezados.
        $revisadas = 0;
        $mejorFila = null;
        $mejorMapa = [];
        foreach ($this->filas($tabla) as $fila) {
            if (++$revisadas > 3) {
                break;
            }
            $mapa = [];
            foreach ($this->celdas($fila) as $i => $texto) {
                $campo = $this->campoDeEncabezado($texto, $mapa);
                if ($campo !== null) {
                    $mapa[$campo] = $i;
                }
            }
            if (count($mapa) > count($mejorMapa)) {
                $mejorFila = $fila;
                $mejorMapa = $mapa;
            }
        }
        return [$mejorFila, $mejorMapa];
    }

    /** Coincidencia exacta primero; si no, encabezado que empieza o contiene el texto. */
    private function campoDeEncabezado(string $texto, array $yaUsados): ?string
    {
        $t = Texto::normalizar($texto);
        if ($t === '') {
            return null;
        }
        foreach ([fn($a) => $t === $a, fn($a) => str_starts_with($t, $a . ' ') || str_starts_with($t, $a . '.'), fn($a) => strlen($a) > 3 && str_contains($t, $a)] as $criterio) {
            foreach ($this->columnas as $campo => $alias) {
                if (isset($yaUsados[$campo])) {
                    continue;
                }
                foreach ($alias as $a) {
                    if ($criterio(Texto::normalizar($a))) {
                        return $campo;
                    }
                }
            }
        }
        return null;
    }

    /** @return list<DOMElement> filas propias de la tabla (no de tablas anidadas) */
    private function filas(DOMElement $tabla): array
    {
        $filas = [];
        foreach ($tabla->getElementsByTagName('tr') as $tr) {
            $padre = $tr->parentNode;
            while ($padre !== null && $padre->nodeName !== 'table') {
                $padre = $padre->parentNode;
            }
            if ($padre === $tabla) {
                $filas[] = $tr;
            }
        }
        return $filas;
    }

    /** @return list<string> */
    private function celdas(DOMElement $fila): array
    {
        $celdas = [];
        foreach ($fila->childNodes as $n) {
            if ($n instanceof DOMElement && ($n->nodeName === 'td' || $n->nodeName === 'th')) {
                $texto = Texto::limpiar($n->textContent);
                $span = max(1, (int) $n->getAttribute('colspan'));
                for ($i = 0; $i < $span; $i++) {
                    $celdas[] = $texto;
                }
            }
        }
        return $celdas;
    }

    private function textoEncabezado(?DOMElement $fila, int $indice): string
    {
        return $fila ? ($this->celdas($fila)[$indice] ?? '') : '';
    }
}
