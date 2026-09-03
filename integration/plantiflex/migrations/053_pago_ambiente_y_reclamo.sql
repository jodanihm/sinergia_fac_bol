-- =============================================================================
-- Migracion 053: ambiente de la pasarela, reclamo exclusivo y rastro de la
-- confirmacion que no se pudo consultar.
--
-- QUE RESUELVE
-- -----------------------------------------------------------------------------
-- Tres defectos que una auditoria del modulo de cobro dejo por escrito antes de
-- la primera prueba. Van juntos en una sola migracion porque los tres son
-- columnas nuevas sobre las dos tablas que ya creo la 050/051 y porque los tres
-- son requisito de la MISMA prueba: sin ellos no se puede probar en sandbox ni
-- se puede garantizar que no haya un doble cobro.
--
--
-- 1) pago_pasarela_cuenta.ambiente -- SIN ESTO NO SE PUEDE PROBAR
-- -----------------------------------------------------------------------------
-- ResolutorLinkPago construia las credenciales con sandbox=false FIJO en el
-- codigo, asi que toda orden salia contra www.flow.cl. Las constantes de sandbox
-- del proveedor eran codigo inalcanzable y no habia forma de hacer una prueba de
-- punta a punta sin cobrarle a alguien de verdad.
--
-- EL DEFAULT ES 'sandbox', Y ES LA DECISION QUE IMPORTA. Una columna nueva con
-- default 'produccion' significaria que cualquier fila creada por un formulario
-- que todavia no manda el campo -- o por un script, o por una restauracion de
-- respaldo -- empieza cobrando dinero real sin que nadie lo haya elegido. Al
-- reves, el peor caso de equivocarse hacia sandbox es que un cobro no se cree y
-- alguien lo note. Los errores de este modulo no son simetricos y el default
-- tiene que caer del lado barato.
--
-- NO HAY BACKFILL, Y SE COMPROBO ANTES DE DECIDIRLO: pago_pasarela_cuenta esta
-- VACIA (0 filas) en produccion al escribir esta migracion, asi que no existe
-- ninguna configuracion en marcha a la que este default pueda cambiarle el
-- comportamiento por sorpresa. Si alguna vez se aplica sobre una base con filas,
-- todas quedarian en sandbox: dejarian de cobrar hasta que alguien las revise,
-- que es exactamente el fallo que se prefiere.
--
--
-- 2) dte_pago_link.reclamado_at -- EL RECLAMO EXCLUSIVO
-- -----------------------------------------------------------------------------
-- uk_pago_link_documento garantiza UNA FILA por documento, pero no garantiza UNA
-- LLAMADA a la pasarela. Dos procesos que entraban a la vez -- el cron y
-- scripts/enviar_correo.php, que no toma el candado del runner -- perdian los dos
-- el INSERT contra el UNIQUE, los dos se quedaban con el mismo id, y los dos
-- seguian adelante y llamaban a crear la orden. Que no naciera un doble cobro
-- dependia enteramente de que la pasarela dedupliqe commerceOrder, cosa que este
-- proyecto NO ha verificado.
--
-- Con esta columna, quien quiere llamar a la pasarela tiene que GANAR un UPDATE
-- condicionado, y solo uno lo gana. Es un candado por documento y en la base,
-- que es donde los dos procesos se pueden ver.
--
-- NO ES UN BLOQUEO ETERNO. Guarda la HORA del reclamo, no un booleano: un
-- proceso que muera despues de reclamar dejaria el documento bloqueado para
-- siempre si esto fuera un flag. Pasado un plazo prudente (el codigo lo fija en
-- RECLAMO_TTL_MINUTOS) el reclamo se considera abandonado y otro proceso lo
-- puede tomar. Ese plazo tiene que ser MAYOR que el timeout de la llamada a la
-- pasarela, o dos procesos podrian solaparse igual mientras el primero sigue
-- esperando respuesta.
--
--
-- 3) dte_pago_link.confirmacion_pendiente_at -- EL AVISO QUE NO SE PUDO MIRAR
-- -----------------------------------------------------------------------------
-- El aviso de pago de la pasarela solo trae un identificador: hay que volver a
-- preguntarle el estado real antes de dar nada por pagado, porque avisa igual
-- cuando el pago se rechaza. Si esa consulta falla, el handler respondia 200 y se
-- acabo: la pasarela daba el aviso por entregado, no lo repetia, y el pago
-- quedaba cobrado de verdad y sin registrar, sin que nada volviera a mirarlo.
--
-- Esta columna deja la marca de que llego un aviso que no se pudo resolver. No
-- es un sistema de conciliacion -- eso seria otra cosa y no toca aqui -- es el
-- minimo para que la pregunta "que avisos quedaron sin mirar" se pueda contestar
-- con un SELECT en vez de con un grep del log:
--
--   SELECT * FROM dte_pago_link
--    WHERE confirmacion_pendiente_at IS NOT NULL AND estado = 'creado';
--
--
-- SOBRE EL ESTADO 'error', QUE HASTA HOY NO LO ESCRIBIA NADIE
-- -----------------------------------------------------------------------------
-- La 050 declaro ese valor del ENUM y ningun camino lo usaba. Ahora si: es donde
-- queda una orden cuyo monto pagado NO coincide con el que se cobro. No se marca
-- pagada -- seria mentir -- y no se deja en 'creado' -- ocultaria el incidente.
-- No hace falta tocar el ENUM: el valor ya estaba.
--
--
-- IDEMPOTENCIA
-- -----------------------------------------------------------------------------
-- Las tres columnas se agregan con el patron information_schema + PREPARE, uno
-- por columna, porque ADD COLUMN IF NOT EXISTS no existe en el MySQL de Oracle.
-- No toca ningun dato.
-- =============================================================================

-- --- 1. Ambiente de la pasarela ---------------------------------------------
SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE pago_pasarela_cuenta
            ADD COLUMN ambiente ENUM(''sandbox'',''produccion'') NOT NULL DEFAULT ''sandbox''
                COMMENT ''sandbox NO cobra dinero real; el default seguro es sandbox''
                AFTER proveedor',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'pago_pasarela_cuenta'
      AND COLUMN_NAME  = 'ambiente'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- --- 2. Reclamo exclusivo de la creacion de la orden -------------------------
SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE dte_pago_link
            ADD COLUMN reclamado_at TIMESTAMP NULL DEFAULT NULL
                COMMENT ''hora en que un proceso tomo el permiso de llamar a la pasarela; caduca''
                AFTER intentos',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'dte_pago_link'
      AND COLUMN_NAME  = 'reclamado_at'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- --- 3. Aviso de pago que no se pudo consultar -------------------------------
SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE dte_pago_link
            ADD COLUMN confirmacion_pendiente_at TIMESTAMP NULL DEFAULT NULL
                COMMENT ''llego un aviso de pago que no se pudo consultar; queda por reconciliar''
                AFTER reintentar_despues_at',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'dte_pago_link'
      AND COLUMN_NAME  = 'confirmacion_pendiente_at'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =============================================================================
-- VERIFICACION POSTERIOR (solo lectura):
--
--   SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
--     FROM information_schema.COLUMNS
--    WHERE TABLE_SCHEMA = DATABASE()
--      AND ((TABLE_NAME = 'pago_pasarela_cuenta' AND COLUMN_NAME = 'ambiente')
--        OR (TABLE_NAME = 'dte_pago_link'
--            AND COLUMN_NAME IN ('reclamado_at','confirmacion_pendiente_at')));
--   -- ambiente: enum('sandbox','produccion'), NO, sandbox
--   -- las dos TIMESTAMP: YES, NULL, EXTRA vacio. Si alguna saliera con
--   --   DEFAULT_GENERATED / on update CURRENT_TIMESTAMP, la base tiene
--   --   explicit_defaults_for_timestamp = OFF y se la puso MySQL sola; el
--   --   reclamo y la marca de conciliacion mentirian.
--
--   SELECT ambiente, COUNT(*) FROM pago_pasarela_cuenta GROUP BY ambiente;
--   -- vacio: la tabla no tiene filas. Si las tuviera, TODAS en sandbox, y
--   -- habria que revisarlas una por una antes de volver a cobrar.
-- =============================================================================
