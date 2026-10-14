<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/vistas.php';

$cfg = config();
Acceso::iniciarSesion();
$volver = Acceso::destinoSeguro($_GET['volver'] ?? $_POST['volver'] ?? 'index.php');
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(Acceso::token(), (string) ($_POST['token'] ?? ''))) {
        $error = 'El formulario venció. Probá de nuevo.';
    } elseif (Acceso::entrar($cfg, trim((string) ($_POST['usuario'] ?? '')), (string) ($_POST['clave'] ?? ''))) {
        header('Location: ' . $volver);
        exit;
    } else {
        $error = 'Usuario o contraseña incorrectos.';
    }
}

encabezado('Ingresar', 0, false);
?>
<form class="login" method="post">
    <h2>Ingresar</h2>
    <?php if (empty($cfg['usuarios'])): ?>
        <p class="falla">Todavía no hay usuarios. Crealos con <code>php cron/crear_usuario.php nombre</code> y agregalos en config.php.</p>
    <?php endif; ?>
    <?php if ($error): ?><p class="falla"><?= e($error) ?></p><?php endif; ?>
    <?= Acceso::campoToken() ?>
    <input type="hidden" name="volver" value="<?= e($volver) ?>">
    <label>Usuario <input name="usuario" autocomplete="username" required autofocus></label>
    <label>Contraseña <input type="password" name="clave" autocomplete="current-password" required></label>
    <button type="submit">Entrar</button>
</form>
<?php pie();
