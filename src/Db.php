<?php
declare(strict_types=1);

final class Db
{
    public static function conectar(array $cfg): PDO
    {
        $opciones = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];

        if (($cfg['driver'] ?? 'mysql') === 'sqlite') {
            $ruta = $cfg['sqlite_path'];
            $nueva = $ruta === ':memory:' || !is_file($ruta);
            if ($ruta !== ':memory:' && !is_dir(dirname($ruta))) {
                mkdir(dirname($ruta), 0775, true);
            }
            $pdo = new PDO('sqlite:' . $ruta, null, null, $opciones);
            $pdo->exec('PRAGMA foreign_keys = ON');
            if ($nueva) {
                $pdo->exec(file_get_contents(dirname(__DIR__) . '/sql/schema.sqlite.sql'));
            }
            return $pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $cfg['host'], $cfg['port'] ?? 3306, $cfg['database']
        );
        return new PDO($dsn, $cfg['user'], $cfg['password'], $opciones);
    }
}
