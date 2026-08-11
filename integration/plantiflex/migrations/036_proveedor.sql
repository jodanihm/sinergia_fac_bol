-- =============================================================================
-- Migracion 036: tabla proveedor (maestro de proveedores por tenant).
--
-- ESPEJO DE cliente (015), NO UNA GENERALIZACION DE ELLA. La tentacion era
-- convertir cliente en "contraparte" con un tipo, y se descarto con dos motivos
-- medidos:
--
--   1. cliente tiene UNIQUE (cuenta_id, rut_cliente), y UN MISMO RUT PUEDE SER
--      CLIENTE Y PROVEEDOR DE LA MISMA CUENTA. Generalizar obligaba a meter el
--      tipo en esa clave, que es cambiar la afirmacion de la 015 -- "la clave de
--      un cliente dentro de un tenant es su RUT" --, no extenderla.
--
--   2. cliente se toca desde 12 sitios SQL y clienteRepo() se invoca 20 veces en
--      el panel. Una tabla nueva tiene riesgo CERO sobre eso.
--
-- QUE **NO** SE ESPEJA, Y ES LO IMPORTANTE: toda la capa de "puede facturarle".
-- cliente marca como incompletos a los que no tienen giro/direccion/comuna, y el
-- motivo esta citado en su repositorio: "sin ellos el SII no acepta la factura".
-- A UN PROVEEDOR NUNCA LE EMITIMOS UN DTE -- la orden de compra es un documento
-- interno nuestro, como la cotizacion --, asi que esa regla no aplica y NO se
-- copia: sin SQL_INCOMPLETO, sin contarIncompletos(), sin filtro de incompletos
-- ni badge en el listado. Si algun dia hay una regla de "proveedor incompleto",
-- sera otra, la definira el negocio y se decidira a proposito.
--
-- Mismo criterio que 015/016 en todo lo demas: escopada por cuenta_id (identidad
-- estable del tenant), SIN columna ambiente (el maestro es de la empresa), baja
-- LOGICA via activo (nunca fisica), y rut_proveedor guardado YA NORMALIZADO
-- (responsabilidad del handler, via Rut::normalizar), para que el UNIQUE
-- distinga por el valor canonico y no por variantes con o sin puntos.
--
-- CAMPOS EXTRA RESPECTO DE cliente, y son SOLO DOS -- ver el informe: contacto y
-- condiciones_pago. Se eligio el minimo que un comprador necesita para que la
-- orden sirva, en vez de un formulario largo que nadie llena. Plazo de entrega
-- NO va aqui: es de cada orden, no del proveedor.
--
-- 100% aditiva: tabla nueva. FK a cuenta(id) con ON DELETE / ON UPDATE RESTRICT
-- explicitos. El UNIQUE sirve ademas como indice de la FK (prefijo izquierdo
-- cuenta_id), por eso NO se agrega un KEY(cuenta_id) redundante.
-- =============================================================================

CREATE TABLE IF NOT EXISTS proveedor (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    cuenta_id        BIGINT UNSIGNED NOT NULL,
    rut_proveedor    VARCHAR(20)  NOT NULL COMMENT 'RUT normalizado; clave unica dentro de la cuenta',
    razon_social     VARCHAR(255) NOT NULL,
    giro             VARCHAR(255) NULL,
    direccion        VARCHAR(255) NULL,
    comuna           VARCHAR(100) NULL,
    email            VARCHAR(255) NULL COMMENT 'a donde se manda la orden de compra',
    telefono         VARCHAR(50)  NULL,
    contacto         VARCHAR(150) NULL COMMENT 'persona con la que se trata; extra respecto de cliente',
    condiciones_pago VARCHAR(150) NULL COMMENT 'texto libre (ej. "30 dias"); se imprime en la orden',
    activo           TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '1=activo, 0=baja logica (nunca se borra fisico)',
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_proveedor_rut (cuenta_id, rut_proveedor),
    CONSTRAINT fk_proveedor_cuenta FOREIGN KEY (cuenta_id)
        REFERENCES cuenta (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
