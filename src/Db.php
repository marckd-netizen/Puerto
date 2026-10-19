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

        $pdo = self::conectarMysql($cfg, $opciones);
        self::actualizarEsquema($pdo);
        return $pdo;
    }

    /**
     * Con host "localhost" PHP se conecta por el socket de MySQL. En muchos hostings el PHP del cron
     * no sabe dónde está ese socket (aunque el sitio web sí) y falla con "No such file or directory":
     * en ese caso se prueba con las ubicaciones habituales y, por último, por TCP (127.0.0.1).
     */
    private static function conectarMysql(array $cfg, array $opciones): PDO
    {
        $base = ';dbname=' . $cfg['database'] . ';charset=utf8mb4';
        $puerto = (int) ($cfg['port'] ?? 3306);
        $dsn = !empty($cfg['socket'])
            ? 'mysql:unix_socket=' . $cfg['socket'] . $base
            : 'mysql:host=' . $cfg['host'] . ';port=' . $puerto . $base;
        try {
            return new PDO($dsn, $cfg['user'], $cfg['password'], $opciones);
        } catch (PDOException $e) {
            $sinSocket = !empty($cfg['socket']) || strtolower((string) $cfg['host']) !== 'localhost'
                || !str_contains($e->getMessage(), '[2002]');
            if ($sinSocket) {
                throw $e;
            }
            $alternativas = [];
            foreach (['/var/lib/mysql/mysql.sock', '/var/run/mysqld/mysqld.sock', '/run/mysqld/mysqld.sock', '/tmp/mysql.sock'] as $socket) {
                if (@file_exists($socket)) {
                    $alternativas[] = 'mysql:unix_socket=' . $socket . $base;
                }
            }
            $alternativas[] = 'mysql:host=127.0.0.1;port=' . $puerto . $base;
            foreach ($alternativas as $alternativa) {
                try {
                    return new PDO($alternativa, $cfg['user'], $cfg['password'], $opciones);
                } catch (PDOException) {
                }
            }
            throw $e;
        }
    }

    /**
     * Columnas agregadas después de la primera versión: CREATE TABLE IF NOT EXISTS no las suma
     * a una tabla que ya existe, así que se agregan acá (una sola vez, la primera vez que hace falta).
     */
    private static function actualizarEsquema(PDO $pdo): void
    {
        $columnas = [
            ['contenedores', 'usuario', 'VARCHAR(40) NULL'],
            ['contenedores', 'tipo', "VARCHAR(10) NOT NULL DEFAULT 'entero'"],
            ['contenedores', 'naviera_consulta', 'DATETIME NULL'],
            ['contenedores', 'naviera_detalle', 'TEXT NULL'],
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
