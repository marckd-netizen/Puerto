<?php
declare(strict_types=1);

const ETIQUETAS = [
    'buque' => 'Buque', 'viaje' => 'Viaje', 'linea' => 'Línea', 'agencia' => 'Agencia',
    'muelle' => 'Muelle', 'eta' => 'ETA', 'etb' => 'ETB', 'etd' => 'ETD',
    'operativa' => 'Operativa', 'estado' => 'Estado', 'cierre' => 'Cierre',
];

function encabezado(string $titulo, int $refresh = 0): void
{
    ?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php if ($refresh > 0): ?><meta http-equiv="refresh" content="<?= $refresh ?>"><?php endif; ?>
<title><?= e($titulo) ?></title>
<link rel="stylesheet" href="estilos.css">
</head>
<body>
<header>
    <h1>⚓ Arribos Puerto de Montevideo</h1>
    <nav><a href="index.php">Arribos</a><a href="historial.php">Historial de cambios</a></nav>
</header>
<main>
<?php
}

function pie(): void
{
    echo "</main>\n</body>\n</html>\n";
}

function etiquetaTerminal(string $codigo, array $cfg): string
{
    $color = $cfg['terminales'][$codigo]['color'] ?? '#555';
    return '<span class="terminal" style="background:' . e($color) . '">' . e($codigo) . '</span>';
}

function esCampoFecha(string $campo): bool
{
    return in_array($campo, ['eta', 'etb', 'etd', 'cierre'], true);
}

function mostrarValor(string $campo, ?string $v): string
{
    return esCampoFecha($campo) ? Texto::mostrarFecha($v) : (string) $v;
}

/** Diferencia en horas entre dos fechas "Y-m-d H:i", o null si no son fechas. */
function horasEntre(?string $desde, ?string $hasta): ?float
{
    $a = $desde ? DateTime::createFromFormat('Y-m-d H:i', $desde) : false;
    $b = $hasta ? DateTime::createFromFormat('Y-m-d H:i', $hasta) : false;
    if (!$a || !$b) {
        return null;
    }
    return ($b->getTimestamp() - $a->getTimestamp()) / 3600;
}

function formatoHoras(float $h): string
{
    $abs = abs($h);
    $txt = $abs >= 48 ? round($abs / 24, 1) . 'd' : round($abs) . 'h';
    return ($h > 0 ? '+' : '−') . $txt;
}

function nombreDia(string $fecha): string
{
    $dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
    $ts = strtotime($fecha);
    if ($ts === false) {
        return 'Sin fecha';
    }
    $hoy = strtotime('today');
    $prefijo = match ((int) round(($ts - $hoy) / 86400)) {
        0 => 'Hoy, ', 1 => 'Mañana, ', -1 => 'Ayer, ', default => '',
    };
    return $prefijo . $dias[(int) date('w', $ts)] . ' ' . date('d/m/Y', $ts);
}
