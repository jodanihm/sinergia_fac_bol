-- =============================================================================
-- Migracion 050: la orden de pago de cada documento, para que el correo pueda
-- llevar un link de cobro.
--
-- QUE RESUELVE
-- -----------------------------------------------------------------------------
-- El correo que recibe el receptor de una factura lleva el XML y el PDF, y nada
-- mas. Si quiere pagar, tiene que averiguar por su cuenta como hacerlo. Esta
-- tabla guarda la orden de cobro que se le crea a cada documento en la pasarela
-- de la empresa emisora, con su link, para que PreparadorEnvio lo pueda pegar
-- en el cuerpo del correo.
--
--
-- POR QUE UNA FILA POR DOCUMENTO Y NO UN CAMPO EN dte_envio_correo
-- -----------------------------------------------------------------------------
-- Porque son dos cosas con vidas distintas. La cola de correos describe un
-- ENVIO: se reintenta, se marca enviado y ahi termina. Una orden de cobro
-- sobrevive al correo -- alguien puede pagarla tres semanas despues -- y va a
-- recibir novedades (el webhook de la pasarela) mucho tiempo despues de que el
-- correo se haya dado por enviado. Meterla como columnas de dte_envio_correo
-- ataria el cobro al ciclo de vida del mensaje, que no es el suyo.
--
--
-- uk_pago_link_documento ES EL CORAZON DE ESTA TABLA
-- -----------------------------------------------------------------------------
-- El UNIQUE sobre dte_emitido_id es lo que impide COBRAR DOS VECES. El link se
-- crea de forma perezosa, dentro del runner de correos, que reintenta: sin esta
-- restriccion, un reintento crearia una segunda orden de cobro por el mismo
-- documento y el receptor podria pagar dos veces. Es el mismo criterio y el
-- mismo motivo que uk_envio_documento en dte_envio_correo (migracion 024), solo
-- que aqui el precio de equivocarse es dinero de un tercero.
--
-- Ese UNIQUE es ademas la razon de que no haga falta ninguna transaccion: el
-- INSERT es el candado.
--
--
-- LA FILA SE INSERTA ANTES DE LLAMAR A LA PASARELA, Y referencia ES POR QUE
-- -----------------------------------------------------------------------------
-- El UNIQUE de arriba protege del reintento ordenado, pero no del caso feo: la
-- peticion sale, la pasarela CREA la orden, y la respuesta se pierde en el
-- camino. Nosotros no nos enteramos y volvemos a pedirla. Ahi ya hay dos ordenes
-- de cobro y el receptor puede pagar dos veces.
--
-- Por eso la fila se inserta con estado='pendiente' ANTES de la llamada, con una
-- referencia DETERMINISTA -- calculada desde (cuenta, tipo, folio), nunca
-- aleatoria -- que es la que viaja como clave del comercio. Si hay que
-- reintentar se manda LA MISMA, y la pasarela reconoce que esa orden ya existe
-- en vez de crear otra. El UNIQUE local es la mitad de la defensa; la referencia
-- estable es la otra mitad, y es la que cubre el corte de red.
--
-- ESTO HAY QUE CONFIRMARLO CONTRA LA PASARELA antes de escribir el adaptador: el
-- diseño depende de que trate commerceOrder como clave unica del comercio. Flow
-- lo hace; una que no lo hiciera necesitaria otra estrategia.
--
--
-- reintentar_despues_at: ESPERAR NO ES MACHACAR
-- -----------------------------------------------------------------------------
-- El correo espera a que haya link (decision de producto), y el runner pasa cada
-- 5 minutos. Sin freno, una pasarela caida costaria 12 llamadas por hora y por
-- documento retenido, y alargaria cada corrida con peticiones que van a fallar
-- igual. Esta columna guarda desde cuando tiene sentido volver a intentar, con
-- espera creciente. Un fallo que NO se arregla reintentando -- credenciales
-- rechazadas -- se aparca un dia entero y queda esperando a una persona.
--
--
-- monto ES UNA FOTO, NO UN JOIN
-- -----------------------------------------------------------------------------
-- Se guarda el total por el que se creo la orden en vez de leerlo de
-- dte_emitido cada vez. Un link ya emitido cobra lo que cobra; si manana el
-- documento se corrige o se anula con una NC, el historial tiene que seguir
-- diciendo por cuanto se cobro, no por cuanto se cobraria hoy. INT UNSIGNED
-- porque los montos del SII son enteros en pesos, igual que dte_emitido.total.
--
--
-- EL WEBHOOK NO EXISTE TODAVIA, Y AUN ASI SUS COLUMNAS ESTAN
-- -----------------------------------------------------------------------------
-- estado='pagado', pagado_at e ix_pago_link_orden no los usa nadie hoy: esta
-- entrega crea el link y no concilia pagos. Van igual, y a proposito. El dia que
-- entre el webhook lo primero que va a necesitar es buscar una fila POR EL ID DE
-- LA ORDEN EN LA PASARELA (que es lo unico que trae la notificacion) y marcarla
-- pagada. Dejarlo listo cuesta un indice y dos columnas nulas ahora; agregarlo
-- despues cuesta una migracion sobre una tabla que ya estara en produccion con
-- ordenes de cobro reales.
--
-- 'omitido' tampoco es decorativo: es el estado que deja el boton "enviar sin
-- link" del panel cuando una persona decide soltar un correo retenido porque la
-- pasarela no responde. Sin ese valor, esa decision no tendria donde anotarse y
-- el correo volveria a quedarse esperando en la corrida siguiente.
--
--
-- COLLATION
-- -----------------------------------------------------------------------------
-- utf8mb4_unicode_ci, la familia de las tablas del panel (cuenta, cliente,
-- dte_envio_correo). dte_emitido es de la familia del motor
-- (utf8mb4_0900_ai_ci), y por eso las dos FK van por id numerico y no por
-- rut_emisor: un JOIN entre familias distintas revienta con "Illegal mix of
-- collations". Es la misma regla que ya siguen las migraciones 024, 026 y 045.
--
--
-- IDEMPOTENCIA
-- -----------------------------------------------------------------------------
-- CREATE TABLE IF NOT EXISTS. No toca ninguna tabla existente ni ningun dato.
-- =============================================================================

CREATE TABLE IF NOT EXISTS dte_pago_link (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    dte_emitido_id BIGINT UNSIGNED NOT NULL
                   COMMENT 'documento que se cobra; UNIQUE para no crear dos ordenes (migracion 050)',
    cuenta_id      BIGINT UNSIGNED NOT NULL
                   COMMENT 'tenant duenio del cobro, para listar y filtrar sin JOIN (migracion 050)',
    proveedor      VARCHAR(30) NOT NULL
                   COMMENT 'pasarela usada: flow, khipu, ... (migracion 050)',
    referencia     VARCHAR(64) NOT NULL
                   COMMENT 'NUESTRA clave de idempotencia enviada a la pasarela (commerceOrder), determinista (migracion 050)',
    orden_externa  VARCHAR(120) NULL
                   COMMENT 'id de la orden en la pasarela; por aqui la buscara el webhook (migracion 050)',
    url            VARCHAR(500) NULL
                   COMMENT 'link de pago devuelto por la pasarela (migracion 050)',
    monto          INT UNSIGNED NOT NULL DEFAULT 0
                   COMMENT 'foto del total por el que se creo la orden, en pesos (migracion 050)',
    estado         ENUM('pendiente','creado','error','omitido','pagado')
                   NOT NULL DEFAULT 'pendiente'
                   COMMENT 'omitido = una persona solto el correo sin link (migracion 050)',
    intentos       INT UNSIGNED NOT NULL DEFAULT 0
                   COMMENT 'intentos de CREAR la orden; NO son los intentos del correo (migracion 050)',
    ultimo_error   VARCHAR(500) NULL
                   COMMENT 'ultimo fallo al hablar con la pasarela (migracion 050)',
    reintentar_despues_at TIMESTAMP NULL DEFAULT NULL
                   COMMENT 'backoff: antes de esta hora no se vuelve a llamar a la pasarela (migracion 050)',
    creado_at      TIMESTAMP NULL DEFAULT NULL
                   COMMENT 'cuando la pasarela acepto la orden (migracion 050)',
    pagado_at      TIMESTAMP NULL DEFAULT NULL
                   COMMENT 'lo llenara el webhook; hoy siempre NULL (migracion 050)',
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_pago_link_documento (dte_emitido_id),
    UNIQUE KEY uk_pago_link_referencia (referencia),
    KEY ix_pago_link_estado (cuenta_id, estado),
    KEY ix_pago_link_orden (proveedor, orden_externa),
    CONSTRAINT fk_pago_link_documento FOREIGN KEY (dte_emitido_id)
        REFERENCES dte_emitido (id) ON DELETE CASCADE ON UPDATE RESTRICT,
    CONSTRAINT fk_pago_link_cuenta FOREIGN KEY (cuenta_id)
        REFERENCES cuenta (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- VERIFICACION POSTERIOR (solo lectura):
--
--   SELECT TABLE_NAME, TABLE_COLLATION FROM information_schema.TABLES
--    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dte_pago_link';
--   -- dte_pago_link, utf8mb4_unicode_ci
--
--   SELECT INDEX_NAME, NON_UNIQUE, COLUMN_NAME FROM information_schema.STATISTICS
--    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dte_pago_link'
--    ORDER BY INDEX_NAME, SEQ_IN_INDEX;
--   -- uk_pago_link_documento y uk_pago_link_referencia, los dos con NON_UNIQUE = 0
--
--   SELECT COLUMN_NAME, COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS
--    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dte_pago_link'
--      AND DATA_TYPE = 'timestamp';
--   -- creado_at, pagado_at y reintentar_despues_at con COLUMN_DEFAULT NULL y
--   -- EXTRA vacio. Si alguna saliera con DEFAULT_GENERATED / on update
--   -- CURRENT_TIMESTAMP, la base tiene explicit_defaults_for_timestamp = OFF y
--   -- se la puso MySQL sola: el backoff mentiria.
--
--   SELECT CONSTRAINT_NAME, REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE
--    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dte_pago_link'
--      AND REFERENCED_TABLE_NAME IS NOT NULL;
--   -- fk_pago_link_documento -> dte_emitido, fk_pago_link_cuenta -> cuenta
--
--   SELECT COLUMN_TYPE FROM information_schema.COLUMNS
--    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dte_pago_link'
--      AND COLUMN_NAME = 'estado';
--   -- enum('pendiente','creado','error','omitido','pagado')
-- =============================================================================
