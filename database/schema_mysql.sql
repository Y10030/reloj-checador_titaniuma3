-- =============================================================================
-- Base de datos complementaria (MySQL 8 / MariaDB 10.6+)
-- Sistema de Checadas Móviles — Integración Acceso 3 TITANIUM (Vía A)
-- =============================================================================

CREATE DATABASE IF NOT EXISTS checador_movil
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE checador_movil;

-- B) Plantillas faciales cifradas (AES-256-GCM)
CREATE TABLE IF NOT EXISTS plantillas_faciales (
    id_plantilla        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    clave_empleado      INT NOT NULL,
    embedding_cifrado   VARBINARY(4096) NOT NULL,
    iv_cifrado          VARBINARY(16) NOT NULL,
    tag_cifrado         VARBINARY(16) NOT NULL,
    modelo_version      VARCHAR(40) NOT NULL DEFAULT 'buffalo_l_r100',
    estado              ENUM('ACTIVA','INACTIVA','REVOCADA') NOT NULL DEFAULT 'ACTIVA',
    fecha_enrolamiento  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_plantilla),
    INDEX idx_pf_clave_estado (clave_empleado, estado)
) ENGINE=InnoDB;

-- C) Dispositivos autorizados (Celulares escáner o individuales)
CREATE TABLE IF NOT EXISTS dispositivos_autorizados (
    id_dispositivo          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    device_uuid             CHAR(36) NOT NULL,
    alias_dispositivo       VARCHAR(80) NOT NULL,
    tipo_modo               ENUM('ESCANER','INDIVIDUAL') NOT NULL DEFAULT 'ESCANER',
    clave_empleado_asignado INT NULL,
    clave_publica_pem       TEXT NOT NULL,
    estado                  ENUM('ACTIVO','REVOCADO') NOT NULL DEFAULT 'ACTIVO',
    fecha_registro          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ultimo_uso              DATETIME NULL,
    PRIMARY KEY (id_dispositivo),
    UNIQUE KEY uk_device_uuid (device_uuid),
    INDEX idx_disp_estado (estado),
    INDEX idx_disp_emp (clave_empleado_asignado)
) ENGINE=InnoDB;

-- D) Ubicaciones permitidas (Geocercas)
CREATE TABLE IF NOT EXISTS ubicaciones_permitidas (
    id_ubicacion          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre                VARCHAR(100) NOT NULL,
    latitud               DECIMAL(10,7) NOT NULL,
    longitud              DECIMAL(10,7) NOT NULL,
    radio_metros          INT UNSIGNED NOT NULL DEFAULT 100,
    precision_max_metros  INT UNSIGNED NOT NULL DEFAULT 120,
    activo                TINYINT(1) NOT NULL DEFAULT 1,
    fecha_creacion        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_ubicacion),
    INDEX idx_ubic_activo (activo)
) ENGINE=InnoDB;

-- E) Registros de checadas aprobadas
CREATE TABLE IF NOT EXISTS registros_checadas (
    id_checada            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_uuid          CHAR(36) NOT NULL,
    clave_empleado        INT NOT NULL,
    nombre_empleado       VARCHAR(150) NOT NULL DEFAULT '',
    id_dispositivo        BIGINT UNSIGNED NOT NULL,
    id_ubicacion          INT UNSIGNED NOT NULL,
    fecha_hora_servidor   DATETIME NOT NULL,
    tipo_evento           ENUM('ENTRADA','SALIDA','COMIDA_INI','COMIDA_FIN','AUTO') NOT NULL DEFAULT 'AUTO',
    latitud               DECIMAL(10,7) NOT NULL,
    longitud              DECIMAL(10,7) NOT NULL,
    precision_gps_metros  FLOAT NOT NULL,
    distancia_metros      FLOAT NOT NULL,
    dentro_area_permitida TINYINT(1) NOT NULL DEFAULT 1,
    puntaje_facial        DECIMAL(5,4) NOT NULL,
    liveness_aprobado     TINYINT(1) NOT NULL DEFAULT 1,
    estado_sync_titanium  ENUM('PENDIENTE','SINCRONIZADO','ERROR') NOT NULL DEFAULT 'PENDIENTE',
    id_registro_titanium  BIGINT NULL,
    detalle_sync          VARCHAR(255) NULL,
    PRIMARY KEY (id_checada),
    UNIQUE KEY uk_request_uuid (request_uuid),
    INDEX idx_chk_emp_fecha (clave_empleado, fecha_hora_servidor),
    INDEX idx_chk_sync (estado_sync_titanium),
    CONSTRAINT fk_chk_disp FOREIGN KEY (id_dispositivo) REFERENCES dispositivos_autorizados(id_dispositivo),
    CONSTRAINT fk_chk_ubic FOREIGN KEY (id_ubicacion) REFERENCES ubicaciones_permitidas(id_ubicacion)
) ENGINE=InnoDB;

-- F) Bitácora de intentos rechazados
CREATE TABLE IF NOT EXISTS bitacora_rechazos (
    id_evento              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_dispositivo         BIGINT UNSIGNED NULL,
    clave_empleado_intento INT NULL,
    motivo_rechazo         VARCHAR(40) NOT NULL,
    detalle                VARCHAR(255) NULL,
    dentro_area_permitida  TINYINT(1) NULL,
    distancia_metros       FLOAT NULL,
    fecha_hora_servidor    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip_origen              VARCHAR(45) NOT NULL DEFAULT '',
    PRIMARY KEY (id_evento),
    INDEX idx_rech_fecha (fecha_hora_servidor),
    INDEX idx_rech_motivo (motivo_rechazo),
    INDEX idx_rech_ip_fecha (ip_origen, fecha_hora_servidor)
) ENGINE=InnoDB;

-- Soporte de seguridad: Desafíos (nonces) de un solo uso
CREATE TABLE IF NOT EXISTS nonces_dispositivos (
    id_nonce        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    nonce           CHAR(64) NOT NULL,
    id_dispositivo  BIGINT UNSIGNED NOT NULL,
    reto_liveness   VARCHAR(40) NOT NULL,
    expira_en       DATETIME NOT NULL,
    usado           TINYINT(1) NOT NULL DEFAULT 0,
    creado_en       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_nonce),
    UNIQUE KEY uk_nonce (nonce),
    INDEX idx_nonce_exp (expira_en)
) ENGINE=InnoDB;

-- Soporte administrativo: Invitaciones de un solo uso para vincular celulares
CREATE TABLE IF NOT EXISTS invitaciones_dispositivos (
    id_invitacion           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    codigo                  VARCHAR(16) NOT NULL,
    alias_dispositivo       VARCHAR(80) NOT NULL,
    tipo_modo               ENUM('ESCANER','INDIVIDUAL') NOT NULL DEFAULT 'ESCANER',
    clave_empleado_asignado INT NULL,
    expira_en               DATETIME NOT NULL,
    usado                   TINYINT(1) NOT NULL DEFAULT 0,
    creado_en               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_invitacion),
    UNIQUE KEY uk_inv_codigo (codigo)
) ENGINE=InnoDB;

-- Usuarios del panel administrativo
CREATE TABLE IF NOT EXISTS usuarios_admin (
    id_admin      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario       VARCHAR(50) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    nombre        VARCHAR(100) NOT NULL,
    rol           ENUM('ADMIN','SUPERVISOR') NOT NULL DEFAULT 'ADMIN',
    activo        TINYINT(1) NOT NULL DEFAULT 1,
    creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_admin),
    UNIQUE KEY uk_admin_user (usuario)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS credenciales_huella (
    id_credencial   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_dispositivo  BIGINT UNSIGNED NOT NULL,
    clave_empleado  INT NOT NULL,
    credential_id   VARCHAR(255) NOT NULL,
    public_key_pem  TEXT NULL,
    estado          ENUM('ACTIVA','REVOCADA') NOT NULL DEFAULT 'ACTIVA',
    fecha_registro  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_credencial),
    UNIQUE KEY uk_cred_id (credential_id),
    INDEX idx_huella_disp_emp (id_dispositivo, clave_empleado, estado),
    CONSTRAINT fk_huella_disp FOREIGN KEY (id_dispositivo) REFERENCES dispositivos_autorizados(id_dispositivo)
) ENGINE=InnoDB;
