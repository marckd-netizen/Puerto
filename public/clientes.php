<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/vistas.php';

$cfg = config();
Acceso::exigir($cfg);
$db = Db::conectar($cfg['db']);
$servicio = new Contenedores($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch ($_POST['accion'] ?? '') {
        case 'guardar':
            $r = $servicio->guardarCliente((string) $_POST['rut'], (string) $_POST['nombre']);
            avisar($r['ok'] ? (str_contains($r['mensaje'], 'atención') ? 'atencion' : 'ok') : 'error', $r['mensaje']);
            break;
        case 'importar':
            avisarImportacion($servicio->importarClientes((string) $_POST['texto']), 'Clientes');
            break;
        case 'borrar':
            $db->prepare('DELETE FROM clientes WHERE id = ?')->execute([(int) $_POST['id']]);
            avisar('ok', 'Cliente borrado. Sus contenedores quedaron sin cliente asignado.');
            break;
    }
    volverA('clientes.php' . (isset($_GET['q']) ? '?q=' . urlencode((string) $_GET['q']) : ''));
}

$buscar = trim((string) ($_GET['q'] ?? ''));
$editar = null;
if (isset($_GET['editar'])) {
    $st = $db->prepare('SELECT * FROM clientes WHERE id = ?');
    $st->execute([(int) $_GET['editar']]);
    $editar = $st->fetch() ?: null;
}

$sql = 'SELECT cl.*, (SELECT COUNT(*) FROM contenedores c WHERE c.cliente_id = cl.id AND c.activo = 1) AS contenedores
        FROM clientes cl';
$params = [];
if ($buscar !== '') {
    $sql .= ' WHERE cl.nombre LIKE ? OR cl.rut LIKE ?';
    $params = ["%$buscar%", '%' . (Validacion::normalizarRut($buscar) ?: $buscar) . '%'];
}
$st = $db->prepare($sql . ' ORDER BY cl.nombre LIMIT 1000');
$st->execute($params);
$clientes = $st->fetchAll();

encabezado('Clientes');
mostrarAvisos();
?>
<div class="dos-columnas">
    <section class="panel">
        <h2><?= $editar ? 'Editar cliente' : 'Nuevo cliente' ?></h2>
        <form method="post" class="formulario">
            <?= Acceso::campoToken() ?>
            <input type="hidden" name="accion" value="guardar">
            <label>RUT <input name="rut" value="<?= e($editar['rut'] ?? '') ?>" required inputmode="numeric" placeholder="12 dígitos" <?= $editar ? 'readonly' : '' ?>></label>
            <label>Nombre <input name="nombre" value="<?= e($editar['nombre'] ?? '') ?>" required></label>
            <button type="submit">Guardar</button>
            <?php if ($editar): ?><a href="clientes.php">Cancelar</a><?php endif; ?>
        </form>
    </section>
    <section class="panel">
        <h2>Importar varios</h2>
        <form method="post" class="formulario">
            <?= Acceso::campoToken() ?>
            <input type="hidden" name="accion" value="importar">
            <label><span>Pegá desde Excel dos columnas: <b>RUT</b> y <b>Nombre</b> (también sirve un CSV con ; o ,).
                Si el RUT ya existe, se actualiza el nombre.</span>
                <textarea name="texto" rows="5" required placeholder="211234560012	EMPRESA EJEMPLO S.A."></textarea>
            </label>
            <button type="submit">Importar</button>
        </form>
    </section>
</div>

<form class="filtros" method="get">
    <input type="search" name="q" value="<?= e($buscar) ?>" placeholder="Buscar por nombre o RUT">
    <button type="submit">Buscar</button>
    <span class="sin-dato"><?= count($clientes) ?> clientes</span>
</form>

<div class="tabla-wrap">
<?php if (!$clientes): ?>
    <div class="vacio">No hay clientes cargados.</div>
<?php else: ?>
<table class="tablero">
    <thead><tr><th>RUT</th><th>Nombre</th><th>Contenedores activos</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($clientes as $c): ?>
        <tr>
            <td><?= e($c['rut']) ?><?php if (!Validacion::digitoRutCorrecto($c['rut'])): ?> <span class="advertencia" title="El dígito verificador no coincide">⚠</span><?php endif; ?></td>
            <td><?= e($c['nombre']) ?></td>
            <td><?php if ($c['contenedores']): ?><a href="contenedores.php?cliente=<?= (int) $c['id'] ?>"><?= (int) $c['contenedores'] ?></a><?php else: ?>0<?php endif; ?></td>
            <td class="acciones">
                <a href="clientes.php?editar=<?= (int) $c['id'] ?>">Editar</a>
                <form method="post" data-pregunta="¿Borrar el cliente <?= e($c['nombre']) ?>?" onsubmit="return confirm(this.dataset.pregunta)">
                    <?= Acceso::campoToken() ?>
                    <input type="hidden" name="accion" value="borrar">
                    <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                    <button type="submit" class="enlace">Borrar</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
</div>
<?php pie();
