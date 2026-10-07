<?php
declare(strict_types=1);

/** Descarga la página de una terminal y la convierte en filas normalizadas. */
final class Lector
{
    public function __construct(private array $http)
    {
    }

    public function descargar(string $url): string
    {
        if (!preg_match('#^https?://#i', $url)) {
            // Permite usar un archivo local (útil para pruebas).
            $contenido = @file_get_contents($url);
            if ($contenido === false) {
                throw new RuntimeException("No se pudo leer $url");
            }
            return $contenido;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => $this->http['timeout'] ?? 30,
            CURLOPT_USERAGENT      => $this->http['user_agent'] ?? 'PuertoUnificado/1.0',
            CURLOPT_ENCODING       => '',
        ]);
        $cuerpo = curl_exec($ch);
        $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($cuerpo === false || $codigo >= 400) {
            throw new RuntimeException("Error al descargar $url: " . ($error ?: "HTTP $codigo"));
        }
        if (!mb_check_encoding($cuerpo, 'UTF-8')) {
            $cuerpo = mb_convert_encoding($cuerpo, 'UTF-8', 'ISO-8859-1');
        }
        return $cuerpo;
    }

    /** @return list<array<string, ?string>> */
    public function leerTerminal(array $terminal): array
    {
        $html = $this->descargar($terminal['url']);
        $parser = match ($terminal['parser'] ?? 'tabla_html') {
            'tabla_html' => new TablaHtmlParser($terminal['columnas']),
            default => throw new RuntimeException('Parser desconocido: ' . $terminal['parser']),
        };

        $filas = [];
        foreach ($parser->leer($html) as $f) {
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
