<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/vistas.php';

$cfg = config();
$db = Db::conectar($cfg['db']);
$horas = (int) ($cfg['horas_destacado'] ?? 24);
$desde = date('Y-m-d H:i:s', time() - $horas * 3600);

$fTerminal = $_GET['terminal'] ?? '';
$fBuscar = trim($_GET['q'] ?? '');
$fSoloCambios = !empty($_GET['cambios']);
$fPasados = !empty($_GET['pasados']);

// Escalas activas
$where = ['e.activa = 1'];
$params = [];
if ($fTerminal !== '' && isset($cfg['terminales'][$fTerminal])) {
    $where[] = 'e.terminal = ?';
    $params[] = $fTerminal;
}
if ($fBuscar !== '') {
    $where[] = '(e.buque LIKE ? OR e.viaje LIKE ? OR e.linea LIKE ? OR e.agencia LIKE ?)';
    array_push($params, "%$fBuscar%", "%$fBuscar%", "%$fBuscar%", "%$fBuscar%");
}
if (!$fPasados) {
    // Oculta los que zarparon hace más de un día.
    $where[] = '(COALESCE(e.etd, e.eta) IS NULL OR COALESCE(e.etd, e.eta) >= ?)';
    $params[] = date('Y-m-d H:i', time() - 86400);
}
$sql = 'SELECT e.* FROM escalas e WHERE ' . implode(' AND ', $where)
     . ' ORDER BY CASE WHEN e.eta IS NULL THEN 1 ELSE 0 END, e.eta, e.buque';
$st = $db->prepare($sql);
$st->execute($params);
$escalas = $st->fetchAll();

// Cambios recientes no vistos, agrupados por escala y campo (se guarda el valor más viejo del período)
$cambios = [];
$st = $db->prepare('SELECT escala_id, campo, valor_anterior, valor_nuevo, detectado FROM cambios WHERE detectado >= ? AND visto = 0 ORDER BY detectado');
$st->execute([$desde]);
foreach ($st->fetchAll() as $c) {
    $id = (int) $c['escala_id'];
    if (!isset($cambios[$id][$c['campo']])) {
        $cambios[$id][$c['campo']] = $c;
    }
    $cambios[$id][$c['campo']]['ultimo'] = $c['detectado'];
}

if ($fSoloCambios) {
    $escalas = array_values(array_filter($escalas, fn($e) => isset($cambios[(int) $e['id']])));
}

// Última lectura de cada terminal
$lecturas = [];
foreach (array_keys($cfg['terminales']) as $cod) {
    $st = $db->prepare('SELECT * FROM lecturas WHERE terminal = ? ORDER BY fecha DESC, id DESC LIMIT 1');
    $st->execute([$cod]);
    $lecturas[$cod] = $st->fetch() ?: null;
}

$conCambios = count(array_filter($escalas, fn($e) => isset($cambios[(int) $e['id']])));
$columnas = ['muelle', 'eta', 'etb', 'etd', 'operativa', 'estado', 'cierre', 'linea', 'agencia'];

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
<br>
<form class="filtros" method="get">
    <select name="terminal" onchange="this.form.submit()">
        <option value="">Todas las terminales</option>
        <?php foreach ($cfg['terminales'] as $cod => $t): ?>
            <option value="<?= e($cod) ?>" <?= $fTerminal === $cod ? 'selected' : '' ?>><?= e($t['nombre']) ?></option>
        <?php endforeach; ?>
    </select>
    <input type="search" name="q" value="<?= e($fBuscar) ?>" placeholder="Buscar buque, viaje, línea, agencia">
    <label><input type="checkbox" name="cambios" value="1" <?= $fSoloCambios ? 'checked' : '' ?> onchange="this.form.submit()"> Solo con cambios</label>
    <label><input type="checkbox" name="pasados" value="1" <?= $fPasados ? 'checked' : '' ?> onchange="this.form.submit()"> Incluir ya zarpados</label>
    <button type="submit">Filtrar</button>
</form>

<div class="resumen">
    <strong><?= count($escalas) ?></strong> buques ·
    <strong><?= $conCambios ?></strong> con cambios en las últimas <?= $horas ?> h
    <?php if ($conCambios): ?>
        <form method="post" action="marcar.php" style="display:inline">
            <button type="submit" name="todo" value="1">Marcar todos como vistos</button>
        </form>
    <?php endif; ?>
</div>

<div class="tabla-wrap">
<?php if (!$escalas): ?>
    <div class="vacio">No hay buques para mostrar.</div>
<?php else: ?>
<table>
    <thead>
    <tr>
        <th>Terminal</th><th>Buque</th><th>Viaje</th>
        <?php foreach ($columnas as $c): ?><th><?= e(ETIQUETAS[$c]) ?></th><?php endforeach; ?>
        <th></th>
    </tr>
    </thead>
    <tbody>
    <?php $diaActual = null; ?>
    <?php foreach ($escalas as $e):
        $id = (int) $e['id'];
        $cam = $cambios[$id] ?? [];
        $esNuevo = strtotime($e['primera_vez']) >= strtotime($desde) && !$cam;
        $dia = $e['eta'] && preg_match('/^\d{4}-\d{2}-\d{2}/', $e['eta']) ? substr($e['eta'], 0, 10) : 'Sin fecha';
        if ($dia !== $diaActual):
            $diaActual = $dia; ?>
            <tr class="dia"><td colspan="<?= count($columnas) + 4 ?>"><?= e($dia === 'Sin fecha' ? $dia : nombreDia($dia)) ?></td></tr>
        <?php endif; ?>
        <tr class="<?= $cam ? 'con-cambio' : '' ?> <?= $esNuevo ? 'es-nuevo' : '' ?>">
            <td><?= etiquetaTerminal($e['terminal'], $cfg) ?></td>
            <td class="buque">
                <?= e($e['buque']) ?>
                <?php if ($cam): ?><span class="etiqueta" title="Cambios detectados en: <?= e(implode(', ', array_map(fn($c) => ETIQUETAS[$c] ?? $c, array_keys($cam)))) ?>">CAMBIO</span><?php endif; ?>
                <?php if ($esNuevo): ?><span class="etiqueta nuevo">NUEVO</span><?php endif; ?>
            </td>
            <td><?= e($e['viaje']) ?></td>
            <?php foreach ($columnas as $c):
                $cambio = $cam[$c] ?? null; ?>
                <td class="<?= $cambio ? 'cambiado' : '' ?>"<?= $cambio ? ' title="Cambió el ' . e(date('d/m H:i', strtotime($cambio['ultimo']))) . '"' : '' ?>>
                    <?php if ($cambio): ?><span class="antes"><?= e(mostrarValor($c, $cambio['valor_anterior']) ?: '(vacío)') ?></span><?php endif; ?>
                    <?= e(mostrarValor($c, $e[$c])) ?>
                    <?php if ($c === 'eta' && ($dif = horasEntre($e['eta_original'], $e['eta'])) !== null && abs($dif) >= 1): ?>
                        <span class="demora <?= $dif > 0 ? 'tarde' : 'antes-de' ?>" title="Respecto a la primera ETA publicada: <?= e(Texto::mostrarFecha($e['eta_original'])) ?>"><?= e(formatoHoras($dif)) ?></span>
                    <?php endif; ?>
                </td>
            <?php endforeach; ?>
            <td>
                <a href="historial.php?escala=<?= $id ?>" title="Ver historial">🕘</a>
                <?php if ($cam): ?>
                    <form method="post" action="marcar.php" style="display:inline"><button type="submit" name="escala" value="<?= $id ?>" title="Marcar como visto">✓</button></form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
</div>
<?php pie();
