<?php
declare(strict_types=1);

/**
 * Conector de integración directa con Acceso 3 RH TITANIUM (Vía A — Firebird 2.5).
 *
 * Esquema real verificado en ACCESODB.FDB (Acceso 3 Ver. 3.2.83.1):
 * - EMPLEADOS: CLAVEEMPLEADO (PK), NOMBRE, APELLIDOPATERNO, APELLIDOMATERNO,
 *              STATUS (0 = Alta, 1 = Baja), CLAVEDEPARTAMENTO, CLAVESUCURSAL
 * - CHECADAS : NUMCHECADA (PK vía GEN_ID(NUMCHECADA, 1)), ID (CLAVEEMPLEADO),
 *              STATUS (0 = Activo), FECHA (TIMESTAMP), TIPOCHECADA (1=Entrada,
 *              2=Comida Inicio, 3=Salida, 4=Comida Fin), VERIFICACION (15 = Face),
 *              FECHAENVIO, ESTACION, SERIAL, MODELO, IP
 */
final class TitaniumConnector
{
    private ?PDO $fbPdo = null;
    private string $mode;

    public function __construct(private array $cfg, private PDO $localPdo)
    {
        $this->mode = $cfg['mode'] ?? 'demo';
    }

    public function mode(): string
    {
        return $this->mode;
    }

    private function firebird(): PDO
    {
        if ($this->fbPdo !== null) {
            return $this->fbPdo;
        }
        if (!in_array('firebird', PDO::getAvailableDrivers(), true)) {
            throw new RuntimeException('La extensión PDO_FIREBIRD no está habilitada en PHP. Active extension=pdo_firebird en php.ini.');
        }

        $dsn = sprintf(
            'firebird:dbname=%s/%d:%s;charset=%s',
            $this->cfg['host'],
            $this->cfg['port'],
            $this->cfg['database'],
            $this->cfg['charset']
        );

        $this->fbPdo = new PDO($dsn, $this->cfg['user'], $this->cfg['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        return $this->fbPdo;
    }

    private function ident(string $name): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            throw new InvalidArgumentException('Identificador SQL inválido en configuración TITANIUM: ' . $name);
        }
        return $name;
    }

    private function statusLabel(mixed $rawStatus): string
    {
        if (is_numeric($rawStatus)) {
            return ((int)$rawStatus === (int)$this->cfg['val_estatus_alta_int']) ? 'Alta' : 'Baja';
        }
        $s = trim((string)$rawStatus);
        return strcasecmp($s, (string)$this->cfg['val_estatus_alta']) === 0 ? 'Alta' : 'Baja';
    }

    /**
     * Lista empleados desde Acceso 3 TITANIUM (solo lectura).
     *
     * @return array<int, array{clave_empleado:int, nombre_completo:string, estatus:string, clave_departamento:int, clave_sucursal:int}>
     */
    public function listEmployees(string $search = '', bool $onlyActive = false): array
    {
        if ($this->mode === 'demo') {
            $sql = 'SELECT clave_empleado, nombre_completo, estatus, clave_departamento, clave_sucursal
                    FROM demo_titanium_empleados WHERE 1=1';
            $params = [];
            if ($onlyActive) {
                $sql .= ' AND LOWER(estatus) = LOWER(?)';
                $params[] = $this->cfg['val_estatus_alta'];
            }
            if ($search !== '') {
                $sql .= ' AND (CAST(clave_empleado AS TEXT) LIKE ? OR LOWER(nombre_completo) LIKE LOWER(?))';
                $params[] = '%' . $search . '%';
                $params[] = '%' . $search . '%';
            }
            $sql .= ' ORDER BY clave_empleado ASC';
            $stmt = $this->localPdo->prepare($sql);
            $stmt->execute($params);
            $demoPlantas = [1 => 'Planta Matriz', 2 => 'Planta Norte'];
            $demoDeptos = [3 => 'Administración', 5 => 'Ventas', 7 => 'Producción / Tarimas'];
            return array_map(static fn(array $r): array => [
                'clave_empleado' => (int)$r['clave_empleado'],
                'nombre_completo' => (string)$r['nombre_completo'],
                'estatus' => (string)$r['estatus'],
                'clave_departamento' => (int)$r['clave_departamento'],
                'clave_sucursal' => (int)$r['clave_sucursal'],
                'planta' => $demoPlantas[(int)$r['clave_sucursal']] ?? 'Planta Matriz',
                'departamento' => $demoDeptos[(int)$r['clave_departamento']] ?? 'Operaciones',
            ], $stmt->fetchAll());
        }

        $fb = $this->firebird();
        $tbl = $this->ident($this->cfg['tbl_empleados']);
        $cId = $this->ident($this->cfg['col_emp_id']);
        $cNom = $this->ident($this->cfg['col_emp_nombre']);
        $cPat = $this->ident($this->cfg['col_emp_ap_pat']);
        $cMat = $this->ident($this->cfg['col_emp_ap_mat']);
        $cEst = $this->ident($this->cfg['col_emp_estatus']);
        $cDep = $this->ident($this->cfg['col_emp_depto']);
        $cSuc = $this->ident($this->cfg['col_emp_sucursal']);

        $sql = "SELECT FIRST 2000 e.{$cId} AS CLAVE, e.{$cNom} AS NOMBRE, e.{$cPat} AS AP_PAT, e.{$cMat} AS AP_MAT,
                       e.{$cEst} AS ESTATUS, e.{$cDep} AS DEPTO, e.{$cSuc} AS SUCURSAL,
                       s.DESCRIPCIONSUCURSAL AS PLANTA, d.DESCRIPCIONDEPARTAMENTO AS DEPARTAMENTO,
                       COALESCE(OCTET_LENGTH(u.FOTO1), OCTET_LENGTH(u.BIOPHOTO), OCTET_LENGTH(u.FOTO2), OCTET_LENGTH(u.FOTO3), 0) AS FOTO_BYTES
                FROM {$tbl} e
                LEFT JOIN SUCURSALES s ON s.CLAVESUCURSAL = e.{$cSuc}
                LEFT JOIN DEPARTAMENTOS d ON d.CLAVEDEPARTAMENTO = e.{$cDep}
                LEFT JOIN USUARIOSTERMINAL u ON u.ID = e.{$cId}
                WHERE 1=1";
        $params = [];
        if ($onlyActive) {
            $sql .= " AND e.{$cEst} = ?";
            $params[] = (int)$this->cfg['val_estatus_alta_int'];
        }
        if ($search !== '') {
            if (ctype_digit($search)) {
                $sql .= " AND e.{$cId} = ?";
                $params[] = (int)$search;
            } else {
                $sql .= " AND (UPPER(e.{$cNom}) CONTAINING UPPER(?) OR UPPER(e.{$cPat}) CONTAINING UPPER(?) OR UPPER(e.{$cMat}) CONTAINING UPPER(?))";
                $params[] = $search;
                $params[] = $search;
                $params[] = $search;
            }
        }
        $sql .= " ORDER BY e.{$cId} ASC";

        $stmt = $fb->prepare($sql);
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $fullName = trim(implode(' ', array_filter([
                trim((string)($r['NOMBRE'] ?? '')),
                trim((string)($r['AP_PAT'] ?? '')),
                trim((string)($r['AP_MAT'] ?? '')),
            ])));
            $planta = trim((string)($r['PLANTA'] ?? ''));
            $depto = trim((string)($r['DEPARTAMENTO'] ?? ''));
            $out[] = [
                'clave_empleado' => (int)$r['CLAVE'],
                'nombre_completo' => $fullName,
                'estatus' => $this->statusLabel($r['ESTATUS'] ?? 1),
                'clave_departamento' => (int)($r['DEPTO'] ?? 0),
                'clave_sucursal' => (int)($r['SUCURSAL'] ?? 0),
                'planta' => $planta !== '' ? $planta : 'Sin planta asignada',
                'departamento' => $depto !== '' ? $depto : 'Sin departamento',
                'tiene_foto_titanium' => ((int)($r['FOTO_BYTES'] ?? 0)) > 0,
            ];
        }
        return $out;
    }

    /**
     * Obtiene un empleado específico por su CLAVEEMPLEADO oficial de TITANIUM.
     */
    public function findEmployee(int $claveEmpleado): ?array
    {
        if ($this->mode === 'demo') {
            $stmt = $this->localPdo->prepare(
                'SELECT clave_empleado, nombre_completo, estatus, clave_departamento, clave_sucursal
                 FROM demo_titanium_empleados WHERE clave_empleado = ?'
            );
            $stmt->execute([$claveEmpleado]);
            $r = $stmt->fetch();
            if (!$r) {
                return null;
            }
            $demoPlantas = [1 => 'Planta Matriz', 2 => 'Planta Norte'];
            $demoDeptos = [3 => 'Administración', 5 => 'Ventas', 7 => 'Producción / Tarimas'];
            return [
                'clave_empleado' => (int)$r['clave_empleado'],
                'nombre_completo' => (string)$r['nombre_completo'],
                'estatus' => (string)$r['estatus'],
                'clave_departamento' => (int)$r['clave_departamento'],
                'clave_sucursal' => (int)$r['clave_sucursal'],
                'planta' => $demoPlantas[(int)$r['clave_sucursal']] ?? 'Planta Matriz',
                'departamento' => $demoDeptos[(int)$r['clave_departamento']] ?? 'Operaciones',
            ];
        }

        $fb = $this->firebird();
        $tbl = $this->ident($this->cfg['tbl_empleados']);
        $cId = $this->ident($this->cfg['col_emp_id']);
        $cNom = $this->ident($this->cfg['col_emp_nombre']);
        $cPat = $this->ident($this->cfg['col_emp_ap_pat']);
        $cMat = $this->ident($this->cfg['col_emp_ap_mat']);
        $cEst = $this->ident($this->cfg['col_emp_estatus']);
        $cDep = $this->ident($this->cfg['col_emp_depto']);
        $cSuc = $this->ident($this->cfg['col_emp_sucursal']);

        $stmt = $fb->prepare(
            "SELECT e.{$cId} AS CLAVE, e.{$cNom} AS NOMBRE, e.{$cPat} AS AP_PAT, e.{$cMat} AS AP_MAT,
                    e.{$cEst} AS ESTATUS, e.{$cDep} AS DEPTO, e.{$cSuc} AS SUCURSAL,
                    s.DESCRIPCIONSUCURSAL AS PLANTA, d.DESCRIPCIONDEPARTAMENTO AS DEPARTAMENTO
             FROM {$tbl} e
             LEFT JOIN SUCURSALES s ON s.CLAVESUCURSAL = e.{$cSuc}
             LEFT JOIN DEPARTAMENTOS d ON d.CLAVEDEPARTAMENTO = e.{$cDep}
             WHERE e.{$cId} = ?"
        );
        $stmt->execute([$claveEmpleado]);
        $r = $stmt->fetch();
        if (!$r) {
            return null;
        }
        $fullName = trim(implode(' ', array_filter([
            trim((string)($r['NOMBRE'] ?? '')),
            trim((string)($r['AP_PAT'] ?? '')),
            trim((string)($r['AP_MAT'] ?? '')),
        ])));
        $planta = trim((string)($r['PLANTA'] ?? ''));
        $depto = trim((string)($r['DEPARTAMENTO'] ?? ''));
        return [
            'clave_empleado' => (int)$r['CLAVE'],
            'nombre_completo' => $fullName,
            'estatus' => $this->statusLabel($r['ESTATUS'] ?? 1),
            'clave_departamento' => (int)($r['DEPTO'] ?? 0),
            'clave_sucursal' => (int)($r['SUCURSAL'] ?? 0),
            'planta' => $planta !== '' ? $planta : 'Sin planta asignada',
            'departamento' => $depto !== '' ? $depto : 'Sin departamento',
        ];
    }

    public function isEmployeeActive(?array $employee): bool
    {
        if ($employee === null) {
            return false;
        }
        return strcasecmp(trim((string)$employee['estatus']), 'Alta') === 0;
    }

    /**
     * Lee la foto de perfil oficial de un empleado directamente desde Acceso 3 TITANIUM
     * (solo lectura en memoria, sin guardar imágenes en la web).
     * Prioridad: USUARIOSTERMINAL.FOTO1 -> BIOPHOTO -> FOTO2 -> FOTO3 -> HVUSUARIOSTERMINAL.FOTO1
     */
    public function getEmployeePhoto(int $claveEmpleado): ?string
    {
        if ($this->mode === 'demo' || $claveEmpleado <= 0) {
            return null;
        }

        $fb = $this->firebird();
        $stmt = $fb->prepare('SELECT FOTOACTIVA, FOTO1, FOTO2, FOTO3, BIOPHOTO FROM USUARIOSTERMINAL WHERE ID = ?');
        $stmt->execute([$claveEmpleado]);
        $row = $stmt->fetch();
        if (is_array($row)) {
            $activeIdx = (int)($row['FOTOACTIVA'] ?? 1);
            $order = match ($activeIdx) {
                2 => ['FOTO2', 'FOTO1', 'FOTO3', 'BIOPHOTO'],
                3 => ['FOTO3', 'FOTO1', 'FOTO2', 'BIOPHOTO'],
                default => ['FOTO1', 'BIOPHOTO', 'FOTO2', 'FOTO3'],
            };
            foreach ($order as $col) {
                $val = $row[$col] ?? null;
                if ($val !== null) {
                    $bin = is_resource($val) ? (string)stream_get_contents($val) : (string)$val;
                    if (strlen($bin) > 100) {
                        return $bin;
                    }
                }
            }
        }

        // Respaldo opcional para terminales Hikvision (HVUSUARIOSTERMINAL)
        try {
            $hv = $fb->prepare('SELECT FOTO1, FOTO2, FOTO3 FROM HVUSUARIOSTERMINAL WHERE ID = ?');
            $hv->execute([$claveEmpleado]);
            $hRow = $hv->fetch();
            if (is_array($hRow)) {
                foreach (['FOTO1', 'FOTO2', 'FOTO3'] as $col) {
                    $val = $hRow[$col] ?? null;
                    if ($val !== null) {
                        $bin = is_resource($val) ? (string)stream_get_contents($val) : (string)$val;
                        if (strlen($bin) > 100) {
                            return $bin;
                        }
                    }
                }
            }
        } catch (Throwable) {
            // Tabla opcional según versión de Acceso 3
        }

        return null;
    }

    /**
     * Devuelve el mapa [clave_empleado => bytes_foto] para todos los empleados en Alta con foto en TITANIUM.
     * Consulta ligera (<5ms) para detectar cambios o eliminaciones de fotos en TITANIUM.
     *
     * @return array<int, int>
     */
    public function listActiveEmployeePhotoSizes(): array
    {
        if ($this->mode === 'demo') {
            return [];
        }

        $fb = $this->firebird();
        $tbl = $this->ident($this->cfg['tbl_empleados']);
        $cId = $this->ident($this->cfg['col_emp_id']);
        $cEst = $this->ident($this->cfg['col_emp_estatus']);
        $altaInt = (int)$this->cfg['val_estatus_alta_int'];

        $stmt = $fb->prepare(
            "SELECT e.{$cId} AS CLAVE,
                    COALESCE(OCTET_LENGTH(u.FOTO1), OCTET_LENGTH(u.BIOPHOTO), OCTET_LENGTH(u.FOTO2), OCTET_LENGTH(u.FOTO3), 0) AS FOTO_BYTES
             FROM {$tbl} e
             LEFT JOIN USUARIOSTERMINAL u ON u.ID = e.{$cId}
             WHERE e.{$cEst} = ?"
        );
        $stmt->execute([$altaInt]);

        $map = [];
        foreach ($stmt->fetchAll() as $r) {
            $bytes = (int)($r['FOTO_BYTES'] ?? 0);
            if ($bytes > 100) {
                $map[(int)$r['CLAVE']] = $bytes;
            }
        }
        return $map;
    }

    /**
     * Traduce el tipo de evento de la app al catálogo TIPOCHECADA de Acceso 3 TITANIUM:
     * 1 = Entrada, 2 = Comida (Inicio), 3 = Salida, 4 = Comida (Fin).
     */
    private function resolveTipoChecadaCode(PDO $fb, int $claveEmpleado, string $fechaHoraServidor, string $tipoEvento): int
    {
        return match (strtoupper($tipoEvento)) {
            'ENTRADA' => 1,
            'COMIDA_INI' => 2,
            'SALIDA' => 3,
            'COMIDA_FIN' => 4,
            default => $this->inferAutoTipoChecada($fb, $claveEmpleado, $fechaHoraServidor),
        };
    }

    private function inferAutoTipoChecada(PDO $fb, int $claveEmpleado, string $fechaHoraServidor): int
    {
        $dayStart = substr($fechaHoraServidor, 0, 10) . ' 00:00:00';
        $dayEnd = substr($fechaHoraServidor, 0, 10) . ' 23:59:59';
        $stmt = $fb->prepare(
            'SELECT COUNT(*) FROM CHECADAS WHERE ID = ? AND FECHA BETWEEN ? AND ?'
        );
        $stmt->execute([$claveEmpleado, $dayStart, $dayEnd]);
        $countToday = (int)$stmt->fetchColumn();
        return $countToday === 0 ? 1 : 3; // 1 = Entrada, 3 = Salida
    }

    /**
     * Comprime una imagen a JPEG compacto (máx. 360px de ancho, calidad 78) para
     * que Acceso 3 TITANIUM la muestre rápido en "Reporte Checadas -> Mostrar Foto"
     * sin inflar innecesariamente el tamaño de ACCESODB.FDB.
     */
    private function normalizePunchPhotoJpeg(?string $rawBytes): ?string
    {
        if ($rawBytes === null || strlen($rawBytes) < 100) {
            return null;
        }
        if (function_exists('imagecreatefromstring') && function_exists('imagejpeg')) {
            $img = @imagecreatefromstring($rawBytes);
            if ($img !== false) {
                $w = imagesx($img);
                $h = imagesy($img);
                $maxW = 360;
                $target = $img;
                if ($w > $maxW && $h > 0) {
                    $newW = $maxW;
                    $newH = max(1, (int)round($h * ($maxW / $w)));
                    $resized = imagecreatetruecolor($newW, $newH);
                    if ($resized !== false) {
                        imagecopyresampled($resized, $img, 0, 0, 0, 0, $newW, $newH, $w, $h);
                        $target = $resized;
                    }
                }
                ob_start();
                imagejpeg($target, null, 78);
                $jpeg = ob_get_clean();
                if ($target !== $img) {
                    imagedestroy($target);
                }
                imagedestroy($img);
                if (is_string($jpeg) && strlen($jpeg) > 100) {
                    return $jpeg;
                }
            }
        }
        // Si GD no está disponible, aceptar solo si ya tiene firma JPEG válida (FF D8 FF)
        return str_starts_with($rawBytes, "\xFF\xD8\xFF") ? $rawBytes : null;
    }

    /**
     * Inserta una checada validada en Acceso 3 RH TITANIUM (Vía A — Firebird 2.5).
     * Utiliza los generadores oficiales NUMCHECADA y NUMFOTO:
     * - Guarda la fotografía de la checada en CHECADAS.FOTO y en la tabla FOTOS de TITANIUM.
     * - VERIFICACION = 15 (Face) para reconocimiento facial
     * - VERIFICACION = 1  (FP)   para huella digital WebAuthn
     */
    public function insertAttendance(
        int $claveEmpleado,
        string $fechaHoraServidor,
        string $tipoEvento,
        string $serial = 'MOVIL-WEB',
        string $ip = '127.0.0.1',
        string $metodoVerificacion = 'FACIAL',
        ?string $photoBytes = null
    ): int {
        if ($this->mode === 'demo') {
            $stmt = $this->localPdo->prepare(
                'INSERT INTO demo_titanium_checadas (clave_empleado, fecha_hora, tipo_evento, origen)
                 VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([
                $claveEmpleado,
                $fechaHoraServidor,
                $tipoEvento,
                $metodoVerificacion === 'HUELLA' ? 'HUELLA_MOVIL' : $this->cfg['val_origen_movil'],
            ]);
            return (int)$this->localPdo->lastInsertId();
        }

        $fb = $this->firebird();
        $tipoCode = $this->resolveTipoChecadaCode($fb, $claveEmpleado, $fechaHoraServidor, $tipoEvento);
        $estacion = (int)($this->cfg['val_estacion'] ?? 0);
        $verifCode = strtoupper($metodoVerificacion) === 'HUELLA' ? 1 : 15; // 1 = FP, 15 = Face
        $modelo = strtoupper($metodoVerificacion) === 'HUELLA' ? 'CELULAR-HUELLA' : 'CELULAR-ESCANER';
        $serialClean = substr($serial, 0, 60);
        $ipClean = substr($ip, 0, 200);
        $jpegPhoto = $this->normalizePunchPhotoJpeg($photoBytes);

        if ($fb->inTransaction()) {
            $fb->commit();
        }
        // Idempotencia: evitar duplicar la misma checada (ID + FECHA) en TITANIUM si hubo un reintento
        $dupStmt = $fb->prepare('SELECT NUMCHECADA FROM CHECADAS WHERE ID = ? AND FECHA = ?');
        $dupStmt->execute([$claveEmpleado, $fechaHoraServidor]);
        $existingNum = $dupStmt->fetchColumn();
        if ($existingNum !== false && (int)$existingNum > 0) {
            return (int)$existingNum;
        }
        if ($fb->inTransaction()) {
            $fb->commit();
        }
        $fb->beginTransaction();
        try {
            $numChecada = (int)$fb->query('SELECT GEN_ID(NUMCHECADA, 1) FROM RDB$DATABASE')->fetchColumn();
            $sql = 'INSERT INTO CHECADAS
                    (NUMCHECADA, ID, STATUS, FECHA, TIPOCHECADA, VERIFICACION, WORKCODE,
                     ID_PROGRAMA, ID_PROGRAMADIA, ID_PROCESO, ENVIOS, FECHAENVIO, TIPOENVIO,
                     ESTACION, SERIAL, MODELO, IP, FOTO)
                    VALUES (?, ?, 0, ?, ?, ?, 0, 0, 0, 0, 0, ?, 0, ?, ?, ?, ?, ?)';
            $stmt = $fb->prepare($sql);
            $stmt->bindValue(1, $numChecada, PDO::PARAM_INT);
            $stmt->bindValue(2, $claveEmpleado, PDO::PARAM_INT);
            $stmt->bindValue(3, $fechaHoraServidor, PDO::PARAM_STR);
            $stmt->bindValue(4, $tipoCode, PDO::PARAM_INT);
            $stmt->bindValue(5, $verifCode, PDO::PARAM_INT);
            $stmt->bindValue(6, $fechaHoraServidor, PDO::PARAM_STR);
            $stmt->bindValue(7, $estacion, PDO::PARAM_INT);
            $stmt->bindValue(8, $serialClean, PDO::PARAM_STR);
            $stmt->bindValue(9, $modelo, PDO::PARAM_STR);
            $stmt->bindValue(10, $ipClean, PDO::PARAM_STR);
            if ($jpegPhoto !== null) {
                $stmt->bindValue(11, $jpegPhoto, PDO::PARAM_LOB);
            } else {
                $stmt->bindValue(11, null, PDO::PARAM_NULL);
            }
            $stmt->execute();

            // También registrar la evidencia fotográfica en la tabla FOTOS de TITANIUM (usada por visor de fotos)
            if ($jpegPhoto !== null) {
                try {
                    $numFoto = (int)$fb->query('SELECT GEN_ID(NUMFOTO, 1) FROM RDB$DATABASE')->fetchColumn();
                    $fStmt = $fb->prepare('INSERT INTO FOTOS (NUMFOTO, ID, FECHA, SERIAL, FOTO) VALUES (?, ?, ?, ?, ?)');
                    $fStmt->bindValue(1, $numFoto, PDO::PARAM_INT);
                    $fStmt->bindValue(2, $claveEmpleado, PDO::PARAM_INT);
                    $fStmt->bindValue(3, $fechaHoraServidor, PDO::PARAM_STR);
                    $fStmt->bindValue(4, $serialClean, PDO::PARAM_STR);
                    $fStmt->bindValue(5, $jpegPhoto, PDO::PARAM_LOB);
                    $fStmt->execute();
                } catch (Throwable) {
                    // Si una versión distinta de TITANIUM no usa tabla FOTOS, CHECADAS.FOTO ya quedó guardada
                }
            }

            $fb->commit();
            return $numChecada;
        } catch (Throwable $e) {
            if ($fb->inTransaction()) {
                $fb->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Comprueba conectividad actual con TITANIUM.
     */
    public function healthCheck(): array
    {
        try {
            if ($this->mode === 'demo') {
                $count = (int)$this->localPdo->query('SELECT COUNT(*) FROM demo_titanium_empleados')->fetchColumn();
                return ['ok' => true, 'mode' => 'demo', 'employees_count' => $count];
            }
            $fb = $this->firebird();
            $count = (int)$fb->query('SELECT COUNT(*) FROM EMPLEADOS')->fetchColumn();
            return [
                'ok' => true,
                'mode' => 'firebird',
                'host' => $this->cfg['host'] . ':' . $this->cfg['port'],
                'database' => basename((string)$this->cfg['database']),
                'employees_count' => $count,
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'mode' => $this->mode, 'error' => $e->getMessage()];
        }
    }
}
