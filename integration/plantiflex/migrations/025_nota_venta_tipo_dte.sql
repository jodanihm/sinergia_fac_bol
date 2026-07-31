-- =============================================================================
-- Migracion 025: tipo de DTE a emitir, en nota_venta y en lote_carga.
--
-- POR QUE LA COLUMNA VA EN nota_venta Y NO SOLO EN lote_carga
-- -----------------------------------------------------------------------------
-- Porque la facturacion masiva NO selecciona por archivo. El selector lista
-- pendientes de TODA la cuenta:
--
--   -- listarNotasVentaPendientes(), panel/public/index.php
--   SELECT ... FROM nota_venta WHERE cuenta_id = :c AND estado = 'pendiente'
--   [AND receptor_rut LIKE :rut] ORDER BY fecha_nota ASC, id ASC LIMIT 500
--
-- No hay ni un lote_carga_id en esa consulta, y el sub-lote se arma con un
-- conjunto LIBRE de ids que manda el navegador. O sea que un mismo sub-lote
-- puede mezclar notas de un archivo exento y de uno afecto, y eso esta permitido
-- por construccion. Con el tipo solo en lote_carga, armarDocumentosSubLote()
-- tendria que hacer un JOIN por nota para saber que emitir, y la guarda de
-- folios tendria que agrupar por lote antes de contar. Denormalizado en la nota,
-- cada fila se basta a si misma.
--
-- lote_carga LA LLEVA IGUAL, y no es redundancia inutil: es la constancia de COMO
-- SE SUBIO el archivo. Sirve para explicar por que las notas de ese lote salieron
-- de un tipo, y para que la pantalla de detalle del lote pueda decirlo sin
-- deducirlo de sus notas. La que manda al facturar es SIEMPRE la de la nota.
--
--
-- POR QUE INT Y NO UN ENUM
-- -----------------------------------------------------------------------------
-- Todo el proyecto trata el tipo de DTE como un entero: dte_emitido.tipo_dte,
-- dte_caf.tipo_dte, dte_folio.tipo_dte y boleta_ref_tipo son INT. Un ENUM aqui
-- seria el unico sitio con otra representacion, y agregar un tipo nuevo exigiria
-- un ALTER en vez de nada.
--
-- DEFAULT 33 Y NOT NULL: 33 es exactamente lo que el codigo actual emite
-- (armarDocumentosSubLote lo tiene escrito a mano), asi que el default hace que
-- las filas que YA existen y las que inserte el codigo VIEJO durante la ventana
-- de despliegue queden con el valor correcto sin backfill ni COALESCE. Es el
-- mismo criterio de la 022: la migracion tiene que poder convivir con el codigo
-- anterior.
--
-- Sin CHECK de tipos permitidos: la lista de tipos emitibles vive en el motor
-- (TIPOS_PERMITIDOS de public/index.php) y cambia con el codigo, no con el
-- esquema. Un CHECK aqui obligaria a un ALTER cada vez que se abre un tipo, y
-- ademas dejaria el mensaje de error en manos de MySQL en vez de en las del
-- validador, que puede explicarlo.
--
-- Sin indice nuevo: nadie filtra por tipo_dte en estas dos tablas. El selector
-- filtra por (cuenta_id, estado), ya cubierto por ix_nota_venta_estado, y la
-- columna solo se LEE una vez traida la fila.
--
-- Sin IF NOT EXISTS en ADD COLUMN: esa variante es exclusiva de MariaDB; MySQL
-- 8.x de Oracle falla con error de sintaxis. La idempotencia se resuelve abajo.
--
--
-- IDEMPOTENCIA
-- -----------------------------------------------------------------------------
-- Re-ejecutar esta migracion tiene que ser inofensivo, igual que las de CREATE
-- TABLE IF NOT EXISTS. Como ADD COLUMN no admite IF NOT EXISTS, se consulta
-- information_schema y se arma el ALTER solo si la columna falta; si ya esta, se
-- ejecuta un SELECT 1 que no hace nada. No hay DROP de por medio: esta migracion
-- nunca borra una columna existente ni pisa sus datos.
-- =============================================================================

-- 1. nota_venta.tipo_dte -- la que manda al facturar.
SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE nota_venta ADD COLUMN tipo_dte INT NOT NULL DEFAULT 33
            COMMENT ''tipo de DTE a emitir: 33 factura afecta, 34 factura exenta''
            AFTER monto_estimado',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nota_venta' AND COLUMN_NAME = 'tipo_dte'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. lote_carga.tipo_dte -- constancia de como se subio el archivo.
SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE lote_carga ADD COLUMN tipo_dte INT NOT NULL DEFAULT 33
            COMMENT ''tipo con el que se subio el archivo; la que manda al facturar es nota_venta.tipo_dte''
            AFTER filas_error',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lote_carga' AND COLUMN_NAME = 'tipo_dte'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =============================================================================
-- VERIFICACION POSTERIOR (solo lectura). Las dos columnas existen, son NOT NULL
-- y ninguna fila previa quedo en un tipo que no sea 33:
--
--   SELECT TABLE_NAME, COLUMN_NAME, IS_NULLABLE, COLUMN_DEFAULT
--     FROM information_schema.COLUMNS
--    WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'tipo_dte'
--      AND TABLE_NAME IN ('nota_venta', 'lote_carga');
--
--   SELECT tipo_dte, COUNT(*) FROM nota_venta GROUP BY tipo_dte;
-- =============================================================================
