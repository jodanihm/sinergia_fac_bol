-- =============================================================================
-- Migracion 010: 3 columnas nuevas en dte_emisor para las etapas 2-4 de la
-- certificacion de FACTURA (Simulacion, Intercambio, Muestras Impresas), que
-- hoy se gestionan FUERA del panel (portal del SII / correo) y no tenian
-- ningun registro persistido -- igual que certificacion_confirmada_at
-- (migracion 005) para la etapa 6, son confirmaciones MANUALES y EXPLICITAS
-- del tenant, nunca inferidas ni consultadas al SII (no existe webservice
-- para ninguna de las 3).
--
--   simulacion_confirmada_at          = NULL     -> Simulacion no confirmada
--   simulacion_confirmada_at          = DATETIME -> fecha en que el tenant confirmo
--   simulacion_track_id               = track ID opcional que el tenant quiso
--                                        dejar registrado para Simulacion (no
--                                        hay forma de derivarlo automaticamente:
--                                        Simulacion no queda en dte_emitido).
--   intercambio_confirmado_at         = NULL/DATETIME, igual criterio.
--   muestras_impresas_confirmadas_at  = NULL/DATETIME, igual criterio.
--
-- Viven en dte_emisor (no en una tabla aparte) por el mismo motivo que
-- certificacion_confirmada_at: son UNA marca por RUT/ambiente/cuenta, no una
-- coleccion de intentos (a diferencia de dte_set_basico_sok, que si es 1
-- fila por track_id porque un tenant puede tener varios envios del Set
-- Basico). Aqui no hay "varios intentos" que listar: es una casilla que se
-- marca una vez.
--
-- 100% aditiva: no se borra ni renombra ninguna columna existente, no se
-- toca ninguna fila. Las 4 columnas son NULLABLE: las filas existentes de
-- dte_emisor no se rompen (quedan "no confirmada", que es lo correcto).
--
-- Portable a MySQL 8.x (Oracle) y MariaDB 10.x: SIN IF NOT EXISTS en la
-- clausula ADD COLUMN (exclusiva de MariaDB; MySQL 8.4 falla con error de
-- sintaxis si se usa ahi).
-- =============================================================================

ALTER TABLE dte_emisor
    ADD COLUMN simulacion_confirmada_at DATETIME NULL AFTER certificacion_confirmada_at;

ALTER TABLE dte_emisor
    ADD COLUMN simulacion_track_id VARCHAR(40) NULL AFTER simulacion_confirmada_at;

ALTER TABLE dte_emisor
    ADD COLUMN intercambio_confirmado_at DATETIME NULL AFTER simulacion_track_id;

ALTER TABLE dte_emisor
    ADD COLUMN muestras_impresas_confirmadas_at DATETIME NULL AFTER intercambio_confirmado_at;
