-- =============================================================================
-- Migracion 032: cotizaciones (cabecera, lineas y correlativo por cuenta).
--
-- UNA COTIZACION NO ES UN DTE. No pasa por el SII, no consume folio del CAF, no
-- lleva timbre y no se persiste en dte_emitido. Es un documento interno del
-- panel, y por eso vive en la familia de tablas del panel
-- (utf8mb4_unicode_ci, escopadas por cuenta_id) y NO en la del motor
-- (dte_emitido es utf8mb4_0900_ai_ci y se identifica por rut_emisor+ambiente,
-- sin cuenta_id).
--
-- Mismo criterio de diseno que cliente (015) y producto (016): escopada por
-- cuenta_id -- la identidad estable del tenant --, SIN columna ambiente (una
-- cotizacion es de la empresa, no de un ambiente del SII), y baja LOGICA.
--
-- -----------------------------------------------------------------------------
-- POR QUE LINEAS EN TABLA Y NO EN JSON, que es lo que hizo nota_venta (020)
-- -----------------------------------------------------------------------------
--
-- La 020 eligio JSON con este argumento textual: "la forma exacta ... todavia
-- depende de una definicion de negocio que el cliente no cerro. JSON da
-- flexibilidad sin comprometerse a un esquema de tabla que despues haya que
-- migrar". Era una eleccion POR INCERTIDUMBRE, y esa incertidumbre aqui ya se
-- resolvio: la facturacion es 1:N por partes y el saldo se lleva por cantidad y
-- por linea, asi que se sabe exactamente que forma tiene el dato.
--
-- Y hay una diferencia de USO que es la que manda, y esta medida:
-- nota_venta.detalle es ESCRITURA UNICA -- se escribe en la carga y despues solo
-- se lee; los cuatro UPDATE sobre nota_venta tocan estado, error_mensaje y
-- resultado_documentos, nunca detalle. Un saldo es lo contrario: lectura,
-- modificacion y escritura en CADA factura parcial, desde una operacion que
-- puede fallar a la mitad (el motor devuelve 422/502 y el folio ya se quemo).
-- Sobre un blob JSON eso obliga a reescribir el documento entero para cambiar un
-- numero, sin poder bloquear una sola linea ni condicionar el UPDATE a que el
-- saldo no haya cambiado -- que es justo lo que el panel SI hace hoy con
-- nota_venta.estado ("... AND estado = 'pendiente'").
--
-- La propia 020 ya habia reconocido el limite del JSON para consultar: creo
-- monto_estimado como columna plana "para que el resumen (SUM antes de facturar)
-- no tenga que agregar sobre el JSON de detalle en cada consulta". Un saldo se
-- consulta mucho mas que un total.
--
-- -----------------------------------------------------------------------------
-- EL IDENTIFICADOR DE LINEA ES EL VINCULO, Y NO SE EMPAREJA POR CONTENIDO
-- -----------------------------------------------------------------------------
--
-- cotizacion_linea.id es el identificador estable que una linea de factura usara
-- (segunda entrega) para decir de que linea cotizada descuenta. NUNCA se empareja
-- por nombre ni por codigo de producto: una linea agregada a mano en la factura
-- que coincidiera con un producto cotizado consumiria saldo EN SILENCIO. Una
-- linea de factura SIN ese identificador no descuenta nada: es venta nueva.
--
-- Por eso las lineas NO se borran y se recrean al editar: eso cambiaria los id y
-- rompería el vinculo de las facturas ya emitidas. La edicion solo se permite
-- mientras la cotizacion no tenga NADA facturado (ver estado_cache).
--
-- -----------------------------------------------------------------------------
-- 100% aditiva: tres tablas nuevas, no toca ninguna existente. CREATE TABLE con
-- IF NOT EXISTS (MySQL 8.x de Oracle y MariaDB 10.x). FK a cuenta(id) con ON
-- DELETE / ON UPDATE RESTRICT explicitos, igual que 015/016/020.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. cotizacion_correlativo: el numero por cuenta.
--
-- TABLA APARTE Y NO UN MAX(numero)+1, con la misma cautela que dte_folio: dos
-- altas simultaneas no pueden llevarse el mismo numero. La asignacion se hace
-- con transaccion + SELECT ... FOR UPDATE sobre ESTA fila (ver
-- MySqlCotizacionRepository::asignarNumero()), que es el mismo mecanismo que usa
-- MySqlFolioRepository::asignarSiguienteFolio(). Un MAX()+1 no sirve: dos
-- transacciones leen el mismo maximo antes de que ninguna inserte.
--
-- El correlativo NO se reinicia por año: es continuo por cuenta.
--
-- proximo es el numero que se entregara en la PROXIMA asignacion (empieza en 1),
-- mismo criterio que dte_folio.proximo_folio.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cotizacion_correlativo (
    cuenta_id BIGINT UNSIGNED NOT NULL,
    proximo   INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'numero que se entregara en la proxima asignacion',
    PRIMARY KEY (cuenta_id),
    CONSTRAINT fk_cot_correlativo_cuenta FOREIGN KEY (cuenta_id)
        REFERENCES cuenta (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 2. cotizacion: la cabecera.
--
-- RECEPTOR CONGELADO, ADEMAS DE cliente_id. El maestro de clientes puede cambiar
-- (razon social, direccion) y una cotizacion ya entregada al cliente tiene que
-- seguir mostrando lo que decia cuando se emitio. cliente_id queda para poder
-- volver a la ficha; los receptor_* son el documento. Mismo criterio que
-- nota_venta, que tambien guarda los receptor_* planos.
--
-- cliente_id es NULLABLE y sin FK a proposito: un cliente se da de baja LOGICA
-- (activo=0) y nunca se borra, pero tampoco queremos que la cotizacion impida
-- nada del maestro. El dato que manda es el congelado.
--
-- notas: TEXTO LIBRE, y aqui SI existe. En un DTE no cabe -- el Formato DTE no
-- ofrece una glosa libre de documento, y por eso el campo "Observaciones" se
-- quito del formulario de emision -- pero una cotizacion no pasa por el SII:
-- su impreso lo define esta casa.
--
-- estado_cache: VER LA NOTA LARGA MAS ABAJO.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cotizacion (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    cuenta_id             BIGINT UNSIGNED NOT NULL,
    numero                INT UNSIGNED NOT NULL COMMENT 'correlativo por cuenta, continuo, no se reinicia por año',
    cliente_id            BIGINT UNSIGNED NULL COMMENT 'ficha de origen; el dato que manda es el congelado de abajo',
    receptor_rut          VARCHAR(20)  NOT NULL COMMENT 'RUT normalizado, mismo criterio que cliente.rut_cliente',
    receptor_razon_social VARCHAR(255) NOT NULL,
    receptor_giro         VARCHAR(255) NULL,
    receptor_direccion    VARCHAR(255) NULL,
    receptor_comuna       VARCHAR(100) NULL,
    receptor_email        VARCHAR(255) NULL,
    fecha                 DATE NOT NULL,
    valida_hasta          DATE NULL COMMENT 'vigencia; informativa, no bloquea nada en esta entrega',
    notas                 TEXT NULL COMMENT 'glosa libre; existe porque esto NO es un DTE',
    estado_cache          ENUM('sin_facturar','parcial','facturada') NOT NULL DEFAULT 'sin_facturar',
    activo                TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=activa, 0=baja logica (nunca se borra fisico)',
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_cotizacion_numero (cuenta_id, numero),
    KEY ix_cotizacion_estado (cuenta_id, estado_cache),
    KEY ix_cotizacion_receptor (cuenta_id, receptor_rut),
    CONSTRAINT fk_cotizacion_cuenta FOREIGN KEY (cuenta_id)
        REFERENCES cuenta (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- ESTADO: LA VERDAD ESTA EN LAS CANTIDADES. estado_cache ES UN CACHE.
--
-- El estado real de una cotizacion se DERIVA de sus lineas:
--
--   SUM(cantidad_facturada) = 0            -> sin_facturar
--   SUM(cantidad_facturada) < SUM(cantidad)-> parcial
--   toda linea con facturada >= cantidad   -> facturada
--
-- Esa consulta es la unica fuente de verdad y es la que decide si se puede
-- editar. estado_cache existe SOLO para que el listado pueda filtrar con indice
-- (ix_cotizacion_estado): un estado calculado con SUM sobre las lineas no se
-- indexa, y el listado va a querer "pendientes de facturar" como filtro normal.
--
-- ESTA ES LA PARTE QUE LA CASA NO HABIA TENIDO QUE RESOLVER ANTES. El otro cache
-- del repositorio, nota_venta.monto_estimado, se documenta como "calculado UNA
-- vez al validar": funciona porque NUNCA cambia despues. Este si cambia, y
-- ademas cambia desde una operacion que puede fallar a la mitad. Asi que hay que
-- decir quien lo recalcula y cuando, y es lo siguiente:
--
--   QUIEN:  MySqlCotizacionRepository::recalcularEstado($cuentaId, $cotizacionId).
--           Es el UNICO sitio que escribe esta columna. Ningun handler la asigna
--           a mano y ningun INSERT la trae con un valor calculado fuera de ahi.
--
--   CUANDO: (a) al crear la cotizacion, donde por construccion vale
--               'sin_facturar' (todas las lineas nacen con facturada = 0);
--           (b) al editarla, porque editar solo se permite sin nada facturado y
--               el recalculo confirma esa premisa en vez de suponerla;
--           (c) EN LA SEGUNDA ENTREGA, dentro de la MISMA TRANSACCION que
--               incrementa cotizacion_linea.cantidad_facturada, despues de que
--               el motor confirmo la emision -- nunca antes, porque una factura
--               que el motor rechaza no descuenta saldo.
--
--   SI SE DESFASA: el estado_cache es SIEMPRE reconstruible desde las lineas.
--           recalcularEstado() no necesita saber el valor anterior; lo recalcula
--           entero. Un desfase se arregla llamandolo, no editando la columna.
--
-- NUNCA se filtra por estado_cache para decidir si se PUEDE EDITAR: para eso se
-- consultan las cantidades. El cache es para listar, no para autorizar.
-- -----------------------------------------------------------------------------

-- -----------------------------------------------------------------------------
-- 3. cotizacion_linea: donde vive el saldo.
--
-- id ES EL VINCULO. Estable, propio de la linea, y lo que la factura parcial
-- guardara para decir de donde descuenta.
--
-- cantidad y cantidad_facturada son DECIMAL(14,4) y NO enteros: media hora de
-- servicio es un caso legitimo y producto.precio_unitario ya es DECIMAL(14,4).
-- Un saldo por cantidad con tipo entero mentiria en la primera fraccion.
--
-- unidad VIAJA CON LA LINEA y no se edita al facturar. Si la unidad de la
-- factura pudiera diferir de la cotizada, el saldo por cantidad pasaria a
-- significar otra cosa sin que nadie lo note (10 "HH" descontados con 10 "UN").
-- Se congela aqui y la segunda entrega la copia, no la pregunta.
--
-- EL CHECK ES LA ULTIMA LINEA DE DEFENSA. "Facturar mas de lo pendiente se
-- rechaza" se valida en la aplicacion con un mensaje claro, pero tambien aqui:
-- si una ruta futura olvidara la comprobacion, la base la rechaza igual. MySQL
-- 8.0.16+ y MariaDB 10.2+ lo aplican de verdad (en versiones anteriores se
-- parsea y se ignora, que es degradacion silenciosa pero no ruptura).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cotizacion_linea (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'EL VINCULO: la factura parcial guarda este id',
    cotizacion_id      BIGINT UNSIGNED NOT NULL,
    orden              INT UNSIGNED NOT NULL COMMENT 'posicion en el impreso; 1..N',
    producto_id        BIGINT UNSIGNED NULL COMMENT 'ficha de origen; el dato que manda es el congelado',
    nombre             VARCHAR(255) NOT NULL,
    descripcion        VARCHAR(500) NULL,
    unidad             VARCHAR(20)  NULL COMMENT 'congelada; la factura la copia, no la pregunta',
    cantidad           DECIMAL(14,4) NOT NULL,
    cantidad_facturada DECIMAL(14,4) NOT NULL DEFAULT 0 COMMENT 'EL SALDO: pendiente = cantidad - cantidad_facturada',
    precio_unitario    DECIMAL(14,4) NOT NULL COMMENT 'precio COTIZADO; la factura puede usar otro',
    descuento_pct      DECIMAL(5,2)  NOT NULL DEFAULT 0,
    exento             TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '0=afecto a IVA, 1=exento',
    PRIMARY KEY (id),
    UNIQUE KEY uk_cotizacion_linea_orden (cotizacion_id, orden),
    CONSTRAINT fk_cotizacion_linea_cot FOREIGN KEY (cotizacion_id)
        REFERENCES cotizacion (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT ck_cotizacion_linea_cantidad CHECK (cantidad > 0),
    CONSTRAINT ck_cotizacion_linea_saldo CHECK (cantidad_facturada >= 0 AND cantidad_facturada <= cantidad)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
