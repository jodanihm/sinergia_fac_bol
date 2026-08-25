-- =============================================================================
-- Migracion 045: las tablas de DTE dejan de colgar de la nada.
--
-- QUE ARREGLA
-- -----------------------------------------------------------------------------
-- /admin/base-datos clasificaba 13 tablas como 'sin_ruta': guardan datos de un
-- contribuyente (tienen rut_emisor) y la base NO podia decir de cual, porque no
-- habia ninguna clave foranea que llevara de ahi a cuenta. Son justo las que
-- guardan los documentos tributarios, o sea las que mas caro cuestan si se
-- filtran. Ver panel/src/AislamientoTenant.php, que es quien lo dejo a la vista.
--
-- Esta migracion NO agrega cuenta_id a esas tablas. Agrega la clave foranea que
-- faltaba, y con eso el camino a cuenta pasa a existir y a estar impuesto por el
-- motor:
--
--     dte_emitido.(rut_emisor, ambiente)  ->  dte_emisor
--     dte_emisor.cuenta_id                ->  cuenta
--
-- POR QUE ASI Y NO CON cuenta_id
-- -----------------------------------------------------------------------------
-- Agregar cuenta_id a las 13 tablas es barato en DATOS (1.214 filas, mapeo
-- deterministico, cero huerfanas) y carisimo en CODIGO: esas tablas se nombran
-- en 443 lugares de 70 archivos, y todos tendrian que empezar a escribir y a
-- filtrar la columna nueva. Y ahi esta la trampa: AislamientoTenant clasifica
-- por PRESENCIA DE COLUMNA, no por uso. Una cuenta_id agregada y no usada por
-- esas 443 consultas pondria las 13 filas rojas del panel en verde sin cambiar
-- nada del riesgo real -- la pantalla pasaria a mentir, que es peor que el rojo.
--
-- La FK compuesta no toca una sola consulta. Se apoya en uk_emisor
-- (rut_emisor, ambiente), que ya existe desde el dump original, y en que las 11
-- tablas ya llevan las dos columnas. AislamientoTenant tampoco se toca: su
-- recorrido BFS encuentra el camino nuevo solo, y las 11 pasan de 'sin_ruta' a
-- 'indirecto' sin que nadie edite una lista.
--
-- LO QUE ESTA MIGRACION NO HACE, Y HAY QUE TENERLO CLARO. Una clave foranea
-- garantiza que cada fila TENGA dueno; no impide que un WHERE olvidado lea las
-- filas de otro. Eso sigue dependiendo de la disciplina de quien escribe la
-- consulta, exactamente igual que en las tablas con cuenta_id. Lo que cambia es
-- que ahora la base puede DECIR de quien es cada fila, y que ya no se puede
-- guardar un documento tributario a nombre de un emisor que no existe.
--
-- NO ES 100% ADITIVA (a diferencia de casi todas las anteriores): cambia la
-- collation de 7 tablas y pone dte_emisor.cuenta_id en NOT NULL. Ninguno de los
-- dos cambios puede perder datos -- ver mas abajo por que --, pero la migracion
-- NO se puede repetir: los ALTER de collation son idempotentes de hecho, los
-- ADD CONSTRAINT y ADD INDEX no.
--
-- VERIFICADO CONTRA LA BASE REAL (sinergia_fac_bol, 2026-08-25) ANTES DE
-- ESCRIBIRLA. Las tres condiciones que la harian fallar a mitad de camino:
--   - pares (rut_emisor, ambiente) sin fila en dte_emisor ....... 0 en las 11
--   - filas de dte_emisor con cuenta_id NULL .................... 0 de 8
--   - RUT apuntando a mas de una cuenta ......................... 0
-- Si alguna de las tres no diera cero en otra base, el ALTER correspondiente
-- falla ruidoso y no deja nada a medias: cada uno es una sentencia sola.
-- =============================================================================


-- -----------------------------------------------------------------------------
-- 1. dte_emisor.cuenta_id pasa a NOT NULL.
--
-- ES EL PASO QUE HACE QUE EL CAMINO LLEGUE. Sin esto la cadena existiria pero
-- podria morir a mitad: un emisor con cuenta_id NULL es una fila de DTE que
-- llega hasta dte_emisor y ahi se queda sin poder decir de que empresa es. La
-- 001 la dejo nulable a proposito, para poder mapear los emisores de Plantiflex
-- que ya existian sin romperlos; ese periodo de transicion ya termino.
--
-- Cuidado al aplicarla en otra base: si hay filas con NULL, este ALTER las
-- convertiria en 0 (que no es una cuenta valida) en modo permisivo, o fallaria
-- en modo estricto. Se comprueba antes con:
--     SELECT COUNT(*) FROM dte_emisor WHERE cuenta_id IS NULL;   -- debe dar 0
-- -----------------------------------------------------------------------------
ALTER TABLE dte_emisor
    MODIFY cuenta_id BIGINT UNSIGNED NOT NULL;


-- -----------------------------------------------------------------------------
-- 2. Collation pareja en las columnas de la clave foranea.
--
-- POR QUE HACE FALTA. El esquema quedo partido en dos: las tablas del dump
-- original y las que agrego cada migracion usan utf8mb4_unicode_ci, y las que
-- nacieron por el lado del motor usan utf8mb4_0900_ai_ci (el default de MySQL
-- 8). Hoy, un JOIN entre dte_emitido y dte_emisor por rut_emisor NO corre: da
-- "ERROR 1267 Illegal mix of collations". Una FK exige lo mismo que el JOIN --
-- collation identica en padre e hija --, asi que sin esto el paso 4 no es
-- posible.
--
-- Se cambian SOLO las dos columnas de la clave, no la tabla entera: el resto de
-- las columnas (estado, track_id, glosa_sii...) no participa de ninguna
-- comparacion entre tablas, y convertirlas seria reescribir longblobs de varios
-- MB sin ganar nada.
--
-- NO PUEDE PERDER DATOS: las dos collations son de utf8mb4, mismo juego de
-- caracteres, y solo cambia la regla de ordenamiento/comparacion. Un RUT es
-- ASCII. Los indices que incluyen estas columnas (uq_emitido, idx_periodo,
-- PRIMARY de dte_idempotencia, ...) se reconstruyen solos.
-- -----------------------------------------------------------------------------
ALTER TABLE dte_boleta_rvd
    MODIFY rut_emisor VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    MODIFY ambiente   ENUM('certificacion','produccion') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL;

ALTER TABLE dte_emitido
    MODIFY rut_emisor VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    MODIFY ambiente   ENUM('certificacion','produccion') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL;

ALTER TABLE dte_idempotencia
    MODIFY rut_emisor VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    MODIFY ambiente   ENUM('certificacion','produccion') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL;

ALTER TABLE dte_intercambio_respuesta
    MODIFY rut_emisor VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    MODIFY ambiente   ENUM('certificacion','produccion') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL;

ALTER TABLE dte_libro
    MODIFY rut_emisor VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    MODIFY ambiente   ENUM('certificacion','produccion') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL;

ALTER TABLE dte_set_basico_sok
    MODIFY rut_emisor VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    MODIFY ambiente   ENUM('certificacion','produccion') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL;

ALTER TABLE dte_set_pruebas_archivo
    MODIFY rut_emisor VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    MODIFY ambiente   ENUM('certificacion','produccion') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL;


-- -----------------------------------------------------------------------------
-- 3. Indices de apoyo donde faltaban.
--
-- InnoDB exige que la tabla hija tenga un indice cuyas PRIMERAS columnas sean
-- las de la clave foranea, EN ESE ORDEN. Ocho de las once ya lo cumplen con un
-- indice que tenian (uq_emitido, uk_cert_emisor, ix_libro_emisor, la PRIMARY de
-- dte_idempotencia...). Las tres de abajo tienen rut_emisor primero pero
-- tipo_dte en el medio, asi que no sirven de prefijo.
--
-- Se crean explicitamente y no se deja que MySQL los invente solo: un indice
-- auto-generado se llama como la constraint y aparece en /admin/base-datos sin
-- que ningun archivo del repo lo mencione.
-- -----------------------------------------------------------------------------
ALTER TABLE dte_caf       ADD INDEX ix_caf_emisor      (rut_emisor, ambiente);
ALTER TABLE dte_folio     ADD INDEX ix_folio_emisor    (rut_emisor, ambiente);
ALTER TABLE dte_folio_log ADD INDEX ix_log_emisor      (rut_emisor, ambiente);


-- -----------------------------------------------------------------------------
-- 4. Las once claves foraneas.
--
-- RESTRICT EN LAS DOS PUNTAS, Y LAS DOS SON DELIBERADAS:
--
--   ON DELETE RESTRICT  Ya no se puede borrar un emisor que tiene documentos
--                       emitidos. Antes se podia, y dejaba 663 DTE sin dueno
--                       sin una sola advertencia.
--
--   ON UPDATE RESTRICT  Ya no se puede cambiar el RUT (o el ambiente) de un
--                       emisor que ya emitio. NO se pone CASCADE a proposito:
--                       un DTE se emitio a nombre de un RUT y ese dato es parte
--                       del documento tributario firmado. Arrastrarlo a un RUT
--                       nuevo seria reescribir la historia. El panel permitia
--                       ese cambio en /empresa; ahora la base lo corta.
--
-- LAS DOS TABLAS QUE QUEDAN FUERA, y por que:
--
--   dte_logo                  NO tiene columna ambiente, asi que no puede
--                             apuntar a uk_emisor (rut_emisor, ambiente).
--                             Agregarsela seria mentir: un logo es de la
--                             empresa, no de un ambiente. Sigue en 'sin_ruta' y
--                             esa etiqueta es la correcta; es tambien la de
--                             menor dano de las trece (guarda una imagen).
--
--   dte_emitido_bak_20260727  Respaldo manual de una migracion vieja. No se le
--                             pone FK porque no es parte del esquema: lo que
--                             corresponde es borrarla, y eso es una decision
--                             aparte de esta migracion.
-- -----------------------------------------------------------------------------
ALTER TABLE dte_boleta_rvd
    ADD CONSTRAINT fk_boleta_rvd_emisor FOREIGN KEY (rut_emisor, ambiente)
        REFERENCES dte_emisor (rut_emisor, ambiente) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE dte_caf
    ADD CONSTRAINT fk_caf_emisor FOREIGN KEY (rut_emisor, ambiente)
        REFERENCES dte_emisor (rut_emisor, ambiente) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE dte_certificado
    ADD CONSTRAINT fk_certificado_emisor FOREIGN KEY (rut_emisor, ambiente)
        REFERENCES dte_emisor (rut_emisor, ambiente) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE dte_emitido
    ADD CONSTRAINT fk_emitido_emisor FOREIGN KEY (rut_emisor, ambiente)
        REFERENCES dte_emisor (rut_emisor, ambiente) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE dte_folio
    ADD CONSTRAINT fk_folio_emisor FOREIGN KEY (rut_emisor, ambiente)
        REFERENCES dte_emisor (rut_emisor, ambiente) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE dte_folio_log
    ADD CONSTRAINT fk_folio_log_emisor FOREIGN KEY (rut_emisor, ambiente)
        REFERENCES dte_emisor (rut_emisor, ambiente) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE dte_idempotencia
    ADD CONSTRAINT fk_idempotencia_emisor FOREIGN KEY (rut_emisor, ambiente)
        REFERENCES dte_emisor (rut_emisor, ambiente) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE dte_intercambio_respuesta
    ADD CONSTRAINT fk_intercambio_emisor FOREIGN KEY (rut_emisor, ambiente)
        REFERENCES dte_emisor (rut_emisor, ambiente) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE dte_libro
    ADD CONSTRAINT fk_libro_emisor FOREIGN KEY (rut_emisor, ambiente)
        REFERENCES dte_emisor (rut_emisor, ambiente) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE dte_set_basico_sok
    ADD CONSTRAINT fk_set_basico_sok_emisor FOREIGN KEY (rut_emisor, ambiente)
        REFERENCES dte_emisor (rut_emisor, ambiente) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE dte_set_pruebas_archivo
    ADD CONSTRAINT fk_set_pruebas_emisor FOREIGN KEY (rut_emisor, ambiente)
        REFERENCES dte_emisor (rut_emisor, ambiente) ON DELETE RESTRICT ON UPDATE RESTRICT;
