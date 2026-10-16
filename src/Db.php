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
            if ($ruta !== ':memory:' && !is_dir(dirname($ruta))) {
                mkdir(dirname($ruta), 0775, true);
            }
            $pdo = new PDO('sqlite:' . $ruta, null, null, $opciones);
            $pdo->exec('PRAGMA foreign_keys = ON');
            // Todo el esquema usa IF NOT EXISTS: aplicarlo siempre suma las tablas nuevas a bases existentes.
            $pdo->exec(file_get_contents(dirname(__DIR__) . '/sql/schema.sqlite.sql'));
            self::actualizarEsquema($pdo);
            return $pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $cfg['host'], $cfg['port'] ?? 3306, $cfg['database']
        );
        $pdo = new PDO($dsn, $cfg['user'], $cfg['password'], $opciones);
        self::actualizarEsquema($pdo);
        return $pdo;
    }

    /**
     * Columnas agregadas después de la primera versión: CREATE TABLE IF NOT EXISTS no las suma
     * a una tabla que ya existe, así que se agregan acá (una sola vez, la primera vez que hace falta).
     */
    private static function actualizarEsquema(PDO $pdo): void
    {
        $columnas = [
            ['contenedores', 'usuario', 'VARCHAR(40) NULL'],
        ];
        foreach ($columnas as [$tabla, $columna, $tipo]) {
            try {
                $pdo->query("SELECT $columna FROM $tabla WHERE 1 = 0");
            } catch (PDOException) {
                try {
                    $pdo->exec("ALTER TABLE $tabla ADD COLUMN $columna $tipo");
                } catch (PDOException) {
                    // La tabla todavía no existe (falta importar el esquema): verificar.php lo indica.
                }
            }
        }
    }
}
