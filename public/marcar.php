<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db = Db::conectar(config()['db']);
    if (!empty($_POST['todo'])) {
        $db->exec('UPDATE cambios SET visto = 1 WHERE visto = 0');
    } elseif (!empty($_POST['escala'])) {
        $db->prepare('UPDATE cambios SET visto = 1 WHERE escala_id = ?')->execute([(int) $_POST['escala']]);
    }
}
$volver = $_SERVER['HTTP_REFERER'] ?? 'index.php';
header('Location: ' . (parse_url($volver, PHP_URL_HOST) === ($_SERVER['HTTP_HOST'] ?? null) ? $volver : 'index.php'));
