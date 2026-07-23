-- =============================================================================
-- Migracion 012: tabla dte_boleta_rvd (persistencia del RVD/ConsumoFolios de
-- boleta enviado).
--
-- El RVD NO es un DTE (no tiene TipoDTE/Folio propio, es un resumen diario de
-- consumo de folios) -- por eso no puede vivir en dte_emitido, igual motivo
-- por el que dte_libro (migracion 006) es una tabla aparte. Sin esto, la
-- estacion de boleta (GET /certificacion/boleta) no puede mostrar "RVD
-- enviado" de forma persistente entre requests: no hay ningun otro dato en el
-- esquema que lo indique.
--
-- Primera pieza de la futura estacion 5b de boleta: solo cubre Set de Boleta
-- (dte_emitido, ya existente) + RVD (esta tabla). El resto de los pasos del
-- proceso de boleta (Intercambio, Muestras, Declaracion Cumplimiento propios
-- de boleta) quedan para una tarea aparte, sin tocar aqui.
--
-- 100% aditiva: tabla nueva, no se toca ninguna tabla existente. Portable a
-- MySQL 8.x (Oracle) y MariaDB 10.x: CREATE TABLE usa IF NOT EXISTS.
-- =============================================================================

CREATE TABLE IF NOT EXISTS dte_boleta_rvd (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    rut_emisor VARCHAR(20) NOT NULL,
    ambiente   ENUM('certificacion','produccion') NOT NULL,
    fecha_rvd  DATE NOT NULL,
    track_id   VARCHAR(40) NULL,
    estado     VARCHAR(20) NOT NULL,  -- 'enviado' al crear
    xml        LONGBLOB NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_boleta_rvd_emisor (rut_emisor, ambiente)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
