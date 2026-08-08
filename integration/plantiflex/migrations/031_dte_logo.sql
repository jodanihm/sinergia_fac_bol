-- =============================================================================
-- Migracion 031: logo de la empresa para la representacion impresa.
--
-- QUE RESUELVE
-- -----------------------------------------------------------------------------
-- El PDF sale sin marca. El renderizador YA sabe dibujar un logo -- setLogo() es
-- publico en el fork y agregarEmisor() lo pinta arriba a la izquierda -- pero
-- nadie se lo pasa nunca, porque el PDF se arma SOLO desde el XML almacenado y
-- el logo no viaja en el XML. Esta tabla es de donde va a salir.
--
--
-- SIN AMBIENTE, Y ESO ES LO PRIMERO QUE HAY QUE ENTENDER
-- -----------------------------------------------------------------------------
-- dte_emisor y dte_certificado llevan (rut_emisor, ambiente) en su UNIQUE. Aqui
-- NO. Un logo es de la EMPRESA, no del ambiente: duplicarlo por ambiente daria
-- un PDF con logo o sin el segun donde se emitio el documento, que es un defecto
-- imposible de explicarle a nadie. La clave es rut_emisor a secas.
--
--
-- POR QUE LA CLAVE ES rut_emisor Y NO cuenta_id
-- -----------------------------------------------------------------------------
-- Aunque conceptualmente el logo cuelgue de la cuenta, el camino que lo necesita
-- no tiene cuenta_id a mano. Los tres llamadores que generan PDF -- el endpoint
-- GET /api/v1/dte/{tipo}/{folio}/pdf, el adjunto del correo (PreparadorEnvio) y
-- las muestras impresas -- trabajan con rut_emisor: el motor resuelve su tenant
-- por RUT y nunca carga cuenta_id en ese camino. Colgarlo de cuenta(id)
-- obligaria a un JOIN que hoy no existe, en los tres.
--
--
-- TABLA APARTE Y SIN CIFRAR
-- -----------------------------------------------------------------------------
-- Aparte, por el mismo motivo que dte_certificado esta separada de dte_emisor:
-- un binario grande no tiene por que viajar en cada SELECT de los datos del
-- emisor, que se leen en casi todas las pantallas del panel.
--
-- Sin cifrar, a diferencia de dte_certificado: un logo NO es secreto. Aparece
-- impreso en cada documento que la empresa manda a sus clientes. Cifrarlo seria
-- ceremonia sin beneficio, y ademas obligaria a que el runner de correos
-- tuviera CRYPTO_MASTER_KEY para poder adjuntar un PDF.
--
--
-- MEDIUMBLOB Y NO LONGBLOB
-- -----------------------------------------------------------------------------
-- El tope de la aplicacion son 512 KB (LogoEmpresa::MAX_BYTES, con su
-- aritmetica al lado). MEDIUMBLOB llega a 16 MB, o sea 32 veces el tope: hay
-- margen de sobra para subirlo si alguna vez hace falta, y no se pide el
-- direccionamiento de 4 bytes de LONGBLOB para algo que nunca va a pasar de
-- medio mega.
--
-- El tope REAL lo pone la aplicacion, no la columna. Una columna que aceptara
-- 16 MB sin que nadie mire seria justamente el problema que esta entrega vino a
-- cerrar: hoy no hay NINGUN limite de tamano en ninguna subida del panel.
--
--
-- ancho_px / alto_px / bytes SON DERIVADOS, Y SE GUARDAN IGUAL
-- -----------------------------------------------------------------------------
-- Se pueden recalcular con getimagesizefromstring() sobre el blob, pero eso
-- obliga a leer medio mega para contestar "que tamano tiene este logo" en una
-- pantalla de configuracion. Guardados, la pantalla los muestra con un SELECT
-- que no toca la columna grande.
--
--
-- COLLATION: NO CRUZAR ESTA COLUMNA CON dte_emitido
-- -----------------------------------------------------------------------------
-- rut_emisor es VARCHAR y por lo tanto HEREDA la collation de la tabla, que
-- aqui es utf8mb4_unicode_ci -- la familia de las migraciones del panel, la
-- misma de dte_emisor y dte_certificado. dte_emitido.rut_emisor esta en
-- utf8mb4_0900_ai_ci. Unir las dos por texto sin un COLLATE explicito da
-- "ERROR 1267: Illegal mix of collations", que es la regla que dejaron anotada
-- la 026 y la 027.
--
-- Por eso el codigo NO hace ningun JOIN contra esta tabla: lee el logo con un
-- SELECT propio y el RUT como parametro (ver LogoEmpresa::leer). Un parametro no
-- tiene collation y el problema no se presenta.
--
--
-- IDEMPOTENCIA
-- -----------------------------------------------------------------------------
-- Es una tabla NUEVA, asi que alcanza con CREATE TABLE IF NOT EXISTS -- que
-- MySQL 8 de Oracle si soporta, a diferencia de ADD COLUMN IF NOT EXISTS. Mismo
-- patron que la 015 y la 016, que tambien crean tablas. No toca ninguna tabla
-- existente ni una sola fila.
-- =============================================================================

CREATE TABLE IF NOT EXISTS dte_logo (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    rut_emisor  VARCHAR(20)     NOT NULL COMMENT 'Clave de la empresa. SIN ambiente: el logo es el mismo en certificacion y produccion',
    png         MEDIUMBLOB      NOT NULL COMMENT 'Bytes del PNG tal cual se subieron; el tope de 512 KB lo aplica la aplicacion',
    ancho_px    INT UNSIGNED    NOT NULL COMMENT 'Derivado de getimagesizefromstring, para mostrarlo sin leer el blob',
    alto_px     INT UNSIGNED    NOT NULL,
    bytes       INT UNSIGNED    NOT NULL COMMENT 'strlen(png), por el mismo motivo',
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_logo_emisor (rut_emisor)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- VERIFICACION POSTERIOR (solo lectura):
--
--   SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
--     FROM information_schema.COLUMNS
--    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dte_logo'
--    ORDER BY ORDINAL_POSITION;
--
--   SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE
--     FROM information_schema.STATISTICS
--    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dte_logo';
--   -- uk_logo_emisor sobre rut_emisor, NON_UNIQUE = 0, y NADA de ambiente.
--
--   SELECT rut_emisor, ancho_px, alto_px, bytes FROM dte_logo;
--   -- vacia inmediatamente despues de aplicarla.
-- =============================================================================
