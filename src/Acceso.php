<?php
declare(strict_types=1);

/**
 * Inicio de sesión y protección de formularios.
 * Los usuarios se guardan en la tabla usuarios (página Usuarios) y, opcionalmente,
 * en config.php ('usuarios' => ['nombre' => hash]).
 */
final class Acceso
{
    /** Llamar al principio de cada página. Redirige a login.php si hace falta. */
    public static function exigir(array $cfg): void
    {
        self::iniciarSesion();
        if (($cfg['requiere_login'] ?? true) && empty($_SESSION['usuario'])) {
            $volver = $_SERVER['REQUEST_URI'] ?? 'index.php';
            header('Location: login.php?volver=' . urlencode($volver));
            exit;
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            self::verificarToken();
        }
    }

    public static function iniciarSesion(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name('puerto');
        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        ]);
        session_start();
    }

    public static function entrar(Usuarios $usuarios, string $usuario, string $clave): bool
    {
        if (!$usuarios->verificar($usuario, $clave)) {
            sleep(1); // frena intentos repetidos
            return false;
        }
        self::iniciarComo($usuario);
        return true;
    }

    public static function iniciarComo(string $usuario): void
    {
        self::iniciarSesion();
        session_regenerate_id(true);
        $_SESSION['usuario'] = $usuario;
    }

    /** La creación del primer usuario solo se permite desde la misma PC. */
    public static function esLocal(): bool
    {
        return in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
    }

    public static function salir(): void
    {
        self::iniciarSesion();
        $_SESSION = [];
        session_destroy();
    }

    public static function usuario(): ?string
    {
        return $_SESSION['usuario'] ?? null;
    }

    public static function token(): string
    {
        self::iniciarSesion();
        return $_SESSION['token'] ??= bin2hex(random_bytes(16));
    }

    /** Campo oculto para incluir en cada formulario POST. */
    public static function campoToken(): string
    {
        return '<input type="hidden" name="token" value="' . self::token() . '">';
    }

    private static function verificarToken(): void
    {
        if (!hash_equals(self::token(), (string) ($_POST['token'] ?? ''))) {
            http_response_code(400);
            exit('La sesión venció o el formulario no es válido. Volvé atrás y recargá la página.');
        }
    }

    /** Valida el destino de "volver" para no redirigir a otro sitio. */
    public static function destinoSeguro(?string $volver): string
    {
        $volver = (string) $volver;
        // Solo rutas del mismo sitio: "/algo" o "pagina.php", nunca "//otro-sitio" ni "https://".
        $local = preg_match('#^/(?![/\\\\])[^\s]*$#', $volver) || preg_match('#^[a-z_]+\.php(\?[^\s]*)?$#i', $volver);
        return $local ? $volver : 'index.php';
    }
}
