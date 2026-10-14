<?php
declare(strict_types=1);

/** Lo que interesa de la respuesta de Lucía para un contenedor. */
final class ResultadoLucia
{
    /**
     * @param bool $encontrado Lucía tiene información del contenedor
     * @param ?string $buque nombre del buque en que viene
     * @param ?string $viaje número de viaje, si lo informa
     * @param ?string $fecha fecha de arribo prevista ("Y-m-d H:i"), si la informa
     * @param array $detalle respuesta completa, para mostrar y para depurar
     */
    public function __construct(
        public readonly bool $encontrado,
        public readonly ?string $buque = null,
        public readonly ?string $viaje = null,
        public readonly ?string $fecha = null,
        public readonly array $detalle = [],
    ) {
    }
}

/** Fuente de información de contenedores (Lucía real o una simulada para pruebas). */
interface ConsultaLucia
{
    /** @throws RuntimeException si la consulta falla (red, credenciales, etc.) */
    public function consultar(string $contenedor): ResultadoLucia;
}

/**
 * Simulación: lee las respuestas de un archivo JSON con la forma
 * {"MSKU1234567": {"buque": "...", "viaje": "...", "fecha": "2026-10-20T08:00:00"}, ...}.
 * Sirve para probar el sistema mientras no está configurado el webservice real.
 */
final class LuciaArchivo implements ConsultaLucia
{
    public function __construct(private string $archivo)
    {
    }

    public function consultar(string $contenedor): ResultadoLucia
    {
        $datos = json_decode((string) @file_get_contents($this->archivo), true);
        if (!is_array($datos)) {
            throw new RuntimeException("No se pudo leer {$this->archivo}");
        }
        $d = $datos[$contenedor] ?? null;
        if ($d === null) {
            return new ResultadoLucia(false);
        }
        return new ResultadoLucia(true, $d['buque'] ?? null, $d['viaje'] ?? null, Texto::fecha($d['fecha'] ?? null), $d);
    }
}

/** Falta configuración: no tiene sentido seguir consultando otros contenedores. */
final class LuciaSinConfigurar extends RuntimeException
{
}

/** Mientras no se conozca el webservice de Lucía, avisa claramente en lugar de fallar en silencio. */
final class LuciaNoConfigurada implements ConsultaLucia
{
    public function consultar(string $contenedor): ResultadoLucia
    {
        throw new LuciaSinConfigurar('El webservice de Lucía todavía no está configurado (config.php → lucia → modo).');
    }
}

final class Lucia
{
    public static function crear(array $cfg): ConsultaLucia
    {
        return match ($cfg['modo'] ?? 'pendiente') {
            'archivo' => new LuciaArchivo($cfg['archivo']),
            'pendiente' => new LuciaNoConfigurada(),
            default => throw new RuntimeException('Modo de Lucía desconocido: ' . $cfg['modo']),
        };
    }
}
