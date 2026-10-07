<?php
declare(strict_types=1);

const ETIQUETAS = [
    'buque' => 'Buque', 'viaje' => 'Viaje', 'linea' => 'Línea', 'agencia' => 'Agencia',
    'muelle' => 'Muelle', 'eta' => 'ETA', 'etb' => 'ETB', 'etd' => 'ETD',
    'servicio' => 'Servicio', 'operativa' => 'Operativa', 'estado' => 'Estado', 'cierre' => 'Cierre',
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

/** "hace 35 min", "hace 3 h", "hace 2 días" */
function haceCuanto(string $fecha, int $ahora): string
{
    $min = max(0, (int) floor(($ahora - strtotime($fecha)) / 60));
    return match (true) {
        $min < 1 => 'recién',
        $min < 60 => "hace $min min",
        $min < 48 * 60 => 'hace ' . intdiv($min, 60) . ' h',
        default => 'hace ' . intdiv($min, 1440) . ' días',
    };
}

/** Valor actual, con el anterior tachado si cambió en las últimas horas. */
function valorConCambio(string $campo, array $escala, array $recientes): string
{
    $html = e(mostrarValor($campo, $escala[$campo])) ?: '—';
    if (isset($recientes[$campo])) {
        $antes = e(mostrarValor($campo, $recientes[$campo]['valor_anterior'])) ?: '(vacío)';
        return '<span class="antes">' . $antes . '</span><strong>' . $html . '</strong>';
    }
    return $html;
}
