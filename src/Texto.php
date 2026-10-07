<?php
declare(strict_types=1);

/** Utilidades para limpiar y comparar los textos que publican las terminales. */
final class Texto
{
    public static function limpiar(?string $s): string
    {
        $s = (string) $s;
        $s = str_replace("\u{00A0}", ' ', $s);
        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    /** Minúsculas y sin tildes, para comparar encabezados y nombres. */
    public static function normalizar(?string $s): string
    {
        $s = mb_strtolower(self::limpiar($s), 'UTF-8');
        return strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    }

    /**
     * Convierte fechas como "07/10/2026 14:30", "7-10-26 14:30", "2026-10-07T14:30"
     * o "07/10 14:30" a "Y-m-d H:i". Si no se reconoce, devuelve el texto tal cual
     * para no perder información.
     */
    public static function fecha(?string $s): ?string
    {
        $s = self::limpiar($s);
        if ($s === '' || $s === '-' || $s === '--') {
            return null;
        }

        // ISO con zona horaria (2026-10-07T17:00:00Z o -03:00): se pasa a la hora local.
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})$/', $s)) {
            try {
                return (new DateTimeImmutable($s))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i');
            } catch (Exception) {
                return $s;
            }
        }

        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T](\d{1,2}):(\d{2}))?/', $s, $m)) {
            return self::armar((int) $m[1], (int) $m[2], (int) $m[3], $m[4] ?? null, $m[5] ?? null) ?? $s;
        }

        if (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})(?:[\/.\-](\d{2,4}))?(?:\s*-?\s*(\d{1,2})[:.h](\d{2}))?/', $s, $m)) {
            $anio = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : self::anioProbable((int) $m[2]);
            if ($anio < 100) {
                $anio += 2000;
            }
            return self::armar($anio, (int) $m[2], (int) $m[1], $m[4] ?? null, $m[5] ?? null) ?? $s;
        }

        return $s;
    }

    private static function armar(int $a, int $mes, int $d, ?string $h, ?string $min): ?string
    {
        if (!checkdate($mes, $d, $a)) {
            return null;
        }
        $hora = ($h !== null && $h !== '') ? sprintf('%02d:%02d', (int) $h, (int) $min) : '00:00';
        return sprintf('%04d-%02d-%02d %s', $a, $mes, $d, $hora);
    }

    /** Para fechas sin año: el año que deja la fecha más cerca de hoy. */
    private static function anioProbable(int $mes): int
    {
        $hoy = (int) date('n');
        $anio = (int) date('Y');
        if ($mes - $hoy > 6) {
            return $anio - 1;
        }
        if ($hoy - $mes > 6) {
            return $anio + 1;
        }
        return $anio;
    }

    /** "2026-10-07 14:30" → "07/10 14:30" para mostrar. */
    public static function mostrarFecha(?string $s): string
    {
        if ($s === null || $s === '') {
            return '';
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})$/', $s, $m)) {
            return "$m[3]/$m[2] $m[4]:$m[5]";
        }
        return $s;
    }
}
