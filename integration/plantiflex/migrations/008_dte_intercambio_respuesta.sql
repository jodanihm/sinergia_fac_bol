-- =============================================================================
-- Migracion 008: tabla dte_intercambio_respuesta (persistencia de las 3
-- respuestas de la etapa "Intercambio de Informacion" del panel).
--
-- Paso 2b del flujo de certificacion en el panel: el tenant sube el EnvioDTE
-- que el SII le entrega en www4.sii.cl/pfeInternet ("SET DE INTERCAMBIO");
-- el panel genera (via EnvioDteParser + RespuestaDteBuilder + EnvioRecibosBuilder,
-- REUSADOS TAL CUAL, sin tocarlos) las 3 respuestas (RecepcionEnvio,
-- ResultadoDTE, EnvioRecibos) para que el tenant las descargue y las suba a
-- mano al mismo portal (no hay API del SII para eso).
--
-- CONVENCIONES: mismo patron que dte_set_pruebas_archivo (007): rut_emisor +
-- ambiente como scope, UNIQUE(rut_emisor, ambiente) porque solo se conserva
-- la ultima generacion (re-subir un EnvioDTE nuevo reemplaza la anterior, sin
-- historial), LONGBLOB para los 4 archivos XML (ISO-8859-1, igual razon que
-- dte_libro/dte_emitido), contenido en claro (no son material secreto).
--
-- numero_intercambio es NULLABLE porque se extrae con un regex best-effort
-- sobre el texto libre "SET INTERCAMBIO NUMERO <n>" (NmbItem del Detalle del
-- EnvioDTE recibido -- confirmado en intercambio_4955508.xml); no es un campo
-- estructurado del EnvioDTE, asi que si el SII cambia esa glosa el campo
-- simplemente queda null, sin romper el resto del flujo.
--
-- 100% aditiva: tabla nueva, no se toca ninguna tabla existente. Portable a
-- MySQL 8.x (Oracle) y MariaDB 10.x: CREATE TABLE usa IF NOT EXISTS.
-- =============================================================================

CREATE TABLE IF NOT EXISTS dte_intercambio_respuesta (
    id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    rut_emisor             VARCHAR(20) NOT NULL,
    ambiente               ENUM('certificacion','produccion') NOT NULL,
    numero_intercambio     INT UNSIGNED NULL,
    archivo_envio_original LONGBLOB NOT NULL,
    respuesta_acuse        LONGBLOB NOT NULL,
    respuesta_resultado    LONGBLOB NOT NULL,
    respuesta_recibos      LONGBLOB NOT NULL,
    created_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY ux_intercambio_emisor (rut_emisor, ambiente)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
