<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/vistas.php';

$cfg = config();
Acceso::exigir($cfg);
$db = Db::conectar($cfg['db']);
$servicio = new Contenedores($db);

const ESTADOS_LUCIA = [
    'pendiente'     => 'Sin consultar',
    'encontrado'    => 'Encontrado',
    'en_stock'      => 'Ingresó a stock (no se consulta más)',
    'no_encontrado' => 'No encontrado',
    'error'         => 'Error al consultar',
];

$volver = 'contenedores.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    switch ($_POST['accion'] ?? '') {
        case 'guardar':
            $r = $servicio->guardarContenedor((string) $_POST['numero'], (string) $_POST['carpeta'], (string) ($_POST['rut'] ?? ''));
            avisar($r['ok'] ? (str_contains($r['mensaje'], 'atención') ? 'atencion' : 'ok') : 'error', $r['mensaje']);
            break;
        case 'importar':
            avisarImportacion($servicio->importarContenedores((string) $_POST['texto']), 'Contenedores');
            break;
        case 'consultar':
            try {
                $servicio->usarManifiestos(Lucia::manifiestos($cfg['lucia'] ?? []), (int) ($cfg['lucia']['reconsultar_horas'] ?? 2));
                avisar('ok', $servicio->consultarUno(Lucia::crear($cfg['lucia'] ?? []), $id));
            } catch (Throwable $t) {
                avisar('error', 'No se pudo consultar Lucía: ' . $t->getMessage());
            }
            break;
        case 'archivar':
        case 'reactivar':
            $db->prepare('UPDATE contenedores SET activo = ? WHERE id = ?')->execute([$_POST['accion'] === 'reactivar' ? 1 : 0, $id]);
            break;
        case 'borrar':
            $db->prepare('DELETE FROM contenedores WHERE id = ?')->execute([$id]);
            avisar('ok', 'Contenedor borrado.');
            break;
    }
    volverA($volver);
}

// Filtros
$fBuscar = trim((string) ($_GET['q'] ?? ''));
$fEstado = (string) ($_GET['estado'] ?? '');
$fCliente = (int) ($_GET['cliente'] ?? 0);
$fArchivados = !empty($_GET['archivados']);

$where = [$fArchivados ? 'c.activo = 0' : 'c.activo = 1'];
$params = [];
if ($fBuscar !== '') {
    $where[] = '(c.numero LIKE ? OR c.carpeta LIKE ? OR cl.nombre LIKE ? OR c.lucia_buque LIKE ?)';
    $q = '%' . $fBuscar . '%';
    array_push($params, '%' . Validacion::normalizarContenedor($fBuscar) . '%', $q, $q, $q);
}
if ($fEstado === 'vinculado') {
    $where[] = 'c.escala_id IS NOT NULL';
} elseif (isset(ESTADOS_LUCIA[$fEstado])) {
    $where[] = 'c.lucia_estado = ?';
    $params[] = $fEstado;
}
if ($fCliente) {
    $where[] = 'c.cliente_id = ?';
    $params[] = $fCliente;
}
$st = $db->prepare('SELECT c.*, cl.nombre AS cliente, cl.rut,
        e.terminal, e.buque AS escala_buque, e.viaje AS escala_viaje, e.eta, e.etb, e.etd, e.estado AS escala_estado
    FROM contenedores c
    LEFT JOIN clientes cl ON cl.id = c.cliente_id
    LEFT JOIN escalas e ON e.id = c.escala_id
    WHERE ' . implode(' AND ', $where) . '
    ORDER BY CASE WHEN e.eta IS NULL THEN 1 ELSE 0 END, e.eta, c.carpeta, c.numero LIMIT 2000');
$st->execute($params);
$contenedores = $st->fetchAll();

$clientes = $db->query('SELECT id, rut, nombre FROM clientes ORDER BY nombre')->fetchAll();
$tablero = new Tablero($cfg, time());
$luciaConfigurada = ($cfg['lucia']['modo'] ?? 'pendiente') !== 'pendiente';

encabezado('Contenedores');
mostrarAvisos();
?>
<?php if (!$luciaConfigurada): ?>
    <p class="aviso aviso-atencion">La consulta a Lucía todavía no está configurada: los contenedores se pueden cargar, pero no se van a vincular con los buques hasta configurarla.</p>
<?php endif; ?>

<div class="dos-columnas">
    <section class="panel">
        <h2>Nuevo contenedor</h2>
        <form method="post" class="formulario">
            <?= Acceso::campoToken() ?>
            <input type="hidden" name="accion" value="guardar">
            <label>Contenedor <input name="numero" required pattern="\s*[A-Za-z]{4}\s*-?\s*\d{6}\s*-?\s*\d\s*" placeholder="AAAA-123456-7" style="text-transform:uppercase"></label>
            <label>Carpeta <input name="carpeta" required></label>
            <label>Cliente
                <select name="rut">
                    <option value="">(sin cliente)</option>
                    <?php foreach ($clientes as $cl): ?>
                        <option value="<?= e($cl['rut']) ?>"><?= e($cl['nombre']) ?> — <?= e($cl['rut']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit">Guardar</button>
        </form>
    </section>
    <section class="panel">
        <h2>Importar varios</h2>
        <form method="post" class="formulario">
            <?= Acceso::campoToken() ?>
            <input type="hidden" name="accion" value="importar">
            <label><span>Pegá desde Excel: <b>Contenedor</b>, <b>Carpeta</b> y, opcional, <b>RUT del cliente</b>.
                El cliente tiene que estar cargado antes.</span>
                <textarea name="texto" rows="5" required placeholder="MSKU-123456-5	2026-0153	211234560012"></textarea>
            </label>
            <button type="submit">Importar</button>
        </form>
    </section>
</div>

<form class="filtros" method="get">
    <input type="search" name="q" value="<?= e($fBuscar) ?>" placeholder="Contenedor, carpeta, cliente o buque">
    <select name="estado" onchange="this.form.submit()">
        <option value="">Todos los estados</option>
        <option value="vinculado" <?= $fEstado === 'vinculado' ? 'selected' : '' ?>>Vinculados a un buque</option>
        <?php foreach (ESTADOS_LUCIA as $k => $v): ?>
            <option value="<?= e($k) ?>" <?= $fEstado === $k ? 'selected' : '' ?>><?= e($v) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="cliente" onchange="this.form.submit()">
        <option value="">Todos los clientes</option>
        <?php foreach ($clientes as $cl): ?>
            <option value="<?= (int) $cl['id'] ?>" <?= $fCliente === (int) $cl['id'] ? 'selected' : '' ?>><?= e($cl['nombre']) ?></option>
        <?php endforeach; ?>
    </select>
    <label><input type="checkbox" name="archivados" value="1" <?= $fArchivados ? 'checked' : '' ?> onchange="this.form.submit()"> Ver archivados</label>
    <button type="submit">Filtrar</button>
    <span class="sin-dato"><?= count($contenedores) ?> contenedores</span>
</form>

<div class="tabla-wrap">
<?php if (!$contenedores): ?>
    <div class="vacio">No hay contenedores para mostrar.</div>
<?php else: ?>
<table class="tablero">
    <thead><tr><th>Contenedor</th><th>Carpeta</th><th>Cliente</th><th>Lucía</th><th>Buque en el tablero</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($contenedores as $c): ?>
        <tr class="<?= $c['escala_id'] ? '' : ($c['lucia_estado'] === 'error' ? 'fila-error' : '') ?>">
            <td class="buque numero">
                <?= e(Validacion::formatearContenedor($c['numero'])) ?>
                <?php if (!Validacion::digitoContenedorCorrecto($c['numero'])): ?><span class="advertencia" title="El dígito verificador no coincide: revisá el número">⚠</span><?php endif; ?>
            </td>
            <td><?= e($c['carpeta']) ?></td>
            <td><?= e($c['cliente']) ?: '<span class="sin-dato">—</span>' ?></td>
            <td>
                <?= e(ESTADOS_LUCIA[$c['lucia_estado']] ?? $c['lucia_estado']) ?>
                <?php if ($etiqueta = etiquetaStock($c, $cfg)): ?><div><?= $etiqueta ?></div><?php endif; ?>
                <?php if ($c['lucia_buque']): ?><div><small>Buque:</small> <?= e($c['lucia_buque']) ?> <?= e($c['lucia_viaje']) ?></div><?php endif; ?>
                <?php if ($c['lucia_mensaje']): ?><div class="detalle"><?= e($c['lucia_mensaje']) ?></div><?php endif; ?>
                <?php $detalleLucia = json_decode((string) $c['lucia_detalle'], true) ?: []; ?>
                <?php if (!empty($detalleLucia['resumen'])): ?>
                    <ul class="resumen-lucia">
                        <?php foreach ($detalleLucia['resumen'] as $linea): ?><li><?= e($linea) ?></li><?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if ($c['lucia_consulta']): ?><div><small>Consultado <?= e(date('d/m H:i', strtotime($c['lucia_consulta']))) ?></small></div><?php endif; ?>
            </td>
            <td>
                <?php if ($c['escala_id']): ?>
                    <?= etiquetaTerminal($c['terminal'], $cfg) ?> <strong><?= e($c['escala_buque']) ?></strong> <?= e($c['escala_viaje']) ?>
                    <div><small>ETA</small> <?= e(Texto::mostrarFecha($c['eta'])) ?: '—' ?> · <small>ETD</small> <?= e(Texto::mostrarFecha($c['etd'])) ?: '—' ?></div>
                    <div><small>Estado:</small> <?= e($c['escala_estado'] ?: $tablero->estadoPorFechas(['etb' => $c['etb'], 'etd' => $c['etd']])) ?></div>
                <?php else: ?>
                    <span class="sin-dato">Sin vincular</span>
                <?php endif; ?>
            </td>
            <td class="acciones">
                <?php if ($c['activo']): ?>
                    <form method="post"><?= Acceso::campoToken() ?><input type="hidden" name="accion" value="consultar"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><button type="submit" class="enlace">Consultar ahora</button></form>
                    <form method="post"><?= Acceso::campoToken() ?><input type="hidden" name="accion" value="archivar"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><button type="submit" class="enlace">Archivar</button></form>
                <?php else: ?>
                    <form method="post"><?= Acceso::campoToken() ?><input type="hidden" name="accion" value="reactivar"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><button type="submit" class="enlace">Reactivar</button></form>
                <?php endif; ?>
                <form method="post" data-pregunta="¿Borrar el contenedor <?= e(Validacion::formatearContenedor($c['numero'])) ?> (carpeta <?= e($c['carpeta']) ?>)?" onsubmit="return confirm(this.dataset.pregunta)">
                    <?= Acceso::campoToken() ?><input type="hidden" name="accion" value="borrar"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><button type="submit" class="enlace">Borrar</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
</div>
<?php pie();
