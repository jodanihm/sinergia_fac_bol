-- =============================================================================
-- Migracion 013: 4 columnas nuevas en dte_emisor para los pasos 4-6 de la
-- certificacion de BOLETA (Revision del Set en el portal SET=2, V°B° del
-- SII, Declaracion de Cumplimiento DE BOLETA) -- pasos 1-3 (CAF/Set/RVD) ya
-- se infieren de datos existentes (dte_caf, dte_emitido, dte_boleta_rvd).
--
-- DECISION: dte_emisor (no tabla propia). Mismo motivo que
-- 010_emisor_etapas_manuales.sql (etapas manuales 2-4 de FACTURA): son UNA
-- marca por RUT/ambiente/cuenta, no una coleccion de intentos -- a
-- diferencia de dte_boleta_rvd o dte_libro (que si son 1 fila por envio,
-- porque puede haber varios intentos). Aqui no hay "varios intentos" que
-- listar: es una casilla que se marca una vez, exactamente igual que
-- certificacion_confirmada_at/simulacion_confirmada_at/etc. Evita ademas un
-- JOIN extra en cada carga de /certificacion/boleta.
--
--   boleta_revision_solicitada_at = NULL/DATETIME -- Revision del Set declarada (SET=2).
--   boleta_revision_track_id      = track ID del SET (no del RVD) que se
--                                    informo en la Revision -- obligatorio en
--                                    el formulario, es literalmente el dato
--                                    que exige el correo del SII.
--   boleta_vobo_at                = NULL/DATETIME -- V°B° del SII confirmado
--                                    (correo o portal, no se puede detectar
--                                    solo -- mismo criterio que las etapas
--                                    manuales de factura).
--   boleta_cumplimiento_confirmado_at = NULL/DATETIME -- Declaracion de
--                                    Cumplimiento DE BOLETA. Columna DISTINTA
--                                    de certificacion_confirmada_at (esa es
--                                    la Declaracion de Cumplimiento DE
--                                    FACTURA, migracion 005): son 2 tramites
--                                    separados ante el SII, cada uno con su
--                                    propio portal (certBolElectDteInternet
--                                    sin SET=2, vs pe_avance1/pe_avance7 de
--                                    factura) -- NUNCA reusar el campo de
--                                    factura para esto.
--
-- 100% aditiva: no se borra ni renombra ninguna columna existente, no se
-- toca ninguna fila. Las 4 columnas son NULLABLE.
--
-- Portable a MySQL 8.x (Oracle) y MariaDB 10.x: SIN IF NOT EXISTS en la
-- clausula ADD COLUMN (exclusiva de MariaDB; MySQL 8.4 falla con error de
-- sintaxis si se usa ahi).
-- =============================================================================

ALTER TABLE dte_emisor
    ADD COLUMN boleta_revision_solicitada_at DATETIME NULL AFTER muestras_impresas_confirmadas_at;

ALTER TABLE dte_emisor
    ADD COLUMN boleta_revision_track_id VARCHAR(40) NULL AFTER boleta_revision_solicitada_at;

ALTER TABLE dte_emisor
    ADD COLUMN boleta_vobo_at DATETIME NULL AFTER boleta_revision_track_id;

ALTER TABLE dte_emisor
    ADD COLUMN boleta_cumplimiento_confirmado_at DATETIME NULL AFTER boleta_vobo_at;
