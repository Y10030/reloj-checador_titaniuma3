-- =============================================================================
-- Base de datos complementaria (SQLite 3 — Modo local/desarrollo y pruebas)
-- Equivalente 1:1 con schema_mysql.sql
-- =============================================================================

PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS plantillas_faciales (
    id_plantilla        INTEGER PRIMARY KEY AUTOINCREMENT,
    clave_empleado      INTEGER NOT NULL,
    embedding_cifrado   BLOB NOT NULL,
    iv_cifrado          BLOB NOT NULL,
    tag_cifrado         BLOB NOT NULL,
    modelo_version      TEXT NOT NULL DEFAULT 'buffalo_l_r100',
    estado              TEXT NOT NULL DEFAULT 'ACTIVA' CHECK(estado IN ('ACTIVA','INACTIVA','REVOCADA')),
    fecha_enrolamiento  TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_pf_clave_estado ON plantillas_faciales(clave_empleado, estado);

CREATE TABLE IF NOT EXISTS dispositivos_autorizados (
    id_dispositivo          INTEGER PRIMARY KEY AUTOINCREMENT,
    device_uuid             TEXT NOT NULL UNIQUE,
    alias_dispositivo       TEXT NOT NULL,
    tipo_modo               TEXT NOT NULL DEFAULT 'ESCANER' CHECK(tipo_modo IN ('ESCANER','INDIVIDUAL')),
    clave_empleado_asignado INTEGER NULL,
    clave_publica_pem       TEXT NOT NULL,
    estado                  TEXT NOT NULL DEFAULT 'ACTIVO' CHECK(estado IN ('ACTIVO','REVOCADO')),
    fecha_registro          TEXT NOT NULL,
    ultimo_uso              TEXT NULL
);
CREATE INDEX IF NOT EXISTS idx_disp_estado ON dispositivos_autorizados(estado);

CREATE TABLE IF NOT EXISTS ubicaciones_permitidas (
    id_ubicacion          INTEGER PRIMARY KEY AUTOINCREMENT,
    nombre                TEXT NOT NULL,
    latitud               REAL NOT NULL,
    longitud              REAL NOT NULL,
    radio_metros          INTEGER NOT NULL DEFAULT 100,
    precision_max_metros  INTEGER NOT NULL DEFAULT 120,
    activo                INTEGER NOT NULL DEFAULT 1,
    fecha_creacion        TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS registros_checadas (
    id_checada            INTEGER PRIMARY KEY AUTOINCREMENT,
    request_uuid          TEXT NOT NULL UNIQUE,
    clave_empleado        INTEGER NOT NULL,
    nombre_empleado       TEXT NOT NULL DEFAULT '',
    id_dispositivo        INTEGER NOT NULL,
    id_ubicacion          INTEGER NOT NULL,
    fecha_hora_servidor   TEXT NOT NULL,
    tipo_evento           TEXT NOT NULL DEFAULT 'AUTO' CHECK(tipo_evento IN ('ENTRADA','SALIDA','COMIDA_INI','COMIDA_FIN','AUTO')),
    latitud               REAL NOT NULL,
    longitud              REAL NOT NULL,
    precision_gps_metros  REAL NOT NULL,
    distancia_metros      REAL NOT NULL,
    dentro_area_permitida INTEGER NOT NULL DEFAULT 1,
    puntaje_facial        REAL NOT NULL,
    liveness_aprobado     INTEGER NOT NULL DEFAULT 1,
    estado_sync_titanium  TEXT NOT NULL DEFAULT 'PENDIENTE' CHECK(estado_sync_titanium IN ('PENDIENTE','SINCRONIZADO','ERROR')),
    id_registro_titanium  INTEGER NULL,
    detalle_sync          TEXT NULL,
    FOREIGN KEY (id_dispositivo) REFERENCES dispositivos_autorizados(id_dispositivo),
    FOREIGN KEY (id_ubicacion) REFERENCES ubicaciones_permitidas(id_ubicacion)
);
CREATE INDEX IF NOT EXISTS idx_chk_emp_fecha ON registros_checadas(clave_empleado, fecha_hora_servidor);
CREATE INDEX IF NOT EXISTS idx_chk_sync ON registros_checadas(estado_sync_titanium);

CREATE TABLE IF NOT EXISTS bitacora_rechazos (
    id_evento              INTEGER PRIMARY KEY AUTOINCREMENT,
    id_dispositivo         INTEGER NULL,
    clave_empleado_intento INTEGER NULL,
    motivo_rechazo         TEXT NOT NULL,
    detalle                TEXT NULL,
    dentro_area_permitida  INTEGER NULL,
    distancia_metros       REAL NULL,
    fecha_hora_servidor    TEXT NOT NULL,
    ip_origen              TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_rech_fecha ON bitacora_rechazos(fecha_hora_servidor);
CREATE INDEX IF NOT EXISTS idx_rech_ip_fecha ON bitacora_rechazos(ip_origen, fecha_hora_servidor);

CREATE TABLE IF NOT EXISTS nonces_dispositivos (
    id_nonce        INTEGER PRIMARY KEY AUTOINCREMENT,
    nonce           TEXT NOT NULL UNIQUE,
    id_dispositivo  INTEGER NOT NULL,
    reto_liveness   TEXT NOT NULL,
    expira_en       TEXT NOT NULL,
    usado           INTEGER NOT NULL DEFAULT 0,
    creado_en       TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS invitaciones_dispositivos (
    id_invitacion           INTEGER PRIMARY KEY AUTOINCREMENT,
    codigo                  TEXT NOT NULL UNIQUE,
    alias_dispositivo       TEXT NOT NULL,
    tipo_modo               TEXT NOT NULL DEFAULT 'ESCANER' CHECK(tipo_modo IN ('ESCANER','INDIVIDUAL')),
    clave_empleado_asignado INTEGER NULL,
    expira_en               TEXT NOT NULL,
    usado                   INTEGER NOT NULL DEFAULT 0,
    creado_en               TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS usuarios_admin (
    id_admin      INTEGER PRIMARY KEY AUTOINCREMENT,
    usuario       TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    nombre        TEXT NOT NULL,
    rol           TEXT NOT NULL DEFAULT 'ADMIN' CHECK(rol IN ('ADMIN','SUPERVISOR')),
    activo        INTEGER NOT NULL DEFAULT 1,
    creado_en     TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS credenciales_huella (
    id_credencial       INTEGER PRIMARY KEY AUTOINCREMENT,
    id_dispositivo      INTEGER NOT NULL,
    clave_empleado      INTEGER NOT NULL,
    credential_id       TEXT NOT NULL UNIQUE,
    public_key_pem      TEXT NULL,
    estado              TEXT NOT NULL DEFAULT 'ACTIVA' CHECK(estado IN ('ACTIVA','REVOCADA')),
    fecha_registro      TEXT NOT NULL,
    FOREIGN KEY (id_dispositivo) REFERENCES dispositivos_autorizados(id_dispositivo)
);
CREATE INDEX IF NOT EXISTS idx_huella_disp_emp ON credenciales_huella(id_dispositivo, clave_empleado, estado);

-- Tablas espejo locales para TITANIUM_MODE=demo (simula DemoAcceso.fdb sin Firebird instalado)
CREATE TABLE IF NOT EXISTS demo_titanium_empleados (
    clave_empleado      INTEGER PRIMARY KEY,
    nombre_completo     TEXT NOT NULL,
    estatus             TEXT NOT NULL DEFAULT 'Alta',
    clave_departamento  INTEGER NOT NULL DEFAULT 1,
    clave_sucursal      INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS demo_titanium_checadas (
    id_registro         INTEGER PRIMARY KEY AUTOINCREMENT,
    clave_empleado      INTEGER NOT NULL,
    fecha_hora          TEXT NOT NULL,
    tipo_evento         TEXT NOT NULL,
    origen              TEXT NOT NULL DEFAULT 'MOVIL'
);
