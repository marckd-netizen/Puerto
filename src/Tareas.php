<?php
declare(strict_types=1);

/**
 * Las tareas automáticas: leer las terminales y consultar Lucía. Las usan cron/actualizar.php,
 * cron/contenedores.php y cron/tareas.php (que corre las dos y anota todo en data/cron.log).
 * $salida recibe cada línea de resultado y $error cada error.
 */
final class Tareas
{
    /** @return bool true si todas las terminales se leyeron bien */
    public static function actualizar(array $cfg, PDO $db, callable $salida, callable $error, ?string $solo = null, bool $sinCambios = false): bool
    {
        $lector = new Lector($cfg['http'] ?? []);
        $sync = new Sincronizador($db, $cfg['campos_vigilados']);
        $ok = true;
        foreach ($cfg['terminales'] as $codigo => $terminal) {
            if ($solo !== null && strcasecmp($solo, $codigo) !== 0) {
                continue;
            }
            $ahora = date('Y-m-d H:i:s');
            try {
                $filas = $lector->leerTerminal($terminal);
                if (!$filas) {
                    // Nunca vaciar la lista por una lectura vacía: suele ser un problema de la página.
                    throw new RuntimeException('La página no devolvió ningún buque');
                }
                $r = $sync->sincronizar($codigo, $filas, $ahora, !$sinCambios);
                $msg = sprintf('%d buques, %d nuevos, %d con cambios (%d campos), %d retirados',
                    count($filas), $r['nuevas'], $r['actualizadas'], $r['cambios'], $r['retiradas'])
                    . ($sinCambios ? ' (sin registrar cambios)' : '');
                self::registrar($db, $codigo, $ahora, true, count($filas), $msg);
                $salida("[$ahora] $codigo: $msg");
            } catch (Throwable $t) {
                $ok = false;
                self::registrar($db, $codigo, $ahora, false, 0, $t->getMessage());
                $error("[$ahora] $codigo: ERROR " . $t->getMessage());
            }
        }
        return $ok;
    }

    /** @return bool true si no hubo errores */
    public static function contenedores(array $cfg, PDO $db, callable $salida, callable $error): bool
    {
        $luciaCfg = $cfg['lucia'] ?? [];
        $ahora = date('Y-m-d H:i:s');
        try {
            $lucia = Lucia::crear($luciaCfg);
            $servicio = new Contenedores($db);
            $servicio->usarManifiestos(Lucia::manifiestos($luciaCfg), (int) ($luciaCfg['reconsultar_horas'] ?? 2));
            $r = $servicio->consultarPendientes($lucia, $luciaCfg, null, fn($linea) => $salida("  $linea"));
        } catch (Throwable $t) {
            $error("[$ahora] LUCIA: ERROR {$t->getMessage()}");
            return false;
        }
        $salida("[$ahora] LUCIA: {$r['consultados']} consultados, {$r['encontrados']} encontrados, {$r['vinculados']} vinculados a un buque");
        foreach ($r['errores'] as $e) {
            $error("[$ahora] LUCIA: ERROR $e");
        }
        return !$r['errores'];
    }

    /** Las tareas se corren desde el cron (php o php-cgi), nunca desde una página web. */
    public static function esPedidoWeb(): bool
    {
        return isset($_SERVER['REQUEST_METHOD']);
    }

    private static function registrar(PDO $db, string $terminal, string $fecha, bool $ok, int $filas, string $mensaje): void
    {
        $db->prepare('INSERT INTO lecturas (terminal, fecha, ok, filas, mensaje) VALUES (?, ?, ?, ?, ?)')
           ->execute([$terminal, $fecha, $ok ? 1 : 0, $filas, mb_substr($mensaje, 0, 500)]);
    }
}
