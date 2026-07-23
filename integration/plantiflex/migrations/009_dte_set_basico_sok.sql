-- =============================================================================
-- Migracion 009: tabla dte_set_basico_sok (confirmacion MANUAL del tenant de
-- que un envio del Set Basico paso la revision de CONTENIDO del SII, SOK).
--
-- BUG QUE CORRIGE: setBasicoAprobado() (panel/public/index.php) solo exigia
-- estado='EPR' (envio procesado tecnicamente) + los 3 tipos de documento en
-- un mismo envio. EPR NO implica que el SII aprobo el CONTENIDO -- ese
-- resultado (SOK/SRH) llega por un correo aparte del SII y, hasta esta
-- migracion, no se persistia en NINGUN lado del sistema. Evidencia real: el
-- envio 0253051518 (EASY AGENDA SPA, 78157243-8) quedo en EPR pero fue
-- rechazado en contenido (SRH); setBasicoAprobado() lo tomo igual como
-- "aprobado" porque era el primero en EPR+3-tipos que encontraba, en vez del
-- envio realmente aprobado (0253079814, EPR + SOK).
--
-- Una fila = el tenant marco ese track_id como SOK (button "Marcar como SOK"
-- en la estacion 5), DESPUES de leer el correo real del SII para ESE envio
-- especifico -- nunca inferido ni migrado automaticamente.
--
-- rut_emisor + ambiente + track_id: mismo scope que dte_libro/dte_emitido.
-- UNIQUE evita duplicados si el tenant hace click dos veces (idempotente).
--
-- Tabla SEPARADA de dte_emitido (no una columna ahi) porque dte_emitido
-- tiene una fila POR DOCUMENTO; la confirmacion SOK es una propiedad del
-- ENVIO completo (track_id), no de cada documento -- guardarla como columna
-- repetida en las 8 filas del envio invitaria a inconsistencias (marcar
-- unas filas si y otras no).
--
-- 100% aditiva: tabla nueva, no se toca ninguna tabla existente.
-- =============================================================================

CREATE TABLE IF NOT EXISTS dte_set_basico_sok (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    rut_emisor        VARCHAR(20) NOT NULL,
    ambiente          ENUM('certificacion','produccion') NOT NULL,
    track_id          VARCHAR(40) NOT NULL,
    confirmado_sok_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY ux_sok_envio (rut_emisor, ambiente, track_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
