<?php
declare(strict_types=1);

/** Clientes, contenedores y su vinculación con las escalas del tablero. */
final class Contenedores
{
    private ?BuscadorManifiesto $manifiestos = null;
    private int $reconsultarHoras = 2;
    private ?ConsultaNaviera $navieras = null;

    public function __construct(private PDO $db)
    {
    }

    /** Activa el tracking de las navieras, para conocer el buque cuando Lucía todavía no lo sabe. */
    public function usarNavieras(?ConsultaNaviera $navieras): void
    {
        $this->navieras = $navieras;
    }

    /** @return array{0: ?ResultadoNaviera, 1: ?string} resultado (solo si trae buque) y aviso de error */
    private function trackingNaviera(string $numero): array
    {
        if ($this->navieras === null) {
            return [null, null];
        }
        try {
            $r = $this->navieras->consultar($numero);
        } catch (Throwable $t) {
            return [null, 'No se pudo consultar el tracking de la naviera: ' . $t->getMessage()];
        }
        return [$r, null];
    }

    /** Activa la consulta del buque por número de manifiesto (Lucía no lo informa en los datos del contenedor). */
    public function usarManifiestos(?BuscadorManifiesto $manifiestos, int $reconsultarHoras = 2): void
    {
        $this->manifiestos = $manifiestos;
        $this->reconsultarHoras = $reconsultarHoras;
    }

    /**
     * Buque de un manifiesto, guardado en la tabla manifiestos para no repetir la consulta:
     * si ya se conoce el buque no se vuelve a preguntar; si no, se reintenta pasadas unas horas.
     * @return array{buque: ?string, viaje: ?string, fecha: ?string, detalle: array}
     */
    public function buqueDeManifiesto(array $clave, int $ahora): array
    {
        $id = implode(',', $clave);
        $st = $this->db->prepare('SELECT buque, viaje, fecha, consultado, detalle FROM manifiestos WHERE clave = ?');
        $st->execute([$id]);
        $guardado = $st->fetch();
        if ($guardado && ($guardado['buque'] !== null || strtotime($guardado['consultado']) > $ahora - $this->reconsultarHoras * 3600)) {
            $guardado['detalle'] = json_decode((string) $guardado['detalle'], true) ?: [];
            return $guardado;
        }

        $m = $this->manifiestos->consultar($clave);
        $this->db->prepare('DELETE FROM manifiestos WHERE clave = ?')->execute([$id]);
        $this->db->prepare('INSERT INTO manifiestos (clave, buque, viaje, fecha, consultado, detalle) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$id, $m->buque, $m->viaje, $m->fecha, date('Y-m-d H:i:s', $ahora), json_encode($m->detalle, JSON_UNESCAPED_UNICODE)]);
        return ['buque' => $m->buque, 'viaje' => $m->viaje, 'fecha' => $m->fecha, 'detalle' => $m->detalle];
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
    /** Contenedor entero o apertura: se siguen distinto. */
    public const TIPOS = ['entero' => 'Entero', 'apertura' => 'Apertura'];

    /** "entero" / "apertura" a partir de lo que se escriba (Entero, E, FCL, Apertura, A, ...); null si no se reconoce. */
    public static function tipoDe(?string $texto): ?string
    {
        $t = Texto::normalizar((string) $texto);
        return match (true) {
            $t === '' => null,
            in_array($t, ['e', 'ent', 'entero', 'completo', 'fcl'], true) => 'entero',
            in_array($t, ['a', 'ap', 'apert', 'apertura', 'lcl'], true) => 'apertura',
            default => null,
        };
    }

    /**
     * @param ?string $usuario quien lo carga; si el contenedor ya existía sin usuario, pasa a ser suyo
     * @param ?string $tipo "entero" o "apertura"; null = entero si es nuevo, sin cambios si ya existía
     */
    public function guardarContenedor(string $numero, string $carpeta, ?string $rut = null, ?string $usuario = null, ?string $tipo = null): array
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
        if ($tipo !== null && !isset(self::TIPOS[$tipo])) {
            return ['ok' => false, 'mensaje' => "Tipo desconocido para " . Validacion::formatearContenedor($numero) . ": tiene que ser Entero o Apertura"];
        }
        $aviso = Validacion::digitoContenedorCorrecto($numero)
            ? '' : ' (atención: el dígito verificador no coincide, revisá el número)';
        $legible = Validacion::formatearContenedor($numero);

        $st = $this->db->prepare('SELECT id FROM contenedores WHERE numero = ? AND carpeta = ?');
        $st->execute([$numero, $carpeta]);
        $id = $st->fetchColumn();
        if ($id !== false) {
            $this->db->prepare('UPDATE contenedores SET cliente_id = ?, activo = 1, usuario = COALESCE(usuario, ?), tipo = COALESCE(?, tipo) WHERE id = ?')
                ->execute([$clienteId, $usuario, $tipo, $id]);
            return ['ok' => true, 'mensaje' => "Contenedor $legible (carpeta $carpeta) actualizado$aviso", 'id' => (int) $id];
        }
        $this->db->prepare('INSERT INTO contenedores (numero, carpeta, cliente_id, creado, usuario, tipo) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$numero, $carpeta, $clienteId, date('Y-m-d H:i:s'), $usuario, $tipo ?? 'entero']);
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
    public function importarContenedores(string $texto, ?string $usuario = null): array
    {
        $filas = self::filasDeTexto($texto, fn($c) => !Validacion::formatoContenedor(Validacion::normalizarContenedor($c[0] ?? '')));
        return $this->importar($filas, function ($c) use ($usuario) {
            // Columnas: Contenedor, Carpeta, RUT (opcional), Tipo (opcional: Entero / Apertura)
            $tipo = trim($c[3] ?? '');
            if ($tipo !== '' && self::tipoDe($tipo) === null) {
                return ['ok' => false, 'mensaje' => 'Tipo "' . $tipo . '" desconocido para ' . Validacion::formatearContenedor(Validacion::normalizarContenedor($c[0] ?? '')) . ': tiene que ser Entero o Apertura'];
            }
            return $this->guardarContenedor($c[0] ?? '', $c[1] ?? '', $c[2] ?? null, $usuario, self::tipoDe($tipo));
        });
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
     * y los vincula con la escala del buque. Los que ya ingresaron a stock no se consultan más.
     * @return array{consultados: int, encontrados: int, vinculados: int, errores: list<string>}
     */
    public function consultarPendientes(ConsultaLucia $lucia, array $cfg, ?int $ahora = null, ?callable $log = null): array
    {
        $ahora ??= time();
        $res = ['consultados' => 0, 'encontrados' => 0, 'vinculados' => 0, 'errores' => []];
        $limite = date('Y-m-d H:i:s', $ahora - (int) (($cfg['reconsultar_horas'] ?? 2) * 3600) + 60);

        $st = $this->db->prepare("SELECT * FROM contenedores WHERE activo = 1 AND lucia_estado <> 'en_stock'
            AND (lucia_consulta IS NULL OR lucia_consulta < ?)
            ORDER BY lucia_consulta IS NOT NULL, lucia_consulta LIMIT " . (int) ($cfg['max_por_ejecucion'] ?? 200));
        $st->execute([$limite]);

        $i = 0;
        foreach ($st->fetchAll() as $c) {
            // Consultados antes de esta regla: si la última respuesta ya mostraba stock, no hace falta consultar.
            if ($c['lucia_estado'] === 'encontrado' && self::ingresoAStock(json_decode((string) $c['lucia_detalle'], true) ?: [])) {
                $this->db->prepare("UPDATE contenedores SET lucia_estado = 'en_stock' WHERE id = ?")->execute([$c['id']]);
                continue;
            }
            if ($i++ > 0 && ($cfg['pausa_segundos'] ?? 0) > 0) {
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
            // Lucía todavía no lo conoce (por ejemplo, viene navegando): el tracking de la naviera dice en qué buque llega.
            [$nav, $avisoNav] = $this->trackingNaviera($c['numero']);
            if ($nav === null || $nav->buque === null) {
                $this->db->prepare("UPDATE contenedores SET lucia_estado = 'no_encontrado', lucia_consulta = ?, lucia_mensaje = ?, lucia_detalle = ? WHERE id = ?")
                    ->execute([$cuando, $avisoNav ? mb_substr($avisoNav, 0, 255) : null, $nav ? json_encode(['resumen' => $nav->resumen], JSON_UNESCAPED_UNICODE) : null, $c['id']]);
                return false;
            }
            $escalaId = $this->buscarEscala($nav->buque, $nav->viaje, $nav->fecha, $ahora);
            $mismaEscala = $escalaId !== null && $c['escala_id'] !== null && (int) $c['escala_id'] === $escalaId;
            $this->db->prepare("UPDATE contenedores SET lucia_estado = 'no_encontrado', lucia_consulta = ?, lucia_buque = ?, lucia_viaje = ?,
                    lucia_fecha = ?, lucia_mensaje = ?, lucia_detalle = ?, escala_id = ?, vinculado = ? WHERE id = ?")
                ->execute([
                    $cuando, $nav->buque, $nav->viaje, $nav->fecha,
                    mb_substr("Todavía no figura en Lucía; buque según el tracking de {$nav->naviera}"
                        . ($escalaId === null ? " ({$nav->buque}, que no figura en el tablero)" : ''), 0, 255),
                    json_encode(['resumen' => $nav->resumen, 'naviera' => $nav->naviera], JSON_UNESCAPED_UNICODE),
                    $escalaId,
                    $escalaId === null ? null : ($mismaEscala ? $c['vinculado'] : $cuando),
                    $c['id'],
                ]);
            return $escalaId;
        }

        // 1) Buque informado por Lucía; 2) si no, el del manifiesto; 3) si no, terminal + fecha de descarga.
        $buque = $r->buque;
        $viaje = $r->viaje;
        $fecha = $r->fecha;
        $manifiestoUsado = null;
        $avisoManifiesto = null;
        $detalle = $r->detalle;
        $detalle['resumen'] ??= [];
        if ($buque === null && $this->manifiestos !== null) {
            foreach ($r->detalle['claves_manifiesto'] ?? [] as $clave) {
                try {
                    $m = $this->buqueDeManifiesto($clave, $ahora);
                } catch (Throwable $t) {
                    if ($t instanceof LuciaSinConfigurar) {
                        throw $t;
                    }
                    $avisoManifiesto = "No se pudo consultar el manifiesto {$clave[2]}: " . $t->getMessage();
                    continue;
                }
                if ($m['buque'] !== null) {
                    [$buque, $viaje, $fecha, $manifiestoUsado] = [$m['buque'], $m['viaje'], $m['fecha'] ?: $r->fecha, $clave[2]];
                    $cab = $m['detalle']['cabecera'] ?? [];
                    $suyo = $m['detalle']['contenedores'][Validacion::normalizarContenedor($c['numero'])] ?? null;
                    array_unshift($detalle['resumen'], trim("Manifiesto {$clave[2]}: $buque"
                        . ($m['fecha'] ? ' · llegada ' . substr(Texto::mostrarFecha($m['fecha']), 0, 5) : '')
                        . (($cab['AgeTraRSoc'] ?? '') !== '' ? " · agencia {$cab['AgeTraRSoc']}" : '')
                        . (($cab['CGLugPart'] ?? '') !== '' ? " · desde {$cab['CGLugPart']}" : '')
                        . ($suyo ? " · conocimiento {$suyo['conocimiento']}" . ($suyo['consignatario'] !== '' ? " ({$suyo['consignatario']})" : '') : '')));
                    break;
                }
            }
        }

        // Sin buque por Lucía ni por manifiesto: el tracking de la naviera, antes de deducirlo por terminal y fecha.
        $navieraUsada = null;
        if ($buque === null) {
            [$nav, $avisoNav] = $this->trackingNaviera($c['numero']);
            if ($nav !== null) {
                array_push($detalle['resumen'], ...$nav->resumen);
                if ($nav->buque !== null) {
                    [$buque, $viaje, $fecha, $navieraUsada] = [$nav->buque, $nav->viaje, $nav->fecha ?? $fecha, $nav->naviera];
                }
            }
            $avisoManifiesto = trim(($avisoManifiesto ?? '') . ' ' . ($avisoNav ?? '')) ?: null;
        }

        $escalaId = $this->buscarEscala($buque, $viaje, $fecha, $ahora);
        $porTerminal = false;
        if ($escalaId === null && $r->terminal !== null && $r->fecha !== null) {
            $escalaId = $this->buscarEscalaPorTerminal($r->terminal, $r->fecha);
            $porTerminal = $escalaId !== null;
        }
        $mismaEscala = $escalaId !== null && $c['escala_id'] !== null && (int) $c['escala_id'] === $escalaId;
        $segunManifiesto = match (true) {
            $manifiestoUsado !== null => " (según manifiesto $manifiestoUsado)",
            $navieraUsada !== null => " (según el tracking de $navieraUsada)",
            default => '',
        };
        $this->db->prepare("UPDATE contenedores SET lucia_estado = ?, lucia_consulta = ?, lucia_buque = ?, lucia_viaje = ?,
                lucia_fecha = ?, lucia_mensaje = ?, lucia_detalle = ?, escala_id = ?, vinculado = ? WHERE id = ?")
            ->execute([
                self::ingresoAStock($detalle) ? 'en_stock' : 'encontrado',
                $cuando, $buque, $viaje, $fecha,
                mb_substr(trim(match (true) {
                    $porTerminal && $buque !== null => "El buque $buque$segunManifiesto no figura en el tablero; se dedujo por terminal ({$r->terminal}) y fecha de descarga (" . Texto::mostrarFecha($r->fecha) . ')',
                    $porTerminal => "Buque deducido por terminal ({$r->terminal}) y fecha de descarga o arribo (" . Texto::mostrarFecha($r->fecha) . ')',
                    $escalaId !== null => $segunManifiesto !== '' ? "Buque$segunManifiesto" : '',
                    $buque !== null => "El buque $buque$segunManifiesto no figura en el tablero",
                    $r->terminal !== null && $r->fecha !== null => "Ningún buque del tablero operaba en {$r->terminal} el " . Texto::mostrarFecha($r->fecha),
                    default => 'Lucía tiene datos del contenedor (' . implode(', ', $r->detalle['secciones_con_datos'] ?? []) . '), pero todavía no se pudo ubicar el buque',
                } . ($avisoManifiesto ? " · $avisoManifiesto" : '')), 0, 255) ?: null,
                json_encode($detalle, JSON_UNESCAPED_UNICODE),
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
            $this->db->query('SELECT id, buque, viaje, eta, etd, activa FROM escalas')->fetchAll(),
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
            // Sin fecha se prefieren las escalas vigentes: activas en el line-up y sin zarpar.
            $vigentes = array_filter($candidatas, fn($e) => $e['activa'] && (!$e['etd'] || strtotime($e['etd']) >= $ahora));
            $candidatas = $vigentes ?: $candidatas;
        }
        usort($candidatas, fn($a, $b) => self::distancia($a['eta'], $referencia) <=> self::distancia($b['eta'], $referencia));
        return (int) $candidatas[0]['id'];
    }

    /**
     * Lucía no informa el buque, pero sí la terminal y la fecha de descarga (o de arribo).
     * Se elige la escala de esa terminal que operaba en ese momento: entre ETB (o ETA) y ETD,
     * con 12 horas de tolerancia. Si ninguna la contiene, la de ETB/ETA más cercana a menos de 48 horas.
     * Se buscan también escalas ya retiradas del line-up, porque la descarga puede ser pasada.
     */
    public function buscarEscalaPorTerminal(string $terminal, string $fecha): ?int
    {
        $t = strtotime($fecha);
        if ($t === false) {
            return null;
        }
        $st = $this->db->prepare('SELECT id, eta, etb, etd FROM escalas WHERE terminal = ?');
        $st->execute([$terminal]);

        $mejor = null;
        $mejorPuntaje = PHP_INT_MAX;
        foreach ($st->fetchAll() as $e) {
            $inicio = strtotime((string) ($e['etb'] ?: $e['eta']));
            if (!$inicio) {
                continue;
            }
            $fin = $e['etd'] ? strtotime($e['etd']) : false;
            if ($fin && $t >= $inicio - 12 * 3600 && $t <= $fin + 12 * 3600) {
                $puntaje = abs($t - $inicio) / 1000; // dentro de la ventana: siempre gana
            } else {
                $puntaje = abs($t - $inicio);
                if ($puntaje > 48 * 3600) {
                    continue;
                }
            }
            if ($puntaje < $mejorPuntaje) {
                $mejorPuntaje = $puntaje;
                $mejor = (int) $e['id'];
            }
        }
        return $mejor;
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
    /** Ya entró a algún depósito (aunque después haya salido): no hace falta seguir consultándolo. */
    public static function ingresoAStock(array $detalle): bool
    {
        return in_array(LuciaSoap::stockDeDetalle($detalle)['estado'] ?? null, ['en_stock', 'salio'], true);
    }

    /** @param ?string $usuario solo los cargados por ese usuario; null = los de todos */
    public function porEscala(?string $usuario = null): array
    {
        $st = $this->db->prepare('SELECT c.*, cl.nombre AS cliente, cl.rut FROM contenedores c
            LEFT JOIN clientes cl ON cl.id = c.cliente_id
            WHERE c.activo = 1 AND c.escala_id IS NOT NULL' . ($usuario !== null ? ' AND c.usuario = ?' : '') . '
            ORDER BY cl.nombre, c.carpeta, c.numero');
        $st->execute($usuario !== null ? [$usuario] : []);
        $res = [];
        foreach ($st->fetchAll() as $c) {
            $res[(int) $c['escala_id']][] = $c;
        }
        return $res;
    }
}
