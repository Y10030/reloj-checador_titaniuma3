<?php
declare(strict_types=1);

/**
 * Orquestador principal de vinculación de dispositivos, enrolamiento facial,
 * validación de checadas (12 controles de servidor) y sincronización Vía A con TITANIUM.
 */
final class AttendanceService
{
    private const LIVENESS_CHALLENGES = [
        'MANTEN_ROSTRO_FRENTE',
        'PARPADEA_SUAVE',
        'GIRA_LEVEMENTE_IZQUIERDA',
        'GIRA_LEVEMENTE_DERECHA',
    ];

    public function __construct(
        private PDO $pdo,
        private CryptoService $crypto,
        private GeoService $geo,
        private TitaniumConnector $titanium,
        private FacialClientInterface $facial,
        private AuditService $audit,
        private array $config
    ) {
    }

    /**
     * Crea una invitación de un solo uso para vincular un celular escáner o individual.
     */
    public function createDeviceInvite(string $alias, string $tipoModo, ?int $claveEmpleado = null, int $ttlHours = 24): array
    {
        $alias = trim($alias);
        if ($alias === '') {
            throw new InvalidArgumentException('El alias del dispositivo es obligatorio.');
        }
        if (!in_array($tipoModo, ['ESCANER', 'INDIVIDUAL'], true)) {
            throw new InvalidArgumentException('Modo de dispositivo inválido.');
        }
        if ($tipoModo === 'INDIVIDUAL') {
            if ($claveEmpleado === null || $claveEmpleado <= 0) {
                throw new InvalidArgumentException('Debe asignar una Clave de empleado para modo INDIVIDUAL.');
            }
            $emp = $this->titanium->findEmployee($claveEmpleado);
            if (!$this->titanium->isEmployeeActive($emp)) {
                throw new InvalidArgumentException('El empleado no existe o no tiene estatus Alta en TITANIUM.');
            }
        } else {
            $claveEmpleado = null;
        }

        $codigo = CryptoService::inviteCode();
        $expira = date('Y-m-d H:i:s', time() + ($ttlHours * 3600));
        $ahora = date('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare(
            'INSERT INTO invitaciones_dispositivos (codigo, alias_dispositivo, tipo_modo, clave_empleado_asignado, expira_en, usado, creado_en)
             VALUES (?, ?, ?, ?, ?, 0, ?)'
        );
        $stmt->execute([$codigo, $alias, $tipoModo, $claveEmpleado, $expira, $ahora]);

        return [
            'id_invitacion' => (int)$this->pdo->lastInsertId(),
            'codigo' => $codigo,
            'alias_dispositivo' => $alias,
            'tipo_modo' => $tipoModo,
            'clave_empleado_asignado' => $claveEmpleado,
            'expira_en' => $expira,
        ];
    }

    /**
     * Completa la vinculación de un celular usando código de invitación + clave pública ECDSA P-256.
     */
    public function registerDevice(string $codigo, string $publicKeyPem): array
    {
        $codigo = strtoupper(trim($codigo));
        $publicKeyPem = trim($publicKeyPem);

        if (openssl_pkey_get_public($publicKeyPem) === false) {
            throw new InvalidArgumentException('Clave pública ECDSA P-256 inválida.');
        }

        $stmt = $this->pdo->prepare(
            'SELECT * FROM invitaciones_dispositivos WHERE codigo = ? AND usado = 0'
        );
        $stmt->execute([$codigo]);
        $inv = $stmt->fetch();

        if (!$inv || (string)$inv['expira_en'] < date('Y-m-d H:i:s')) {
            $this->audit->logRejection('DISPOSITIVO_NO_AUTORIZADO', null, null, null, null, 'Código de invitación inválido o expirado: ' . $codigo);
            throw new RuntimeException('Código de invitación inválido o expirado.');
        }

        $deviceUuid = CryptoService::uuidV4();
        $ahora = date('Y-m-d H:i:s');

        $this->pdo->beginTransaction();
        try {
            $upd = $this->pdo->prepare('UPDATE invitaciones_dispositivos SET usado = 1 WHERE id_invitacion = ? AND usado = 0');
            $upd->execute([(int)$inv['id_invitacion']]);
            if ($upd->rowCount() !== 1) {
                throw new RuntimeException('El código de invitación ya fue utilizado.');
            }

            $ins = $this->pdo->prepare(
                'INSERT INTO dispositivos_autorizados
                 (device_uuid, alias_dispositivo, tipo_modo, clave_empleado_asignado, clave_publica_pem, estado, fecha_registro)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([
                $deviceUuid,
                (string)$inv['alias_dispositivo'],
                (string)$inv['tipo_modo'],
                $inv['clave_empleado_asignado'] !== null ? (int)$inv['clave_empleado_asignado'] : null,
                $publicKeyPem,
                'ACTIVO',
                $ahora,
            ]);
            $idDispositivo = (int)$this->pdo->lastInsertId();
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return [
            'id_dispositivo' => $idDispositivo,
            'device_uuid' => $deviceUuid,
            'alias_dispositivo' => (string)$inv['alias_dispositivo'],
            'tipo_modo' => (string)$inv['tipo_modo'],
            'clave_empleado_asignado' => $inv['clave_empleado_asignado'] !== null ? (int)$inv['clave_empleado_asignado'] : null,
            'estado' => 'ACTIVO',
        ];
    }

    /**
     * Emite un desafío aleatorio (nonce) y reto de vida para iniciar una checada.
     */
    public function issueChallenge(string $deviceUuid): array
    {
        $device = $this->findActiveDevice($deviceUuid);
        if ($device === null) {
            $this->audit->logRejection('DISPOSITIVO_NO_AUTORIZADO', null, null, null, null, 'Intento de challenge con UUID no activo.');
            throw new RuntimeException('Dispositivo no autorizado o revocado.');
        }

        $nonce = CryptoService::randomHex(32);
        $ttl = (int)$this->config['security']['nonce_ttl'];
        $expira = date('Y-m-d H:i:s', time() + $ttl);
        $reto = self::LIVENESS_CHALLENGES[random_int(0, count(self::LIVENESS_CHALLENGES) - 1)];

        $stmt = $this->pdo->prepare(
            'INSERT INTO nonces_dispositivos (nonce, id_dispositivo, reto_liveness, expira_en, usado, creado_en)
             VALUES (?, ?, ?, ?, 0, ?)'
        );
        $stmt->execute([$nonce, (int)$device['id_dispositivo'], $reto, $expira, date('Y-m-d H:i:s')]);

        return [
            'nonce' => $nonce,
            'reto_liveness' => $reto,
            'expira_en' => $expira,
            'device' => [
                'alias_dispositivo' => (string)$device['alias_dispositivo'],
                'tipo_modo' => (string)$device['tipo_modo'],
                'clave_empleado_asignado' => $device['clave_empleado_asignado'] !== null ? (int)$device['clave_empleado_asignado'] : null,
            ],
        ];
    }

    /**
     * Enrola el rostro de un empleado activo de TITANIUM (cifrado AES-256-GCM en servidor).
     *
     * @param string[] $imagesBase64
     */
    public function enrollEmployeeFace(int $claveEmpleado, array $imagesBase64): array
    {
        if (empty($imagesBase64)) {
            throw new InvalidArgumentException('Se requiere al menos una fotografía del rostro para el enrolamiento.');
        }

        $emp = $this->titanium->findEmployee($claveEmpleado);
        if (!$this->titanium->isEmployeeActive($emp)) {
            throw new RuntimeException('El empleado no existe o no tiene estatus Alta en Acceso 3 TITANIUM.');
        }

        $extract = $this->facial->extract($imagesBase64);
        if (empty($extract['ok']) || empty($extract['embedding']) || !is_array($extract['embedding'])) {
            $reason = (string)($extract['message'] ?? 'No se pudo extraer una plantilla facial válida.');
            throw new RuntimeException($reason);
        }

        $encrypted = $this->crypto->encryptEmbedding($extract['embedding']);
        $modelVersion = (string)($extract['model_version'] ?? $this->config['facial']['model_version']);
        $ahora = date('Y-m-d H:i:s');

        $this->pdo->beginTransaction();
        try {
            $deact = $this->pdo->prepare("UPDATE plantillas_faciales SET estado = 'INACTIVA' WHERE clave_empleado = ? AND estado = 'ACTIVA'");
            $deact->execute([$claveEmpleado]);

            $ins = $this->pdo->prepare(
                'INSERT INTO plantillas_faciales (clave_empleado, embedding_cifrado, iv_cifrado, tag_cifrado, modelo_version, estado, fecha_enrolamiento)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([
                $claveEmpleado,
                $encrypted['ciphertext'],
                $encrypted['iv'],
                $encrypted['tag'],
                $modelVersion,
                'ACTIVA',
                $ahora,
            ]);
            $idPlantilla = (int)$this->pdo->lastInsertId();
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return [
            'id_plantilla' => $idPlantilla,
            'clave_empleado' => $claveEmpleado,
            'nombre_completo' => $emp['nombre_completo'],
            'modelo_version' => $modelVersion,
            'fecha_enrolamiento' => $ahora,
        ];
    }

    /**
     * Sincroniza en memoria el vector facial de un empleado leyendo ÚNICAMENTE su foto oficial
     * almacenada en Acceso 3 TITANIUM (USUARIOSTERMINAL / HVUSUARIOSTERMINAL).
     * Nunca guarda archivos de imagen en la web; solo mantiene la caché vectorial cifrada AES-256-GCM
     * vinculada al SHA-256 exacto de la foto en TITANIUM.
     */
    public function syncEmployeeFaceFromTitanium(int $claveEmpleado): ?array
    {
        if ($this->titanium->mode() === 'demo') {
            return $this->loadActiveTemplate($claveEmpleado);
        }

        $emp = $this->titanium->findEmployee($claveEmpleado);
        if (!$this->titanium->isEmployeeActive($emp)) {
            $this->pdo->prepare("UPDATE plantillas_faciales SET estado = 'REVOCADA' WHERE clave_empleado = ? AND estado = 'ACTIVA'")
                ->execute([$claveEmpleado]);
            return null;
        }

        $photoBin = $this->titanium->getEmployeePhoto($claveEmpleado);
        if ($photoBin === null) {
            $this->pdo->prepare("UPDATE plantillas_faciales SET estado = 'REVOCADA' WHERE clave_empleado = ? AND estado = 'ACTIVA'")
                ->execute([$claveEmpleado]);
            return null;
        }

        $hash = hash('sha256', $photoBin);
        $bytes = strlen($photoBin);

        // Si ya tenemos el vector cifrado exacto para esta misma foto de TITANIUM, usarlo al instante
        $cur = $this->pdo->prepare(
            "SELECT clave_empleado, embedding_cifrado, iv_cifrado, tag_cifrado, source_hash
             FROM plantillas_faciales WHERE clave_empleado = ? AND estado = 'ACTIVA'
             ORDER BY id_plantilla DESC LIMIT 1"
        );
        $cur->execute([$claveEmpleado]);
        $row = $cur->fetch();
        if ($row && (string)($row['source_hash'] ?? '') === $hash) {
            return [
                'clave_empleado' => $claveEmpleado,
                'embedding' => $this->crypto->decryptEmbedding((string)$row['embedding_cifrado'], (string)$row['iv_cifrado'], (string)$row['tag_cifrado']),
                'updated' => false,
            ];
        }

        $b64 = 'data:image/jpeg;base64,' . base64_encode($photoBin);
        $extract = $this->facial->extract([$b64], true);
        if (empty($extract['ok']) || empty($extract['embedding']) || !is_array($extract['embedding'])) {
            $this->pdo->prepare("UPDATE plantillas_faciales SET estado = 'REVOCADA' WHERE clave_empleado = ? AND estado = 'ACTIVA'")
                ->execute([$claveEmpleado]);
            return null;
        }

        $encrypted = $this->crypto->encryptEmbedding($extract['embedding']);
        $modelVersion = 'titanium:' . (string)($extract['model_version'] ?? $this->config['facial']['model_version']);
        $ahora = date('Y-m-d H:i:s');

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare("UPDATE plantillas_faciales SET estado = 'INACTIVA' WHERE clave_empleado = ? AND estado = 'ACTIVA'")
                ->execute([$claveEmpleado]);
            $this->pdo->prepare(
                'INSERT INTO plantillas_faciales
                 (clave_empleado, embedding_cifrado, iv_cifrado, tag_cifrado, modelo_version, estado, fecha_enrolamiento, source_hash, source_bytes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $claveEmpleado,
                $encrypted['ciphertext'],
                $encrypted['iv'],
                $encrypted['tag'],
                $modelVersion,
                'ACTIVA',
                $ahora,
                $hash,
                $bytes,
            ]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return [
            'clave_empleado' => $claveEmpleado,
            'embedding' => $extract['embedding'],
            'updated' => true,
        ];
    }

    /**
     * Sincroniza el catálogo completo de rostros estrictamente desde las fotos de Acceso 3 TITANIUM.
     * - Si $forceFull = false, hace sincronización delta rápida comparando tamaño de foto en TITANIUM (<5ms).
     * - Revoca automáticamente vectores de empleados dados de Baja o sin foto en TITANIUM.
     */
    public function syncAllFacesFromTitanium(bool $forceFull = false): array
    {
        if ($this->titanium->mode() === 'demo') {
            return ['con_foto' => 0, 'listos' => 0, 'actualizados' => 0, 'invalidas' => 0, 'revocados' => 0, 'detalles_invalidas' => []];
        }

        $titMap = $this->titanium->listActiveEmployeePhotoSizes(); // [clave => bytes]
        $cachedRows = $this->pdo->query(
            "SELECT clave_empleado, source_hash, source_bytes FROM plantillas_faciales WHERE estado = 'ACTIVA'"
        )->fetchAll();
        $cachedMap = [];
        foreach ($cachedRows as $cr) {
            $cachedMap[(int)$cr['clave_empleado']] = [
                'hash' => (string)($cr['source_hash'] ?? ''),
                'bytes' => (int)($cr['source_bytes'] ?? 0),
            ];
        }

        // Revocar cualquier plantilla que ya no exista en TITANIUM (Baja o foto borrada o vieja manual sin source_hash)
        $revocados = 0;
        foreach ($cachedMap as $clave => $meta) {
            if (!isset($titMap[$clave]) || $meta['hash'] === '') {
                $this->pdo->prepare("UPDATE plantillas_faciales SET estado = 'REVOCADA' WHERE clave_empleado = ? AND estado = 'ACTIVA'")
                    ->execute([$clave]);
                unset($cachedMap[$clave]);
                $revocados++;
            }
        }

        $actualizados = 0;
        $listos = 0;
        $invalidas = 0;
        $detallesInvalidas = [];

        foreach ($titMap as $clave => $bytes) {
            $alreadySynced = isset($cachedMap[$clave]) && $cachedMap[$clave]['bytes'] === $bytes && $cachedMap[$clave]['hash'] !== '';
            if (!$forceFull && $alreadySynced) {
                $listos++;
                continue;
            }
            $res = $this->syncEmployeeFaceFromTitanium($clave);
            if ($res !== null) {
                $listos++;
                if (!empty($res['updated'])) {
                    $actualizados++;
                }
            } else {
                $invalidas++;
                $detallesInvalidas[] = 'Clave #' . $clave;
            }
        }

        return [
            'con_foto' => count($titMap),
            'listos' => $listos,
            'actualizados' => $actualizados,
            'invalidas' => $invalidas,
            'revocados' => $revocados,
            'detalles_invalidas' => array_slice($detallesInvalidas, 0, 20),
        ];
    }

    /**
     * Consulta un empleado de TITANIUM (con Planta y Departamento) para la vista móvil.
     */
    public function lookupEmployeeForDevice(string $deviceUuid, int $claveEmpleado): array
    {
        $device = $this->findActiveDevice($deviceUuid);
        if ($device === null) {
            throw new RuntimeException('Dispositivo no autorizado o revocado.');
        }
        // Anti-IDOR: un celular en modo INDIVIDUAL únicamente puede consultar su propio empleado asignado
        if ((string)$device['tipo_modo'] === 'INDIVIDUAL' && (int)$device['clave_empleado_asignado'] !== $claveEmpleado) {
            throw new RuntimeException('Acceso denegado: este dispositivo solo puede consultar su empleado asignado.');
        }
        if ($claveEmpleado <= 0) {
            throw new InvalidArgumentException('Clave de empleado inválida.');
        }
        $emp = $this->titanium->findEmployee($claveEmpleado);
        if ($emp === null) {
            throw new RuntimeException('No se encontró un empleado con la clave ' . $claveEmpleado . ' en Acceso 3 TITANIUM.');
        }

        if ($this->titanium->mode() === 'firebird') {
            $hasFace = $this->titanium->getEmployeePhoto($claveEmpleado) !== null;
        } else {
            $faceStmt = $this->pdo->prepare("SELECT COUNT(*) FROM plantillas_faciales WHERE clave_empleado = ? AND estado = 'ACTIVA'");
            $faceStmt->execute([$claveEmpleado]);
            $hasFace = ((int)$faceStmt->fetchColumn()) > 0;
        }

        $fpStmt = $this->pdo->prepare(
            "SELECT credential_id FROM credenciales_huella
             WHERE id_dispositivo = ? AND clave_empleado = ? AND estado = 'ACTIVA'
             ORDER BY id_credencial DESC LIMIT 1"
        );
        $fpStmt->execute([(int)$device['id_dispositivo'], $claveEmpleado]);
        $credId = $fpStmt->fetchColumn();

        return [
            'clave_empleado' => $emp['clave_empleado'],
            'nombre_completo' => $emp['nombre_completo'],
            'estatus' => $emp['estatus'],
            'activo' => $this->titanium->isEmployeeActive($emp),
            'planta' => (string)($emp['planta'] ?? 'Sin planta asignada'),
            'departamento' => (string)($emp['departamento'] ?? 'Sin departamento'),
            'tiene_rostro' => $hasFace,
            'tiene_huella' => $credId !== false,
            'credential_id' => $credId !== false ? (string)$credId : null,
            'checadas_hoy' => $this->getTodayPunches((int)$device['id_dispositivo'], $claveEmpleado),
        ];
    }

    /**
     * Registra una credencial WebAuthn de Huella Digital del celular para un empleado activo.
     * Exige nonce vigente y firma criptográfica ECDSA P-256 del dispositivo autorizado.
     */
    public function registerFingerprint(
        string $deviceUuid,
        int $claveEmpleado,
        string $credentialId,
        ?string $publicKeyPem = null,
        string $nonce = '',
        string $signatureB64 = ''
    ): array {
        $device = $this->findActiveDevice($deviceUuid);
        if ($device === null) {
            throw new RuntimeException('Dispositivo no autorizado o revocado.');
        }
        $deviceId = (int)$device['id_dispositivo'];

        if ((string)$device['tipo_modo'] === 'INDIVIDUAL' && (int)$device['clave_empleado_asignado'] !== $claveEmpleado) {
            throw new RuntimeException('Este celular individual solo puede activar huella para su empleado asignado.');
        }

        // Verificar nonce de un solo uso y firma del dispositivo para evitar secuestro de credencial
        $nonceRow = $this->consumeNonce($nonce, $deviceId);
        if ($nonceRow === null) {
            $this->audit->logRejection('DISPOSITIVO_NO_AUTORIZADO', $deviceId, $claveEmpleado, null, null, 'Registro de huella sin nonce válido.');
            throw new RuntimeException('Sesión de registro de huella expirada. Intente nuevamente.');
        }
        $signedPayload = implode('|', ['FP_REG', $deviceUuid, $nonce, (string)$claveEmpleado, trim($credentialId)]);
        if (!$this->crypto->verifyEcdsaSignature((string)$device['clave_publica_pem'], $signedPayload, $signatureB64)) {
            $this->audit->logRejection('DISPOSITIVO_NO_AUTORIZADO', $deviceId, $claveEmpleado, null, null, 'Firma Web Crypto inválida al activar huella.');
            throw new RuntimeException('Firma criptográfica del dispositivo inválida.');
        }

        $emp = $this->titanium->findEmployee($claveEmpleado);
        if (!$this->titanium->isEmployeeActive($emp)) {
            throw new RuntimeException('El empleado no existe o no está en estatus Alta en Acceso 3 TITANIUM.');
        }
        $credentialId = trim($credentialId);
        if ($credentialId === '' || strlen($credentialId) > 255) {
            throw new InvalidArgumentException('Credencial WebAuthn de huella inválida.');
        }

        $ahora = date('Y-m-d H:i:s');
        $pemClean = ($publicKeyPem !== null && trim($publicKeyPem) !== '' && openssl_pkey_get_public($publicKeyPem) !== false)
            ? trim($publicKeyPem)
            : null;

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                "UPDATE credenciales_huella SET estado = 'REVOCADA'
                 WHERE id_dispositivo = ? AND clave_empleado = ? AND estado = 'ACTIVA'"
            )->execute([$deviceId, $claveEmpleado]);

            $this->pdo->prepare(
                'INSERT INTO credenciales_huella (id_dispositivo, clave_empleado, credential_id, public_key_pem, estado, fecha_registro, sign_count)
                 VALUES (?, ?, ?, ?, ?, ?, 0)'
            )->execute([$deviceId, $claveEmpleado, $credentialId, $pemClean, 'ACTIVA', $ahora]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return [
            'clave_empleado' => $claveEmpleado,
            'nombre_completo' => $emp['nombre_completo'],
            'planta' => (string)($emp['planta'] ?? 'Sin planta asignada'),
            'credential_id' => $credentialId,
            'fecha_registro' => $ahora,
        ];
    }

    /**
     * Obtiene las checadas aprobadas de hoy para el celular o un empleado específico.
     */
    public function getTodayPunches(int $deviceId, ?int $claveEmpleado = null): array
    {
        $hoy = date('Y-m-d') . ' 00:00:00';
        if ($claveEmpleado !== null && $claveEmpleado > 0) {
            $stmt = $this->pdo->prepare(
                'SELECT id_checada, clave_empleado, nombre_empleado, planta, tipo_evento, metodo_verificacion, fecha_hora_servidor, precision_gps_metros, estado_sync_titanium
                 FROM registros_checadas
                 WHERE clave_empleado = ? AND fecha_hora_servidor >= ?
                 ORDER BY id_checada DESC LIMIT 15'
            );
            $stmt->execute([$claveEmpleado, $hoy]);
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT id_checada, clave_empleado, nombre_empleado, planta, tipo_evento, metodo_verificacion, fecha_hora_servidor, precision_gps_metros, estado_sync_titanium
                 FROM registros_checadas
                 WHERE id_dispositivo = ? AND fecha_hora_servidor >= ?
                 ORDER BY id_checada DESC LIMIT 15'
            );
            $stmt->execute([$deviceId, $hoy]);
        }
        return $stmt->fetchAll();
    }

    /**
     * Decodifica Base64URL estándar de WebAuthn.
     */
    private static function base64UrlDecode(string $input): string
    {
        $pad = strlen($input) % 4;
        if ($pad > 0) {
            $input .= str_repeat('=', 4 - $pad);
        }
        $dec = base64_decode(strtr($input, '-_', '+/'), true);
        return $dec === false ? '' : $dec;
    }

    /**
     * Ejecuta el pipeline completo de validaciones de una checada y registra en TITANIUM (Vía A).
     */
    public function processPunch(array $input): array
    {
        $deviceUuid = trim((string)($input['device_uuid'] ?? ''));
        $nonce = trim((string)($input['nonce'] ?? ''));
        $requestUuid = trim((string)($input['request_uuid'] ?? ''));
        $signatureB64 = trim((string)($input['signature'] ?? ''));
        $tipoEvento = strtoupper(trim((string)($input['tipo_evento'] ?? 'AUTO')));
        $metodoVerif = strtoupper(trim((string)($input['metodo_verificacion'] ?? 'FACIAL')));
        if (!in_array($metodoVerif, ['FACIAL', 'HUELLA'], true)) {
            $metodoVerif = 'FACIAL';
        }
        $nota = trim((string)($input['nota'] ?? ''));
        $nota = $nota !== '' ? substr($nota, 0, 160) : null;
        $claveManual = isset($input['clave_empleado']) && $input['clave_empleado'] !== '' ? (int)$input['clave_empleado'] : null;
        $lat = isset($input['latitude']) && is_numeric($input['latitude']) ? (float)$input['latitude'] : null;
        $lon = isset($input['longitude']) && is_numeric($input['longitude']) ? (float)$input['longitude'] : null;
        $acc = isset($input['accuracy']) && is_numeric($input['accuracy']) ? (float)$input['accuracy'] : null;
        $images = isset($input['images_base64']) && is_array($input['images_base64']) ? array_values($input['images_base64']) : [];

        if (!in_array($tipoEvento, ['ENTRADA', 'SALIDA', 'COMIDA_INI', 'COMIDA_FIN', 'AUTO'], true)) {
            $tipoEvento = 'AUTO';
        }
        if ($requestUuid === '') {
            $requestUuid = CryptoService::uuidV4();
        }

        $ip = $this->audit->clientIp();

        // 1. Validar dispositivo autorizado
        $device = $this->findActiveDevice($deviceUuid);
        if ($device === null) {
            $this->audit->logRejection('DISPOSITIVO_NO_AUTORIZADO', null, $claveManual, null, null, 'Dispositivo desconocido o revocado.', $ip);
            return $this->rejectResponse('DISPOSITIVO_NO_AUTORIZADO', 'Dispositivo no autorizado o revocado.');
        }
        $deviceId = (int)$device['id_dispositivo'];

        // 2. Validar nonce (anti-repetición) y firma criptográfica Web Crypto
        $nonceRow = $this->consumeNonce($nonce, $deviceId);
        if ($nonceRow === null) {
            $this->audit->logRejection('DISPOSITIVO_NO_AUTORIZADO', $deviceId, $claveManual, null, null, 'Nonce inválido, usado o expirado.', $ip);
            return $this->rejectResponse('DISPOSITIVO_NO_AUTORIZADO', 'La sesión de checada expiró. Intente nuevamente.');
        }

        $signedPayload = implode('|', [
            $deviceUuid,
            $nonce,
            $requestUuid,
            $lat !== null ? number_format($lat, 6, '.', '') : 'null',
            $lon !== null ? number_format($lon, 6, '.', '') : 'null',
        ]);

        if (!$this->crypto->verifyEcdsaSignature((string)$device['clave_publica_pem'], $signedPayload, $signatureB64)) {
            $this->audit->logRejection('DISPOSITIVO_NO_AUTORIZADO', $deviceId, $claveManual, null, null, 'Firma Web Crypto inválida.', $ip);
            return $this->rejectResponse('DISPOSITIVO_NO_AUTORIZADO', 'Firma criptográfica del dispositivo inválida.');
        }

        // 4. Control de idempotencia (request_uuid)
        $dupReq = $this->pdo->prepare('SELECT id_checada, fecha_hora_servidor, nombre_empleado FROM registros_checadas WHERE request_uuid = ?');
        $dupReq->execute([$requestUuid]);
        if ($existing = $dupReq->fetch()) {
            $this->audit->logRejection('DUPLICADO', $deviceId, $claveManual, true, null, 'request_uuid duplicado: ' . $requestUuid, $ip);
            return $this->rejectResponse('DUPLICADO', 'Esta solicitud de checada ya fue procesada previamente.');
        }

        // 5. Validación geográfica estricta (BLOQUEO fuera de área permitida)
        $geoRes = $this->geo->evaluate($lat, $lon, $acc);
        if (!$geoRes['dentro_area_permitida']) {
            $motivo = (string)($geoRes['motivo_rechazo'] ?? 'FUERA_DE_AREA');
            $this->audit->logRejection(
                $motivo,
                $deviceId,
                $claveManual,
                false,
                $geoRes['distancia_metros'],
                $geoRes['mensaje'],
                $ip
            );
            return [
                'ok' => false,
                'motivo_rechazo' => $motivo,
                'dentro_area_permitida' => false,
                'mensaje' => 'Fuera de área permitida. El registro fue bloqueado.',
            ];
        }

        // 6. Validación biométrica: HUELLA (WebAuthn nativo del celular) o FACIAL (Python InsightFace ONNX)
        $matchedClave = null;
        $puntajeFacial = 0.0;
        $livenessOk = false;

        if ($metodoVerif === 'HUELLA') {
            $webauthn = is_array($input['webauthn'] ?? null) ? $input['webauthn'] : [];
            $credId = trim((string)($webauthn['credential_id'] ?? ''));
            $clientDataB64 = (string)($webauthn['client_data_json'] ?? '');
            $authDataB64 = (string)($webauthn['authenticator_data'] ?? '');
            $waSigB64 = (string)($webauthn['signature'] ?? '');

            $targetClave = ((string)$device['tipo_modo'] === 'INDIVIDUAL')
                ? (int)$device['clave_empleado_asignado']
                : $claveManual;

            if ($targetClave === null || $targetClave <= 0 || $credId === '' || $clientDataB64 === '' || $authDataB64 === '') {
                $this->audit->logRejection('ROSTRO_NO_RECONOCIDO', $deviceId, $targetClave, true, $geoRes['distancia_metros'], 'Datos de huella WebAuthn incompletos.', $ip);
                return $this->rejectResponse('HUELLA_INVALIDA', 'Verificación de huella incompleta. Active su huella digital primero.');
            }

            $credStmt = $this->pdo->prepare(
                "SELECT * FROM credenciales_huella
                 WHERE id_dispositivo = ? AND clave_empleado = ? AND credential_id = ? AND estado = 'ACTIVA' LIMIT 1"
            );
            $credStmt->execute([$deviceId, $targetClave, $credId]);
            $credRow = $credStmt->fetch();
            if (!$credRow) {
                $this->audit->logRejection('DISPOSITIVO_NO_AUTORIZADO', $deviceId, $targetClave, true, $geoRes['distancia_metros'], 'Credencial de huella no registrada en este celular.', $ip);
                return $this->rejectResponse('HUELLA_INVALIDA', 'La huella digital no está activada para este empleado en este celular.');
            }

            // Verificar challenge y origin en clientDataJSON (protección contra phishing cross-origin)
            $clientDataRaw = self::base64UrlDecode($clientDataB64);
            $clientData = json_decode($clientDataRaw, true);
            $expectedChallenge = rtrim(strtr(base64_encode($nonce), '+/', '-_'), '=');
            if (!is_array($clientData) || ($clientData['type'] ?? '') !== 'webauthn.get' || ($clientData['challenge'] ?? '') !== $expectedChallenge) {
                $this->audit->logRejection('DISPOSITIVO_NO_AUTORIZADO', $deviceId, $targetClave, true, $geoRes['distancia_metros'], 'Challenge WebAuthn no coincide con el nonce del servidor.', $ip);
                return $this->rejectResponse('HUELLA_INVALIDA', 'Desafío biométrico de huella inválido o expirado.');
            }

            $origin = (string)($clientData['origin'] ?? '');
            $originHost = (string)(parse_url($origin, PHP_URL_HOST) ?? '');
            $serverHost = (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? '');
            $serverHost = strtolower(explode(':', explode(',', $serverHost)[0])[0]);
            if ($serverHost !== '' && strtolower($originHost) !== $serverHost) {
                $this->audit->logRejection('DISPOSITIVO_NO_AUTORIZADO', $deviceId, $targetClave, true, $geoRes['distancia_metros'], 'Origin WebAuthn no autorizado: ' . $origin, $ip);
                return $this->rejectResponse('HUELLA_INVALIDA', 'Origen WebAuthn no coincide con el servidor.');
            }

            // Verificar authenticatorData: rpIdHash (32B), flags (UP + UV), signCount (4B big-endian)
            $authDataRaw = self::base64UrlDecode($authDataB64);
            if (strlen($authDataRaw) < 37) {
                return $this->rejectResponse('HUELLA_INVALIDA', 'Respuesta del sensor biométrico inválida.');
            }
            if ($serverHost !== '') {
                $rpIdHash = substr($authDataRaw, 0, 32);
                $expectedRpHash = hash('sha256', $serverHost, true);
                if (!hash_equals($expectedRpHash, $rpIdHash)) {
                    $this->audit->logRejection('DISPOSITIVO_NO_AUTORIZADO', $deviceId, $targetClave, true, $geoRes['distancia_metros'], 'rpIdHash WebAuthn inválido.', $ip);
                    return $this->rejectResponse('HUELLA_INVALIDA', 'Identificador de dominio biométrico inválido.');
                }
            }
            $flags = ord($authDataRaw[32]);
            $userPresent = ($flags & 0x01) !== 0;
            $userVerified = ($flags & 0x04) !== 0;
            if (!$userPresent || !$userVerified) {
                $this->audit->logRejection('INTENTO_SUPLANTACION', $deviceId, $targetClave, true, $geoRes['distancia_metros'], 'WebAuthn sin confirmación biométrica UV.', $ip);
                return $this->rejectResponse('HUELLA_INVALIDA', 'El teléfono no confirmó la verificación biométrica de huella.');
            }

            // Anti-clonación de autenticador (signCount)
            $signCountArr = unpack('Ncnt', substr($authDataRaw, 33, 4));
            $newSignCount = (int)($signCountArr['cnt'] ?? 0);
            $prevSignCount = (int)($credRow['sign_count'] ?? 0);
            if ($prevSignCount > 0 && $newSignCount > 0 && $newSignCount <= $prevSignCount) {
                $this->audit->logRejection('INTENTO_SUPLANTACION', $deviceId, $targetClave, true, $geoRes['distancia_metros'], 'Posible autenticador WebAuthn clonado (signCount).', $ip);
                return $this->rejectResponse('HUELLA_INVALIDA', 'Alerta de seguridad: contador biométrico inconsistente.');
            }

            // Verificar criptográficamente la firma WebAuthn con la clave pública registrada
            if (!empty($credRow['public_key_pem'])) {
                if ($waSigB64 === '') {
                    return $this->rejectResponse('HUELLA_INVALIDA', 'Falta firma criptográfica de huella.');
                }
                $signedBytes = $authDataRaw . hash('sha256', $clientDataRaw, true);
                $sigRaw = self::base64UrlDecode($waSigB64);
                if (!$this->crypto->verifyEcdsaSignature((string)$credRow['public_key_pem'], $signedBytes, base64_encode($sigRaw))) {
                    $this->audit->logRejection('DISPOSITIVO_NO_AUTORIZADO', $deviceId, $targetClave, true, $geoRes['distancia_metros'], 'Firma criptográfica WebAuthn inválida.', $ip);
                    return $this->rejectResponse('HUELLA_INVALIDA', 'Firma biométrica de huella inválida.');
                }
            }

            if ($newSignCount > $prevSignCount) {
                $this->pdo->prepare('UPDATE credenciales_huella SET sign_count = ? WHERE id_credencial = ?')
                    ->execute([$newSignCount, (int)$credRow['id_credencial']]);
            }

            $matchedClave = $targetClave;
            $puntajeFacial = 1.0;
            $livenessOk = true;
        } else {
            if (empty($images)) {
                return $this->rejectResponse('CALIDAD_IMAGEN', 'No se recibió captura de cámara.');
            }

            $retoLiveness = (string)$nonceRow['reto_liveness'];
            $targetClave = null;
            if ((string)$device['tipo_modo'] === 'INDIVIDUAL') {
                $targetClave = (int)$device['clave_empleado_asignado'];
            } elseif ($claveManual !== null && $claveManual > 0) {
                $targetClave = $claveManual;
            }

            if ($targetClave !== null) {
                // Verificación 1:1 estrictamente contra la foto vigente en Acceso 3 TITANIUM
                $tpl = $this->titanium->mode() === 'firebird'
                    ? $this->syncEmployeeFaceFromTitanium($targetClave)
                    : $this->loadActiveTemplate($targetClave);
                if ($tpl === null) {
                    $this->audit->logRejection('ROSTRO_NO_RECONOCIDO', $deviceId, $targetClave, true, $geoRes['distancia_metros'], 'Empleado sin foto válida registrada en Acceso 3 TITANIUM.', $ip);
                    return $this->rejectResponse('ROSTRO_NO_RECONOCIDO', 'El empleado no tiene una foto facial válida en Acceso 3 TITANIUM.');
                }
                $res = $this->facial->verify($images, $tpl['embedding'], $retoLiveness);
                $livenessOk = !empty($res['liveness_passed']);
                $puntajeFacial = (float)($res['similarity'] ?? 0.0);

                if (empty($res['quality_ok'])) {
                    return $this->rejectResponse('CALIDAD_IMAGEN', (string)($res['message'] ?? 'Mejore el encuadre o la iluminación e intente de nuevo.'));
                }
                if (!$livenessOk) {
                    $this->audit->logRejection('INTENTO_SUPLANTACION', $deviceId, $targetClave, true, $geoRes['distancia_metros'], (string)($res['message'] ?? 'Fallo liveness 1:1'), $ip);
                    return $this->rejectResponse('INTENTO_SUPLANTACION', 'Prueba de vida no superada. Coloque su rostro real frente a la cámara.');
                }
                if (empty($res['matched'])) {
                    $this->audit->logRejection('ROSTRO_NO_RECONOCIDO', $deviceId, $targetClave, true, $geoRes['distancia_metros'], 'Similitud 1:1 insuficiente: ' . round($puntajeFacial, 4), $ip);
                    return $this->rejectResponse('ROSTRO_NO_RECONOCIDO', 'No se pudo confirmar la identidad facial.');
                }
                $matchedClave = $targetClave;
            } else {
                // Identificación 1:N (Modo Escáner) estrictamente contra fotos vigentes en Acceso 3 TITANIUM
                if ($this->titanium->mode() === 'firebird') {
                    $this->syncAllFacesFromTitanium(false);
                }
                $candidates = $this->loadAllActiveTemplates();
                if (empty($candidates)) {
                    return $this->rejectResponse('ROSTRO_NO_RECONOCIDO', 'No existen empleados con foto válida sincronizada desde Acceso 3 TITANIUM.');
                }
                $res = $this->facial->identify($images, $candidates, $retoLiveness);
                $livenessOk = !empty($res['liveness_passed']);
                $puntajeFacial = (float)($res['similarity'] ?? 0.0);

                if (empty($res['quality_ok'])) {
                    return $this->rejectResponse('CALIDAD_IMAGEN', (string)($res['message'] ?? 'Asegúrese de que aparezca un solo rostro con buena luz.'));
                }
                if (!$livenessOk) {
                    $this->audit->logRejection('INTENTO_SUPLANTACION', $deviceId, null, true, $geoRes['distancia_metros'], (string)($res['message'] ?? 'Fallo liveness 1:N'), $ip);
                    return $this->rejectResponse('INTENTO_SUPLANTACION', 'Prueba de vida no superada. Coloque su rostro real frente a la cámara.');
                }
                if (empty($res['matched']) || empty($res['clave_empleado'])) {
                    $this->audit->logRejection('ROSTRO_NO_RECONOCIDO', $deviceId, null, true, $geoRes['distancia_metros'], (string)($res['message'] ?? 'Sin coincidencia 1:N'), $ip);
                    return $this->rejectResponse('ROSTRO_NO_RECONOCIDO', 'Rostro no reconocido o ambiguo. Intente de nuevo o ingrese su clave.');
                }
                $matchedClave = (int)$res['clave_empleado'];
            }
        }

        // 7. Verificar estatus laboral vigente en Acceso 3 TITANIUM (Alta)
        $emp = $this->titanium->findEmployee($matchedClave);
        if (!$this->titanium->isEmployeeActive($emp)) {
            $this->audit->logRejection('EMPLEADO_INACTIVO', $deviceId, $matchedClave, true, $geoRes['distancia_metros'], 'Empleado inexistente o en Baja en TITANIUM.', $ip);
            return $this->rejectResponse('EMPLEADO_INACTIVO', 'El empleado no se encuentra activo en Acceso 3 TITANIUM.');
        }

        // 8. Control anti-duplicados por ventana anti-rebote
        $debounceMin = (int)$this->config['security']['debounce_minutes'];
        $sinceDebounce = date('Y-m-d H:i:s', time() - ($debounceMin * 60));
        $debStmt = $this->pdo->prepare(
            'SELECT fecha_hora_servidor FROM registros_checadas WHERE clave_empleado = ? AND fecha_hora_servidor >= ? ORDER BY id_checada DESC LIMIT 1'
        );
        $debStmt->execute([$matchedClave, $sinceDebounce]);
        if ($lastPunch = $debStmt->fetch()) {
            $this->audit->logRejection('DUPLICADO', $deviceId, $matchedClave, true, $geoRes['distancia_metros'], 'Checada previa hace menos de ' . $debounceMin . ' min.', $ip);
            return $this->rejectResponse('DUPLICADO', 'Ya existe una checada reciente registrada a las ' . substr((string)$lastPunch['fecha_hora_servidor'], 11, 5) . '.');
        }

        // 9. Hora oficial del servidor + guardado en Base Complementaria
        $horaServidor = date('Y-m-d H:i:s');
        $plantaEmp = (string)($emp['planta'] ?? 'Sin planta asignada');
        $ins = $this->pdo->prepare(
            'INSERT INTO registros_checadas
             (request_uuid, clave_empleado, nombre_empleado, planta, id_dispositivo, id_ubicacion, fecha_hora_servidor,
              tipo_evento, metodo_verificacion, nota, latitud, longitud, precision_gps_metros, distancia_metros,
              dentro_area_permitida, puntaje_facial, liveness_aprobado, estado_sync_titanium)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, 1, ?)'
        );
        $ins->execute([
            $requestUuid,
            $matchedClave,
            (string)$emp['nombre_completo'],
            $plantaEmp,
            $deviceId,
            (int)$geoRes['id_ubicacion'],
            $horaServidor,
            $tipoEvento,
            $metodoVerif,
            $nota,
            $lat,
            $lon,
            $geoRes['precision_gps_metros'],
            $geoRes['distancia_metros'],
            round($puntajeFacial, 4),
            'PENDIENTE',
        ]);
        $idChecada = (int)$this->pdo->lastInsertId();

        $this->pdo->prepare('UPDATE dispositivos_autorizados SET ultimo_uso = ? WHERE id_dispositivo = ?')
            ->execute([$horaServidor, $deviceId]);

        // 10. Sincronización directa Vía A hacia Firebird 2.5 (Acceso 3 TITANIUM, incluyendo foto de la checada)
        $estadoSync = 'PENDIENTE';
        $idTitanium = null;
        $detalleSync = null;

        $punchPhotoBytes = null;
        $rawImgB64 = !empty($images[0])
            ? (string)$images[0]
            : (isset($input['photo_base64']) && is_string($input['photo_base64']) ? $input['photo_base64'] : '');
        if ($rawImgB64 !== '') {
            $cleanB64 = str_contains($rawImgB64, ',') ? explode(',', $rawImgB64, 2)[1] : $rawImgB64;
            $decoded = base64_decode($cleanB64, true);
            if ($decoded !== false && strlen($decoded) > 100) {
                $punchPhotoBytes = $decoded;
            }
        }

        try {
            $idTitanium = $this->titanium->insertAttendance(
                $matchedClave,
                $horaServidor,
                $tipoEvento,
                (string)($device['alias_dispositivo'] ?? 'MOVIL-WEB'),
                $ip,
                $metodoVerif,
                $punchPhotoBytes
            );
            $estadoSync = 'SINCRONIZADO';
            $detalleSync = 'Registrado en TITANIUM (' . $this->titanium->mode() . ')';
        } catch (Throwable $e) {
            $estadoSync = 'PENDIENTE';
            $detalleSync = 'En cola de reintento: ' . substr($e->getMessage(), 0, 200);
        }

        $this->pdo->prepare(
            'UPDATE registros_checadas SET estado_sync_titanium = ?, id_registro_titanium = ?, detalle_sync = ? WHERE id_checada = ?'
        )->execute([$estadoSync, $idTitanium, $detalleSync, $idChecada]);

        return [
            'ok' => true,
            'id_checada' => $idChecada,
            'clave_empleado' => $matchedClave,
            'nombre_empleado' => (string)$emp['nombre_completo'],
            'planta' => $plantaEmp,
            'departamento' => (string)($emp['departamento'] ?? 'Sin departamento'),
            'fecha_hora_servidor' => $horaServidor,
            'tipo_evento' => $tipoEvento,
            'metodo_verificacion' => $metodoVerif,
            'dentro_area_permitida' => true,
            'estado_sync_titanium' => $estadoSync,
            'mensaje' => 'Checada registrada exitosamente.',
        ];
    }

    /**
     * Reintenta enviar a Acceso 3 TITANIUM todas las checadas en estado PENDIENTE o ERROR.
     * Preserva el dispositivo y el método biométrico original (Rostro / Huella).
     */
    public function retryPendingSync(): array
    {
        $rows = $this->pdo->query(
            "SELECT r.id_checada, r.clave_empleado, r.fecha_hora_servidor, r.tipo_evento,
                    r.metodo_verificacion, d.alias_dispositivo
             FROM registros_checadas r
             LEFT JOIN dispositivos_autorizados d ON d.id_dispositivo = r.id_dispositivo
             WHERE r.estado_sync_titanium IN ('PENDIENTE','ERROR')
             ORDER BY r.id_checada ASC LIMIT 100"
        )->fetchAll();

        $synced = 0;
        $failed = 0;

        foreach ($rows as $row) {
            try {
                $idTit = $this->titanium->insertAttendance(
                    (int)$row['clave_empleado'],
                    (string)$row['fecha_hora_servidor'],
                    (string)$row['tipo_evento'],
                    (string)($row['alias_dispositivo'] ?: 'MOVIL-WEB'),
                    '127.0.0.1',
                    (string)($row['metodo_verificacion'] ?: 'FACIAL')
                );
                $this->pdo->prepare(
                    "UPDATE registros_checadas SET estado_sync_titanium = 'SINCRONIZADO', id_registro_titanium = ?, detalle_sync = 'Sincronizado en reintento' WHERE id_checada = ?"
                )->execute([$idTit, (int)$row['id_checada']]);
                $synced++;
            } catch (Throwable $e) {
                $this->pdo->prepare(
                    "UPDATE registros_checadas SET estado_sync_titanium = 'ERROR', detalle_sync = ? WHERE id_checada = ?"
                )->execute([substr($e->getMessage(), 0, 220), (int)$row['id_checada']]);
                $failed++;
            }
        }

        return ['processed' => count($rows), 'synced' => $synced, 'failed' => $failed];
    }

    public function findActiveDevice(string $deviceUuid): ?array
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $deviceUuid)) {
            return null;
        }
        $stmt = $this->pdo->prepare("SELECT * FROM dispositivos_autorizados WHERE device_uuid = ? AND estado = 'ACTIVO'");
        $stmt->execute([$deviceUuid]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function consumeNonce(string $nonce, int $deviceId): ?array
    {
        if ($nonce === '') {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT * FROM nonces_dispositivos WHERE nonce = ? AND id_dispositivo = ? AND usado = 0'
        );
        $stmt->execute([$nonce, $deviceId]);
        $row = $stmt->fetch();
        if (!$row || (string)$row['expira_en'] < date('Y-m-d H:i:s')) {
            return null;
        }

        $upd = $this->pdo->prepare('UPDATE nonces_dispositivos SET usado = 1 WHERE id_nonce = ? AND usado = 0');
        $upd->execute([(int)$row['id_nonce']]);
        return $upd->rowCount() === 1 ? $row : null;
    }

    private function loadActiveTemplate(int $claveEmpleado): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT clave_empleado, embedding_cifrado, iv_cifrado, tag_cifrado
             FROM plantillas_faciales WHERE clave_empleado = ? AND estado = 'ACTIVA'
             ORDER BY id_plantilla DESC LIMIT 1"
        );
        $stmt->execute([$claveEmpleado]);
        $r = $stmt->fetch();
        if (!$r) {
            return null;
        }
        return [
            'clave_empleado' => (int)$r['clave_empleado'],
            'embedding' => $this->crypto->decryptEmbedding((string)$r['embedding_cifrado'], (string)$r['iv_cifrado'], (string)$r['tag_cifrado']),
        ];
    }

    /**
     * @return array<int, array{clave_empleado:int, embedding:float[]}>
     */
    private function loadAllActiveTemplates(): array
    {
        $rows = $this->pdo->query(
            "SELECT clave_empleado, embedding_cifrado, iv_cifrado, tag_cifrado
             FROM plantillas_faciales WHERE estado = 'ACTIVA'"
        )->fetchAll();

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'clave_empleado' => (int)$r['clave_empleado'],
                'embedding' => $this->crypto->decryptEmbedding((string)$r['embedding_cifrado'], (string)$r['iv_cifrado'], (string)$r['tag_cifrado']),
            ];
        }
        return $out;
    }

    private function rejectResponse(string $code, string $message): array
    {
        return [
            'ok' => false,
            'motivo_rechazo' => $code,
            'mensaje' => $message,
        ];
    }
}
