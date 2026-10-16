<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/vistas.php';

$cfg = config();
Acceso::iniciarSesion();
$usuarios = new Usuarios(Db::conectar($cfg['db']), $cfg);
$volver = Acceso::destinoSeguro($_GET['volver'] ?? $_POST['volver'] ?? 'index.php');
$primeraVez = !$usuarios->hayAlguno();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(Acceso::token(), (string) ($_POST['token'] ?? ''))) {
        $error = 'El formulario venció. Probá de nuevo.';
    } elseif ($primeraVez) {
        // Solo mientras no exista ningún usuario, y solo desde la misma PC.
        if (!Acceso::esLocal()) {
            $error = 'El primer usuario se crea desde la misma PC donde está instalado el sitio.';
        } else {
            $nombre = trim((string) ($_POST['usuario'] ?? ''));
            $error = $usuarios->crear($nombre, (string) ($_POST['clave'] ?? ''), (string) ($_POST['clave2'] ?? '')) ?? '';
            if ($error === '') {
                Acceso::iniciarComo($nombre);
                header('Location: index.php');
                exit;
            }
        }
    } elseif (Acceso::entrar($usuarios, trim((string) ($_POST['usuario'] ?? '')), (string) ($_POST['clave'] ?? ''))) {
        header('Location: ' . $volver);
        exit;
    } else {
        $error = 'Usuario o contraseña incorrectos.';
    }
}

encabezado($primeraVez ? 'Crear el primer usuario' : 'Ingresar', 0, false);
?>
<form class="login" method="post">
    <?php if ($primeraVez): ?>
        <h2>Crear el primer usuario</h2>
        <p class="sin-dato">Todavía no hay usuarios. Elegí un nombre y una contraseña para entrar al sitio.
            Después podés agregar más desde la página Usuarios.</p>
        <?php if (!Acceso::esLocal()): ?>
            <p class="falla">Por seguridad, el primer usuario se crea desde la misma PC donde está instalado el sitio
                (entrando por <code>http://localhost/...</code>).</p>
        <?php endif; ?>
    <?php else: ?>
        <h2>Ingresar</h2>
    <?php endif; ?>
    <?php if ($error): ?><p class="falla"><?= e($error) ?></p><?php endif; ?>
    <?= Acceso::campoToken() ?>
    <input type="hidden" name="volver" value="<?= e($volver) ?>">
    <label>Usuario <input name="usuario" autocomplete="username" required autofocus value="<?= e($primeraVez ? (string) ($_POST['usuario'] ?? '') : '') ?>"></label>
    <label>Contraseña <input type="password" name="clave" autocomplete="<?= $primeraVez ? 'new-password' : 'current-password' ?>" required <?= $primeraVez ? 'minlength="' . Usuarios::CLAVE_MINIMA . '"' : '' ?>></label>
    <?php if ($primeraVez): ?>
        <label>Repetir contraseña <input type="password" name="clave2" autocomplete="new-password" required></label>
        <button type="submit">Crear usuario y entrar</button>
    <?php else: ?>
        <button type="submit">Entrar</button>
    <?php endif; ?>
</form>
<?php pie();
