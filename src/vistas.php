<?php
declare(strict_types=1);

const ETIQUETAS = [
    'buque' => 'Buque', 'viaje' => 'Viaje', 'linea' => 'Línea', 'agencia' => 'Agencia',
    'muelle' => 'Muelle', 'eta' => 'ETA', 'etb' => 'ETB', 'etd' => 'ETD',
    'servicio' => 'Servicio', 'operativa' => 'Operativa', 'estado' => 'Estado', 'cierre' => 'Cierre',
];

function encabezado(string $titulo, int $refresh = 0, bool $menu = true): void
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
    <?php if ($menu): ?>
    <nav>
        <a href="index.php">Arribos</a><a href="contenedores.php">Contenedores</a><a href="clientes.php">Clientes</a><a href="historial.php">Historial de cambios</a><a href="usuarios.php">Usuarios</a>
        <?php if (Acceso::usuario()): ?><span class="usuario"><?= e(Acceso::usuario()) ?> · <a href="salir.php">Salir</a></span><?php endif; ?>
    </nav>
    <?php endif; ?>
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

/**
 * Situación de stock de un contenedor según Lucía (MOVIMSTOCK). null si todavía no hay datos de Lucía.
 * @return ?array{estado: string, texto: string}
 */
function stockContenedor(array $c, array $cfg): ?array
{
    if (!in_array($c['lucia_estado'] ?? '', ['encontrado', 'en_stock'], true)) {
        return null;
    }
    $s = LuciaSoap::stockDeDetalle(json_decode((string) ($c['lucia_detalle'] ?? ''), true) ?: []);
    if ($s === null) {
        return null;
    }
    $dep = $s['deposito'] ?? null;
    $nombre = $dep !== null ? ($cfg['lucia']['depositos'][$dep] ?? null) : null;
    $donde = $dep !== null ? ' en depósito ' . $dep . ($nombre ? " ($nombre)" : '') : '';
    $fecha = $s['fecha'] ? date('d/m', strtotime($s['fecha'])) : '';
    return ['estado' => $s['estado'], 'texto' => match ($s['estado']) {
        'en_stock' => "En stock$donde desde $fecha",
        'salio'    => "Salió del stock $fecha$donde",
        default    => 'Sin stock todavía',
    }];
}

function etiquetaStock(array $c, array $cfg): string
{
    $s = stockContenedor($c, $cfg);
    return $s === null ? '' : '<span class="stock stock-' . e($s['estado']) . '">' . e($s['texto']) . '</span>';
}

/**
 * Ver los contenedores propios o los de todos. La elección se recuerda (cookie) y vale para el tablero
 * y la página Contenedores. Devuelve el usuario por el que filtrar, o null para ver todos
 * (también si el sitio se usa sin usuarios).
 */
function verContenedoresDe(): ?string
{
    $usuario = Acceso::usuario();
    if ($usuario === null) {
        return null;
    }
    $ver = $_GET['ver'] ?? null;
    if (in_array($ver, ['mios', 'todos'], true)) {
        setcookie('ver_contenedores', $ver, ['expires' => time() + 365 * 86400, 'path' => '/', 'samesite' => 'Lax']);
    } else {
        $ver = ($_COOKIE['ver_contenedores'] ?? 'mios') === 'todos' ? 'todos' : 'mios';
    }
    return $ver === 'mios' ? $usuario : null;
}

/** Selector "Míos / De todos" para los filtros (mantiene los demás filtros de la página). */
function selectorVerContenedores(?string $filtro): string
{
    if (Acceso::usuario() === null) {
        return '';
    }
    $html = '<span class="selector-ver">Contenedores:';
    foreach (['mios' => 'Míos', 'todos' => 'De todos'] as $valor => $texto) {
        $activo = ($valor === 'mios') === ($filtro !== null);
        $url = '?' . http_build_query(['ver' => $valor] + array_diff_key($_GET, ['ver' => 1]));
        $html .= $activo ? ' <strong>' . e($texto) . '</strong>' : ' <a href="' . e($url) . '">' . e($texto) . '</a>';
    }
    return $html . '</span>';
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

/** Mensajes que sobreviven a una redirección (después de guardar un formulario). */
function avisar(string $tipo, string $mensaje): void
{
    $_SESSION['avisos'][] = [$tipo, $mensaje];
}

function mostrarAvisos(): void
{
    foreach ($_SESSION['avisos'] ?? [] as [$tipo, $mensaje]) {
        echo '<p class="aviso aviso-' . e($tipo) . '">' . nl2br(e($mensaje)) . "</p>\n";
    }
    unset($_SESSION['avisos']);
}

/** Resultado de una importación como avisos. */
function avisarImportacion(array $r, string $que): void
{
    avisar($r['ok'] ? 'ok' : 'error', "$que importados: {$r['ok']}");
    if ($r['avisos']) {
        avisar('atencion', implode("\n", $r['avisos']));
    }
    if ($r['errores']) {
        avisar('error', count($r['errores']) . " con errores:\n" . implode("\n", array_slice($r['errores'], 0, 30))
            . (count($r['errores']) > 30 ? "\n…" : ''));
    }
}

function volverA(string $pagina): never
{
    header('Location: ' . $pagina);
    exit;
}
