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
    servicio      VARCHAR(100) NULL,
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

-- Clientes y contenedores (seguimiento con Lucía)
CREATE TABLE IF NOT EXISTS clientes (
    id      INTEGER PRIMARY KEY AUTOINCREMENT,
    rut     VARCHAR(12)  NOT NULL UNIQUE,
    nombre  VARCHAR(150) NOT NULL,
    creado  DATETIME     NOT NULL
);

CREATE TABLE IF NOT EXISTS contenedores (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    numero          CHAR(11)     NOT NULL,
    carpeta         VARCHAR(30)  NOT NULL,
    cliente_id      INTEGER NULL REFERENCES clientes (id) ON DELETE SET NULL,
    activo          TINYINT(1)   NOT NULL DEFAULT 1,
    creado          DATETIME     NOT NULL,
    lucia_estado    VARCHAR(20)  NOT NULL DEFAULT 'pendiente',
    lucia_consulta  DATETIME     NULL,
    lucia_buque     VARCHAR(150) NULL,
    lucia_viaje     VARCHAR(60)  NULL,
    lucia_fecha     VARCHAR(30)  NULL,
    lucia_mensaje   VARCHAR(255) NULL,
    lucia_detalle   TEXT         NULL,
    escala_id       INTEGER NULL REFERENCES escalas (id) ON DELETE SET NULL,
    vinculado       DATETIME     NULL,
    usuario         VARCHAR(40)  NULL,
    tipo            VARCHAR(10)  NOT NULL DEFAULT 'entero',
    UNIQUE (numero, carpeta)
);

-- Manifiestos ya consultados en Lucía (varios contenedores comparten manifiesto)
CREATE TABLE IF NOT EXISTS manifiestos (
    clave       VARCHAR(80)  NOT NULL PRIMARY KEY,
    buque       VARCHAR(150) NULL,
    viaje       VARCHAR(60)  NULL,
    fecha       VARCHAR(30)  NULL,
    consultado  DATETIME     NOT NULL,
    detalle     TEXT         NULL
);

-- Usuarios del sitio (además de los que se definan en config.php)
CREATE TABLE IF NOT EXISTS usuarios (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    nombre      VARCHAR(40)  NOT NULL UNIQUE,
    clave_hash  VARCHAR(255) NOT NULL,
    creado      DATETIME     NOT NULL
);
