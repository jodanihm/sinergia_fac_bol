-- =============================================================================
-- Migracion 037: ordenes de compra (correlativo, cabecera y lineas).
--
-- UNA ORDEN DE COMPRA VA EN LA DIRECCION CONTRARIA A TODO LO DEMAS DEL SISTEMA:
-- la emite esta empresa HACIA un proveedor. No es un DTE, no pasa por el SII, no
-- consume folio del CAF y no se escribe en dte_emitido. Vive entera en la
-- familia del panel (utf8mb4_unicode_ci, escopada por cuenta_id).
--
-- SIN ESTADOS DE SEGUIMIENTO, por decision: no hay enviada/recibida/cerrada.
-- Alcanza con activo para la baja logica, igual que cliente y proveedor. Que el
-- correo salio o no se sabe en la cola (038), que es otra cosa: el estado del
-- ENVIO no es el estado de la ORDEN.
--
-- -----------------------------------------------------------------------------
-- 1. orden_compra_correlativo
-- -----------------------------------------------------------------------------
-- IDENTICA a cotizacion_correlativo, y TABLA APARTE en vez de agregarle un tipo
-- a aquella. Aquella tiene PRIMARY KEY (cuenta_id) -- un contador por cuenta,
-- sin tipo de documento --, asi que compartirla haria que la cotizacion 5 y la
-- orden 6 llevaran numeraciones entrelazadas, que no es lo que nadie espera. Y
-- meterle el tipo a la PK obliga a migrar las filas vivas de una tabla que ya
-- esta en produccion.
--
-- La asignacion se hace con transaccion + SELECT ... FOR UPDATE sobre esta fila,
-- copiado TAL CUAL de MySqlCotizacionRepository::asignarNumero() -- incluida la
-- guarda que lanza si no hay transaccion abierta. Ese mecanismo ya se probo bajo
-- concurrencia real con dos conexiones y no se reinventa. Un MAX(numero)+1 no
-- sirve: dos transacciones leen el mismo maximo antes de que ninguna inserte.
--
-- El correlativo NO se reinicia por año: continuo por cuenta, igual que
-- cotizacion.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS orden_compra_correlativo (
    cuenta_id BIGINT UNSIGNED NOT NULL,
    proximo   INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'numero que se entregara en la proxima asignacion',
    PRIMARY KEY (cuenta_id),
    CONSTRAINT fk_oc_correlativo_cuenta FOREIGN KEY (cuenta_id)
        REFERENCES cuenta (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 2. orden_compra: la cabecera.
--
-- PROVEEDOR CONGELADO, ademas de proveedor_id. El maestro puede cambiar y una
-- orden ya enviada tiene que seguir diciendo lo que decia. proveedor_id queda
-- para volver a la ficha; los proveedor_* SON el documento. Mismo criterio que
-- cotizacion con su receptor.
--
-- proveedor_id NULLABLE y SIN FK, igual que cotizacion.cliente_id: se puede
-- comprar una vez a alguien que no esta en el maestro, y la orden no debe
-- impedir nada del maestro.
--
-- LOS TOTALES SE GUARDAN, no se recalculan al mostrar. Una orden enviada es una
-- foto: si el precio del maestro cambia, la orden que el proveedor tiene en su
-- correo no cambia. Por eso neto/iva/total son columnas y no una vista.
--
-- LA REGLA DEL IVA NO SE REINVENTA: es la misma de DteXmlBuilder --
-- TASA_IVA = 19 y el IVA se calcula como round(neto * 19 / 100) sobre el neto ya
-- descontado. Se guarda en enteros (pesos) por el mismo motivo que dte_emitido:
-- un peso con decimales no existe.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS orden_compra (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    cuenta_id             BIGINT UNSIGNED NOT NULL,
    numero                INT UNSIGNED NOT NULL COMMENT 'correlativo por cuenta, continuo',
    proveedor_id          BIGINT UNSIGNED NULL COMMENT 'ficha de origen; el dato que manda es el congelado',
    proveedor_rut         VARCHAR(20)  NOT NULL,
    proveedor_razon_social VARCHAR(255) NOT NULL,
    proveedor_giro        VARCHAR(255) NULL,
    proveedor_direccion   VARCHAR(255) NULL,
    proveedor_comuna      VARCHAR(100) NULL,
    proveedor_email       VARCHAR(255) NULL COMMENT 'foto del correo al emitir; a este se manda',
    proveedor_contacto    VARCHAR(150) NULL,
    condiciones_pago      VARCHAR(150) NULL COMMENT 'copiado del maestro, editable en la orden',
    fecha                 DATE NOT NULL,
    fecha_entrega         DATE NULL COMMENT 'plazo de ESTA orden; por eso no vive en el maestro',
    lugar_entrega         VARCHAR(255) NULL,
    notas                 TEXT NULL,
    neto                  INT UNSIGNED NOT NULL DEFAULT 0,
    exento                INT UNSIGNED NOT NULL DEFAULT 0,
    iva                   INT UNSIGNED NOT NULL DEFAULT 0,
    total                 INT UNSIGNED NOT NULL DEFAULT 0,
    activo                TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=activa, 0=baja logica',
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_orden_compra_numero (cuenta_id, numero),
    KEY ix_orden_compra_proveedor (cuenta_id, proveedor_rut),
    CONSTRAINT fk_orden_compra_cuenta FOREIGN KEY (cuenta_id)
        REFERENCES cuenta (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 3. orden_compra_linea
--
-- LINEAS EN TABLA Y NO EN JSON, por el mismo argumento que cotizacion_linea: la
-- forma del dato ya se conoce. Aqui NO hay saldo por linea -- no existe
-- facturacion parcial de una orden de compra --, asi que la tabla es mas simple:
-- lo que se pide, cuanto y a que precio.
--
-- cantidad y precio_unitario en DECIMAL(14,4) igual que en cotizacion: media
-- hora de servicio o 2,5 kilos son cantidades legitimas, y producto.precio_
-- unitario ya es DECIMAL(14,4).
--
-- exento POR LINEA: una orden puede mezclar items afectos y exentos, y el IVA se
-- calcula solo sobre los afectos. Mismo criterio que cotizacion_linea.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS orden_compra_linea (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    orden_compra_id  BIGINT UNSIGNED NOT NULL,
    orden            INT UNSIGNED NOT NULL COMMENT 'posicion en el impreso; 1..N',
    producto_id      BIGINT UNSIGNED NULL COMMENT 'ficha de origen; el dato que manda es el congelado',
    nombre           VARCHAR(255) NOT NULL,
    descripcion      VARCHAR(500) NULL,
    unidad           VARCHAR(20)  NULL,
    cantidad         DECIMAL(14,4) NOT NULL,
    precio_unitario  DECIMAL(14,4) NOT NULL,
    descuento_pct    DECIMAL(5,2)  NOT NULL DEFAULT 0,
    exento           TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '0=afecto a IVA, 1=exento',
    PRIMARY KEY (id),
    UNIQUE KEY uk_orden_compra_linea_orden (orden_compra_id, orden),
    CONSTRAINT fk_oc_linea_orden FOREIGN KEY (orden_compra_id)
        REFERENCES orden_compra (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_oc_linea_cantidad CHECK (cantidad > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
