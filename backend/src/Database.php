<?php
declare(strict_types=1);

/**
 * Conexión PDO unificada para la base de datos complementaria (MySQL/MariaDB o SQLite).
 * Inicializa automáticamente el esquema y los datos base en SQLite cuando es primera ejecución.
 */
final class Database
{
    private PDO $pdo;
    private string $driver;

    public function __construct(array $dbConfig, array $adminConfig = [])
    {
        $this->driver = $dbConfig['driver'] ?? 'sqlite';

        if ($this->driver === 'mysql') {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $dbConfig['host'],
                $dbConfig['port'],
                $dbConfig['name']
            );
            $this->pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } else {
            $path = $dbConfig['sqlite_path'];
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $this->pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $this->pdo->exec('PRAGMA foreign_keys = ON;');
            $this->pdo->exec('PRAGMA journal_mode = WAL;');
            $this->bootstrapSqlite();
        }

        $this->ensureDefaultAdmin($adminConfig);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function driver(): string
    {
        return $this->driver;
    }

    private function bootstrapSqlite(): void
    {
        $schemaPath = AppConfig::rootDir() . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'schema_sqlite.sql';
        if (is_file($schemaPath)) {
            $sql = file_get_contents($schemaPath);
            if ($sql !== false) {
                $this->pdo->exec($sql);
            }
        }

        // Migración incremental segura de columnas nuevas en registros_checadas
        $cols = $this->pdo->query('PRAGMA table_info(registros_checadas)')->fetchAll();
        $colNames = array_map(static fn(array $c): string => (string)$c['name'], $cols);
        if (!in_array('metodo_verificacion', $colNames, true)) {
            $this->pdo->exec("ALTER TABLE registros_checadas ADD COLUMN metodo_verificacion TEXT NOT NULL DEFAULT 'FACIAL'");
        }
        if (!in_array('planta', $colNames, true)) {
            $this->pdo->exec("ALTER TABLE registros_checadas ADD COLUMN planta TEXT NOT NULL DEFAULT ''");
        }
        if (!in_array('nota', $colNames, true)) {
            $this->pdo->exec("ALTER TABLE registros_checadas ADD COLUMN nota TEXT NULL");
        }

        // Migración incremental: sign_count anti-clonación para credenciales WebAuthn
        $fpCols = $this->pdo->query('PRAGMA table_info(credenciales_huella)')->fetchAll();
        $fpNames = array_map(static fn(array $c): string => (string)$c['name'], $fpCols);
        if (!in_array('sign_count', $fpNames, true)) {
            $this->pdo->exec('ALTER TABLE credenciales_huella ADD COLUMN sign_count INTEGER NOT NULL DEFAULT 0');
        }

        // Migración incremental: hash y tamaño de foto origen en TITANIUM (solo caché vectorial cifrada, cero imágenes en web)
        $pfCols = $this->pdo->query('PRAGMA table_info(plantillas_faciales)')->fetchAll();
        $pfNames = array_map(static fn(array $c): string => (string)$c['name'], $pfCols);
        if (!in_array('source_hash', $pfNames, true)) {
            $this->pdo->exec('ALTER TABLE plantillas_faciales ADD COLUMN source_hash TEXT NULL');
        }
        if (!in_array('source_bytes', $pfNames, true)) {
            $this->pdo->exec('ALTER TABLE plantillas_faciales ADD COLUMN source_bytes INTEGER NOT NULL DEFAULT 0');
        }

        // Sembrar empleados demo equivalentes a DemoAcceso.fdb si está vacío
        $empCount = (int)$this->pdo->query('SELECT COUNT(*) FROM demo_titanium_empleados')->fetchColumn();
        if ($empCount === 0) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO demo_titanium_empleados (clave_empleado, nombre_completo, estatus, clave_departamento, clave_sucursal)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $demos = [
                [1, 'Carolina Rivera Armendariz', 'Alta', 3, 1],
                [2, 'Christian Ocaña Rios', 'Alta', 5, 2],
                [3, 'Luz Maria Alonso Acosta', 'Alta', 3, 1],
                [4, 'Luis Daniel Ramirez Loredo', 'Alta', 7, 2],
                [5, 'Alma Delia Samaniego Ventura', 'Baja', 3, 1],
            ];
            foreach ($demos as $row) {
                $stmt->execute($row);
            }
        }

        // Sembrar ubicación demo inicial si no existe ninguna
        $locCount = (int)$this->pdo->query('SELECT COUNT(*) FROM ubicaciones_permitidas')->fetchColumn();
        if ($locCount === 0) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO ubicaciones_permitidas (nombre, latitud, longitud, radio_metros, precision_max_metros, activo, fecha_creacion)
                 VALUES (?, ?, ?, ?, ?, 1, ?)'
            );
            $stmt->execute(['Oficina Matriz (Demo)', 19.4326077, -99.1332080, 150, 120, date('Y-m-d H:i:s')]);
        }
    }

    private function ensureDefaultAdmin(array $adminConfig): void
    {
        $count = (int)$this->pdo->query('SELECT COUNT(*) FROM usuarios_admin')->fetchColumn();
        if ($count > 0) {
            return;
        }
        $user = $adminConfig['default_user'] ?? 'admin';
        $pass = $adminConfig['default_pass'] ?? 'Titanium2026!';
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        $hash = password_hash($pass, $algo);

        $stmt = $this->pdo->prepare(
            'INSERT INTO usuarios_admin (usuario, password_hash, nombre, rol, activo, creado_en)
             VALUES (?, ?, ?, ?, 1, ?)'
        );
        $stmt->execute([$user, $hash, 'Administrador General', 'ADMIN', date('Y-m-d H:i:s')]);
    }
}
