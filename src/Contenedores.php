<?php
declare(strict_types=1);

/** Clientes, contenedores y su vinculación con las escalas del tablero. */
final class Contenedores
{
    public function __construct(private PDO $db)
    {
    }

    // ---------------------------------------------------------------- clientes

    /** Crea o actualiza (por RUT) un cliente. @return array{ok: bool, mensaje: string, id?: int} */
    public function guardarCliente(string $rut, string $nombre): array
    {
        $rut = Validacion::normalizarRut($rut);
        $nombre = Texto::limpiar($nombre);
        if (!Validacion::formatoRut($rut)) {
            return ['ok' => false, 'mensaje' => "RUT inválido ($rut): debe tener 12 dígitos"];
        }
        if ($nombre === '') {
            return ['ok' => false, 'mensaje' => "Falta el nombre del cliente con RUT $rut"];
        }
        $aviso = Validacion::digitoRutCorrecto($rut) ? '' : ' (atención: el dígito verificador del RUT no coincide)';

        $id = $this->idCliente($rut);
        if ($id !== null) {
            $this->db->prepare('UPDATE clientes SET nombre = ? WHERE id = ?')->execute([$nombre, $id]);
            return ['ok' => true, 'mensaje' => "Cliente $rut actualizado$aviso", 'id' => $id];
        }
        $this->db->prepare('INSERT INTO clientes (rut, nombre, creado) VALUES (?, ?, ?)')
            ->execute([$rut, $nombre, date('Y-m-d H:i:s')]);
        return ['ok' => true, 'mensaje' => "Cliente $rut agregado$aviso", 'id' => (int) $this->db->lastInsertId()];
    }

    public function idCliente(string $rut): ?int
    {
        $st = $this->db->prepare('SELECT id FROM clientes WHERE rut = ?');
        $st->execute([Validacion::normalizarRut($rut)]);
        $id = $st->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    // ------------------------------------------------------------ contenedores

    /** @return array{ok: bool, mensaje: string, id?: int} */
    public function guardarContenedor(string $numero, string $carpeta, ?string $rut = null): array
    {
        $numero = Validacion::normalizarContenedor($numero);
        $carpeta = Texto::limpiar($carpeta);
        if (!Validacion::formatoContenedor($numero)) {
            return ['ok' => false, 'mensaje' => "Contenedor inválido ($numero): el formato es AAAA-123456-7"];
        }
        if ($carpeta === '') {
            return ['ok' => false, 'mensaje' => "Falta el número de carpeta del contenedor " . Validacion::formatearContenedor($numero)];
        }
        $clienteId = null;
        if ($rut !== null && Validacion::normalizarRut($rut) !== '') {
            $clienteId = $this->idCliente($rut);
            if ($clienteId === null) {
                return ['ok' => false, 'mensaje' => "No existe un cliente con RUT " . Validacion::normalizarRut($rut) . " (contenedor " . Validacion::formatearContenedor($numero) . ")"];
            }
        }
        $aviso = Validacion::digitoContenedorCorrecto($numero)
            ? '' : ' (atención: el dígito verificador no coincide, revisá el número)';
        $legible = Validacion::formatearContenedor($numero);

        $st = $this->db->prepare('SELECT id FROM contenedores WHERE numero = ? AND carpeta = ?');
        $st->execute([$numero, $carpeta]);
        $id = $st->fetchColumn();
        if ($id !== false) {
            $this->db->prepare('UPDATE contenedores SET cliente_id = ?, activo = 1 WHERE id = ?')->execute([$clienteId, $id]);
            return ['ok' => true, 'mensaje' => "Contenedor $legible (carpeta $carpeta) actualizado$aviso", 'id' => (int) $id];
        }
        $this->db->prepare('INSERT INTO contenedores (numero, carpeta, cliente_id, creado) VALUES (?, ?, ?, ?)')
            ->execute([$numero, $carpeta, $clienteId, date('Y-m-d H:i:s')]);
        return ['ok' => true, 'mensaje' => "Contenedor $legible (carpeta $carpeta) agregado$aviso", 'id' => (int) $this->db->lastInsertId()];
    }

    // -------------------------------------------------------------- importación

    /**
     * Separa un texto pegado desde Excel o un CSV en filas y columnas.
     * Acepta tabulador, punto y coma o coma; ignora líneas vacías y una fila de encabezado.
     * @return list<list<string>>
     */
    public static function filasDeTexto(string $texto, callable $esEncabezado): array
    {
        $lineas = preg_split('/\R/', trim($texto));
        $sep = "\t";
        foreach (["\t", ';', ','] as $candidato) {
            if (str_contains($lineas[0] ?? '', $candidato)) {
                $sep = $candidato;
                break;
            }
        }
        $filas = [];
        foreach ($lineas as $i => $linea) {
            if (trim($linea) === '') {
                continue;
            }
            $celdas = array_map(fn($c) => trim($c, " \t\"'"), str_getcsv($linea, $sep));
            if ($i === 0 && $esEncabezado($celdas)) {
                continue;
            }
            $filas[] = $celdas;
        }
        return $filas;
    }

    /** Formato: RUT ; Nombre. @return array{ok: int, errores: list<string>, avisos: list<string>} */
    public function importarClientes(string $texto): array
    {
        $filas = self::filasDeTexto($texto, fn($c) => !preg_match('/\d{6,}/', $c[0] ?? ''));
        return $this->importar($filas, fn($c) => $this->guardarCliente($c[0] ?? '', $c[1] ?? ''));
    }

    /** Formato: Contenedor ; Carpeta ; RUT (opcional). @return array{ok: int, errores: list<string>, avisos: list<string>} */
    public function importarContenedores(string $texto): array
    {
        $filas = self::filasDeTexto($texto, fn($c) => !Validacion::formatoContenedor(Validacion::normalizarContenedor($c[0] ?? '')));
        return $this->importar($filas, fn($c) => $this->guardarContenedor($c[0] ?? '', $c[1] ?? '', $c[2] ?? null));
    }

    private function importar(array $filas, callable $guardar): array
    {
        $res = ['ok' => 0, 'errores' => [], 'avisos' => []];
        $this->db->beginTransaction();
        try {
            foreach ($filas as $celdas) {
                $r = $guardar($celdas);
                if (!$r['ok']) {
                    $res['errores'][] = $r['mensaje'];
                    continue;
                }
                $res['ok']++;
                if (str_contains($r['mensaje'], 'atención')) {
                    $res['avisos'][] = $r['mensaje'];
                }
            }
            $this->db->commit();
        } catch (Throwable $t) {
            $this->db->rollBack();
            throw $t;
        }
        return $res;
    }

    // ------------------------------------------------- consulta a Lucía y vínculo

    /**
     * Consulta en Lucía los contenedores activos que no se consultaron en las últimas horas
     * y los vincula con la escala del buque.
     * @return array{consultados: int, encontrados: int, vinculados: int, errores: list<string>}
     */
    public function consultarPendientes(ConsultaLucia $lucia, array $cfg, ?int $ahora = null, ?callable $log = null): array
    {
        $ahora ??= time();
        $res = ['consultados' => 0, 'encontrados' => 0, 'vinculados' => 0, 'errores' => []];
        $limite = date('Y-m-d H:i:s', $ahora - (int) (($cfg['reconsultar_horas'] ?? 2) * 3600) + 60);

        $st = $this->db->prepare('SELECT * FROM contenedores WHERE activo = 1 AND (lucia_consulta IS NULL OR lucia_consulta < ?)
            ORDER BY lucia_consulta IS NOT NULL, lucia_consulta LIMIT ' . (int) ($cfg['max_por_ejecucion'] ?? 200));
        $st->execute([$limite]);

        foreach ($st->fetchAll() as $i => $c) {
            if ($i > 0 && ($cfg['pausa_segundos'] ?? 0) > 0) {
                usleep((int) ($cfg['pausa_segundos'] * 1_000_000));
            }
            $res['consultados']++;
            try {
                $escalaId = $this->procesar($lucia, $c, $ahora);
            } catch (Throwable $t) {
                $res['errores'][] = Validacion::formatearContenedor($c['numero']) . ": {$t->getMessage()}";
                if ($t instanceof LuciaSinConfigurar) {
                    break; // no tiene sentido seguir si falta configuración
                }
                continue;
            }
            if ($escalaId !== false) {
                $res['encontrados']++;
                $res['vinculados'] += $escalaId === null ? 0 : 1;
            }
            if ($log) {
                $log(Validacion::formatearContenedor($c['numero']) . ': ' . match (true) {
                    $escalaId === false => 'no encontrado en Lucía',
                    $escalaId === null => 'encontrado, pero su buque no figura en el tablero',
                    default => "vinculado a la escala #$escalaId",
                });
            }
        }
        return $res;
    }

    /** Consulta un contenedor ahora mismo. @return string resultado para mostrar */
    public function consultarUno(ConsultaLucia $lucia, int $id, ?int $ahora = null): string
    {
        $st = $this->db->prepare('SELECT * FROM contenedores WHERE id = ?');
        $st->execute([$id]);
        $c = $st->fetch();
        if (!$c) {
            throw new RuntimeException('Contenedor inexistente');
        }
        $escalaId = $this->procesar($lucia, $c, $ahora ?? time());
        $numero = Validacion::formatearContenedor($c['numero']);
        return match (true) {
            $escalaId === false => "$numero: Lucía no tiene información del contenedor",
            $escalaId === null => "$numero: encontrado, pero su buque no figura en el tablero",
            default => "$numero: vinculado al buque",
        };
    }

    /**
     * Consulta un contenedor en Lucía y guarda el resultado.
     * @return int|null|false id de la escala vinculada; null si se encontró pero sin escala; false si Lucía no lo tiene
     * @throws Throwable si la consulta falla (queda registrado en el contenedor)
     */
    private function procesar(ConsultaLucia $lucia, array $c, int $ahora): int|null|false
    {
        $cuando = date('Y-m-d H:i:s', $ahora);
        try {
            $r = $lucia->consultar($c['numero']);
        } catch (Throwable $t) {
            $this->db->prepare("UPDATE contenedores SET lucia_estado = 'error', lucia_consulta = ?, lucia_mensaje = ? WHERE id = ?")
                ->execute([$cuando, mb_substr($t->getMessage(), 0, 255), $c['id']]);
            throw $t;
        }

        if (!$r->encontrado) {
            $this->db->prepare("UPDATE contenedores SET lucia_estado = 'no_encontrado', lucia_consulta = ?, lucia_mensaje = NULL WHERE id = ?")
                ->execute([$cuando, $c['id']]);
            return false;
        }

        $escalaId = $this->buscarEscala($r->buque, $r->viaje, $r->fecha, $ahora);
        $mismaEscala = $escalaId !== null && $c['escala_id'] !== null && (int) $c['escala_id'] === $escalaId;
        $this->db->prepare("UPDATE contenedores SET lucia_estado = 'encontrado', lucia_consulta = ?, lucia_buque = ?, lucia_viaje = ?,
                lucia_fecha = ?, lucia_mensaje = ?, lucia_detalle = ?, escala_id = ?, vinculado = ? WHERE id = ?")
            ->execute([
                $cuando, $r->buque, $r->viaje, $r->fecha,
                match (true) {
                    $escalaId !== null => null,
                    $r->buque === null => 'Lucía tiene datos del contenedor (' . implode(', ', $r->detalle['secciones_con_datos'] ?? []) . ') pero no informa el buque',
                    default => "El buque {$r->buque} no figura en el tablero",
                },
                json_encode($r->detalle, JSON_UNESCAPED_UNICODE),
                $escalaId,
                $escalaId === null ? null : ($mismaEscala ? $c['vinculado'] : $cuando),
                $c['id'],
            ]);
        return $escalaId;
    }

    /**
     * Busca la escala del buque informado por Lucía entre las escalas activas.
     * Prioridad: mismo viaje; si no, la ETA más cercana a la fecha informada;
     * si Lucía no informa fecha, la más cercana a hoy que no haya zarpado.
     */
    public function buscarEscala(?string $buque, ?string $viaje, ?string $fecha, ?int $ahora = null): ?int
    {
        $ahora ??= time();
        $nombre = self::clave($buque);
        if ($nombre === '') {
            return null;
        }
        $candidatas = array_values(array_filter(
            $this->db->query('SELECT id, buque, viaje, eta, etd FROM escalas WHERE activa = 1')->fetchAll(),
            fn($e) => self::clave($e['buque']) === $nombre
        ));
        if (!$candidatas) {
            return null;
        }

        $viaje = self::clave($viaje);
        if ($viaje !== '') {
            foreach ($candidatas as $e) {
                if (self::clave($e['viaje']) === $viaje) {
                    return (int) $e['id'];
                }
            }
        }

        $referencia = ($fecha && ($t = strtotime($fecha)) !== false) ? $t : $ahora;
        if (!$fecha) {
            // Sin fecha de Lucía se descartan las escalas que ya zarparon, si queda alguna.
            $vigentes = array_filter($candidatas, fn($e) => !$e['etd'] || strtotime($e['etd']) >= $ahora);
            $candidatas = $vigentes ?: $candidatas;
        }
        usort($candidatas, fn($a, $b) => self::distancia($a['eta'], $referencia) <=> self::distancia($b['eta'], $referencia));
        return (int) $candidatas[0]['id'];
    }

    private static function distancia(?string $eta, int $referencia): int
    {
        $t = $eta ? strtotime($eta) : false;
        return $t === false ? PHP_INT_MAX : abs($t - $referencia);
    }

    /** Nombre o viaje comparable: mayúsculas sin espacios ni signos. */
    private static function clave(?string $s): string
    {
        return preg_replace('/[^a-z0-9]/', '', Texto::normalizar($s));
    }

    // ----------------------------------------------------------------- consultas

    /** Contenedores activos vinculados, agrupados por escala. @return array<int, list<array>> */
    public function porEscala(): array
    {
        $st = $this->db->query('SELECT c.*, cl.nombre AS cliente, cl.rut FROM contenedores c
            LEFT JOIN clientes cl ON cl.id = c.cliente_id
            WHERE c.activo = 1 AND c.escala_id IS NOT NULL ORDER BY cl.nombre, c.carpeta, c.numero');
        $res = [];
        foreach ($st->fetchAll() as $c) {
            $res[(int) $c['escala_id']][] = $c;
        }
        return $res;
    }
}
