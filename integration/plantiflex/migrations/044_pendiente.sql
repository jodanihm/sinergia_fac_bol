-- =============================================================================
-- Migracion 044: el backlog deja de ser un archivo y pasa a la base.
--
-- POR QUE EXISTE
-- -----------------------------------------------------------------------------
-- Hasta hoy los pendientes vivian en panel/datos/pendientes.php, un array a
-- mano con la misma forma que changelog.php, flujos.php y documentos.php. Esa
-- forma se eligio bien para esos cuatro y sigue siendo correcta para tres de
-- ellos: son textos que describen el producto, cambian cuando cambia el
-- producto, y viajan en el mismo commit que el cambio que describen.
--
-- EL BACKLOG ES OTRA COSA Y SE NOTO AL USARLO. Un pendiente cambia de estado
-- muchas veces sin que cambie una linea de codigo: se toma, se pausa, se
-- bloquea esperando una decision, se cierra. Con el archivo, cada uno de esos
-- movimientos era editar PHP, commitear y desplegar -- reconstruir dos imagenes
-- de docker para anotar que algo quedo en curso. En la practica eso significa
-- que nadie lo mueve, y una lista que no se mueve no describe el trabajo real.
--
-- LO QUE SE GANA, ademas de poder cambiar el estado desde la pantalla:
--
--   HISTORIAL. La regla del archivo era BORRAR el item al concretarlo, para
--   que la lista no se llenara de ruido. Con estado 'hecho' y cerrado_at ya no
--   hace falta borrar: lo cerrado sale del listado por defecto pero queda, y se
--   puede responder "que se hizo este mes" sin leer el git log.
--
--   ORDEN POR PRIORIDAD. Un array no tiene con que ordenarse: quedaba el orden
--   en que alguien escribio las entradas.
--
--   FILTROS. Con seis ejes (area, categoria, prioridad, severidad, estado,
--   texto) sobre un array se termina filtrando en PHP toda la tabla en cada
--   carga. Aqui son indices.
--
-- LO QUE SE PIERDE, Y HAY QUE TENERLO CLARO: los pendientes dejan de viajar en
-- el repositorio. Un clon limpio en otra maquina levanta el panel con el
-- backlog VACIO hasta que alguien corra la siembra, y el contenido ya no queda
-- en el historial de git. Es el precio de que se pueda editar en caliente, y
-- es el mismo trato que ya tienen los datos de los clientes.
--
-- ES UNA TABLA GLOBAL, A PROPOSITO. Sin cuenta_id y sin rut_emisor: el backlog
-- es de la casa, no de ningun contribuyente. AislamientoTenant la va a
-- clasificar como 'global' en /admin/base-datos y esa etiqueta es la correcta,
-- no un hueco de aislamiento -- es la misma clase que llevan los catalogos y
-- las tablas de infraestructura. Ninguna consulta de tenant la toca.
--
-- SIN FK A usuario EN cerrado_por. Se guarda el id y nada mas: una FK con
-- ON DELETE RESTRICT impediria borrar a un usuario que alguna vez cerro un
-- pendiente, y con SET NULL se perderia quien lo hizo. El backlog es un
-- registro interno, no un dato que deba impedir operaciones sobre usuario. La
-- auditoria (admin_auditoria) guarda la version completa de cada cambio.
--
-- 100% aditiva: CREATE TABLE IF NOT EXISTS y nada mas. No toca ninguna tabla
-- existente, no borra ni renombra nada, y se puede repetir sin ruido. El
-- contenido inicial NO va aqui -- ninguna migracion de este proyecto trae
-- datos -- sino en scripts/sembrar_pendientes.php, que es idempotente.
-- =============================================================================

CREATE TABLE IF NOT EXISTS pendiente (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Que parte del sistema. 'transversal' es para lo que no cae en una sola
    -- (la suite de tests, una convencion que cruza todo el repo): sin ese valor
    -- habria que elegir una al azar y el filtro por area mentiria.
    area        ENUM('panel','motor','integracion','infra','datos','transversal')
                NOT NULL DEFAULT 'panel',

    -- Que clase de trabajo es. Separada de 'area' a proposito: "seguridad en el
    -- motor" y "seguridad en el panel" son la misma clase de trabajo en dos
    -- lugares, y quien prioriza necesita cruzarlas.
    categoria   ENUM('seguridad','producto','refactor','deuda','infra','datos')
                NOT NULL DEFAULT 'producto',

    -- CUANDO hay que hacerlo. P0 es "esto se atiende ahora".
    prioridad   ENUM('P0','P1','P2','P3') NOT NULL DEFAULT 'P3',

    -- CUANTO duele si no se hace. Separada de prioridad porque no son lo
    -- mismo: un riesgo de severidad alta puede ser P2 si todavia no lo dispara
    -- nadie, y una molestia de severidad baja puede ser P0 el dia que sale a
    -- produccion. Confundirlas en un solo campo obliga a mentir en uno de los
    -- dos ejes.
    severidad   ENUM('alta','media','baja','info') NOT NULL DEFAULT 'info',

    estado      ENUM('abierto','en_curso','bloqueado','hecho','descartado')
                NOT NULL DEFAULT 'abierto',

    titulo      VARCHAR(255) NOT NULL,
    detalle     TEXT NOT NULL,

    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Se llenan juntos al pasar a 'hecho' o 'descartado', y se limpian juntos
    -- si el item se reabre. Sin esto, "cerrado" solo se podria deducir del
    -- estado y se perderia CUANDO y QUIEN, que es la mitad del valor de tener
    -- historial.
    cerrado_at  TIMESTAMP NULL DEFAULT NULL,
    cerrado_por BIGINT UNSIGNED NULL DEFAULT NULL,

    PRIMARY KEY (id),

    -- El listado por defecto es "sin cerrar, ordenado por prioridad": este
    -- indice lo cubre entero.
    KEY idx_estado_prioridad (estado, prioridad),
    KEY idx_area (area),
    KEY idx_categoria (categoria)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Backlog interno del producto. Tabla GLOBAL: no es de ningun tenant (migracion 044)';
