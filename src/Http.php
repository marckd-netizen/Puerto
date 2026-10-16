<?php
declare(strict_types=1);

/**
 * Pedidos HTTP con curl si está disponible y, si no, con las funciones de PHP (streams),
 * que solo necesitan la extensión openssl para HTTPS. Así el sitio funciona aunque no se
 * pueda activar curl.
 */
final class Http
{
    /** Para las pruebas: usar siempre la alternativa sin curl. */
    public static bool $sinCurl = false;

    /**
     * @param array<int, string> $encabezados líneas "Nombre: valor"
     * @param array{timeout?: int, usuario?: string, clave?: string, ca_bundle?: string, user_agent?: string} $opciones
     * @return array{codigo: int, cuerpo: string}
     * @throws RuntimeException si no se pudo conectar
     */
    public static function pedir(string $url, ?string $cuerpo = null, array $encabezados = [], array $opciones = []): array
    {
        $ca = self::certificados($opciones['ca_bundle'] ?? '');
        return (extension_loaded('curl') && !self::$sinCurl)
            ? self::conCurl($url, $cuerpo, $encabezados, $opciones, $ca)
            : self::conStreams($url, $cuerpo, $encabezados, $opciones, $ca);
    }

    public static function usaCurl(): bool
    {
        return extension_loaded('curl') && !self::$sinCurl;
    }

    private static function conCurl(string $url, ?string $cuerpo, array $encabezados, array $opciones, ?string $ca): array
    {
        $ch = curl_init($url);
        $o = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => $opciones['timeout'] ?? 30,
            CURLOPT_USERAGENT      => $opciones['user_agent'] ?? 'PuertoUnificado/1.0',
            CURLOPT_ENCODING       => '',
            CURLOPT_HTTPHEADER     => $encabezados,
        ];
        if ($cuerpo !== null) {
            $o[CURLOPT_POST] = true;
            $o[CURLOPT_POSTFIELDS] = $cuerpo;
        }
        if (($opciones['usuario'] ?? '') !== '') {
            $o[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
            $o[CURLOPT_USERPWD] = $opciones['usuario'] . ':' . ($opciones['clave'] ?? '');
        }
        if ($ca !== null) {
            $o[CURLOPT_CAINFO] = $ca;
        }
        curl_setopt_array($ch, $o);
        $respuesta = curl_exec($ch);
        $codigo = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        unset($ch); // curl_close() no hace nada desde PHP 8.0 y es obsoleta en 8.5
        if ($respuesta === false) {
            throw new RuntimeException("No se pudo conectar con $url: $error");
        }
        return ['codigo' => $codigo, 'cuerpo' => (string) $respuesta];
    }

    private static function conStreams(string $url, ?string $cuerpo, array $encabezados, array $opciones, ?string $ca): array
    {
        if (str_starts_with(strtolower($url), 'https://') && !extension_loaded('openssl')) {
            throw new RuntimeException('Para conectarse por HTTPS sin curl hace falta la extensión openssl de PHP.');
        }
        if (($opciones['usuario'] ?? '') !== '') {
            $encabezados[] = 'Authorization: Basic ' . base64_encode($opciones['usuario'] . ':' . ($opciones['clave'] ?? ''));
        }
        $encabezados[] = 'User-Agent: ' . ($opciones['user_agent'] ?? 'PuertoUnificado/1.0');
        $encabezados[] = 'Connection: close';
        $http = [
            'method'          => $cuerpo === null ? 'GET' : 'POST',
            'header'          => implode("\r\n", $encabezados),
            'timeout'         => (float) ($opciones['timeout'] ?? 30),
            'ignore_errors'   => true, // devolver el cuerpo también en 4xx/5xx
            'follow_location' => 1,
            'max_redirects'   => 5,
            'protocol_version' => 1.1,
        ];
        if ($cuerpo !== null) {
            $http['content'] = $cuerpo;
        }
        $ssl = ['verify_peer' => true, 'verify_peer_name' => true];
        if ($ca !== null) {
            $ssl['cafile'] = $ca;
        }

        $error = null;
        set_error_handler(function (int $n, string $m) use (&$error) {
            $error = $m;
            return true;
        });
        try {
            $respuesta = file_get_contents($url, false, stream_context_create(['http' => $http, 'ssl' => $ssl]));
        } finally {
            restore_error_handler();
        }
        if ($respuesta === false) {
            $error = preg_replace('/^file_get_contents\([^)]*\): /', '', (string) $error);
            throw new RuntimeException("No se pudo conectar con $url: " . ($error ?: 'sin respuesta')
                . (str_contains((string) $error, 'certificate') ? ' (falta la lista de certificados: configurar ca_bundle en config.php)' : ''));
        }

        // Código de la última respuesta (puede haber redirecciones antes).
        $codigo = 0;
        // PHP 8.4+ tiene http_get_last_response_headers(); $http_response_header es obsoleta en 8.5.
        $cabeceras = function_exists('http_get_last_response_headers')
            ? (http_get_last_response_headers() ?? [])
            : ($http_response_header ?? []);
        foreach ($cabeceras as $linea) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $linea, $m)) {
                $codigo = (int) $m[1];
            }
        }
        return ['codigo' => $codigo, 'cuerpo' => $respuesta];
    }

    /**
     * Lista de certificados para verificar HTTPS. PHP en Windows (XAMPP) muchas veces no la tiene
     * configurada; se usa la que viene con XAMPP si existe.
     */
    public static function certificados(string $configurado = ''): ?string
    {
        if ($configurado !== '') {
            return $configurado;
        }
        if (ini_get('openssl.cafile') || ini_get('curl.cainfo') || PHP_OS_FAMILY !== 'Windows') {
            return null; // PHP ya sabe dónde están
        }
        // Carpeta de PHP: la de php.ini o la de las extensiones (con Apache, PHP_BINARY es httpd.exe).
        $carpetas = array_filter([
            php_ini_loaded_file() ? dirname(php_ini_loaded_file()) : null,
            ini_get('extension_dir') ? dirname(ini_get('extension_dir')) : null,
            dirname(PHP_BINARY),
        ]);
        foreach (array_unique($carpetas) as $php) {
            foreach ([
                $php . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'cacert.pem',
                dirname($php) . DIRECTORY_SEPARATOR . 'apache' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'curl-ca-bundle.crt',
                $php . DIRECTORY_SEPARATOR . 'cacert.pem',
                $php . DIRECTORY_SEPARATOR . 'curl-ca-bundle.crt',
            ] as $candidato) {
                if (is_file($candidato)) {
                    return $candidato;
                }
            }
        }
        return null;
    }
}
