-- =============================================================================
-- Migracion 039: dos desenlaces nuevos en chat_consulta, para el armado de
-- facturas por conversacion.
--
--
-- POR QUE HACE FALTA UN ALTER Y NO ALCANZA CON ESCRIBIR OTRO VALOR
-- -----------------------------------------------------------------------------
-- La columna es un ENUM CERRADO de cuatro valores (migracion 035):
--
--     desenlace ENUM('respondida','imposible','no_entendida','error') NOT NULL
--
-- Un INSERT con un valor que no esta en la lista FALLA en modo estricto -- y en
-- modo no estricto es peor: guarda la cadena vacia y el historial pasa a mentir
-- en silencio. O sea que el ENUM no es una etiqueta descriptiva sino una lista
-- blanca, del mismo tipo que las que ya rigen en el motor. Se amplia a
-- proposito, aqui, o no se puede escribir.
--
--
-- POR QUE SOLO DOS VALORES, Y NO UNO POR CADA DESENLACE DEL TRADUCTOR
-- -----------------------------------------------------------------------------
-- El traductor de armado distingue mas casos que estos dos, pero esta columna no
-- guarda "que dijo el modelo": guarda COMO TERMINO EL TURNO para el usuario, que
-- es lo que la tarjeta de actividad reciente muestra. Los dos finales que existen
-- y que hoy no se pueden expresar son:
--
--   armando   El turno entendio el pedido y PIDIO UN DATO QUE FALTABA. La
--             conversacion sigue abierta. Es el desenlace mas frecuente de un
--             armado y hoy tendria que guardarse como 'respondida', que es
--             falso: no se respondio nada, se pregunto.
--
--   borrador  El turno CERRO el borrador (quedo la cotizacion o el Excel). Es el
--             unico turno de la conversacion que produjo algo, y por eso merece
--             distinguirse: es el que sirve para saber cuantos armados llegaron
--             a destino y cuantos se abandonaron a mitad.
--
-- EL CAMBIO DE TEMA NO LLEVA VALOR PROPIO. Cuando el usuario deja el armado a
-- medias y pregunta otra cosa, ese turno ES una consulta y termina como cualquier
-- consulta: 'respondida', 'imposible', 'no_entendida' o 'error'. Inventarle un
-- quinto valor guardaria dos veces el mismo hecho.
--
--
-- EL ORDEN DE LOS VALORES: LOS NUEVOS VAN AL FINAL
-- -----------------------------------------------------------------------------
-- MySQL guarda internamente el INDICE del valor, no su texto. Reordenar la lista
-- reinterpretaria las filas ya escritas: la que hoy dice 'imposible' pasaria a
-- decir otra cosa sin que ningun UPDATE la toque. Por eso los cuatro valores
-- originales se repiten EN EL MISMO ORDEN y los dos nuevos se agregan detras.
-- Esta es la parte innegociable de esta migracion.
--
--
-- IDEMPOTENCIA
-- -----------------------------------------------------------------------------
-- Mismo patron que la 025 a la 030: MODIFY COLUMN no admite IF NOT EXISTS, asi
-- que se consulta information_schema y el ALTER se arma solo si el valor nuevo
-- todavia no esta en COLUMN_TYPE. Si ya esta, se ejecuta un SELECT 1 que no hace
-- nada -- lo que ademas evita el rebuild de tabla que un MODIFY dispara aunque
-- el resultado sea identico.
--
-- No toca ni una fila de datos: ampliar un ENUM por el final no reescribe nada.
-- =============================================================================

SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE chat_consulta
            MODIFY COLUMN desenlace
                ENUM(''respondida'',''imposible'',''no_entendida'',''error'',''armando'',''borrador'')
                NOT NULL',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'chat_consulta'
      AND COLUMN_NAME  = 'desenlace'
      AND COLUMN_TYPE LIKE '%''borrador''%'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =============================================================================
-- VERIFICACION POSTERIOR (solo lectura):
--
--   SELECT COLUMN_TYPE FROM information_schema.COLUMNS
--    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_consulta'
--      AND COLUMN_NAME = 'desenlace';
--   -- enum('respondida','imposible','no_entendida','error','armando','borrador')
--   -- Los cuatro primeros, EN ESE ORDEN: si aparecieran movidos, las filas
--   -- viejas cambiaron de significado.
--
--   SELECT desenlace, COUNT(*) FROM chat_consulta GROUP BY desenlace;
--   -- inmediatamente despues de aplicarla, cero filas en 'armando' y 'borrador'.
-- =============================================================================
