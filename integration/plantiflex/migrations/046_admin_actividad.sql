-- =============================================================================
-- Migracion 046: queda registro de TODO lo que hace el superadmin en el panel
-- de control.
--
-- POR QUE EXISTE, Y POR QUE NO ALCANZA CON admin_auditoria
-- -----------------------------------------------------------------------------
-- admin_auditoria (migracion 011) responde "QUE CAMBIO": guarda la accion, la
-- entidad tocada y el antes/despues en JSON. Es un changelog de cambios y esta
-- bien hecho para eso -- por eso esta tabla NO lo reemplaza ni lo toca.
--
-- Lo que ninguna de las dos podia responder hasta hoy es "QUE SE HIZO": que
-- pantallas se abrieron, que se miro, desde donde y cuando. Y esa es justo la
-- pregunta de una auditoria del ADMINISTRADOR, porque en este panel MIRAR ya es
-- un acto con consecuencias: /admin/tenants/{id} muestra los datos de un
-- contribuyente que no es el suyo, /admin/base-datos el esquema entero,
-- /admin/tenants/{id}/ver le deja recorrer el panel de una empresa cliente. Un
-- registro que solo anota los cambios da por no ocurrido todo eso.
--
-- La diferencia en numeros, el 26-08-2026: admin_auditoria tiene 6 filas desde
-- julio. Seis. No porque el panel se use poco, sino porque solo seis de las
-- cosas que se hacen ahi son "cambios".
--
-- UNA FILA POR PETICION A /admin/*, SIN EXCEPCIONES
-- -----------------------------------------------------------------------------
-- Incluye las lecturas (GET) y las acciones (POST); incluye el intento fallido
-- de alguien que entro con una cuenta que no es superadmin y se llevo un 403; e
-- incluye abrir la pantalla que muestra este mismo registro. Lo ultimo no es un
-- descuido: en una auditoria, consultarla tambien es un acto, y una bitacora
-- con un agujero exactamente del tamano de quien la lee no sirve para lo que
-- existe.
--
-- LO QUE NO SE GUARDA, Y ES DELIBERADO:
--
--   EL CUERPO DE LA PETICION. Nunca. Por ahi viajan las claves de
--   POST /admin/login y los datos de alta de una cuenta. Se guarda el METODO y
--   la RUTA, que dicen que se hizo, y nada de lo que se escribio.
--
--   LA RESPUESTA. Ni el HTML ni los datos que se mostraron. Guardar lo que se
--   vio duplicaria en esta tabla los datos de los contribuyentes que la tabla
--   existe para proteger.
--
--   LOS PARAMETROS SENSIBLES. Se guarda el query string porque distingue una
--   busqueda de otra, pero ActividadAdmin::parametros() descarta el valor de
--   cualquier clave que se llame token, clave, password, secreto o csrf. Hoy
--   ninguna ruta de /admin/* manda algo asi por la URL; el filtro esta para la
--   que se escriba manana.
--
-- APPEND-ONLY Y CRECE PARA SIEMPRE, igual que admin_auditoria: no se actualiza
-- ninguna fila y no hay proceso que limpie. Una peticion por fila y un solo
-- superadmin usando el panel es del orden de decenas de filas por dia. El
-- indice por fecha y la paginacion obligatoria de la pantalla estan por eso: el
-- dia que sean cientos de miles, la pantalla sigue respondiendo. Si alguna vez
-- hay que podarla, que sea una decision explicita y con su propia migracion --
-- no un DELETE a mano que deje un hueco sin explicacion en una auditoria.
--
-- ES UNA TABLA GLOBAL, A PROPOSITO: sin cuenta_id y sin rut_emisor. No registra
-- lo que hace un contribuyente en su panel -- eso ya lo cubre admin_auditoria
-- por el lado de los cambios --, registra lo que hace la CASA sobre el sistema.
-- AislamientoTenant la va a clasificar como 'global', que es lo correcto.
--
-- 100% ADITIVA: un solo CREATE TABLE IF NOT EXISTS. No toca ninguna tabla
-- existente, no borra ni renombra nada, y se puede repetir sin ruido.
-- =============================================================================

CREATE TABLE IF NOT EXISTS admin_actividad (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- NULL POSIBLE A PROPOSITO. Casi siempre hay usuario: a /admin/* no se
    -- llega sin sesion. La excepcion es POST /admin/login fallido, que es
    -- justamente el evento que mas interesa de esa ruta. Con NOT NULL habria
    -- que elegir entre inventar un id o no registrar el intento.
    usuario_id  BIGINT UNSIGNED NULL DEFAULT NULL,

    metodo      VARCHAR(10)  NOT NULL,
    ruta        VARCHAR(255) NOT NULL,

    -- El query string YA FILTRADO (ver ActividadAdmin::parametros). Vacio si no
    -- habia. Es lo que separa "abrio la ficha de la cuenta 3" de "abrio la 7".
    parametros  VARCHAR(500) NOT NULL DEFAULT '',

    -- LECTURA / ACCION, derivado del metodo. Se guarda calculado y no se deduce
    -- al leer porque es el eje por el que se filtra la pantalla, y un filtro
    -- sobre una expresion no usa indice. En este router la separacion es limpia
    -- y esta demostrada en el bloque de corte por demo: toda mutacion es POST.
    efecto      ENUM('lectura','accion') NOT NULL DEFAULT 'lectura',

    -- El codigo con el que termino. Es lo que distingue "entro" de "lo
    -- rebotaron": un 403 aqui es un intento de alguien que no es superadmin.
    http        SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    -- Cuanto tardo. No es una metrica de rendimiento: sirve para reconocer una
    -- pantalla que alguien dejo colgada o una sonda que se fue al timeout.
    ms          INT UNSIGNED NOT NULL DEFAULT 0,

    -- VARCHAR(45) entra una IPv6 completa. Puede ser NULL: detras del tunel de
    -- Cloudflare la direccion llega por cabecera, y una cabecera puede faltar.
    ip          VARCHAR(45) NULL DEFAULT NULL,

    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    -- El orden natural de la pantalla (lo mas reciente primero) y el filtro por
    -- rango de fechas. Es el indice que sostiene la paginacion.
    KEY ix_actividad_fecha (created_at),

    -- "Que hizo esta persona" sin recorrer la tabla entera.
    KEY ix_actividad_usuario (usuario_id, created_at),

    -- "Quien abrio esta pantalla". El prefijo de 100 alcanza para distinguir
    -- las rutas de /admin/* y evita un indice de 255 caracteres por fila.
    KEY ix_actividad_ruta (ruta(100)),

    -- ON DELETE SET NULL y no RESTRICT, al reves que las FK de la migracion
    -- 045: alla la fila hija ES el documento tributario y perder su emisor la
    -- deja sin sentido. Aqui la fila vale por si sola -- que se hizo, cuando y
    -- desde donde -- y borrar un usuario no puede borrar ni bloquear el
    -- registro de lo que hizo. Eso seria dejar que se limpie la auditoria
    -- dando de baja al auditado.
    CONSTRAINT fk_actividad_usuario FOREIGN KEY (usuario_id)
        REFERENCES usuario (id) ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Bitacora de actividad del panel de control: una fila por peticion a /admin/* (migracion 046)';
