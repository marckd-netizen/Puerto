<?php
declare(strict_types=1);

/** Normalización y validación de números de contenedor (ISO 6346) y RUT uruguayo. */
final class Validacion
{
    private const VALOR_LETRA = [
        'A' => 10, 'B' => 12, 'C' => 13, 'D' => 14, 'E' => 15, 'F' => 16, 'G' => 17, 'H' => 18, 'I' => 19,
        'J' => 20, 'K' => 21, 'L' => 23, 'M' => 24, 'N' => 25, 'O' => 26, 'P' => 27, 'Q' => 28, 'R' => 29,
        'S' => 30, 'T' => 31, 'U' => 32, 'V' => 34, 'W' => 35, 'X' => 36, 'Y' => 37, 'Z' => 38,
    ];

    /** "msku 123456-7" → "MSKU1234567" */
    public static function normalizarContenedor(string $s): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper($s));
    }

    /** "MSKU1234565" → "MSKU-123456-5" (formato que usa Lucía y como se lee habitualmente). */
    public static function formatearContenedor(string $numero): string
    {
        $n = self::normalizarContenedor($numero);
        return self::formatoContenedor($n) ? substr($n, 0, 4) . '-' . substr($n, 4, 6) . '-' . $n[10] : $numero;
    }

    /** Formato AAAAnnnnnnn: 4 letras y 7 números. */
    public static function formatoContenedor(string $numero): bool
    {
        return (bool) preg_match('/^[A-Z]{4}\d{7}$/', $numero);
    }

    /** Dígito verificador ISO 6346 calculado sobre los primeros 10 caracteres. */
    public static function digitoContenedor(string $numero): int
    {
        $suma = 0;
        for ($i = 0; $i < 10; $i++) {
            $c = $numero[$i];
            $valor = ctype_digit($c) ? (int) $c : self::VALOR_LETRA[$c];
            $suma += $valor * (2 ** $i);
        }
        return $suma % 11 % 10;
    }

    public static function digitoContenedorCorrecto(string $numero): bool
    {
        return self::formatoContenedor($numero) && self::digitoContenedor($numero) === (int) $numero[10];
    }

    /** Deja solo los dígitos: "21.234.567-0012" → "212345670012" */
    public static function normalizarRut(string $s): string
    {
        return preg_replace('/\D/', '', $s);
    }

    public static function formatoRut(string $rut): bool
    {
        return (bool) preg_match('/^\d{12}$/', $rut);
    }

    /** Dígito verificador del RUT (módulo 11 con pesos 4,3,2,9,8,7,6,5,4,3,2). */
    public static function digitoRutCorrecto(string $rut): bool
    {
        if (!self::formatoRut($rut)) {
            return false;
        }
        $pesos = [4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $suma = 0;
        foreach ($pesos as $i => $p) {
            $suma += (int) $rut[$i] * $p;
        }
        $dv = 11 - $suma % 11;
        if ($dv === 11) {
            $dv = 0;
        }
        return $dv !== 10 && $dv === (int) $rut[11];
    }
}
