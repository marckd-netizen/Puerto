<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/vistas.php';

$cfg = config();
Acceso::exigir($cfg);
$db = Db::conectar($cfg['db']);
$ahora = time();
$horas = (int) ($cfg['horas_destacado'] ?? 24);
$horasReciente = (int) ($cfg['horas_cambio_reciente'] ?? 2);
$terminales = array_keys($cfg['terminales']);

$fTerminal = $_GET['terminal'] ?? '';
$fBuscar = trim($_GET['q'] ?? '');
$fSoloCambios = !empty($_GET['cambios']);
$fPasados = !empty($_GET['pasados']);
$fMios = !empty($_GET['mios']);
$verDe = verContenedoresDe();

// Escalas activas
$where = ['activa = 1'];
$params = [];
if (!$fPasados) {
    // Oculta los que zarparon hace más de un día.
    $where[] = '(COALESCE(etd, eta) IS NULL OR COALESCE(etd, eta) >= ?)';
    $params[] = date('Y-m-d H:i', $ahora - 86400);
}
$st = $db->prepare('SELECT * FROM escalas WHERE ' . implode(' AND ', $where));
$st->execute($params);
$escalas = $st->fetchAll();

// Último cambio de cada escala (todos los campos que cambiaron en esa misma lectura)
$ultimos = [];
$st = $db->query('SELECT c.* FROM cambios c
    JOIN (SELECT escala_id, MAX(detectado) AS m FROM cambios GROUP BY escala_id) u
      ON u.escala_id = c.escala_id AND u.m = c.detectado
    ORDER BY c.id');
foreach ($st->fetchAll() as $c) {
    $ultimos[(int) $c['escala_id']][] = $c;
}

// Cambios de las últimas horas por escala y campo (se guarda el valor más viejo del período)
$recientes = [];
$st = $db->prepare('SELECT * FROM cambios WHERE detectado >= ? ORDER BY detectado, id');
$st->execute([date('Y-m-d H:i:s', $ahora - $horas * 3600)]);
foreach ($st->fetchAll() as $c) {
    $recientes[(int) $c['escala_id']][$c['campo']] ??= $c;
}

$filas = (new Tablero($cfg, $ahora))->filas($escalas, $ultimos, $recientes);

// Contenedores propios vinculados a cada buque (por cualquiera de sus escalas)
$contenedoresPorEscala = (new Contenedores($db))->porEscala($verDe);
foreach ($filas as &$f) {
    $f['contenedores'] = [];
    foreach ($f['terminales'] as $e) {
        array_push($f['contenedores'], ...($contenedoresPorEscala[(int) $e['id']] ?? []));
    }
}
unset($f);

// Servicios disponibles y servicios ocultos. La elección se guarda en una cookie;
// si nunca se eligió, se usan los de config.php.
$serviciosDisponibles = [];
foreach ($filas as $f) {
    foreach ($f['servicios'] as $clave => $nombre) {
        $serviciosDisponibles[$clave] ??= ['nombre' => $nombre, 'buques' => 0];
        $serviciosDisponibles[$clave]['buques']++;
    }
}
ksort($serviciosDisponibles);

if (isset($_GET['servicios'])) {
    $ocultos = array_values(array_filter(array_map('strval', (array) ($_GET['ocultar'] ?? []))));
    setcookie('servicios_ocultos', json_encode($ocultos), [
        'expires' => time() + 365 * 86400, 'path' => '/', 'samesite' => 'Lax',
    ]);
} elseif (isset($_COOKIE['servicios_ocultos']) && is_array($c = json_decode($_COOKIE['servicios_ocultos'], true))) {
    $ocultos = array_values(array_filter(array_map('strval', $c)));
} else {
    $ocultos = $cfg['servicios_ocultos'] ?? [];
}
$ocultosNorm = array_map([Texto::class, 'normalizar'], $ocultos);

$ocultadosPorServicio = 0;
$filas = array_values(array_filter($filas, function ($f) use ($fTerminal, $fBuscar, $fSoloCambios, $fMios, $ocultos, &$ocultadosPorServicio) {
    if ($fTerminal !== '' && !isset($f['terminales'][$fTerminal])) {
        return false;
    }
    if ($fMios && !$f['contenedores']) {
        return false;
    }
    // Un buque con contenedores propios nunca se oculta por servicio.
    if (!$f['contenedores'] && Tablero::ocultaPorServicio($f, $ocultos)) {
        $ocultadosPorServicio++;
        return false;
    }
    if ($fSoloCambios && !in_array($f['nivel_cambio'], ['reciente', 'cambio'], true)) {
        return false;
    }
    if ($fBuscar !== '') {
        $texto = $f['buque'] . ' ' . $f['servicio'] . ' ' . implode(' ', $f['estados']);
        foreach ($f['terminales'] as $e) {
            $texto .= ' ' . $e['viaje'] . ' ' . $e['linea'] . ' ' . $e['agencia'];
        }
        foreach ($f['contenedores'] as $c) {
            $texto .= ' ' . $c['numero'] . ' ' . Validacion::formatearContenedor($c['numero']) . ' ' . $c['carpeta'] . ' ' . $c['cliente'];
        }
        if (!str_contains(Texto::normalizar($texto), Texto::normalizar($fBuscar))) {
            return false;
        }
    }
    return true;
}));

// Última lectura de cada terminal
$lecturas = [];
foreach ($terminales as $cod) {
    $st = $db->prepare('SELECT * FROM lecturas WHERE terminal = ? ORDER BY fecha DESC, id DESC LIMIT 1');
    $st->execute([$cod]);
    $lecturas[$cod] = $st->fetch() ?: null;
}

$cuenta = array_count_values(array_column($filas, 'nivel'));

encabezado('Arribos Montevideo', (int) ($cfg['auto_refresh'] ?? 300));
?>
<div class="estado-lecturas">
<?php foreach ($lecturas as $cod => $l): ?>
    <?php if (!$l): ?>
        <span class="falla"><?= e($cod) ?>: sin lecturas todavía</span>
    <?php elseif (!$l['ok']): ?>
        <span class="falla" title="<?= e($l['mensaje']) ?>"><?= e($cod) ?>: error al leer (<?= e(date('d/m H:i', strtotime($l['fecha']))) ?>)</span>
    <?php else: ?>
        <span title="<?= e($l['mensaje']) ?>"><?= e($cod) ?>: leído <?= e(date('d/m H:i', strtotime($l['fecha']))) ?></span>
    <?php endif; ?>
<?php endforeach; ?>
</div>

<form class="filtros" method="get">
    <select name="terminal" onchange="this.form.submit()">
        <option value="">Todas las terminales</option>
        <?php foreach ($cfg['terminales'] as $cod => $t): ?>
            <option value="<?= e($cod) ?>" <?= $fTerminal === $cod ? 'selected' : '' ?>><?= e($t['nombre']) ?></option>
        <?php endforeach; ?>
    </select>
    <input type="search" name="q" value="<?= e($fBuscar) ?>" placeholder="Buscar buque, servicio, contenedor, carpeta o cliente">
    <label><input type="checkbox" name="cambios" value="1" <?= $fSoloCambios ? 'checked' : '' ?> onchange="this.form.submit()"> Solo con cambios (últimas <?= $horas ?> h)</label>
    <label><input type="checkbox" name="pasados" value="1" <?= $fPasados ? 'checked' : '' ?> onchange="this.form.submit()"> Incluir ya zarpados</label>
    <label><input type="checkbox" name="mios" value="1" <?= $fMios ? 'checked' : '' ?> onchange="this.form.submit()"> Solo buques con contenedores<?= $verDe !== null ? ' míos' : '' ?></label>
    <?= selectorVerContenedores($verDe) ?>
    <details class="servicios">
        <summary>Servicios<?= $ocultos ? ' (' . count($ocultos) . ' ocultos)' : '' ?></summary>
        <div class="servicios-lista">
            <input type="hidden" name="servicios" value="1">
            <p>Marcá los servicios que querés ocultar:</p>
            <?php foreach ($serviciosDisponibles as $clave => $s): ?>
                <label>
                    <input type="checkbox" name="ocultar[]" value="<?= e($s['nombre']) ?>" <?= in_array($clave, $ocultosNorm, true) ? 'checked' : '' ?> onchange="this.form.submit()">
                    <?= e($s['nombre']) ?> <small>(<?= $s['buques'] ?>)</small>
                </label>
            <?php endforeach; ?>
            <?php foreach ($ocultos as $o): if (!isset($serviciosDisponibles[Texto::normalizar($o)])): ?>
                <input type="hidden" name="ocultar[]" value="<?= e($o) ?>">
            <?php endif; endforeach; ?>
        </div>
    </details>
    <button type="submit">Filtrar</button>
</form>

<div class="leyenda">
    <span><i class="muestra operando"></i> Operando (<?= $cuenta['operando'] ?? 0 ?>)</span>
    <span><i class="muestra reciente"></i> Cambio hace menos de <?= $horasReciente ?> h (<?= $cuenta['reciente'] ?? 0 ?>)</span>
    <span><i class="muestra cambio"></i> Cambio hace <?= $horasReciente ?>–<?= $horas ?> h (<?= $cuenta['cambio'] ?? 0 ?>)</span>
    <span><?= count($filas) ?> buques<?php if ($ocultadosPorServicio): ?>
        · <?= $ocultadosPorServicio ?> ocultos por servicio (<?= e(implode(', ', $ocultos)) ?>)
        <a href="?<?= e(http_build_query(array_filter(['terminal' => $fTerminal, 'q' => $fBuscar, 'cambios' => $fSoloCambios ? 1 : null, 'pasados' => $fPasados ? 1 : null, 'mios' => $fMios ? 1 : null, 'servicios' => 1]))) ?>">mostrar todos</a>
    <?php endif; ?></span>
</div>

<div class="tabla-wrap">
<?php if (!$filas): ?>
    <div class="vacio">No hay buques para mostrar.</div>
<?php else: ?>
<table class="tablero">
    <thead>
    <tr>
        <th>Buque</th>
        <th>Estado</th>
        <th>Terminal</th>
        <?php foreach ($terminales as $cod): ?><th>ETA / ETD - <?= e($cod) ?></th><?php endforeach; ?>
        <th>Servicio</th>
        <th>Último cambio</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($filas as $f): ?>
        <tr class="nivel-<?= e($f['nivel'] ?: 'ninguno') ?>">
            <td class="buque">
                <?= e($f['buque']) ?>
                <?php $viajes = array_unique(array_filter(array_column($f['terminales'], 'viaje'))); ?>
                <?php if ($viajes): ?><span class="viaje"><?= e(implode(' / ', $viajes)) ?></span><?php endif; ?>
                <?php if ($f['contenedores']): $clientesFila = array_unique(array_filter(array_column($f['contenedores'], 'cliente'))); ?>
                    <details class="contenedores-buque">
                        <?php $enStock = count(array_filter($f['contenedores'], fn($c) => (stockContenedor($c, $cfg)['estado'] ?? '') === 'en_stock')); ?>
                        <summary>📦 <?= count($f['contenedores']) ?> contenedor<?= count($f['contenedores']) > 1 ? 'es' : '' ?><?= $enStock ? " · $enStock en stock" : '' ?><?= $clientesFila ? ' · ' . e(implode(', ', array_slice($clientesFila, 0, 2))) . (count($clientesFila) > 2 ? ' y ' . (count($clientesFila) - 2) . ' más' : '') : '' ?></summary>
                        <ul>
                            <?php foreach ($f['contenedores'] as $c): ?>
                                <li><strong><?= e(Validacion::formatearContenedor($c['numero'])) ?></strong> · Carpeta <?= e($c['carpeta']) ?><?= $c['cliente'] ? ' · ' . e($c['cliente']) : '' ?><?= $verDe === null && $c['usuario'] ? ' · <small>' . e($c['usuario']) . '</small>' : '' ?> <?= etiquetaStock($c, $cfg) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </details>
                <?php endif; ?>
            </td>
            <td>
                <?php if (count($f['estados']) > 1 && count(array_unique(array_map([Texto::class, 'normalizar'], $f['estados']))) > 1): ?>
                    <?php foreach ($f['estados'] as $cod => $estado): ?>
                        <div><small><?= e($cod) ?>:</small> <?= valorConCambio('estado', $f['terminales'][$cod], $f['recientes'][$cod]) ?></div>
                    <?php endforeach; ?>
                <?php elseif ($f['estados']): ?>
                    <?php $cod = array_key_first($f['estados']); ?>
                    <?= valorConCambio('estado', $f['terminales'][$cod], $f['recientes'][$cod]) ?>
                <?php else: ?>—<?php endif; ?>
            </td>
            <td><?php foreach (array_keys($f['terminales']) as $cod): ?><?= etiquetaTerminal($cod, $cfg) ?> <?php endforeach; ?></td>
            <?php foreach ($terminales as $cod): ?>
                <td class="fechas">
                    <?php if (isset($f['terminales'][$cod])): $e = $f['terminales'][$cod]; $rec = $f['recientes'][$cod]; ?>
                        <div><small>ETA</small> <?= valorConCambio('eta', $e, $rec) ?></div>
                        <div><small>ETD</small> <?= valorConCambio('etd', $e, $rec) ?></div>
                    <?php else: ?>
                        <span class="sin-dato">—</span>
                    <?php endif; ?>
                </td>
            <?php endforeach; ?>
            <td><?= e($f['servicio']) ?: '—' ?></td>
            <td class="ultimo-cambio cambio-<?= e($f['nivel_cambio'] ?: 'ninguno') ?>">
                <?php if ($f['ultimo_cambio']): ?>
                    <strong><?= e(haceCuanto($f['ultimo_cambio'], $ahora)) ?></strong>
                    <small><?= e(date('d/m H:i', strtotime($f['ultimo_cambio']))) ?></small>
                    <?php foreach ($f['detalle_cambio'] as $c): ?>
                        <div class="detalle">
                            <?= e(ETIQUETAS[$c['campo']] ?? $c['campo']) ?><?= count($f['terminales']) > 1 ? ' ' . e($c['terminal']) : '' ?>:
                            <?= e(mostrarValor($c['campo'], $c['valor_anterior'])) ?: '(vacío)' ?> → <?= e(mostrarValor($c['campo'], $c['valor_nuevo'])) ?: '(vacío)' ?>
                        </div>
                    <?php endforeach; ?>
                    <?php foreach ($f['terminales'] as $e): ?>
                        <a class="historial-link" href="historial.php?escala=<?= (int) $e['id'] ?>">historial<?= count($f['terminales']) > 1 ? ' ' . e($e['terminal']) : '' ?></a>
                    <?php endforeach; ?>
                <?php else: ?>
                    <span class="sin-dato">Sin cambios</span>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
</div>
<?php pie();
