<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/vistas.php';

$cfg = config();
$db = Db::conectar($cfg['db']);

$escalaId = (int) ($_GET['escala'] ?? 0);
$dias = max(1, min(90, (int) ($_GET['dias'] ?? 7)));

$where = [];
$params = [];
if ($escalaId) {
    $where[] = 'c.escala_id = ?';
    $params[] = $escalaId;
} else {
    $where[] = 'c.detectado >= ?';
    $params[] = date('Y-m-d H:i:s', time() - $dias * 86400);
}
$st = $db->prepare('SELECT c.*, e.terminal, e.buque, e.viaje FROM cambios c JOIN escalas e ON e.id = c.escala_id WHERE '
    . implode(' AND ', $where) . ' ORDER BY c.detectado DESC, c.id DESC LIMIT 1000');
$st->execute($params);
$filas = $st->fetchAll();

$escala = null;
if ($escalaId) {
    $st = $db->prepare('SELECT * FROM escalas WHERE id = ?');
    $st->execute([$escalaId]);
    $escala = $st->fetch() ?: null;
}

encabezado('Historial de cambios');
?>
<?php if ($escala): ?>
    <h2><?= etiquetaTerminal($escala['terminal'], $cfg) ?> <?= e($escala['buque']) ?> <?= e($escala['viaje']) ?></h2>
    <p class="resumen">Visto por primera vez: <?= e(date('d/m/Y H:i', strtotime($escala['primera_vez']))) ?> ·
        ETA original: <?= e(Texto::mostrarFecha($escala['eta_original'])) ?: '—' ?> ·
        ETA actual: <?= e(Texto::mostrarFecha($escala['eta'])) ?: '—' ?>
        · <a href="historial.php">Ver todos los cambios</a></p>
<?php else: ?>
    <form class="filtros" method="get">
        Cambios de los últimos
        <select name="dias" onchange="this.form.submit()">
            <?php foreach ([1, 3, 7, 15, 30, 90] as $d): ?>
                <option value="<?= $d ?>" <?= $d === $dias ? 'selected' : '' ?>><?= $d ?> días</option>
            <?php endforeach; ?>
        </select>
    </form>
<?php endif; ?>

<div class="tabla-wrap">
<?php if (!$filas): ?>
    <div class="vacio">No hay cambios registrados.</div>
<?php else: ?>
<table class="historial">
    <thead><tr><th>Detectado</th><th>Terminal</th><th>Buque</th><th>Campo</th><th>Antes</th><th>Ahora</th></tr></thead>
    <tbody>
    <?php foreach ($filas as $f): ?>
        <tr>
            <td><?= e(date('d/m H:i', strtotime($f['detectado']))) ?></td>
            <td><?= etiquetaTerminal($f['terminal'], $cfg) ?></td>
            <td><a href="historial.php?escala=<?= (int) $f['escala_id'] ?>"><?= e($f['buque']) ?></a> <?= e($f['viaje']) ?></td>
            <td><?= e(ETIQUETAS[$f['campo']] ?? $f['campo']) ?></td>
            <td><span class="antes" style="display:inline"><?= e(mostrarValor($f['campo'], $f['valor_anterior'])) ?: '(vacío)' ?></span></td>
            <td><strong><?= e(mostrarValor($f['campo'], $f['valor_nuevo'])) ?: '(vacío)' ?></strong></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
</div>
<?php pie();
