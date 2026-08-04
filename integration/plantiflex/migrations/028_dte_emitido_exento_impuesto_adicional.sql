-- =============================================================================
-- Migracion 028: monto exento y monto de impuesto adicional en dte_emitido.
--
-- DOS COLUMNAS Y NO UNA, Y EL MOTIVO ES ARITMETICO
-- -----------------------------------------------------------------------------
-- Hasta hoy dte_emitido guarda neto, iva y total, y NO guarda el exento. Ese
-- hueco se toleraba porque el exento era despejable: total - neto - iva. Es
-- incomodo (informeDetalleDocumentos() documenta que no puede mostrarlo) pero
-- no destructivo.
--
-- Con el impuesto adicional el total pasa a componerse asi (Formato DTE v2.5,
-- campo 124, pag. 31):
--
--     MntTotal = MntNeto + MntExe + IVA + Impuestos Adicionales + ...
--
-- o sea UNA ecuacion con DOS incognitas. Guardar el impuesto adicional sin
-- columna propia no solo dejaria ese monto fuera de la base: volveria
-- IRRECUPERABLE el exento de cualquier documento que llevara los dos. Un
-- pedido de cerveceria con cervezas (afectas, con ILA) y, digamos, un servicio
-- exento, entra exactamente en ese caso.
--
-- Por eso las dos columnas van juntas y en la misma migracion: ya que se abre
-- la tabla, se cierra tambien el hueco que se arrastra desde la factura exenta.
--
--
-- LA COLLATION: LA REGLA DE LA 026 SIGUE VIGENTE Y AQUI NO APLICA
-- -----------------------------------------------------------------------------
-- El esquema vive en dos familias -- tablas del motor en utf8mb4_0900_ai_ci,
-- tablas de las migraciones del panel en utf8mb4_unicode_ci -- y cruzar
-- columnas de TEXTO entre ellas produce "ERROR 1267: Illegal mix of
-- collations". dte_emitido es de la primera familia.
--
-- Estas dos columnas son INT UNSIGNED: los tipos numericos no tienen character
-- set ni collation, asi que se pueden comparar y sumar contra cualquier tabla
-- de la otra familia sin COLLATE explicito. Es el mismo caso que la 026 (TINYINT
-- y DATE) y NO el de la 027, donde glosa_sii SI hereda la collation por ser
-- VARCHAR.
--
--
-- TIPOS ELEGIDOS
-- -----------------------------------------------------------------------------
-- INT UNSIGNED NOT NULL DEFAULT 0, igual que neto/iva/total, que ya son
-- `int unsigned NOT NULL DEFAULT '0'`. Se sigue la columna vecina en vez de
-- inventar un tipo nuevo.
--
-- DEFAULT 0 y NO NULL, y la diferencia importa: aqui cero es un HECHO -- "este
-- documento no tuvo exento" -- y no una ausencia de dato. Es lo contrario de
-- forma_pago en la 026, donde NULL significaba "no se pregunto" y por eso NO se
-- puso default. Un DTE siempre tiene un monto exento; puede ser cero.
--
-- LO QUE EL DEFAULT NO ARREGLA, Y HAY QUE DECIRLO: las filas YA EMITIDAS quedan
-- las dos en 0. Para el impuesto adicional eso es correcto (ningun documento
-- anterior pudo llevarlo: el motor no sabia emitirlo). Para el exento NO lo es:
-- un 34 emitido antes de esta migracion tenia exento > 0 y aqui va a decir 0.
-- Esta migracion NO hace backfill a proposito -- el dato real esta en el XML de
-- cada fila y reconstruirlo es un recorrido con parseo, no un UPDATE; se hace
-- aparte, con su propia verificacion, o no se hace. Lo que no se puede es
-- creerle a esta columna para documentos anteriores a la 028.
--
-- Sin indice: nadie filtra ni ordena por estos montos, se suman en informes que
-- ya escanean por rut+ambiente+fecha (idx_periodo).
--
--
-- IDEMPOTENCIA
-- -----------------------------------------------------------------------------
-- Mismo patron que la 025, la 026 y la 027: ADD COLUMN no admite IF NOT EXISTS
-- en MySQL 8 de Oracle, asi que se consulta information_schema y se arma el
-- ALTER solo si la columna falta. Si ya esta, se ejecuta un SELECT 1 que no hace
-- nada. Nunca borra ni pisa una columna existente.
--
-- Las dos columnas van en UN SOLO ALTER y por lo tanto bajo UNA sola guarda: un
-- corte entre ambas no es posible, asi que la huella del verificador puede
-- pedir las dos juntas sin riesgo de falso "APLICADA".
-- =============================================================================

SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE dte_emitido
            ADD COLUMN exento INT UNSIGNED NOT NULL DEFAULT 0
                COMMENT ''Totales/MntExe del DTE emitido; 0 = sin monto exento''
                AFTER neto,
            ADD COLUMN impuesto_adicional INT UNSIGNED NOT NULL DEFAULT 0
                COMMENT ''Suma de Totales/ImptoReten/MontoImp (ILA y otros); 0 = sin impuesto adicional''
                AFTER iva',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dte_emitido' AND COLUMN_NAME = 'exento'
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
--      AND COLUMN_NAME IN ('exento', 'impuesto_adicional');
--   -- int unsigned, NO, 0, COLLATION_NAME NULL (son numericas).
--
--   SELECT COUNT(*) FROM dte_emitido WHERE exento <> 0 OR impuesto_adicional <> 0;
--   -- debe dar 0 inmediatamente despues de aplicarla (sin backfill).
-- =============================================================================
