-- =============================================================================
-- Migracion 027: glosa del veredicto del SII en dte_emitido.
--
-- POR QUE, Y CUANTO COSTO NO TENERLA
-- -----------------------------------------------------------------------------
-- Un cliente emitio 68 facturas exentas en produccion. El sistema las dio por
-- buenas y mando los correos; el SII habia RECHAZADO los siete sobres por un
-- error de caratula. Nadie se entero hasta el dia siguiente.
--
-- La consulta al SII (QueryEstUp.jws) devuelve DOS datos en su RESP_HDR: ESTADO
-- y GLOSA. El codigo leia los dos (SiiConsultor::consultarEnvio) pero solo
-- persistia el primero: la glosa viajaba hasta el handler y se descartaba ahi.
-- En el incidente, ESTADO decia "RCT" -- un codigo de tres letras -- y la GLOSA
-- decia "Rechazado por Error en Caratula", con el detalle "(CRT-3-19)
-- Fecha/Numero Resolucion Invalido" que explicaba la causa exacta. Ese era el
-- dato que resolvia el problema, y era el que se estaba tirando.
--
-- Esta columna existe para que ese texto quede escrito al lado del estado.
--
--
-- ES UNA COLUMNA DE TEXTO, Y ESO REACTIVA LA REGLA DE LAS COLLATIONS
-- -----------------------------------------------------------------------------
-- La migracion 026 dejo anotado que sus columnas cruzaban sin problema entre las
-- dos familias de collation del esquema (tablas del motor en utf8mb4_0900_ai_ci,
-- tablas de las migraciones del panel en utf8mb4_unicode_ci) PORQUE eran TINYINT
-- y DATE: los tipos numericos y de fecha no tienen character set ni collation.
-- Y advertia, literal, que "si alguna vez se agregara una glosa de texto
-- (TermPagoGlosa, por ejemplo), esa SI heredaria la collation de su tabla y
-- volveria a aplicar la regla de no cruzarlas por texto".
--
-- Esto es exactamente esa glosa. glosa_sii nace VARCHAR y por lo tanto hereda
-- utf8mb4_0900_ai_ci de dte_emitido. NO se puede comparar ni unir con una
-- columna de texto de nota_venta, lote_carga, cliente ni de ninguna otra tabla
-- creada por las migraciones del panel sin un COLLATE explicito: MySQL responde
-- "ERROR 1267: Illegal mix of collations". Hoy nadie la cruza y no hay motivo
-- para hacerlo -- es un texto para leer, no una clave.
--
--
-- VARCHAR(255) Y NO TEXT
-- -----------------------------------------------------------------------------
-- La glosa observada en el incidente ronda los 80 caracteres. 255 deja tres
-- veces ese margen y mantiene la columna en la fila, sin el salto a
-- almacenamiento externo de TEXT en una tabla que ya carga el XML en un
-- longblob. El codigo que escribe TRUNCA a 255 de forma explicita (ver
-- RegistroVeredictoSii::persistir) en vez de dejar que MySQL corte o falle
-- segun el sql_mode: una glosa cortada sigue siendo util, un INSERT que revienta
-- por 3 caracteres de mas no.
--
-- NULL significa "nunca se consulto" o "se consulto y el SII no mando glosa".
-- No se pone DEFAULT '': una cadena vacia y un NULL contarian la misma historia
-- y no son lo mismo.
--
-- Sin indice: nadie filtra por glosa, se lee. El filtro util es por estado, y
-- ese indice (idx_estado) ya existe desde el esquema original.
--
--
-- IDEMPOTENCIA
-- -----------------------------------------------------------------------------
-- Mismo patron que la 025 y la 026: ADD COLUMN no admite IF NOT EXISTS en MySQL
-- 8 de Oracle (esa variante es exclusiva de MariaDB), asi que se consulta
-- information_schema y se arma el ALTER solo si la columna falta. Si ya esta, se
-- ejecuta un SELECT 1 que no hace nada. Nunca borra ni pisa una columna
-- existente, y no toca ni una fila de datos.
-- =============================================================================

SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE dte_emitido
            ADD COLUMN glosa_sii VARCHAR(255) NULL
                COMMENT ''RESP_HDR/GLOSA de la ultima consulta al SII; NULL = nunca consultado''
                AFTER estado',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dte_emitido' AND COLUMN_NAME = 'glosa_sii'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =============================================================================
-- VERIFICACION POSTERIOR (solo lectura):
--
--   SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLLATION_NAME
--     FROM information_schema.COLUMNS
--    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dte_emitido'
--      AND COLUMN_NAME = 'glosa_sii';
--   -- varchar(255), YES, NULL, utf8mb4_0900_ai_ci
--
--   SELECT COUNT(*) AS con_glosa FROM dte_emitido WHERE glosa_sii IS NOT NULL;
--   -- debe dar 0 inmediatamente despues de aplicarla.
-- =============================================================================
