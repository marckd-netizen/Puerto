<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/vistas.php';

$cfg = config();
Acceso::exigir($cfg);
$usuarios = new Usuarios(Db::conectar($cfg['db']), $cfg);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $clave = (string) ($_POST['clave'] ?? '');
    $clave2 = (string) ($_POST['clave2'] ?? '');
    $error = match ($_POST['accion'] ?? '') {
        'crear'  => $usuarios->crear((string) ($_POST['usuario'] ?? ''), $clave, $clave2),
        'clave'  => $usuarios->cambiarClave($id, $clave, $clave2),
        'borrar' => $usuarios->borrar($id),
        default  => 'Acción desconocida.',
    };
    avisar($error === null ? 'ok' : 'error', $error ?? match ($_POST['accion']) {
        'crear'  => 'Usuario creado.',
        'clave'  => 'Contraseña cambiada.',
        'borrar' => 'Usuario borrado.',
    });
    volverA('usuarios.php');
}

$lista = $usuarios->lista();

encabezado('Usuarios');
mostrarAvisos();
?>
<div class="dos-columnas">
    <section class="panel">
        <h2>Nuevo usuario</h2>
        <form method="post" class="formulario">
            <?= Acceso::campoToken() ?>
            <input type="hidden" name="accion" value="crear">
            <label>Usuario <input name="usuario" required pattern="[a-zA-Z0-9._\-]{2,40}" autocomplete="off"></label>
            <label>Contraseña <input type="password" name="clave" required minlength="<?= Usuarios::CLAVE_MINIMA ?>" autocomplete="new-password"></label>
            <label>Repetir contraseña <input type="password" name="clave2" required autocomplete="new-password"></label>
            <button type="submit">Crear usuario</button>
        </form>
    </section>
</div>

<div class="tabla-wrap">
<table class="tablero">
    <thead><tr><th>Usuario</th><th>Creado</th><th>Cambiar contraseña</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($lista as $u): ?>
        <tr>
            <td class="buque"><?= e($u['nombre']) ?><?= $u['nombre'] === Acceso::usuario() ? ' <small>(vos)</small>' : '' ?></td>
            <td><?= $u['creado'] ? e(date('d/m/Y', strtotime($u['creado']))) : '<span class="sin-dato">—</span>' ?></td>
            <td>
                <?php if ($u['id'] !== null): ?>
                    <form method="post" class="clave-en-linea">
                        <?= Acceso::campoToken() ?>
                        <input type="hidden" name="accion" value="clave">
                        <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                        <input type="password" name="clave" placeholder="Nueva" required minlength="<?= Usuarios::CLAVE_MINIMA ?>" autocomplete="new-password">
                        <input type="password" name="clave2" placeholder="Repetir" required autocomplete="new-password">
                        <button type="submit">Cambiar</button>
                    </form>
                <?php else: ?>
                    <span class="sin-dato">Definido en config.php</span>
                <?php endif; ?>
            </td>
            <td class="acciones">
                <?php if ($u['id'] !== null): ?>
                    <form method="post" data-pregunta="¿Borrar el usuario <?= e($u['nombre']) ?>?" onsubmit="return confirm(this.dataset.pregunta)">
                        <?= Acceso::campoToken() ?><input type="hidden" name="accion" value="borrar"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                        <button type="submit" class="enlace">Borrar</button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php pie();
