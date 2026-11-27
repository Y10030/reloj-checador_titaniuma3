<?php
declare(strict_types=1);

/**
 * Worker CLI para Windows Task Scheduler (ejecutar cada 1-5 minutos).
 * Reintenta insertar en Acceso 3 TITANIUM (Firebird 2.5) las checadas en estado PENDIENTE o ERROR.
 */
require_once __DIR__ . '/../backend/config/config.php';
require_once __DIR__ . '/../backend/src/Database.php';
require_once __DIR__ . '/../backend/src/CryptoService.php';
require_once __DIR__ . '/../backend/src/GeoService.php';
require_once __DIR__ . '/../backend/src/TitaniumConnector.php';
require_once __DIR__ . '/../backend/src/FacialClient.php';
require_once __DIR__ . '/../backend/src/AuditService.php';
require_once __DIR__ . '/../backend/src/AttendanceService.php';

$config = AppConfig::load();
$db = new Database($config['db'], $config['admin']);
$pdo = $db->pdo();

$attendance = new AttendanceService(
    $pdo,
    new CryptoService($config['app']['encryption_key']),
    new GeoService($pdo, $config['security']['default_max_gps_accuracy']),
    new TitaniumConnector($config['titanium'], $pdo),
    new FacialClient($config['facial']),
    new AuditService($pdo, $config['security'], $config['app']['trust_proxy']),
    $config
);

$result = $attendance->retryPendingSync();
echo sprintf(
    "[%s] Reintento TITANIUM -> Procesadas: %d | Sincronizadas: %d | Fallidas: %d\n",
    date('Y-m-d H:i:s'),
    $result['processed'],
    $result['synced'],
    $result['failed']
);
