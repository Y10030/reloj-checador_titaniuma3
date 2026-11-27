<?php
declare(strict_types=1);

/**
 * Suite de pruebas automatizadas extremo a extremo:
 * - Cifrado AES-256-GCM de plantillas biométricas + detección de alteración
 * - Verificación de firmas ECDSA P-256 formato Web Crypto (IEEE P1363 64 bytes -> ASN.1 DER)
 * - Fórmula de Haversine y bloqueo fuera de área / GPS impreciso / sin GPS
 * - Flujo completo de vinculación, enrolamiento, checada 1:N, bloqueo geográfico,
 *   anti-repetición de nonce, debounce anti-duplicado, anti-spoofing y sincronización TITANIUM.
 */

require_once __DIR__ . '/../backend/config/config.php';
require_once __DIR__ . '/../backend/src/Database.php';
require_once __DIR__ . '/../backend/src/CryptoService.php';
require_once __DIR__ . '/../backend/src/GeoService.php';
require_once __DIR__ . '/../backend/src/TitaniumConnector.php';
require_once __DIR__ . '/../backend/src/FacialClient.php';
require_once __DIR__ . '/../backend/src/AuditService.php';
require_once __DIR__ . '/../backend/src/AuthService.php';
require_once __DIR__ . '/../backend/src/AttendanceService.php';

$passed = 0;
$failed = 0;

function assertTrue(bool $cond, string $label): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  [OK]   $label\n";
    } else {
        $failed++;
        echo "  [FAIL] $label\n";
    }
}

/**
 * Extrae firma IEEE P1363 (r||s, 64 bytes) desde DER OpenSSL para simular Web Crypto API.
 */
function derToP1363(string $der): string
{
    $offset = 2;
    if (ord($der[1]) & 0x80) {
        $offset += ord($der[1]) & 0x7F;
    }
    // r
    $offset++; // 0x02
    $rLen = ord($der[$offset++]);
    $r = substr($der, $offset, $rLen);
    $offset += $rLen;
    // s
    $offset++; // 0x02
    $sLen = ord($der[$offset++]);
    $s = substr($der, $offset, $sLen);

    $r = str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT);
    $s = str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);
    return $r . $s;
}

echo "=== 1. Pruebas de CryptoService (AES-256-GCM + ECDSA P-256 Web Crypto) ===\n";
$crypto = new CryptoService(bin2hex(random_bytes(32)));

$vec = [];
for ($i = 0; $i < 512; $i++) {
    $vec[] = round(sin($i * 0.17), 5);
}
$enc = $crypto->encryptEmbedding($vec);
$dec = $crypto->decryptEmbedding($enc['ciphertext'], $enc['iv'], $enc['tag']);
assertTrue(count($dec) === 512 && abs($dec[10] - $vec[10]) < 1e-5, 'AES-256-GCM cifra y descifra embedding 512-d con precisión');

$tampered = $enc['ciphertext'];
$tampered[0] = chr(ord($tampered[0]) ^ 0xFF);
$tamperBlocked = false;
try {
    $crypto->decryptEmbedding($tampered, $enc['iv'], $enc['tag']);
} catch (RuntimeException) {
    $tamperBlocked = true;
}
assertTrue($tamperBlocked, 'AES-256-GCM detecta alteración en plantilla cifrada');

// Prueba firma ECDSA P-256 en formato IEEE P1363 (exacto al que produce Web Crypto API)
$sslCfgCandidates = [
    dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.cnf',
    'C:\laragon\bin\apache\httpd-2.4.54-win64-VS16\conf\openssl.cnf',
];
$ecOpts = [
    'private_key_type' => OPENSSL_KEYTYPE_EC,
    'curve_name' => 'prime256v1',
];
foreach ($sslCfgCandidates as $cand) {
    if (is_file($cand)) {
        $ecOpts['config'] = $cand;
        break;
    }
}
$ecRes = openssl_pkey_new($ecOpts);
if ($ecRes === false) {
    throw new RuntimeException('No se pudo generar par de llaves ECDSA de prueba: ' . (string)openssl_error_string());
}
$ecDetails = openssl_pkey_get_details($ecRes);
$pubPem = $ecDetails['key'];
$payload = 'dev-uuid|nonce123|req-uuid|19.432608|-99.133208';
openssl_sign($payload, $derSig, $ecRes, OPENSSL_ALGO_SHA256);
$p1363Sig = derToP1363($derSig);
assertTrue(strlen($p1363Sig) === 64, 'Firma simulada Web Crypto P-256 mide 64 bytes (IEEE P1363)');
assertTrue($crypto->verifyEcdsaSignature($pubPem, $payload, base64_encode($p1363Sig)), 'CryptoService verifica firma Web Crypto IEEE P1363 correctamente');
assertTrue(!$crypto->verifyEcdsaSignature($pubPem, $payload . 'X', base64_encode($p1363Sig)), 'CryptoService rechaza payload alterado');

echo "\n=== 2. Pruebas de GeoService (Haversine y bloqueo estricto) ===\n";
$tmpDb = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'checador_test_' . uniqid() . '.sqlite';
$cfg = AppConfig::load();
$cfg['db']['driver'] = 'sqlite';
$cfg['db']['sqlite_path'] = $tmpDb;
$cfg['titanium']['mode'] = 'demo';

$db = new Database($cfg['db'], $cfg['admin']);
$pdo = $db->pdo();
$geo = new GeoService($pdo, 120);

$inside = $geo->evaluate(19.4328000, -99.1332000, 18.0);
assertTrue($inside['dentro_area_permitida'] === true && $inside['distancia_metros'] < 40.0, 'Dentro de geocerca (radio 150m) -> En área permitida');

$outside = $geo->evaluate(19.4426000, -99.1332000, 15.0);
assertTrue($outside['dentro_area_permitida'] === false && $outside['motivo_rechazo'] === 'FUERA_DE_AREA', 'Fuera de geocerca (~1.1km) -> Bloqueo FUERA_DE_AREA');

$imprecise = $geo->evaluate(19.4326077, -99.1332080, 250.0);
assertTrue($imprecise['dentro_area_permitida'] === false && $imprecise['motivo_rechazo'] === 'GPS_IMPRECISO', 'Precisión GPS 250m > 120m -> Bloqueo GPS_IMPRECISO');

$noGps = $geo->evaluate(null, null, null);
assertTrue($noGps['dentro_area_permitida'] === false && $noGps['motivo_rechazo'] === 'SIN_GPS', 'Sin coordenadas GPS -> Bloqueo SIN_GPS');

echo "\n=== 3. Pruebas de flujo completo (Vinculación, Checada, Bloqueos y Sync TITANIUM) ===\n";

final class InMemoryTestFacialClient implements FacialClientInterface
{
    public function health(): array
    {
        return ['ok' => true, 'mode' => 'in_memory_test', 'model_version' => 'buffalo_l_r100'];
    }

    public function extract(array $imagesBase64, bool $sourceProfile = false): array
    {
        $emb = [];
        for ($i = 0; $i < 512; $i++) {
            $emb[] = round(cos($i * 0.13), 5);
        }
        return ['ok' => true, 'quality_ok' => true, 'embedding' => $emb, 'model_version' => 'buffalo_l_r100'];
    }

    public function verify(array $imagesBase64, array $referenceEmbedding, string $livenessChallenge): array
    {
        $firstImg = (string)($imagesBase64[0] ?? '');
        if (str_contains($firstImg, 'SPOOF')) {
            return ['ok' => false, 'quality_ok' => true, 'liveness_passed' => false, 'matched' => false, 'similarity' => 0.0, 'message' => 'Foto estática detectada.'];
        }
        return ['ok' => true, 'quality_ok' => true, 'liveness_passed' => true, 'matched' => true, 'similarity' => 0.9102, 'message' => 'Identidad verificada.'];
    }

    public function identify(array $imagesBase64, array $candidates, string $livenessChallenge): array
    {
        $firstImg = (string)($imagesBase64[0] ?? '');
        if (str_contains($firstImg, 'SPOOF')) {
            return ['ok' => false, 'quality_ok' => true, 'liveness_passed' => false, 'matched' => false, 'similarity' => 0.0, 'message' => 'Foto estática detectada.'];
        }
        $clave = isset($candidates[0]['clave_empleado']) ? (int)$candidates[0]['clave_empleado'] : 1;
        return ['ok' => true, 'quality_ok' => true, 'liveness_passed' => true, 'matched' => true, 'clave_empleado' => $clave, 'similarity' => 0.8921, 'margin' => 0.42, 'message' => 'Empleado identificado.'];
    }
}

try {
    $titanium = new TitaniumConnector($cfg['titanium'], $pdo);
    $facial = new InMemoryTestFacialClient();
    $audit = new AuditService($pdo, $cfg['security'], true);
    $attendance = new AttendanceService($pdo, $crypto, $geo, $titanium, $facial, $audit, $cfg);

    // 3.1 Vincular celular en Modo Escáner
    $inv = $attendance->createDeviceInvite('Escáner Recepción', 'ESCANER');
    $dev = $attendance->registerDevice($inv['codigo'], $pubPem);
    assertTrue($dev['tipo_modo'] === 'ESCANER' && $dev['estado'] === 'ACTIVO', 'Dispositivo vinculado correctamente en Modo Escáner');

    $reusedBlocked = false;
    try {
        $attendance->registerDevice($inv['codigo'], $pubPem);
    } catch (RuntimeException) {
        $reusedBlocked = true;
    }
    assertTrue($reusedBlocked, 'Código de invitación de un solo uso no puede reutilizarse');

    // 3.2 Enrolamiento facial (Empleado Alta vs Baja)
    $enr1 = $attendance->enrollEmployeeFace(1, ['data:image/jpeg;base64,EMP_1_OK']);
    assertTrue($enr1['clave_empleado'] === 1, 'Enrolamiento facial cifrado exitoso para Clave 1 (Alta)');

    $bajaBlocked = false;
    try {
        $attendance->enrollEmployeeFace(5, ['data:image/jpeg;base64,EMP_5']);
    } catch (RuntimeException) {
        $bajaBlocked = true;
    }
    assertTrue($bajaBlocked, 'Bloquea enrolamiento para empleado en estatus Baja (Clave 5)');

    // Helper para firmar payload de checada
    $makeSignedPunch = function (string $nonce, float $lat, float $lon, float $acc, array $imgs, ?string $reqUuid = null) use ($dev, $ecRes): array {
        $reqId = $reqUuid ?? CryptoService::uuidV4();
        $p = implode('|', [
            $dev['device_uuid'],
            $nonce,
            $reqId,
            number_format($lat, 6, '.', ''),
            number_format($lon, 6, '.', ''),
        ]);
        openssl_sign($p, $dSig, $ecRes, OPENSSL_ALGO_SHA256);
        return [
            'device_uuid' => $dev['device_uuid'],
            'nonce' => $nonce,
            'request_uuid' => $reqId,
            'signature' => base64_encode(derToP1363($dSig)),
            'tipo_evento' => 'ENTRADA',
            'latitude' => $lat,
            'longitude' => $lon,
            'accuracy' => $acc,
            'images_base64' => $imgs,
        ];
    };

    // 3.3 Bloqueo geográfico fuera de área permitida
    $ch1 = $attendance->issueChallenge($dev['device_uuid']);
    $resOut = $attendance->processPunch($makeSignedPunch($ch1['nonce'], 19.450000, -99.133208, 12.0, ['IMG_OK_1']));
    assertTrue($resOut['ok'] === false && $resOut['motivo_rechazo'] === 'FUERA_DE_AREA', 'Checada fuera de área es BLOQUEADA (FUERA_DE_AREA)');

    // 3.4 Intento de reutilización de nonce (replay attack)
    $resReplay = $attendance->processPunch($makeSignedPunch($ch1['nonce'], 19.432608, -99.133208, 12.0, ['IMG_OK_1']));
    assertTrue($resReplay['ok'] === false && $resReplay['motivo_rechazo'] === 'DISPOSITIVO_NO_AUTORIZADO', 'Reutilización de nonce (replay attack) es BLOQUEADA');

    // 3.5 Bloqueo por intento de suplantación (anti-spoofing)
    $ch2 = $attendance->issueChallenge($dev['device_uuid']);
    $resSpoof = $attendance->processPunch($makeSignedPunch($ch2['nonce'], 19.432608, -99.133208, 12.0, ['IMG_SPOOF']));
    assertTrue($resSpoof['ok'] === false && $resSpoof['motivo_rechazo'] === 'INTENTO_SUPLANTACION', 'Intento de suplantación (foto/video) es BLOQUEADO');

    // 3.6 Checada válida 1:N dentro del área + sincronización TITANIUM
    $ch3 = $attendance->issueChallenge($dev['device_uuid']);
    $resOk = $attendance->processPunch($makeSignedPunch($ch3['nonce'], 19.432610, -99.133210, 15.0, ['IMG_MATCH_1']));
    assertTrue(
        $resOk['ok'] === true
        && $resOk['clave_empleado'] === 1
        && $resOk['dentro_area_permitida'] === true
        && $resOk['estado_sync_titanium'] === 'SINCRONIZADO',
        'Checada válida 1:N aprobada y sincronizada en TITANIUM'
    );

    // 3.7 Control anti-duplicados (ventana anti-rebote)
    $ch4 = $attendance->issueChallenge($dev['device_uuid']);
    $resDup = $attendance->processPunch($makeSignedPunch($ch4['nonce'], 19.432610, -99.133210, 15.0, ['IMG_MATCH_1']));
    assertTrue($resDup['ok'] === false && $resDup['motivo_rechazo'] === 'DUPLICADO', 'Segunda checada inmediata del mismo empleado es BLOQUEADA (DUPLICADO)');

    // 3.8 Verificar bitácora de rechazos y registro en tabla TITANIUM demo
    $rechCount = (int)$pdo->query('SELECT COUNT(*) FROM bitacora_rechazos')->fetchColumn();
    $titCount = (int)$pdo->query('SELECT COUNT(*) FROM demo_titanium_checadas')->fetchColumn();
    assertTrue($rechCount >= 4 && $titCount === 1, "Auditoría íntegra (rechazos registrados: {$rechCount}, checadas en TITANIUM: {$titCount})");

    // 3.9 Pruebas de blindaje: registro de huella sin firma bloqueado + anti-IDOR en modo INDIVIDUAL
    $fpUnsignedBlocked = false;
    try {
        $attendance->registerFingerprint($dev['device_uuid'], 1, 'cred_fake_123', $pubPem, '', '');
    } catch (RuntimeException) {
        $fpUnsignedBlocked = true;
    }
    assertTrue($fpUnsignedBlocked, 'Registro de huella sin nonce/firma criptográfica es BLOQUEADO');

    $invInd = $attendance->createDeviceInvite('Celular Personal Clave 2', 'INDIVIDUAL', 2);
    $devInd = $attendance->registerDevice($invInd['codigo'], $pubPem);
    $idorBlocked = false;
    try {
        $attendance->lookupEmployeeForDevice($devInd['device_uuid'], 1);
    } catch (RuntimeException) {
        $idorBlocked = true;
    }
    assertTrue($idorBlocked, 'Anti-IDOR: celular INDIVIDUAL de Clave 2 no puede consultar Clave 1');

    // 3.10 Registro de huella firmado + bloqueo WebAuthn sin flag UV (biometría real)
    $chFpReg = $attendance->issueChallenge($devInd['device_uuid']);
    $fpRegPayload = implode('|', ['FP_REG', $devInd['device_uuid'], $chFpReg['nonce'], '2', 'cred_real_emp2']);
    openssl_sign($fpRegPayload, $fpRegDer, $ecRes, OPENSSL_ALGO_SHA256);
    $fpReg = $attendance->registerFingerprint(
        $devInd['device_uuid'],
        2,
        'cred_real_emp2',
        $pubPem,
        $chFpReg['nonce'],
        base64_encode(derToP1363($fpRegDer))
    );
    assertTrue($fpReg['clave_empleado'] === 2, 'Registro de huella firmado con Web Crypto aprobado');

    $chFpPunch = $attendance->issueChallenge($devInd['device_uuid']);
    $reqFp = CryptoService::uuidV4();
    $pFp = implode('|', [$devInd['device_uuid'], $chFpPunch['nonce'], $reqFp, '19.432610', '-99.133210']);
    openssl_sign($pFp, $dFpSig, $ecRes, OPENSSL_ALGO_SHA256);
    $fakeClientData = rtrim(strtr(base64_encode(json_encode([
        'type' => 'webauthn.get',
        'challenge' => rtrim(strtr(base64_encode($chFpPunch['nonce']), '+/', '-_'), '='),
        'origin' => 'https://phishing-evil.example',
    ])), '+/', '-_'), '=');
    // authData con UP=1 pero UV=0 (sin verificación biométrica)
    $fakeAuthData = rtrim(strtr(base64_encode(str_repeat("\x00", 32) . "\x01" . "\x00\x00\x00\x01"), '+/', '-_'), '=');
    $resFpBad = $attendance->processPunch([
        'device_uuid' => $devInd['device_uuid'],
        'nonce' => $chFpPunch['nonce'],
        'request_uuid' => $reqFp,
        'signature' => base64_encode(derToP1363($dFpSig)),
        'tipo_evento' => 'ENTRADA',
        'metodo_verificacion' => 'HUELLA',
        'latitude' => 19.432610,
        'longitude' => -99.133210,
        'accuracy' => 15.0,
        'webauthn' => [
            'credential_id' => 'cred_real_emp2',
            'client_data_json' => $fakeClientData,
            'authenticator_data' => $fakeAuthData,
            'signature' => '',
        ],
    ]);
    assertTrue($resFpBad['ok'] === false && $resFpBad['motivo_rechazo'] === 'HUELLA_INVALIDA', 'WebAuthn sin confirmación biométrica UV es BLOQUEADO');
} finally {
    $pdo = null;
    $db = null;
    @unlink($tmpDb);
    @unlink($tmpDb . '-wal');
    @unlink($tmpDb . '-shm');
}

echo "\n=======================================================\n";
echo "RESULTADO: {$passed} pruebas aprobadas, {$failed} fallidas.\n";
echo "=======================================================\n";
exit($failed > 0 ? 1 : 0);
