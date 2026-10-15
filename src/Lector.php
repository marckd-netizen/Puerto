<?php
declare(strict_types=1);

/** Descarga la página de una terminal y la convierte en filas normalizadas. */
final class Lector
{
    public function __construct(private array $http)
    {
    }

    /**
     * @param array<string, string> $encabezados
     * @param string|null $cuerpo si se indica, la consulta se envía por POST con ese cuerpo
     */
    public function descargar(string $url, array $encabezados = [], ?string $cuerpo = null): string
    {
        if (!preg_match('#^https?://#i', $url)) {
            // Permite usar un archivo local (útil para pruebas).
            $contenido = @file_get_contents($url);
            if ($contenido === false) {
                throw new RuntimeException("No se pudo leer $url");
            }
            return $contenido;
        }

        $r = Http::pedir($url, $cuerpo, array_map(fn($k, $v) => "$k: $v", array_keys($encabezados), $encabezados), [
            'timeout'    => $this->http['timeout'] ?? 30,
            'user_agent' => $this->http['user_agent'] ?? 'PuertoUnificado/1.0',
            'ca_bundle'  => $this->http['ca_bundle'] ?? '',
        ]);
        if ($r['codigo'] >= 400) {
            throw new RuntimeException("Error al descargar $url: HTTP {$r['codigo']}");
        }
        $cuerpo = $r['cuerpo'];
        if (!mb_check_encoding($cuerpo, 'UTF-8')) {
            $cuerpo = mb_convert_encoding($cuerpo, 'UTF-8', 'ISO-8859-1');
        }
        return $cuerpo;
    }

    /** @return list<array<string, ?string>> */
    public function leerTerminal(array $terminal): array
    {
        $contenido = $this->descargar($terminal['url'], $terminal['encabezados'] ?? [], $terminal['cuerpo_post'] ?? null);
        $parser = match ($terminal['parser'] ?? 'tabla_html') {
            'tabla_html' => new TablaHtmlParser($terminal['columnas']),
            'json_api' => new JsonApiParser($terminal['columnas'], $terminal['excluir_si'] ?? []),
            default => throw new RuntimeException('Parser desconocido: ' . $terminal['parser']),
        };

        $filas = [];
        foreach ($parser->leer($contenido) as $f) {
            // Formato de presentación opcional por campo, por ejemplo 'viaje' => 'Sem. %s'.
            foreach ($terminal['formato'] ?? [] as $campo => $formato) {
                if (($f[$campo] ?? '') !== '') {
                    $f[$campo] = sprintf($formato, $f[$campo]);
                }
            }
            foreach (['eta', 'etb', 'etd', 'cierre'] as $campoFecha) {
                if (array_key_exists($campoFecha, $f)) {
                    $f[$campoFecha] = Texto::fecha($f[$campoFecha]);
                }
            }
            foreach ($f as $k => $v) {
                if ($v === '') {
                    $f[$k] = null;
                }
            }
            $filas[] = $f;
        }
        return $filas;
    }
}
