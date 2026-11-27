<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/SecurityHeaders.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/CryptoService.php';
require_once __DIR__ . '/../src/GeoService.php';
require_once __DIR__ . '/../src/TitaniumConnector.php';
require_once __DIR__ . '/../src/FacialClient.php';
require_once __DIR__ . '/../src/AuditService.php';
require_once __DIR__ . '/../src/AuthService.php';
require_once __DIR__ . '/../src/AttendanceService.php';

SecurityHeaders::applyApi();

const MAX_JSON_BODY_BYTES = 6291456; // 6 MB máximo por solicitud (anti-DoS)
const MAX_IMAGE_COUNT = 3;
const MAX_SINGLE_IMAGE_CHARS = 2800000; // ~2 MB en base64

function jsonOut(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function readJsonBody(): array
{
    $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($len > MAX_JSON_BODY_BYTES) {
        jsonOut(['ok' => false, 'mensaje' => 'El tamaño de la solicitud excede el límite permitido.'], 413);
    }
    $raw = file_get_contents('php://input', false, null, 0, MAX_JSON_BODY_BYTES + 1);
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    if (strlen($raw) > MAX_JSON_BODY_BYTES) {
        jsonOut(['ok' => false, 'mensaje' => 'El tamaño de la solicitud excede el límite permitido.'], 413);
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        jsonOut(['ok' => false, 'mensaje' => 'Cuerpo JSON inválido.'], 400);
    }
    return $decoded;
}

function validateImagesPayload(mixed $images): array
{
    if (!is_array($images)) {
        return [];
    }
    if (count($images) > MAX_IMAGE_COUNT) {
        jsonOut(['ok' => false, 'mensaje' => 'Demasiadas imágenes en una sola solicitud.'], 400);
    }
    $clean = [];
    foreach ($images as $img) {
        if (!is_string($img) || strlen($img) > MAX_SINGLE_IMAGE_CHARS) {
            jsonOut(['ok' => false, 'mensaje' => 'Imagen inválida o demasiado pesada.'], 400);
        }
        $clean[] = $img;
    }
    return $clean;
}

function logInternalError(Throwable $e): void
{
    $logDir = AppConfig::rootDir() . DIRECTORY_SEPARATOR . 'logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0770, true);
    }
    $line = sprintf("[%s] %s in %s:%d\n", date('Y-m-d H:i:s'), $e->getMessage(), $e->getFile(), $e->getLine());
    @file_put_contents($logDir . DIRECTORY_SEPARATOR . 'app_errors.log', $line, FILE_APPEND | LOCK_EX);
}

$isDev = false;

try {
    $config = AppConfig::load();
    $isDev = ($config['app']['env'] ?? 'production') === 'development';
    $db = new Database($config['db'], $config['admin']);
    $pdo = $db->pdo();

    $crypto = new CryptoService($config['app']['encryption_key']);
    $geo = new GeoService($pdo, $config['security']['default_max_gps_accuracy']);
    $titanium = new TitaniumConnector($config['titanium'], $pdo);
    $facial = new FacialClient($config['facial']);
    $audit = new AuditService($pdo, $config['security'], $config['app']['trust_proxy']);
    $auth = new AuthService($pdo);
    $attendance = new AttendanceService($pdo, $crypto, $geo, $titanium, $facial, $audit, $config);

    $action = (string)($_GET['action'] ?? '');
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $clientIp = $audit->clientIp();

    // =========================================================================
    // ENDPOINTS PÚBLICOS / MÓVILES (sin fuga de información interna)
    // =========================================================================
    if ($action === 'health' && $method === 'GET') {
        $assetFiles = [
            __DIR__ . '/index.php',
            __DIR__ . '/admin.php',
            __DIR__ . '/assets/css/scanner.css',
            __DIR__ . '/assets/js/scanner.js',
            __DIR__ . '/assets/js/crypto.js',
            __DIR__ . '/assets/css/admin.css',
            __DIR__ . '/assets/js/admin.js',
        ];
        $mtimes = [];
        foreach ($assetFiles as $af) {
            $mtimes[] = (int)@filemtime($af);
        }
        $locRows = $pdo->query('SELECT id_ubicacion, latitud, longitud, radio_metros, precision_max_metros, activo FROM ubicaciones_permitidas ORDER BY id_ubicacion ASC')->fetchAll();
        jsonOut([
            'ok' => true,
            'server_time' => date('Y-m-d H:i:s'),
            'app_version' => substr(sha1(implode(':', $mtimes)), 0, 12),
            'config_version' => substr(sha1((string)json_encode($locRows)), 0, 12),
        ]);
    }

    if ($action === 'device_register' && $method === 'POST') {
        if ($audit->isBruteForceBlocked('DISPOSITIVO_NO_AUTORIZADO', $clientIp, 12, 15)) {
            jsonOut(['ok' => false, 'mensaje' => 'Demasiados códigos inválidos desde esta red. Intente más tarde.'], 429);
        }
        $body = readJsonBody();
        $res = $attendance->registerDevice(
            (string)($body['codigo'] ?? ''),
            (string)($body['public_key_pem'] ?? '')
        );
        jsonOut(['ok' => true, 'device' => $res]);
    }

    if ($action === 'device_status' && $method === 'GET') {
        $uuid = trim((string)($_GET['device_uuid'] ?? ''));
        $dev = $attendance->findActiveDevice($uuid);
        if (!$dev) {
            jsonOut(['ok' => false, 'authorized' => false, 'mensaje' => 'Dispositivo no autorizado o revocado.']);
        }
        jsonOut([
            'ok' => true,
            'authorized' => true,
            'server_time' => date('Y-m-d H:i:s'),
            'device' => [
                'alias_dispositivo' => (string)$dev['alias_dispositivo'],
                'tipo_modo' => (string)$dev['tipo_modo'],
                'clave_empleado_asignado' => $dev['clave_empleado_asignado'] !== null ? (int)$dev['clave_empleado_asignado'] : null,
            ],
        ]);
    }

    if ($action === 'geo_check' && $method === 'POST') {
        $body = readJsonBody();
        $uuid = trim((string)($body['device_uuid'] ?? ''));
        if ($attendance->findActiveDevice($uuid) === null) {
            jsonOut(['ok' => false, 'mensaje' => 'Dispositivo no autorizado.'], 403);
        }
        $lat = isset($body['latitude']) && is_numeric($body['latitude']) ? (float)$body['latitude'] : null;
        $lon = isset($body['longitude']) && is_numeric($body['longitude']) ? (float)$body['longitude'] : null;
        $acc = isset($body['accuracy']) && is_numeric($body['accuracy']) ? (float)$body['accuracy'] : null;

        $res = $geo->evaluate($lat, $lon, $acc);
        jsonOut([
            'ok' => true,
            'dentro_area_permitida' => (bool)$res['dentro_area_permitida'],
            'estado_texto' => $res['dentro_area_permitida'] ? 'En área permitida' : 'Fuera de área permitida',
        ]);
    }

    if ($action === 'employee_lookup' && $method === 'GET') {
        $uuid = trim((string)($_GET['device_uuid'] ?? ''));
        $clave = (int)($_GET['clave'] ?? 0);
        $emp = $attendance->lookupEmployeeForDevice($uuid, $clave);
        jsonOut(['ok' => true, 'employee' => $emp]);
    }

    if ($action === 'fingerprint_register' && $method === 'POST') {
        $body = readJsonBody();
        $res = $attendance->registerFingerprint(
            (string)($body['device_uuid'] ?? ''),
            (int)($body['clave_empleado'] ?? 0),
            (string)($body['credential_id'] ?? ''),
            isset($body['public_key_pem']) ? (string)$body['public_key_pem'] : null,
            (string)($body['nonce'] ?? ''),
            (string)($body['signature'] ?? '')
        );
        jsonOut(['ok' => true, 'fingerprint' => $res]);
    }

    if ($action === 'today_punches' && $method === 'GET') {
        $uuid = trim((string)($_GET['device_uuid'] ?? ''));
        $dev = $attendance->findActiveDevice($uuid);
        if ($dev === null) {
            jsonOut(['ok' => false, 'mensaje' => 'Dispositivo no autorizado.'], 403);
        }
        $clave = isset($_GET['clave']) && $_GET['clave'] !== '' ? (int)$_GET['clave'] : null;
        if ((string)$dev['tipo_modo'] === 'INDIVIDUAL') {
            $clave = (int)$dev['clave_empleado_asignado'];
        }
        jsonOut(['ok' => true, 'punches' => $attendance->getTodayPunches((int)$dev['id_dispositivo'], $clave)]);
    }

    if ($action === 'device_unbind_admin' && $method === 'POST') {
        if ($audit->isBruteForceBlocked('LOGIN_ADMIN_FALLIDO', $clientIp, 8, 15)) {
            jsonOut(['ok' => false, 'mensaje' => 'Bloqueado temporalmente por intentos fallidos.'], 429);
        }
        $body = readJsonBody();
        $uuid = trim((string)($body['device_uuid'] ?? ''));
        $usuario = trim((string)($body['usuario'] ?? ''));
        $password = (string)($body['password'] ?? '');

        $stmtAdm = $pdo->prepare('SELECT id_admin, password_hash, activo FROM usuarios_admin WHERE usuario = ?');
        $stmtAdm->execute([$usuario]);
        $admRow = $stmtAdm->fetch();
        if (!$admRow || (int)$admRow['activo'] !== 1 || !password_verify($password, (string)$admRow['password_hash'])) {
            $audit->logRejection('LOGIN_ADMIN_FALLIDO', null, null, null, null, 'Desvinculación móvil fallida: ' . substr($usuario, 0, 40), $clientIp);
            usleep(350000);
            jsonOut(['ok' => false, 'mensaje' => 'Credenciales de administrador inválidas.'], 401);
        }
        $dev = $attendance->findActiveDevice($uuid);
        if ($dev !== null) {
            $idDev = (int)$dev['id_dispositivo'];
            $pdo->prepare("UPDATE dispositivos_autorizados SET estado = 'REVOCADO' WHERE id_dispositivo = ?")->execute([$idDev]);
            $pdo->prepare("UPDATE credenciales_huella SET estado = 'REVOCADA' WHERE id_dispositivo = ?")->execute([$idDev]);
        }
        jsonOut(['ok' => true]);
    }

    if ($action === 'punch_challenge' && $method === 'POST') {
        $body = readJsonBody();
        $res = $attendance->issueChallenge((string)($body['device_uuid'] ?? ''));
        jsonOut(['ok' => true] + $res);
    }

    if ($action === 'punch_submit' && $method === 'POST') {
        $body = readJsonBody();
        if (isset($body['images_base64'])) {
            $body['images_base64'] = validateImagesPayload($body['images_base64']);
        }
        if (isset($body['photo_base64']) && (!is_string($body['photo_base64']) || strlen($body['photo_base64']) > MAX_SINGLE_IMAGE_CHARS)) {
            jsonOut(['ok' => false, 'mensaje' => 'Fotografía adjunta inválida o demasiado pesada.'], 400);
        }
        $res = $attendance->processPunch($body);
        jsonOut($res, $res['ok'] ? 200 : 422);
    }

    // =========================================================================
    // AUTENTICACIÓN ADMINISTRATIVA (protegida contra fuerza bruta)
    // =========================================================================
    if ($action === 'admin_login' && $method === 'POST') {
        if ($audit->isBruteForceBlocked('LOGIN_ADMIN_FALLIDO', $clientIp, 8, 15)) {
            jsonOut(['ok' => false, 'mensaje' => 'Acceso administrativo bloqueado temporalmente por intentos fallidos.'], 429);
        }
        $body = readJsonBody();
        $usuario = (string)($body['usuario'] ?? '');
        $admin = $auth->login($usuario, (string)($body['password'] ?? ''));
        if ($admin === null) {
            $audit->logRejection('LOGIN_ADMIN_FALLIDO', null, null, null, null, 'Intento fallido de usuario: ' . substr($usuario, 0, 40), $clientIp);
            usleep(350000); // Retardo anti-fuerza bruta
            jsonOut(['ok' => false, 'mensaje' => 'Credenciales inválidas.'], 401);
        }
        jsonOut(['ok' => true, 'admin' => $admin, 'csrf_token' => $auth->csrfToken()]);
    }

    if ($action === 'admin_session' && $method === 'GET') {
        $admin = $auth->currentAdmin();
        if ($admin === null) {
            jsonOut(['ok' => false, 'authenticated' => false]);
        }
        jsonOut(['ok' => true, 'authenticated' => true, 'admin' => $admin, 'csrf_token' => $auth->csrfToken()]);
    }

    // =========================================================================
    // ENDPOINTS PROTEGIDOS DEL PANEL ADMINISTRATIVO (Sesión + CSRF obligatorio)
    // =========================================================================
    $admin = $auth->currentAdmin();
    if ($admin === null) {
        jsonOut(['ok' => false, 'mensaje' => 'Sesión administrativa requerida.'], 401);
    }
    if ($method !== 'GET') {
        $csrf = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!$auth->verifyCsrf($csrf)) {
            jsonOut(['ok' => false, 'mensaje' => 'Token CSRF inválido.'], 403);
        }
    }

    if ($action === 'admin_logout' && $method === 'POST') {
        $auth->logout();
        jsonOut(['ok' => true]);
    }

    if ($action === 'admin_dashboard' && $method === 'GET') {
        $hoyInicio = date('Y-m-d') . ' 00:00:00';
        $st1 = $pdo->prepare('SELECT COUNT(*) FROM registros_checadas WHERE fecha_hora_servidor >= ?');
        $st1->execute([$hoyInicio]);
        $checadasHoy = (int)$st1->fetchColumn();

        $pendientesSync = (int)$pdo->query("SELECT COUNT(*) FROM registros_checadas WHERE estado_sync_titanium IN ('PENDIENTE','ERROR')")->fetchColumn();

        $st2 = $pdo->prepare('SELECT COUNT(*) FROM bitacora_rechazos WHERE fecha_hora_servidor >= ?');
        $st2->execute([$hoyInicio]);
        $rechazosHoy = (int)$st2->fetchColumn();

        $escaneresActivos = (int)$pdo->query("SELECT COUNT(*) FROM dispositivos_autorizados WHERE estado = 'ACTIVO'")->fetchColumn();
        $enrolados = (int)$pdo->query("SELECT COUNT(DISTINCT clave_empleado) FROM plantillas_faciales WHERE estado = 'ACTIVA'")->fetchColumn();
        $ubicacionesActivas = (int)$pdo->query('SELECT COUNT(*) FROM ubicaciones_permitidas WHERE activo = 1')->fetchColumn();

        jsonOut([
            'ok' => true,
            'server_time' => date('Y-m-d H:i:s'),
            'metrics' => [
                'checadas_hoy' => $checadasHoy,
                'pendientes_sync' => $pendientesSync,
                'rechazos_hoy' => $rechazosHoy,
                'escaneres_activos' => $escaneresActivos,
                'empleados_enrolados' => $enrolados,
                'ubicaciones_activas' => $ubicacionesActivas,
            ],
            'titanium' => $titanium->healthCheck(),
            'facial' => $facial->health(),
        ]);
    }

    if ($action === 'admin_employees' && $method === 'GET') {
        $q = trim((string)($_GET['q'] ?? ''));
        $emps = $titanium->listEmployees($q, false);
        $enrolledRows = $pdo->query("SELECT clave_empleado, fecha_enrolamiento, modelo_version FROM plantillas_faciales WHERE estado = 'ACTIVA'")->fetchAll();
        $enrolledMap = [];
        foreach ($enrolledRows as $er) {
            $enrolledMap[(int)$er['clave_empleado']] = $er;
        }
        foreach ($emps as &$e) {
            $k = $e['clave_empleado'];
            $e['enrolado'] = isset($enrolledMap[$k]);
            $e['fecha_enrolamiento'] = $enrolledMap[$k]['fecha_enrolamiento'] ?? null;
        }
        unset($e);
        jsonOut(['ok' => true, 'employees' => $emps]);
    }

    if ($action === 'admin_enroll_face' && $method === 'POST') {
        if ($titanium->mode() === 'firebird') {
            jsonOut(['ok' => false, 'mensaje' => 'Las fotos de empleados se administran únicamente en Acceso 3 TITANIUM. Use "Sincronizar Fotos desde TITANIUM".'], 400);
        }
        $body = readJsonBody();
        $imgs = validateImagesPayload($body['images_base64'] ?? []);
        $res = $attendance->enrollEmployeeFace((int)($body['clave_empleado'] ?? 0), $imgs);
        jsonOut(['ok' => true, 'enrollment' => $res]);
    }

    if ($action === 'admin_sync_titanium_faces' && $method === 'POST') {
        $body = readJsonBody();
        $clave = isset($body['clave_empleado']) ? (int)$body['clave_empleado'] : 0;
        if ($clave > 0) {
            $res = $attendance->syncEmployeeFaceFromTitanium($clave);
            if ($res === null) {
                jsonOut(['ok' => false, 'mensaje' => 'El empleado #' . $clave . ' no tiene una foto facial válida en Acceso 3 TITANIUM (o está en Baja).'], 422);
            }
            jsonOut(['ok' => true, 'single' => true, 'clave_empleado' => $clave, 'updated' => !empty($res['updated'])]);
        }
        $summary = $attendance->syncAllFacesFromTitanium(!empty($body['force']));
        jsonOut(['ok' => true, 'summary' => $summary]);
    }

    if ($action === 'admin_revoke_face' && $method === 'POST') {
        $body = readJsonBody();
        $clave = (int)($body['clave_empleado'] ?? 0);
        $pdo->prepare("UPDATE plantillas_faciales SET estado = 'REVOCADA' WHERE clave_empleado = ? AND estado = 'ACTIVA'")
            ->execute([$clave]);
        jsonOut(['ok' => true]);
    }

    if ($action === 'admin_devices' && $method === 'GET') {
        $devices = $pdo->query(
            'SELECT id_dispositivo, device_uuid, alias_dispositivo, tipo_modo, clave_empleado_asignado, estado, fecha_registro, ultimo_uso
             FROM dispositivos_autorizados ORDER BY id_dispositivo DESC'
        )->fetchAll();
        $invites = $pdo->query(
            'SELECT id_invitacion, codigo, alias_dispositivo, tipo_modo, clave_empleado_asignado, expira_en, usado, creado_en
             FROM invitaciones_dispositivos WHERE usado = 0 ORDER BY id_invitacion DESC LIMIT 25'
        )->fetchAll();
        jsonOut(['ok' => true, 'devices' => $devices, 'invites' => $invites]);
    }

    if ($action === 'admin_device_invite' && $method === 'POST') {
        $body = readJsonBody();
        $inv = $attendance->createDeviceInvite(
            (string)($body['alias_dispositivo'] ?? ''),
            strtoupper((string)($body['tipo_modo'] ?? 'ESCANER')),
            isset($body['clave_empleado_asignado']) && $body['clave_empleado_asignado'] !== '' ? (int)$body['clave_empleado_asignado'] : null,
            max(1, min(168, (int)($body['ttl_hours'] ?? 24)))
        );
        jsonOut(['ok' => true, 'invite' => $inv]);
    }

    if ($action === 'admin_device_revoke' && $method === 'POST') {
        $body = readJsonBody();
        $id = (int)($body['id_dispositivo'] ?? 0);
        $pdo->prepare("UPDATE dispositivos_autorizados SET estado = 'REVOCADO' WHERE id_dispositivo = ?")->execute([$id]);
        $pdo->prepare("UPDATE credenciales_huella SET estado = 'REVOCADA' WHERE id_dispositivo = ?")->execute([$id]);
        jsonOut(['ok' => true]);
    }

    if ($action === 'admin_locations' && $method === 'GET') {
        $locs = $pdo->query('SELECT * FROM ubicaciones_permitidas ORDER BY id_ubicacion DESC')->fetchAll();
        jsonOut(['ok' => true, 'locations' => $locs]);
    }

    if ($action === 'admin_location_save' && $method === 'POST') {
        $body = readJsonBody();
        $id = isset($body['id_ubicacion']) && (int)$body['id_ubicacion'] > 0 ? (int)$body['id_ubicacion'] : null;
        $nombre = substr(trim((string)($body['nombre'] ?? '')), 0, 100);
        $lat = (float)($body['latitud'] ?? 0);
        $lon = (float)($body['longitud'] ?? 0);
        $radio = max(10, min(5000, (int)($body['radio_metros'] ?? 100)));
        $prec = max(10, min(5000, (int)($body['precision_max_metros'] ?? 120)));
        $activo = !empty($body['activo']) ? 1 : 0;

        if ($nombre === '' || !is_finite($lat) || !is_finite($lon) || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
            jsonOut(['ok' => false, 'mensaje' => 'Datos de ubicación inválidos.'], 400);
        }

        if ($id !== null) {
            $pdo->prepare(
                'UPDATE ubicaciones_permitidas SET nombre = ?, latitud = ?, longitud = ?, radio_metros = ?, precision_max_metros = ?, activo = ?
                 WHERE id_ubicacion = ?'
            )->execute([$nombre, $lat, $lon, $radio, $prec, $activo, $id]);
        } else {
            $pdo->prepare(
                'INSERT INTO ubicaciones_permitidas (nombre, latitud, longitud, radio_metros, precision_max_metros, activo, fecha_creacion)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([$nombre, $lat, $lon, $radio, $prec, $activo, date('Y-m-d H:i:s')]);
            $id = (int)$pdo->lastInsertId();
        }
        jsonOut(['ok' => true, 'id_ubicacion' => $id]);
    }

    if ($action === 'admin_punches' && $method === 'GET') {
        $rows = $pdo->query(
            'SELECT r.*, d.alias_dispositivo, u.nombre AS nombre_ubicacion
             FROM registros_checadas r
             LEFT JOIN dispositivos_autorizados d ON d.id_dispositivo = r.id_dispositivo
             LEFT JOIN ubicaciones_permitidas u ON u.id_ubicacion = r.id_ubicacion
             ORDER BY r.id_checada DESC LIMIT 100'
        )->fetchAll();
        jsonOut(['ok' => true, 'punches' => $rows]);
    }

    if ($action === 'admin_rejections' && $method === 'GET') {
        $rows = $pdo->query(
            'SELECT b.*, d.alias_dispositivo
             FROM bitacora_rechazos b
             LEFT JOIN dispositivos_autorizados d ON d.id_dispositivo = b.id_dispositivo
             ORDER BY b.id_evento DESC LIMIT 100'
        )->fetchAll();
        jsonOut(['ok' => true, 'rejections' => $rows]);
    }

    if ($action === 'admin_sync_retry' && $method === 'POST') {
        $res = $attendance->retryPendingSync();
        jsonOut(['ok' => true, 'sync' => $res]);
    }

    jsonOut(['ok' => false, 'mensaje' => 'Ruta API no encontrada.'], 404);
} catch (InvalidArgumentException $e) {
    jsonOut(['ok' => false, 'mensaje' => $e->getMessage()], 400);
} catch (RuntimeException $e) {
    logInternalError($e);
    jsonOut(['ok' => false, 'mensaje' => $e->getMessage()], 400);
} catch (Throwable $e) {
    logInternalError($e);
    jsonOut([
        'ok' => false,
        'mensaje' => $isDev ? $e->getMessage() : 'Error interno del servidor. Consulte la bitácora del sistema.',
    ], 500);
}
