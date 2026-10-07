-- Esquema MySQL / MariaDB
CREATE TABLE IF NOT EXISTS escalas (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    terminal      VARCHAR(30)  NOT NULL,
    clave         VARCHAR(190) NOT NULL,
    buque         VARCHAR(150) NOT NULL,
    viaje         VARCHAR(60)  NULL,
    linea         VARCHAR(100) NULL,
    agencia       VARCHAR(100) NULL,
    muelle        VARCHAR(60)  NULL,
    eta           VARCHAR(30)  NULL,
    etb           VARCHAR(30)  NULL,
    etd           VARCHAR(30)  NULL,
    servicio      VARCHAR(100) NULL,
    operativa     VARCHAR(255) NULL,
    estado        VARCHAR(100) NULL,
    cierre        VARCHAR(30)  NULL,
    eta_original  VARCHAR(30)  NULL,
    primera_vez   DATETIME     NOT NULL,
    ultima_vez    DATETIME     NOT NULL,
    activa        TINYINT(1)   NOT NULL DEFAULT 1,
    UNIQUE KEY uk_terminal_clave (terminal, clave),
    KEY idx_eta (eta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cambios (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    escala_id       INT UNSIGNED NOT NULL,
    campo           VARCHAR(30)  NOT NULL,
    valor_anterior  VARCHAR(255) NULL,
    valor_nuevo     VARCHAR(255) NULL,
    detectado       DATETIME     NOT NULL,
    KEY idx_escala (escala_id),
    KEY idx_detectado (detectado),
    CONSTRAINT fk_cambios_escala FOREIGN KEY (escala_id) REFERENCES escalas (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lecturas (
    id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    terminal  VARCHAR(30)  NOT NULL,
    fecha     DATETIME     NOT NULL,
    ok        TINYINT(1)   NOT NULL,
    filas     INT          NOT NULL DEFAULT 0,
    mensaje   VARCHAR(500) NULL,
    KEY idx_terminal_fecha (terminal, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
