<?php
declare(strict_types=1);

/**
 * Carga la configuración desde .env en la raíz del proyecto.
 */
final class AppConfig
{
    private static ?array $data = null;

    public static function rootDir(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function load(?string $envFile = null): array
    {
        if (self::$data !== null && $envFile === null) {
            return self::$data;
        }

        $root = self::rootDir();
        $path = $envFile ?? ($root . DIRECTORY_SEPARATOR . '.env');
        $values = [];

        if (is_file($path)) {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach ($lines as $line) {
                $trimmed = trim($line);
                if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                    continue;
                }
                $eqPos = strpos($trimmed, '=');
                if ($eqPos === false) {
                    continue;
                }
                $key = trim(substr($trimmed, 0, $eqPos));
                $val = trim(substr($trimmed, $eqPos + 1));
                if (
                    (str_starts_with($val, '"') && str_ends_with($val, '"')) ||
                    (str_starts_with($val, "'") && str_ends_with($val, "'"))
                ) {
                    $val = substr($val, 1, -1);
                }
                $values[$key] = $val;
            }
        }

        // Permitir sobreescritura por variables de entorno del sistema
        foreach ($_ENV as $k => $v) {
            if (is_string($v)) {
                $values[$k] = $v;
            }
        }

        $tz = $values['APP_TIMEZONE'] ?? 'America/Mexico_City';
        date_default_timezone_set($tz);

        $sqliteRel = $values['DB_SQLITE_PATH'] ?? 'database/checador_local.sqlite';
        $sqliteFull = preg_match('/^[A-Za-z]:\\\\|^\//', $sqliteRel)
            ? $sqliteRel
            : $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $sqliteRel);

        $config = [
            'app' => [
                'env' => $values['APP_ENV'] ?? 'production',
                'timezone' => $tz,
                'base_url' => rtrim($values['APP_BASE_URL'] ?? 'http://127.0.0.1:8080', '/'),
                'trust_proxy' => filter_var($values['APP_TRUST_PROXY'] ?? 'true', FILTER_VALIDATE_BOOLEAN),
                'encryption_key' => $values['APP_ENCRYPTION_KEY'] ?? '',
            ],
            'admin' => [
                'default_user' => $values['ADMIN_DEFAULT_USER'] ?? 'admin',
                'default_pass' => $values['ADMIN_DEFAULT_PASS'] ?? 'Titanium2026!',
            ],
            'db' => [
                'driver' => strtolower($values['DB_DRIVER'] ?? 'sqlite'),
                'sqlite_path' => $sqliteFull,
                'host' => $values['DB_HOST'] ?? '127.0.0.1',
                'port' => (int)($values['DB_PORT'] ?? 3306),
                'name' => $values['DB_NAME'] ?? 'checador_movil',
                'user' => $values['DB_USER'] ?? 'root',
                'pass' => $values['DB_PASS'] ?? '',
            ],
            'titanium' => [
                'mode' => strtolower($values['TITANIUM_MODE'] ?? 'demo'),
                'host' => $values['TITANIUM_FB_HOST'] ?? 'localhost',
                'port' => (int)($values['TITANIUM_FB_PORT'] ?? 3050),
                'database' => $values['TITANIUM_FB_DATABASE'] ?? 'C:\Program Files (x86)\Acceso3\DB\DemoAcceso.fdb',
                'user' => $values['TITANIUM_FB_USER'] ?? 'SYSDBA',
                'pass' => $values['TITANIUM_FB_PASS'] ?? 'masterkey',
                'charset' => $values['TITANIUM_FB_CHARSET'] ?? 'UTF8',
                'tbl_empleados' => $values['TITANIUM_TBL_EMPLEADOS'] ?? 'EMPLEADOS',
                'col_emp_id' => $values['TITANIUM_COL_EMP_ID'] ?? 'CLAVEEMPLEADO',
                'col_emp_nombre' => $values['TITANIUM_COL_EMP_NOMBRE'] ?? 'NOMBRE',
                'col_emp_ap_pat' => $values['TITANIUM_COL_EMP_AP_PAT'] ?? 'APELLIDOPATERNO',
                'col_emp_ap_mat' => $values['TITANIUM_COL_EMP_AP_MAT'] ?? 'APELLIDOMATERNO',
                'col_emp_estatus' => $values['TITANIUM_COL_EMP_ESTATUS'] ?? 'STATUS',
                'col_emp_depto' => $values['TITANIUM_COL_EMP_DEPTO'] ?? 'CLAVEDEPARTAMENTO',
                'col_emp_sucursal' => $values['TITANIUM_COL_EMP_SUCURSAL'] ?? 'CLAVESUCURSAL',
                'val_estatus_alta' => $values['TITANIUM_VAL_ESTATUS_ALTA'] ?? 'Alta',
                'val_estatus_alta_int' => (int)($values['TITANIUM_VAL_ESTATUS_ALTA_INT'] ?? 0),
                'tbl_checadas' => $values['TITANIUM_TBL_CHECADAS'] ?? 'CHECADAS',
                'val_estacion' => (int)($values['TITANIUM_VAL_ESTACION'] ?? 0),
                'val_origen_movil' => $values['TITANIUM_VAL_ORIGEN_MOVIL'] ?? 'MOVIL',
            ],
            'facial' => [
                'url' => rtrim($values['FACIAL_SERVICE_URL'] ?? 'http://127.0.0.1:8001', '/'),
                'token' => $values['FACIAL_SERVICE_TOKEN'] ?? '',
                'timeout' => (int)($values['FACIAL_TIMEOUT_SECONDS'] ?? 12),
                'threshold_1_1' => (float)($values['FACIAL_THRESHOLD_1_1'] ?? 0.45),
                'threshold_1_n' => (float)($values['FACIAL_THRESHOLD_1_N'] ?? 0.48),
                'margin_1_n' => (float)($values['FACIAL_MARGIN_1_N'] ?? 0.08),
                'model_version' => $values['FACIAL_MODEL_VERSION'] ?? 'buffalo_l_r100',
            ],
            'security' => [
                'nonce_ttl' => (int)($values['NONCE_TTL_SECONDS'] ?? 90),
                'debounce_minutes' => (int)($values['DEBOUNCE_MINUTES'] ?? 3),
                'rate_limit_max_fails' => (int)($values['RATE_LIMIT_MAX_FAILS'] ?? 8),
                'rate_limit_window_min' => (int)($values['RATE_LIMIT_WINDOW_MINUTES'] ?? 10),
                'default_max_gps_accuracy' => (int)($values['DEFAULT_MAX_GPS_ACCURACY'] ?? 120),
            ],
        ];

        if ($envFile === null) {
            self::$data = $config;
        }
        return $config;
    }

    public static function reset(): void
    {
        self::$data = null;
    }
}
