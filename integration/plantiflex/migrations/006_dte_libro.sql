-- =============================================================================
-- Migracion 006: tabla dte_libro (persistencia de Libros IECV enviados).
--
-- POST /api/v1/libro ya envia Libros de Ventas/Compras al SII (verificado con
-- EASY AGENDA SPA, 78157243-8: Libro de Ventas trackId 0253051774 -> LOK,
-- Libro de Compras trackId 0253051804 -> LOK), pero hoy NO persiste nada: solo
-- devuelve el trackId al cliente. Sin esto el panel no puede mostrar el
-- estado de los libros, y la certificacion de factura exige 3 componentes
-- (Set Basico + Libro de Ventas + Libro de Compras) -- ver
-- docs/REFERENCIA_CERTIFICACION_SII_DTE.md seccion 4.
--
-- CONVENCIONES: replica las de dte_emitido (ver estructura_facturacion_cl.sql)
-- en vez de las de las tablas de tenancy (001_tenancy.sql): timestamp (no
-- datetime) para created_at, longblob para el xml, varchar(40) para track_id,
-- varchar(20) para estado, mismo charset/collation (utf8mb4_0900_ai_ci) --
-- dte_libro es una tabla hermana de dte_emitido, vive en el mismo dominio.
--
-- Los libros NO usan CAF ni consumen folios (ver LibroService::enviarLibro()),
-- por eso no hay columnas de folio aqui; folio_notificacion es un dato del
-- Libro (Dto\Libro::$folioNotificacion), no un folio de DTE.
--
-- 100% aditiva: tabla nueva, no se toca ninguna tabla existente. Portable a
-- MySQL 8.x (Oracle) y MariaDB 10.x: CREATE TABLE usa IF NOT EXISTS (soportado
-- por ambos motores; distinto de ADD COLUMN, que NO lo soporta en MySQL de
-- Oracle -- ver 001_tenancy.sql).
--
-- Indices: (rut_emisor, ambiente) para listar los libros de un tenant (futura
-- estacion 5 del panel); (track_id) para la consulta de estado por trackId
-- (GET /api/v1/libro/{trackId}/estado-sii).
-- =============================================================================

CREATE TABLE IF NOT EXISTS dte_libro (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    rut_emisor         VARCHAR(20) NOT NULL,
    ambiente           ENUM('certificacion','produccion') NOT NULL,
    tipo_operacion     ENUM('VENTA','COMPRA') NOT NULL,
    periodo_tributario VARCHAR(7) NOT NULL,   -- AAAA-MM
    tipo_libro         VARCHAR(20) NOT NULL,  -- MENSUAL/ESPECIAL/RECTIFICA
    tipo_envio         VARCHAR(20) NOT NULL,  -- TOTAL/PARCIAL/AJUSTE
    folio_notificacion INT UNSIGNED NULL,
    track_id           VARCHAR(40) NULL,
    estado             VARCHAR(20) NOT NULL,  -- 'enviado' al crear; luego LOK/LTC/LRC/etc.
    xml                LONGBLOB NOT NULL,
    created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_libro_emisor (rut_emisor, ambiente),
    KEY ix_libro_track (track_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
