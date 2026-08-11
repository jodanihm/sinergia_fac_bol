-- =============================================================================
-- Migracion 033: el vinculo entre una cotizacion y las facturas que salieron
-- de ella (segunda entrega de cotizaciones: la conversion).
--
-- UNA FACTURA POR CONVERSION. Facturar varias de una vez es otra funcionalidad
-- y no existe: cada fila de cotizacion_factura es UNA emision.
--
-- -----------------------------------------------------------------------------
-- POR QUE UNA TABLA Y NO UNA COLUMNA EN dte_emitido
-- -----------------------------------------------------------------------------
--
-- dte_emitido es tabla del MOTOR, no del panel, y esta medido: no tiene
-- cuenta_id (se identifica por UNIQUE(rut_emisor, ambiente, tipo_dte, folio)),
-- es utf8mb4_0900_ai_ci frente al utf8mb4_unicode_ci del panel, y la escribe el
-- motor en un INSERT ... ON DUPLICATE KEY UPDATE de 18 columnas. El panel no
-- inserta ahi, asi que no puede poner un cotizacion_id al emitir; tendria que
-- viajar por la API del motor y el motor pasaria a conocer un concepto que no es
-- suyo. Una FK ademas cruzaria la frontera de collation.
--
-- Asi que el vinculo vive del lado del panel y apunta al documento por su
-- identidad publica: tipo_dte + folio. Es la MISMA forma que ya usa
-- nota_venta.resultado_documentos ([{tipoDte, folio, trackId}]).
--
-- POR QUE TABLA Y NO JSON, a diferencia de nota_venta: alli una nota produce sus
-- documentos DE UNA SOLA VEZ y el JSON se escribe una vez. Aqui una cotizacion
-- produce facturas en VARIAS pasadas, asi que un JSON seria
-- lectura-modificacion-escritura en cada conversion -- el mismo argumento por el
-- que las lineas de la 032 no quedaron en JSON.
--
-- -----------------------------------------------------------------------------
-- LA CLAVE DE IDEMPOTENCIA, AUNQUE HOY NO HAYA RECONCILIADOR
-- -----------------------------------------------------------------------------
--
-- clave_idempotencia es UNIQUE y se deriva del envio, no es aleatoria: mismo
-- criterio que facturarSubLote(), que arma 'sublote-' . sha256(ids ordenados)
-- para que un reintento del MISMO envio produzca la MISMA clave.
--
-- Aqui la clave es 'cot-{cotizacion_id}-{idem_key del formulario}'. El idem_key
-- lo genera el GET del formulario UNA vez y se conserva en el hidden entre
-- reintentos, asi que:
--   - dos envios del MISMO formulario dan la misma clave (el UNIQUE los funde),
--   - dos conversiones distintas de la misma cotizacion dan claves distintas
--     aunque facturen las mismas lineas y cantidades -- que es un caso real:
--     facturar 2 unidades hoy y otras 2 la semana que viene.
--
-- NO HAY RECONCILIADOR Y NO SE VA A CONSTRUIR AHORA: el caso todavia no ocurrio
-- nunca. Esta columna existe para que se pueda construir el dia que haga falta,
-- y no cuesta nada ponerla hoy. Sin ella, un descuento que fallara despues del
-- 201 no tendria como identificarse para repararlo sin adivinar.
--
-- -----------------------------------------------------------------------------
-- 100% aditiva: dos tablas nuevas. FK a cuenta(id) y a cotizacion(id) con ON
-- DELETE / ON UPDATE RESTRICT explicitos, igual que 015/016/020/032.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. cotizacion_factura: UNA emision salida de UNA cotizacion.
--
-- NO hay FK hacia dte_emitido y no es un olvido: es la tabla del motor, en otra
-- familia de collation y sin cuenta_id. tipo_dte + folio + rut_emisor es como se
-- identifica un documento en todo el proyecto.
--
-- rut_emisor se guarda porque el folio SOLO es unico dentro de (rut_emisor,
-- ambiente, tipo_dte): sin el, dos cuentas con emisores distintos podrian tener
-- el mismo tipo+folio y este vinculo apuntaria a dos documentos a la vez.
--
-- ambiente NO se guarda: el panel emite EXCLUSIVAMENTE en produccion (todas las
-- rutas de emision pasan por exigirProduccionCompleto() y usan la key de
-- servicio, filtrada por ambiente='produccion'). Mismo criterio que nota_venta,
-- que tampoco lo lleva y lo deja escrito.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cotizacion_factura (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    cuenta_id           BIGINT UNSIGNED NOT NULL,
    cotizacion_id       BIGINT UNSIGNED NOT NULL,
    rut_emisor          VARCHAR(20) NOT NULL COMMENT 'el folio solo es unico dentro de su emisor',
    tipo_dte            INT NOT NULL,
    folio               INT UNSIGNED NOT NULL,
    track_id            VARCHAR(40) NULL,
    clave_idempotencia  VARCHAR(120) NOT NULL COMMENT 'cot-{cotizacion_id}-{idem_key}; derivada, nunca aleatoria',
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_cot_factura_idem (clave_idempotencia),
    -- Una misma factura no puede colgar de dos cotizaciones.
    UNIQUE KEY uk_cot_factura_doc (rut_emisor, tipo_dte, folio),
    KEY ix_cot_factura_cotizacion (cotizacion_id),
    CONSTRAINT fk_cot_factura_cuenta FOREIGN KEY (cuenta_id)
        REFERENCES cuenta (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_cot_factura_cotizacion FOREIGN KEY (cotizacion_id)
        REFERENCES cotizacion (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 2. cotizacion_factura_linea: CUANTO consumio esa factura de CADA linea.
--
-- ES EL DETALLE QUE PERMITE REPARAR A MANO. cotizacion_linea.cantidad_facturada
-- es un acumulado: si se desfasara, con el acumulado solo no se sabe que
-- factura aporto cuanto. Con estas filas, cantidad_facturada de cada linea es
-- SIEMPRE reconstruible como SUM(cantidad) de aqui.
--
-- Solo se escriben las lineas QUE DESCUENTAN. Una linea agregada a mano en la
-- factura no tiene cotizacion_linea_id y por lo tanto no aparece aqui: es venta
-- nueva dentro de la misma factura, no consumo de saldo.
--
-- El UNIQUE(factura, linea) impide que una misma emision descuente dos veces de
-- la misma linea de cotizacion, que es lo que pasaria si el formulario mandara
-- dos filas con el mismo id.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cotizacion_factura_linea (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    cotizacion_factura_id BIGINT UNSIGNED NOT NULL,
    cotizacion_linea_id   BIGINT UNSIGNED NOT NULL,
    cantidad              DECIMAL(14,4) NOT NULL COMMENT 'lo que ESTA factura descontó de esa linea',
    PRIMARY KEY (id),
    UNIQUE KEY uk_cot_factura_linea (cotizacion_factura_id, cotizacion_linea_id),
    KEY ix_cot_factura_linea_linea (cotizacion_linea_id),
    CONSTRAINT fk_cfl_factura FOREIGN KEY (cotizacion_factura_id)
        REFERENCES cotizacion_factura (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_cfl_linea FOREIGN KEY (cotizacion_linea_id)
        REFERENCES cotizacion_linea (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_cfl_cantidad CHECK (cantidad > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
