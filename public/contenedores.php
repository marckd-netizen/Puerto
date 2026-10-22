<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/vistas.php';

$cfg = config();
Acceso::exigir($cfg);
$db = Db::conectar($cfg['db']);
$servicio = new Contenedores($db);
$verDe = verContenedoresDe();

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
            $r = $servicio->guardarContenedor((string) $_POST['numero'], (string) $_POST['carpeta'], (string) ($_POST['rut'] ?? ''), Acceso::usuario(),
                Contenedores::tipoDe((string) ($_POST['tipo'] ?? '')) ?? 'entero');
            avisar($r['ok'] ? (str_contains($r['mensaje'], 'atención') ? 'atencion' : 'ok') : 'error', $r['mensaje']);
            break;
        case 'importar':
            avisarImportacion($servicio->importarContenedores((string) $_POST['texto'], Acceso::usuario()), 'Contenedores');
            break;
        case 'editar':
            $r = $servicio->editarContenedor($id, (string) ($_POST['carpeta'] ?? ''), ((int) ($_POST['cliente_id'] ?? 0)) ?: null);
            avisar($r['ok'] ? 'ok' : 'error', $r['mensaje']);
            break;
        case 'tipo':
            if ($tipo = Contenedores::tipoDe((string) ($_POST['tipo'] ?? ''))) {
                $db->prepare('UPDATE contenedores SET tipo = ? WHERE id = ?')->execute([$tipo, $id]);
            }
            break;
        case 'asignarme':
            if (Acceso::usuario() !== null) {
                $n = $db->prepare('UPDATE contenedores SET usuario = ? WHERE usuario IS NULL');
                $n->execute([Acceso::usuario()]);
                avisar('ok', $n->rowCount() . ' contenedor(es) sin usuario quedaron a tu nombre.');
            }
            break;
        case 'consultar':
            try {
                $servicio->usarManifiestos(Lucia::manifiestos($cfg['lucia'] ?? []), (int) ($cfg['lucia']['reconsultar_horas'] ?? 2));
                $servicio->usarNavieras(Navieras::crear($cfg['navieras'] ?? [], $cfg['http'] ?? []), (int) ($cfg['navieras']['reconsultar_dias'] ?? 7));
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
$fTipo = Contenedores::tipoDe((string) ($_GET['tipo'] ?? ''));

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
if ($fTipo !== null) {
    $where[] = 'c.tipo = ?';
    $params[] = $fTipo;
}
if ($verDe !== null) {
    $where[] = 'c.usuario = ?';
    $params[] = $verDe;
}
$sinUsuario = Acceso::usuario() !== null ? (int) $db->query('SELECT COUNT(*) FROM contenedores WHERE usuario IS NULL')->fetchColumn() : 0;
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
<?php if ($sinUsuario): ?>
    <form method="post" class="aviso aviso-atencion">
        <?= Acceso::campoToken() ?><input type="hidden" name="accion" value="asignarme">
        Hay <?= $sinUsuario ?> contenedor(es) cargados antes de que se guardara quién los carga: solo se ven en "De todos".
        <button type="submit" class="enlace">Pasarlos a mi nombre</button>
    </form>
<?php endif; ?>
<?php if (!$luciaConfigurada): ?>
    <p class="aviso aviso-atencion">La consulta a Lucía todavía no está configurada: los contenedores se pueden cargar, pero no se van a vincular con los buques hasta configurarla.</p>
<?php endif; ?>

<div class="dos-columnas">
    <section class="panel">
        <h2>Nuevo contenedor</h2>
        <form method="post" class="formulario">
            <?= Acceso::campoToken() ?>
            <input type="hidden" name="accion" value="guardar">
            <label>Contenedor <input name="numero" id="numero-contenedor" required maxlength="13" autocomplete="off" placeholder="AAAA-123456-7" style="text-transform:uppercase">
                <small id="numero-aviso" class="numero-aviso"></small></label>
            <label>Carpeta <input name="carpeta" required></label>
            <fieldset class="opciones-tipo">
                <legend>Tipo</legend>
                <?php foreach (Contenedores::TIPOS as $valor => $texto): ?>
                    <label><input type="radio" name="tipo" value="<?= e($valor) ?>" <?= $valor === 'entero' ? 'checked' : '' ?>> <?= e($texto) ?></label>
                <?php endforeach; ?>
            </fieldset>
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
            <label><span>Pegá desde Excel: <b>Contenedor</b>, <b>Carpeta</b> y, opcionales, <b>RUT del cliente</b>
                y <b>Tipo</b> (Entero o Apertura; si no se indica, Entero). El cliente tiene que estar cargado antes.</span>
                <textarea name="texto" rows="5" required placeholder="MSKU-123456-5	2026-0153	211234560012	Apertura"></textarea>
            </label>
            <button type="submit">Importar</button>
        </form>
    </section>
</div>

<script>
// Número de contenedor: guiones automáticos (AAAA-123456-7) y validación ISO 6346 mientras se escribe.
// El servidor vuelve a validar al guardar.
(function () {
    var campo = document.getElementById('numero-contenedor'), aviso = document.getElementById('numero-aviso');
    if (!campo) return;
    var valor = {};
    'ABCDEFGHIJKLMNOPQRSTUVWXYZ'.split('').reduce(function (v, letra) {
        if (v % 11 === 0) v++;           // ISO 6346: se saltean 11, 22 y 33
        valor[letra] = v;
        return v + 1;
    }, 10);
    function digito(n) {
        var suma = 0;
        for (var i = 0; i < 10; i++) {
            var c = n.charAt(i);
            suma += (/\d/.test(c) ? +c : valor[c]) * Math.pow(2, i);
        }
        return suma % 11 % 10;
    }
    function revisar() {
        var n = campo.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 11);
        var con = n.slice(0, 4) + (n.length > 4 ? '-' + n.slice(4, 10) : '') + (n.length > 10 ? '-' + n.slice(10) : '');
        if (campo.value !== con) campo.value = con;
        var error = '', ayuda = '';
        if (!/^[A-Z]*$/.test(n.slice(0, 4))) error = 'Empieza con 4 letras';
        else if (n.length > 4 && !/^\d+$/.test(n.slice(4))) error = 'Después de las 4 letras van 7 números';
        else if (n.length >= 4 && 'UJZ'.indexOf(n.charAt(3)) < 0) error = 'La cuarta letra tiene que ser U, J o Z';
        else if (n.length === 10) ayuda = 'El dígito verificador debería ser ' + digito(n);
        else if (n.length === 11 && digito(n) !== +n.charAt(10)) error = 'El dígito verificador no corresponde: debería ser ' + digito(n);
        else if (n.length === 11) ayuda = '✔ Número válido';
        campo.setCustomValidity(error || (n.length > 0 && n.length < 11 ? 'Faltan caracteres: son 4 letras y 7 números' : ''));
        aviso.textContent = error || ayuda;
        aviso.className = 'numero-aviso' + (error ? ' falla' : (ayuda.charAt(0) === '✔' ? ' bien' : ''));
    }
    campo.addEventListener('input', revisar);
})();
</script>

<form class="filtros" method="get">
    <input type="search" name="q" value="<?= e($fBuscar) ?>" placeholder="Contenedor, carpeta, cliente o buque">
    <select name="estado" onchange="this.form.submit()">
        <option value="">Todos los estados</option>
        <option value="vinculado" <?= $fEstado === 'vinculado' ? 'selected' : '' ?>>Vinculados a un buque</option>
        <?php foreach (ESTADOS_LUCIA as $k => $v): ?>
            <option value="<?= e($k) ?>" <?= $fEstado === $k ? 'selected' : '' ?>><?= e($v) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="tipo" onchange="this.form.submit()">
        <option value="">Enteros y aperturas</option>
        <?php foreach (Contenedores::TIPOS as $valor => $texto): ?>
            <option value="<?= e($valor) ?>" <?= $fTipo === $valor ? 'selected' : '' ?>><?= e($texto === 'Entero' ? 'Solo enteros' : 'Solo aperturas') ?></option>
        <?php endforeach; ?>
    </select>
    <select name="cliente" onchange="this.form.submit()">
        <option value="">Todos los clientes</option>
        <?php foreach ($clientes as $cl): ?>
            <option value="<?= (int) $cl['id'] ?>" <?= $fCliente === (int) $cl['id'] ? 'selected' : '' ?>><?= e($cl['nombre']) ?></option>
        <?php endforeach; ?>
    </select>
    <label><input type="checkbox" name="archivados" value="1" <?= $fArchivados ? 'checked' : '' ?> onchange="this.form.submit()"> Ver archivados</label>
    <?= selectorVerContenedores($verDe) ?>
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
                <div><?= etiquetaTipo($c) ?></div>
            </td>
            <td><?= e($c['carpeta']) ?><?php if ($verDe === null && $c['usuario']): ?><div><small>Cargado por <?= e($c['usuario']) ?></small></div><?php endif; ?></td>
            <td><?= e($c['cliente']) ?: '<span class="sin-dato">—</span>' ?></td>
            <td>
                <?= e(ESTADOS_LUCIA[$c['lucia_estado']] ?? $c['lucia_estado']) ?>
                <?php if ($etiqueta = etiquetaLiberacion($c)): ?><div><?= $etiqueta ?></div><?php endif; ?>
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
                <details class="editar-contenedor">
                    <summary class="enlace">Editar</summary>
                    <form method="post" class="formulario">
                        <?= Acceso::campoToken() ?><input type="hidden" name="accion" value="editar"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                        <label>Carpeta <input name="carpeta" required value="<?= e($c['carpeta']) ?>"></label>
                        <label>Cliente
                            <select name="cliente_id">
                                <option value="">(sin cliente)</option>
                                <?php foreach ($clientes as $cl): ?>
                                    <option value="<?= (int) $cl['id'] ?>" <?= (int) $c['cliente_id'] === (int) $cl['id'] ? 'selected' : '' ?>><?= e($cl['nombre']) ?> — <?= e($cl['rut']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <button type="submit">Guardar</button>
                    </form>
                </details>
                <?php if ($c['activo']): ?>
                    <form method="post"><?= Acceso::campoToken() ?><input type="hidden" name="accion" value="consultar"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><button type="submit" class="enlace">Consultar ahora</button></form>
                    <?php $otroTipo = ($c['tipo'] ?? 'entero') === 'apertura' ? 'entero' : 'apertura'; ?>
                    <form method="post"><?= Acceso::campoToken() ?><input type="hidden" name="accion" value="tipo"><input type="hidden" name="tipo" value="<?= $otroTipo ?>"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><button type="submit" class="enlace">Pasar a <?= e(mb_strtolower(Contenedores::TIPOS[$otroTipo])) ?></button></form>
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
