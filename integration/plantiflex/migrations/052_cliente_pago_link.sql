-- =============================================================================
-- Migracion 052: poder dejar a un cliente concreto fuera del link de pago.
--
-- QUE RESUELVE
-- -----------------------------------------------------------------------------
-- El interruptor de la migracion 051 es de la EMPRESA: encendido, el link va en
-- todos sus correos. Eso no alcanza. Una empresa tiene clientes que pagan por
-- transferencia acordada, clientes con convenio, o el contador que recibe copia
-- y no paga nada. Mandarles un link de cobro a esos es, en el mejor caso, ruido;
-- en el peor, un cobro duplicado.
--
--
-- POR QUE AQUI SI ES UNA COLUMNA
-- -----------------------------------------------------------------------------
-- Al reves que la 051, este caso SI es el de la migracion 040: un booleano, con
-- tipo y con default, sobre una fila que ya existe. Una tabla aparte para un
-- flag por cliente obligaria a un LEFT JOIN y a decidir que significa la
-- ausencia de fila, para no guardar nada mas que lo que cabe en un TINYINT.
--
--
-- EL DEFAULT ES 1, Y ESA ES LA DECISION QUE IMPORTA
-- -----------------------------------------------------------------------------
-- Arranca INCLUIDO. Si arrancara en 0, encender la funcion en la empresa no
-- haria nada visible hasta que alguien recorriera el maestro cliente por cliente
-- -- y quien encendio el interruptor concluiria, con razon, que esta roto. Con
-- default 1 el interruptor de la empresa manda, y excluir es la excepcion
-- explicita que se marca a mano.
--
-- Que el default sea 1 NO significa que se cobre a todo el mundo desde el primer
-- dia: sin la empresa habilitada (051, habilitado = 0 por defecto) no se crea
-- ninguna orden. Los dos interruptores tienen que estar en su sitio.
--
--
-- COMO SE CRUZA, Y EL CUIDADO QUE HAY QUE TENER
-- -----------------------------------------------------------------------------
-- El correo sabe el RUT del receptor por dte_emitido.receptor_rut; el maestro es
-- cliente (cuenta_id, rut_cliente). El cruce es por esos dos campos.
--
-- OJO: los documentos emitidos ANTES del arreglo de RUT canonico pueden tener el
-- receptor_rut guardado con puntos ("78.159.082-7"), mientras que cliente
-- .rut_cliente siempre estuvo normalizado. Quien consulte tiene que pasar el
-- valor leido por Rut::normalizar() antes de buscar; si no, a esos clientes se
-- les mandaria link aunque estuvieran excluidos, y el fallo seria silencioso.
-- Esta migracion NO reescribe esos receptor_rut historicos: cambiar datos ya
-- emitidos es otra decision, y normalizar en la consulta resuelve el caso sin
-- tocarlos.
--
--
-- IDEMPOTENCIA
-- -----------------------------------------------------------------------------
-- ADD COLUMN no admite IF NOT EXISTS en MySQL de Oracle (solo en MariaDB), asi
-- que se consulta information_schema y el ALTER se arma solo si la columna
-- falta. Nunca pisa una columna existente ni toca datos.
-- =============================================================================

SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE cliente
            ADD COLUMN pago_link TINYINT(1) NOT NULL DEFAULT 1
                COMMENT ''0 = a este cliente no se le manda link de pago en el correo''
                AFTER telefono',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'cliente'
      AND COLUMN_NAME  = 'pago_link'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =============================================================================
-- VERIFICACION POSTERIOR (solo lectura):
--
--   SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
--     FROM information_schema.COLUMNS
--    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cliente'
--      AND COLUMN_NAME = 'pago_link';
--   -- tinyint(1), NO, 1
--
--   SELECT pago_link, COUNT(*) FROM cliente GROUP BY pago_link;
--   -- todos los clientes existentes en 1 inmediatamente despues de aplicarla.
--
-- PARA EXCLUIR UN CLIENTE (a mano; la pantalla lo hace con un check):
--   UPDATE cliente SET pago_link = 0 WHERE cuenta_id = :cuentaId AND rut_cliente = :rut;
-- =============================================================================
