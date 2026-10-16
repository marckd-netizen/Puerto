<?php
declare(strict_types=1);

/** Usuarios del sitio: los de la tabla usuarios y, además, los definidos en config.php. */
final class Usuarios
{
    public const CLAVE_MINIMA = 8;

    public function __construct(private PDO $db, private array $cfg)
    {
    }

    public function hayAlguno(): bool
    {
        return !empty($this->cfg['usuarios'])
            || (int) $this->db->query('SELECT COUNT(*) FROM usuarios')->fetchColumn() > 0;
    }

    public function verificar(string $nombre, string $clave): bool
    {
        $hash = $this->cfg['usuarios'][$nombre] ?? null;
        if (!is_string($hash)) {
            $st = $this->db->prepare('SELECT clave_hash FROM usuarios WHERE nombre = ?');
            $st->execute([$nombre]);
            $hash = $st->fetchColumn() ?: null;
        }
        return is_string($hash) && password_verify($clave, $hash);
    }

    /** @return string|null mensaje de error, o null si se creó */
    public function crear(string $nombre, string $clave, string $repetida): ?string
    {
        $nombre = trim($nombre);
        if (!preg_match('/^[a-zA-Z0-9._-]{2,40}$/', $nombre)) {
            return 'El usuario debe tener entre 2 y 40 letras, números, punto, guion o guion bajo (sin espacios).';
        }
        if ($error = $this->validarClave($clave, $repetida)) {
            return $error;
        }
        if ($this->existe($nombre)) {
            return "Ya existe el usuario $nombre.";
        }
        $this->db->prepare('INSERT INTO usuarios (nombre, clave_hash, creado) VALUES (?, ?, ?)')
            ->execute([$nombre, password_hash($clave, PASSWORD_DEFAULT), date('Y-m-d H:i:s')]);
        return null;
    }

    /** @return string|null mensaje de error, o null si se cambió */
    public function cambiarClave(int $id, string $clave, string $repetida): ?string
    {
        if ($error = $this->validarClave($clave, $repetida)) {
            return $error;
        }
        $this->db->prepare('UPDATE usuarios SET clave_hash = ? WHERE id = ?')->execute([password_hash($clave, PASSWORD_DEFAULT), $id]);
        return null;
    }

    /** @return string|null mensaje de error, o null si se borró */
    public function borrar(int $id): ?string
    {
        $total = count($this->cfg['usuarios'] ?? []) + (int) $this->db->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();
        if ($total <= 1) {
            return 'No se puede borrar el único usuario: nadie podría entrar al sitio.';
        }
        $this->db->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$id]);
        return null;
    }

    /** @return list<array{id: ?int, nombre: string, creado: ?string, origen: string}> */
    public function lista(): array
    {
        $lista = [];
        foreach (array_keys($this->cfg['usuarios'] ?? []) as $nombre) {
            $lista[] = ['id' => null, 'nombre' => (string) $nombre, 'creado' => null, 'origen' => 'config.php'];
        }
        foreach ($this->db->query('SELECT id, nombre, creado FROM usuarios ORDER BY nombre')->fetchAll() as $u) {
            $lista[] = ['id' => (int) $u['id'], 'nombre' => $u['nombre'], 'creado' => $u['creado'], 'origen' => 'base'];
        }
        return $lista;
    }

    private function existe(string $nombre): bool
    {
        if (isset($this->cfg['usuarios'][$nombre])) {
            return true;
        }
        $st = $this->db->prepare('SELECT 1 FROM usuarios WHERE nombre = ?');
        $st->execute([$nombre]);
        return (bool) $st->fetchColumn();
    }

    private function validarClave(string $clave, string $repetida): ?string
    {
        if (mb_strlen($clave) < self::CLAVE_MINIMA) {
            return 'La contraseña debe tener al menos ' . self::CLAVE_MINIMA . ' caracteres.';
        }
        if ($clave !== $repetida) {
            return 'Las dos contraseñas no coinciden.';
        }
        return null;
    }
}
