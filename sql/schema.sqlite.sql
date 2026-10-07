-- Esquema SQLite (solo para pruebas locales)
CREATE TABLE IF NOT EXISTS escalas (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
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
    operativa     VARCHAR(255) NULL,
    estado        VARCHAR(100) NULL,
    cierre        VARCHAR(30)  NULL,
    eta_original  VARCHAR(30)  NULL,
    primera_vez   DATETIME     NOT NULL,
    ultima_vez    DATETIME     NOT NULL,
    activa        TINYINT(1)   NOT NULL DEFAULT 1,
    UNIQUE (terminal, clave)
);

CREATE TABLE IF NOT EXISTS cambios (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    escala_id       INTEGER NOT NULL,
    campo           VARCHAR(30)  NOT NULL,
    valor_anterior  VARCHAR(255) NULL,
    valor_nuevo     VARCHAR(255) NULL,
    detectado       DATETIME     NOT NULL,
    visto           TINYINT(1)   NOT NULL DEFAULT 0,
    FOREIGN KEY (escala_id) REFERENCES escalas (id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS lecturas (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    terminal  VARCHAR(30)  NOT NULL,
    fecha     DATETIME     NOT NULL,
    ok        TINYINT(1)   NOT NULL,
    filas     INT          NOT NULL DEFAULT 0,
    mensaje   VARCHAR(500) NULL
);
