-- =============================================================================
-- Migracion 056: carga_adaptacion -- el archivo de un cliente adaptado a la
-- plantilla de notas de venta, y quien autorizo importarlo.
--
-- QUE RESUELVE
-- -----------------------------------------------------------------------------
-- La carga masiva exige la plantilla exacta. Los clientes mandan lo que exporta
-- su sistema: otras columnas, otro orden, montos con "$". El adaptador
-- (/ventas/carga-masiva/adaptar) propone las equivalencias, muestra que va a
-- cambiar y SOLO importa cuando el usuario lo autoriza expresamente. Esta tabla
-- es la memoria de ese proceso: una fila por archivo subido al adaptador.
--
-- POR QUE EL ARCHIVO VA EN LA BASE (MEDIUMBLOB) Y NO EN DISCO
-- -----------------------------------------------------------------------------
-- 1. Hay que CONSERVAR EL ORIGINAL: es la prueba de que lo que se importo salio
--    de lo que el cliente mando. En la base viaja con los respaldos que ya
--    existen; en disco haria falta un volumen nuevo, con su propio respaldo.
-- 2. Ya hay precedente: 007 (archivos del set de pruebas) y 031 (logo).
-- 3. El adaptador acepta hasta 16 MB, que es el tope de MEDIUMBLOB. El
--    max_allowed_packet de produccion es 64 MB.
--
-- POR QUE TAMBIEN SE GUARDA EL CORREGIDO, si se puede regenerar
-- -----------------------------------------------------------------------------
-- Se puede regenerar con el codigo de HOY. Si manana cambia una regla de
-- normalizacion, regenerarlo daria un archivo distinto del que se importo, y la
-- auditoria mentiria. Se guarda el que de verdad entro, al momento de importar.
--
-- QUE QUEDA REGISTRADO DE LA AUTORIZACION
-- -----------------------------------------------------------------------------
-- autorizado_por / autorizado_en / autorizado_ip: quien, cuando, desde donde.
-- huella_autorizada: sha256 de las filas EXACTAS que el usuario vio en la vista
--   previa. Al importar se recalcula y tiene que coincidir: lo autorizado y lo
--   importado son demostrablemente lo mismo.
-- cambios: la lista completa de cada celda que cambio (fila, columna, antes,
--   despues, tipo), mas las equivalencias y valores que eligio el usuario.
--
-- 100% aditiva: tabla nueva, no toca ninguna existente.
-- =============================================================================

CREATE TABLE IF NOT EXISTS carga_adaptacion (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    cuenta_id           BIGINT UNSIGNED NOT NULL,
    usuario_id          BIGINT UNSIGNED NOT NULL COMMENT 'quien subio el archivo',
    nombre_archivo      VARCHAR(255) NOT NULL,
    extension           VARCHAR(10)  NOT NULL COMMENT 'xlsx, xls o csv',
    archivo_original    MEDIUMBLOB   NOT NULL COMMENT 'el archivo tal como llego; nunca se modifica',
    sha256_original     CHAR(64)     NOT NULL,
    hoja                VARCHAR(255) NULL     COMMENT 'hoja de donde se leyeron los datos',
    fila_encabezado     INT UNSIGNED NOT NULL COMMENT 'numero de fila del encabezado en esa hoja (base 1)',
    encabezados         JSON         NOT NULL COMMENT 'los encabezados del archivo, en su orden',
    -- Misma firma para el mismo formato de archivo (ver AdaptadorPlantilla::firma):
    -- la proxima vez que el cliente mande ese formato se proponen las
    -- equivalencias que ya se confirmaron.
    firma_encabezados   CHAR(64)     NOT NULL,
    -- Lo que el usuario fue eligiendo. NULL hasta que guarda la primera vez.
    mapeo               JSON         NULL COMMENT 'indice de columna del archivo => columna de la plantilla',
    constantes          JSON         NULL COMMENT 'columna => valor indicado para todas las filas',
    equivalencias       JSON         NULL COMMENT 'columna => {valor del archivo => valor de la plantilla}',
    identificador_por_fila TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'el usuario autorizo identificar cada nota por su fila',
    es_exento           TINYINT(1)   NOT NULL DEFAULT 0,
    estado              ENUM('borrador','importada','descartada') NOT NULL DEFAULT 'borrador',
    -- La autorizacion. Las cinco van juntas: o estan todas (importada) o
    -- ninguna (borrador/descartada).
    autorizado_por      BIGINT UNSIGNED NULL,
    autorizado_en       DATETIME     NULL,
    autorizado_ip       VARCHAR(45)  NULL,
    huella_autorizada   CHAR(64)     NULL COMMENT 'sha256 de las filas vistas en la vista previa',
    cambios             JSON         NULL COMMENT 'cada celda que cambio y las elecciones del usuario',
    archivo_corregido   MEDIUMBLOB   NULL COMMENT 'el .xlsx con la forma de la plantilla que se importo',
    lote_carga_id       BIGINT UNSIGNED NULL COMMENT 'el lote que produjo la importacion',
    created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- Un lote sale de UNA adaptacion como mucho. Es lo que impide que dos
    -- confirmaciones simultaneas de la misma adaptacion terminen apuntando al
    -- mismo lote (la otra mitad de esa garantia es el SELECT ... FOR UPDATE
    -- de la importacion).
    UNIQUE KEY uk_adaptacion_lote (lote_carga_id),
    KEY ix_adaptacion_cuenta (cuenta_id, created_at),
    KEY ix_adaptacion_firma (cuenta_id, firma_encabezados, estado),
    CONSTRAINT fk_adaptacion_cuenta FOREIGN KEY (cuenta_id)
        REFERENCES cuenta (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_adaptacion_usuario FOREIGN KEY (usuario_id)
        REFERENCES usuario (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_adaptacion_autorizado FOREIGN KEY (autorizado_por)
        REFERENCES usuario (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_adaptacion_lote FOREIGN KEY (lote_carga_id)
        REFERENCES lote_carga (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
