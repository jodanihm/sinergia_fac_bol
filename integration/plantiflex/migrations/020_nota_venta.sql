-- =============================================================================
-- Migracion 020: tabla nota_venta (M4, carga masiva de notas de venta).
--
-- Una fila = una linea de un archivo Excel cargado (lote_carga), VALIDA o
-- INVALIDA. No existe tabla error_carga aparte: la fila invalida persistida
-- (estado='error' + error_mensaje) ES el informe de errores permanente del
-- lote -- decision final, no un compromiso (se puede revisar un lote meses
-- despues y ver exactamente que fallo en cada fila).
--
-- COLUMNAS TIPADAS NULLABLE (receptor_rut, receptor_razon_social, fecha_nota,
-- detalle, identificador_externo): una fila INVALIDA puede traer datos que no
-- se pueden guardar en su tipo real (fecha basura, RUT vacio, cantidad no
-- numerica). Para esas filas TODAS estas columnas quedan NULL a proposito
-- (no se intenta guardar un valor a medias parseado) y el dato crudo se
-- preserva completo en fila_original (JSON con los 14 valores tal como
-- vinieron del Excel, como texto). identificador_externo en particular
-- SIEMPRE se guarda NULL en las filas de error (nunca el valor crudo): asi
-- una fila duplicada o vacia nunca puede chocar con el UNIQUE ni con otra
-- fila de error igual de vacia (MySQL permite multiples NULL en un indice
-- UNIQUE). El texto de por que fallo vive en error_mensaje, no en estas
-- columnas.
--
-- IDEMPOTENCIA DE NEGOCIO: UNIQUE(cuenta_id, identificador_externo). El motor
-- (POST /api/v1/dte/lote) no tiene idempotencia propia (confirmado en PASO 0
-- de M4: sin header Idempotency-Key, todo-o-nada por sobre). Esta tabla cubre
-- DOS reintentos distintos:
--   1. Recargar el mismo Excel: el INSERT falla por el UNIQUE antes de crear
--      una fila duplicada (detectado en la app con un SELECT previo, para dar
--      un mensaje claro en vez de dejar reventar el UNIQUE).
--   2. Reintentar la FACTURACION de un lote ya procesado: lo cubre la columna
--      estado -- una nota 'facturada' nunca se vuelve a mandar al motor.
--
-- detalle y resultado_documentos en JSON (no tablas aparte): la forma exacta
-- de cuantos documentos produce una nota (siempre 1 factura, o factura + NC
-- si reemplaza una boleta) todavia depende de una definicion de negocio que
-- el cliente no cerro. JSON da flexibilidad sin comprometerse a un esquema de
-- tabla que despues haya que migrar.
--   detalle:              [{nombre,cantidad,precioUnitario,exento,descuentoPorcentaje}]
--   resultado_documentos:  [{tipoDte,folio,trackId}] -- null hasta facturar
--   fila_original:         los 14 valores crudos del Excel, como texto -- solo
--                          para las filas de error (auditoria/informe)
--
-- boleta_ref_tipo/folio/fecha SI son columnas planas (no JSON): es una
-- referencia de forma fija y estable (tipo+folio+fecha), mismo patron que
-- dte_emitido.folio_ref/tipo_dte_ref ya existente en el motor -- no hay
-- ambiguedad ahi que justifique JSON.
--
-- monto_estimado: calculado UNA vez al validar la fila, guardado como cache
-- plano para que el resumen (SUM antes de facturar) no tenga que agregar
-- sobre el JSON de detalle en cada consulta. 0 por defecto (filas de error).
--
-- Sin columna ambiente: M4 factura exclusivamente en produccion (mismo
-- criterio que M3/M5, guardado por exigirProduccionCompleto()).
--
-- 100% aditiva: tabla nueva, no toca ninguna existente. CREATE TABLE con IF
-- NOT EXISTS. FK a cuenta(id) y lote_carga(id) con ON DELETE/ON UPDATE
-- RESTRICT explicitos.
-- =============================================================================

CREATE TABLE IF NOT EXISTS nota_venta (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    cuenta_id             BIGINT UNSIGNED NOT NULL,
    lote_carga_id         BIGINT UNSIGNED NOT NULL,
    identificador_externo VARCHAR(100) NULL COMMENT 'id de reserva/pago del origen; idempotencia de negocio junto a cuenta_id; NULL en filas de error',
    receptor_rut          VARCHAR(20) NULL,
    receptor_razon_social VARCHAR(255) NULL,
    receptor_giro         VARCHAR(255) NULL,
    receptor_direccion    VARCHAR(255) NULL,
    receptor_comuna       VARCHAR(100) NULL,
    receptor_email        VARCHAR(255) NULL,
    fecha_nota            DATE NULL COMMENT 'fecha de la reserva/venta original; informativa',
    detalle               JSON NULL,
    monto_estimado        INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'cache calculado al validar, para el resumen; 0 en filas de error',
    boleta_ref_tipo       INT NULL COMMENT 'normalmente 39; NULL si esta nota no reemplaza una boleta previa',
    boleta_ref_folio      INT UNSIGNED NULL,
    boleta_ref_fecha      DATE NULL,
    estado                ENUM('pendiente','en_proceso','facturada','error') NOT NULL DEFAULT 'pendiente',
    error_mensaje         VARCHAR(500) NULL COMMENT 'solo si estado=error',
    fila_original         JSON NULL COMMENT 'los 14 valores crudos del Excel; solo se guarda en filas de error',
    resultado_documentos  JSON NULL COMMENT 'documentos emitidos; null hasta facturar',
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_nota_venta_externa (cuenta_id, identificador_externo),
    KEY ix_nota_venta_lote (lote_carga_id),
    KEY ix_nota_venta_estado (cuenta_id, estado),
    CONSTRAINT fk_nota_venta_cuenta FOREIGN KEY (cuenta_id)
        REFERENCES cuenta (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_nota_venta_lote FOREIGN KEY (lote_carga_id)
        REFERENCES lote_carga (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
