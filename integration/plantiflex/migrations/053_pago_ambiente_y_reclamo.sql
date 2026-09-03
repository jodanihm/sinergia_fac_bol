-- =============================================================================
-- Migracion 053: ambiente de la pasarela, reclamo exclusivo y conciliacion de
-- pagos que no dependa de que la pasarela vuelva a avisar.
--
-- QUE RESUELVE
-- -----------------------------------------------------------------------------
-- Tres defectos que dos revisiones del modulo de cobro dejaron por escrito antes
-- de la primera prueba. Van juntos en una sola migracion porque los tres son
-- columnas nuevas sobre las dos tablas que ya creo la 050/051 y porque los tres
-- son requisito de la MISMA prueba: sin ellos no se puede probar en sandbox, no
-- se puede garantizar que no haya un doble cobro, y un pago cobrado se puede
-- perder sin que nadie se entere.
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
-- 3) LA CONCILIACION: confirmacion_pendiente_at, conciliado_at, conciliacion_intentos
-- -----------------------------------------------------------------------------
-- FLOW NO REINTENTA EL AVISO DE PAGO. Es el hecho del que cuelga todo este
-- bloque, y conviene dejarlo escrito porque es contraintuitivo: su documentacion
-- dice que llama por POST a urlConfirmation, espera un 200 en menos de 15
-- segundos y, si no lo recibe, MANDA UN CORREO de "Alerta: Problema de
-- integracion" -- pero NO vuelve a llamar. Y anade que el estado de la
-- transaccion no se ve afectado por ese error: el pago sigue cobrado de su lado.
--
-- Consecuencia directa: si nuestro handler no puede resolver el aviso en ese
-- momento -- porque la consulta de estado esta caida, porque la fila todavia no
-- se habia guardado, porque la red fallo -- ESE PAGO SE PIERDE PARA NOSOTROS. No
-- hay segunda oportunidad que venga de fuera.
--
-- Por eso la recuperacion tiene que ser NUESTRA y no puede depender de recibir
-- otro aviso. Estas tres columnas son lo que la sostiene:
--
--   confirmacion_pendiente_at  llego un aviso que no se pudo resolver. Es una
--                              pista para priorizar, no la unica fuente: el
--                              conciliador barre TODAS las ordenes creadas,
--                              tambien aquellas cuyo aviso nunca llego.
--   conciliado_at              cuando se le pregunto por ultima vez a la
--                              pasarela. Sin esto, un barrido preguntaria por
--                              cada orden en cada pasada.
--   conciliacion_intentos      cuantas veces se ha preguntado. Es el tope que
--                              impide un bucle eterno sobre una orden que
--                              simplemente nadie va a pagar nunca.
--
-- POR QUE EL CONCILIADOR NO SE LIMITA A LAS MARCADAS. Porque el peor caso no
-- deja marca: si el aviso no llega nunca -- se perdio en la red, nuestro panel
-- estaba caido esos 15 segundos -- no hay nada que marcar, y esa orden pagada
-- quedaria invisible para siempre. Barrer por estado='creado' cubre los dos
-- casos con la misma consulta.
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
-- Las cinco columnas se agregan con el patron information_schema + PREPARE, uno
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

-- --- 4. Conciliacion: cuando se pregunto por ultima vez y cuantas veces -------
SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE dte_pago_link
            ADD COLUMN conciliado_at TIMESTAMP NULL DEFAULT NULL
                COMMENT ''ultima vez que se le pregunto el estado a la pasarela''
                AFTER confirmacion_pendiente_at',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'dte_pago_link'
      AND COLUMN_NAME  = 'conciliado_at'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE dte_pago_link
            ADD COLUMN conciliacion_intentos INT UNSIGNED NOT NULL DEFAULT 0
                COMMENT ''cuantas veces se pregunto; su tope evita un bucle eterno''
                AFTER conciliado_at',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'dte_pago_link'
      AND COLUMN_NAME  = 'conciliacion_intentos'
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
