<?php
declare(strict_types=1);

/**
 * Compara lo que publica una terminal con lo guardado, registra los cambios en
 * los campos vigilados y marca como inactivas las escalas que ya no aparecen.
 */
final class Sincronizador
{
    private const CAMPOS = ['buque', 'viaje', 'linea', 'agencia', 'muelle', 'eta', 'etb', 'etd', 'operativa', 'estado', 'cierre'];

    /** @param string[] $vigilados */
    public function __construct(private PDO $db, private array $vigilados)
    {
    }

    /**
     * @param list<array<string, ?string>> $filas
     * @return array{nuevas:int, actualizadas:int, cambios:int, retiradas:int}
     */
    public function sincronizar(string $terminal, array $filas, ?string $ahora = null): array
    {
        $ahora ??= date('Y-m-d H:i:s');
        $res = ['nuevas' => 0, 'actualizadas' => 0, 'cambios' => 0, 'retiradas' => 0];
        $vistas = [];

        $this->db->beginTransaction();
        try {
            foreach ($filas as $fila) {
                $clave = $this->clave($fila, $vistas);
                $vistas[$clave] = true;

                $sel = $this->db->prepare('SELECT * FROM escalas WHERE terminal = ? AND clave = ?');
                $sel->execute([$terminal, $clave]);
                $actual = $sel->fetch();

                if (!$actual) {
                    $this->insertar($terminal, $clave, $fila, $ahora);
                    $res['nuevas']++;
                    continue;
                }

                $cambios = [];
                foreach ($this->vigilados as $campo) {
                    if (!array_key_exists($campo, $fila)) {
                        continue; // la terminal no publica ese dato
                    }
                    $antes = $actual[$campo] ?? null;
                    $despues = $fila[$campo];
                    if ((string) $antes !== (string) $despues) {
                        $cambios[$campo] = [$antes, $despues];
                    }
                }

                $this->actualizar((int) $actual['id'], $fila, $ahora);
                if ($cambios) {
                    $ins = $this->db->prepare('INSERT INTO cambios (escala_id, campo, valor_anterior, valor_nuevo, detectado) VALUES (?, ?, ?, ?, ?)');
                    foreach ($cambios as $campo => [$antes, $despues]) {
                        $ins->execute([$actual['id'], $campo, $antes, $despues, $ahora]);
                    }
                    $res['cambios'] += count($cambios);
                    $res['actualizadas']++;
                }
            }

            // Escalas que dejaron de publicarse: se ocultan, no se borran.
            $activas = $this->db->prepare('SELECT id, clave FROM escalas WHERE terminal = ? AND activa = 1');
            $activas->execute([$terminal]);
            $baja = $this->db->prepare('UPDATE escalas SET activa = 0 WHERE id = ?');
            foreach ($activas->fetchAll() as $e) {
                if (!isset($vistas[$e['clave']])) {
                    $baja->execute([$e['id']]);
                    $res['retiradas']++;
                }
            }

            $this->db->commit();
        } catch (Throwable $t) {
            $this->db->rollBack();
            throw $t;
        }
        return $res;
    }

    /** Identifica la escala por buque + viaje; si no hay viaje, solo por buque. */
    private function clave(array $fila, array $yaVistas): string
    {
        $base = Texto::normalizar($fila['buque']) . '|' . Texto::normalizar($fila['viaje'] ?? '');
        $clave = $base;
        for ($n = 2; isset($yaVistas[$clave]); $n++) {
            $clave = "$base#$n";
        }
        return $clave;
    }

    private function insertar(string $terminal, string $clave, array $fila, string $ahora): void
    {
        $cols = ['terminal', 'clave', 'eta_original', 'primera_vez', 'ultima_vez', 'activa'];
        $vals = [$terminal, $clave, $fila['eta'] ?? null, $ahora, $ahora, 1];
        foreach (self::CAMPOS as $c) {
            $cols[] = $c;
            $vals[] = $fila[$c] ?? null;
        }
        $sql = sprintf('INSERT INTO escalas (%s) VALUES (%s)', implode(', ', $cols), implode(', ', array_fill(0, count($cols), '?')));
        $this->db->prepare($sql)->execute($vals);
    }

    private function actualizar(int $id, array $fila, string $ahora): void
    {
        $sets = ['ultima_vez = ?', 'activa = 1'];
        $vals = [$ahora];
        foreach (self::CAMPOS as $c) {
            if (array_key_exists($c, $fila)) {
                $sets[] = "$c = ?";
                $vals[] = $fila[$c];
            }
        }
        $vals[] = $id;
        $this->db->prepare('UPDATE escalas SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
    }
}
