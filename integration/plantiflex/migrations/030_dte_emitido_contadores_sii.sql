-- =============================================================================
-- Migracion 030: contadores del veredicto del SII por tipo de documento.
--
-- QUE RESUELVE
-- -----------------------------------------------------------------------------
-- EPR no significa aceptado. El SII responde EPR cuando TERMINO de procesar el
-- sobre, y adentro puede haber documentos rechazados. Lo dice el mismo servicio,
-- en el RESP_BODY de getEstUp, y hasta esta migracion se tiraba entero. Respuesta
-- real capturada el 04-08-2026 (track 0253081988):
--
--   <TIPO_DOCTO>33</TIPO_DOCTO><INFORMADOS>22</INFORMADOS><ACEPTADOS>22</ACEPTADOS><RECHAZADOS>0</RECHAZADOS><REPAROS>0</REPAROS>
--   <TIPO_DOCTO>56</TIPO_DOCTO><INFORMADOS>2</INFORMADOS><ACEPTADOS>2</ACEPTADOS><RECHAZADOS>0</RECHAZADOS><REPAROS>0</REPAROS>
--   <TIPO_DOCTO>61</TIPO_DOCTO><INFORMADOS>6</INFORMADOS><ACEPTADOS>3</ACEPTADOS><RECHAZADOS>0</RECHAZADOS><REPAROS>3</REPAROS>
--
-- Ese sobre esta guardado hoy como EPR y nadie miro sus tres notas de credito
-- observadas. Hay dos casos asi en produccion.
--
--
-- ESTAS COLUMNAS NO CAMBIAN NINGUN TOTAL, Y ESO ES DELIBERADO
-- -----------------------------------------------------------------------------
-- La tentacion evidente era usarlas para sacar de la facturacion los documentos
-- que el SII no acepto. NO SE HACE, y el motivo es que el SII dice CUANTOS, no
-- CUALES: en el bloque de arriba hay 6 notas de credito informadas y 3 con
-- reparos, sin ninguna forma de saber cuales 3. Excluir el bloque entero sacaria
-- de los totales 3 documentos perfectamente validos, o sea le mentiria al
-- cliente sobre su propia facturacion -- en la direccion CONTRARIA al problema
-- que EstadoContable existe para arreglar, pero mintiendo igual.
--
-- Asi que EstadoContable queda intacto y sigue clasificando solo por estado.
-- Esta entrega DETECTA Y AVISA: el runner manda un correo distinguiendo un sobre
-- rechazado de uno procesado con rechazos adentro, y estas columnas guardan el
-- dato crudo.
--
-- PARA QUE SE GUARDAN ENTONCES. Son el insumo de la entrega siguiente: la que
-- averigua QUE folio es, consultando getEstDte documento por documento. Esa
-- consulta cuesta tres viajes SOAP por documento, asi que solo tiene sentido
-- lanzarla sobre los sobres donde ya se sabe que hay algo que buscar. Estas
-- columnas son exactamente esa lista.
--
--
-- APLICARLA TARDE NO ROMPE NADA, Y ESO ESTA VERIFICADO
-- -----------------------------------------------------------------------------
-- Si el codigo llega antes que esta migracion, el UPDATE de los contadores falla
-- y RegistroVeredictoSii::persistir() lo registra en el log y sigue: el estado y
-- la glosa del veredicto quedan guardados igual. Es la misma regla del encolado
-- de correo -- el extra nunca puede tumbar lo esencial -- y no es una precaucion
-- teorica: el 04-08-2026 el runner murio entero por una columna que todavia no
-- existia, y la primera corrida del A/B de certificacion volvio a reproducirlo
-- exacto antes de que la guarda existiera.
--
-- Igual conviene aplicarla primero, para no perder contadores. Pero el orden ya
-- no es una bomba.
--
--
-- POR QUE COLUMNAS EN dte_emitido Y NO UNA TABLA PROPIA
-- -----------------------------------------------------------------------------
-- La granularidad del dato NO es la de esta tabla: los contadores son de
-- (rut_emisor, ambiente, track_id, tipo_dte) y una fila de dte_emitido es
-- (rut_emisor, ambiente, tipo_dte, folio). Los mismos cuatro numeros quedan
-- repetidos en las N filas de ese tipo dentro del sobre. Normalizado seria una
-- tabla con esa clave y su indice por track_id.
--
-- Se eligen columnas porque la pregunta que hay que contestar es POR DOCUMENTO,
-- no por sobre: "esta fila pertenece a un bloque donde el SII informo algo, o
-- sea vale la pena preguntarle a getEstDte por su folio?". Con columnas eso es
-- un WHERE; con tabla aparte es un JOIN por (rut, ambiente, track_id, tipo_dte)
-- contra una tabla que NO tiene indice por track_id -- los indices de dte_emitido
-- son uq_emitido, idx_estado, idx_receptor e idx_periodo --, asi que habria que
-- crear el indice ademas de la tabla.
--
-- El costo de la decision es la duplicacion: 16 bytes por fila, repetidos dentro
-- de cada sobre. Se paga a gusto.
--
--
-- INT UNSIGNED NOT NULL DEFAULT 0
-- -----------------------------------------------------------------------------
-- Misma convencion que neto, iva, total, exento e impuesto_adicional, que ya son
-- INT UNSIGNED NOT NULL DEFAULT 0 en esta tabla. Contar documentos no admite
-- negativos.
--
-- EL 0 NO DECIDE NADA, y conviene decirlo porque es lo que hace inofensiva la
-- migracion. El aviso se calcula sobre la respuesta RECIEN PARSEADA
-- (RegistroVeredictoSii::motivoAviso), no leyendo estas columnas. Por eso un
-- bloque ilegible -- que a proposito no se escribe y queda en 0 -- no puede
-- leerse nunca como "cero rechazados": el correo ya salio con el dato de verdad.
-- Y las filas historicas quedan todas en 0 sin cambiar de comportamiento en
-- ninguna pantalla, porque ninguna pantalla las lee todavia.
--
-- NO SON NULL, aunque un NULL contaria mejor la historia de "nunca se consulto".
-- El consumidor que viene es un WHERE que busca "> 0"; con NULL habria que
-- escribir IS NULL OR = 0 en cada sitio, y esa condicion con OR dentro de un
-- WHERE mas grande es una trampa de precedencia esperando a que alguien la pise.
--
--
-- SIN INDICE
-- -----------------------------------------------------------------------------
-- Son columnas de cardinalidad casi nula: practicamente todo va a ser 0. Un
-- indice ahi solo costaria escrituras. El UPDATE que las llena filtra por
-- (rut_emisor, ambiente, track_id, tipo_dte), el mismo acceso sin indice que ya
-- hace el UPDATE del estado desde la migracion 027; no se empeora nada.
--
--
-- SON NUMERICAS: NO APLICA LA REGLA DE LAS COLLATIONS
-- -----------------------------------------------------------------------------
-- Mismo caso que la 026 y la 028 y NO el de la 027: INT no tiene character set
-- ni collation, asi que estas columnas se pueden comparar con cualquier tabla
-- del esquema sin el COLLATE explicito que si necesita glosa_sii.
--
--
-- IDEMPOTENCIA
-- -----------------------------------------------------------------------------
-- Mismo patron que la 025, la 026, la 027, la 028 y la 029: ADD COLUMN no admite
-- IF NOT EXISTS en MySQL 8 de Oracle (esa variante es exclusiva de MariaDB), asi
-- que se consulta information_schema y se arma el ALTER solo si las columnas
-- faltan. Si ya estan, se ejecuta un SELECT 1 que no hace nada. Nunca borra ni
-- pisa una columna existente, y no toca ni una fila de datos.
--
-- La guarda cuenta sii_rechazados, que es la que decide los totales: si por lo
-- que sea el esquema quedara a medias, es la que hay que tener.
-- =============================================================================

SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE dte_emitido
            ADD COLUMN sii_informados INT UNSIGNED NOT NULL DEFAULT 0
                COMMENT ''RESP_BODY/INFORMADOS de getEstUp para este tipo en este sobre''
                AFTER glosa_sii,
            ADD COLUMN sii_aceptados INT UNSIGNED NOT NULL DEFAULT 0
                COMMENT ''RESP_BODY/ACEPTADOS de getEstUp para este tipo en este sobre''
                AFTER sii_informados,
            ADD COLUMN sii_rechazados INT UNSIGNED NOT NULL DEFAULT 0
                COMMENT ''RESP_BODY/RECHAZADOS de getEstUp; NO afecta totales, ver la migracion''
                AFTER sii_aceptados,
            ADD COLUMN sii_reparos INT UNSIGNED NOT NULL DEFAULT 0
                COMMENT ''RESP_BODY/REPAROS de getEstUp; NO afecta totales, ver la migracion''
                AFTER sii_rechazados',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dte_emitido' AND COLUMN_NAME = 'sii_rechazados'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =============================================================================
-- VERIFICACION POSTERIOR (solo lectura):
--
--   SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
--     FROM information_schema.COLUMNS
--    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dte_emitido'
--      AND COLUMN_NAME LIKE 'sii\_%';
--   -- cuatro filas, int unsigned, NO, 0
--
--   SELECT COUNT(*) FROM dte_emitido WHERE sii_rechazados > 0 OR sii_reparos > 0;
--   -- debe dar 0 inmediatamente despues de aplicarla: nadie consulto todavia.
--
--   -- Y despues de la primera corrida del runner, los sobres con problema:
--   SELECT track_id, tipo_dte, sii_informados, sii_aceptados, sii_rechazados, sii_reparos
--     FROM dte_emitido
--    WHERE sii_rechazados > 0 OR sii_reparos > 0
--    GROUP BY track_id, tipo_dte, sii_informados, sii_aceptados, sii_rechazados, sii_reparos;
-- =============================================================================
