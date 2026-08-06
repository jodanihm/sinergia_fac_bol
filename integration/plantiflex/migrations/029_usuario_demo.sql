-- =============================================================================
-- Migracion 029: marca de usuario de DEMOSTRACION (solo lectura) en usuario.
--
-- QUE RESUELVE
-- -----------------------------------------------------------------------------
-- Mostrarle el sistema a un prospecto exige una cuenta que se vea COMPLETA --
-- menu entero habilitado, dashboard con datos, informes con contenido -- y que
-- al mismo tiempo no pueda tocar nada: ni emitir un DTE contra el SII, ni
-- quemar un folio real, ni mandar un correo, ni cargar un certificado, ni
-- suspender un tenant. Antes de esta columna las dos cosas eran incompatibles:
-- "todo habilitado" y "no puede hacer nada" se decidian con el mismo dato (las
-- filas de produccion del tenant), asi que apagar lo segundo apagaba lo primero.
--
-- La columna separa los dos ejes. Las filas del tenant siguen decidiendo QUE SE
-- VE (el menu y los guards no cambian ni una linea); esta bandera decide QUE SE
-- PUEDE ESCRIBIR, y lo hace en un unico punto del router: el bloque que ya
-- validaba CSRF para las ~58 rutas POST. Un handler nuevo queda cubierto el dia
-- que se escribe, sin que nadie tenga que acordarse -- exactamente el mismo
-- argumento por el que el CSRF se centralizo ahi.
--
--
-- POR QUE UNA COLUMNA Y NO rol = 'demo'
-- -----------------------------------------------------------------------------
-- usuario.rol ya existe y seria tentador reusarlo, pero rol responde "que puede
-- administrar este usuario dentro de su cuenta" (owner / colaborador /
-- superadmin) y hoy lo lee exigirSuperadmin(). Meter 'demo' ahi mezclaria dos
-- preguntas distintas en un solo campo: un demo con permisos de owner y un demo
-- con permisos de colaborador dejarian de ser expresables, y cualquier futuro
-- `rol === 'owner'` empezaria a excluir al demo por accidente, en silencio.
-- Ortogonal es ortogonal: rol dice QUIEN ES, demo dice SI PUEDE ESCRIBIR.
--
--
-- POR QUE EN usuario Y NO EN cuenta
-- -----------------------------------------------------------------------------
-- Lo que se bloquea es una SESION HUMANA del panel, y la sesion guarda
-- usuario_id (ver panel/src/Auth.php). Colgarlo de la cuenta obligaria a
-- resolver cuenta -> usuario en cada POST para responder algo que la fila del
-- usuario ya contesta directo, y ademas impediria lo unico que probablemente se
-- va a pedir despues: una cuenta real con un usuario invitado de solo lectura.
--
-- NO cubre la API del motor, y no pretende hacerlo: esa superficie se autentica
-- por X-Api-Key y no ve esta columna. La contencion ahi es no entregarle al
-- demo ninguna api_key de tipo 'externa' activa (la de tipo 'servicio', que usa
-- el propio panel para leer documentos y PDFs, no se expone en pantalla).
--
--
-- TINYINT(1) NOT NULL DEFAULT 0
-- -----------------------------------------------------------------------------
-- Mismo criterio que cliente.activo y producto.activo, que ya son la convencion
-- booleana de este esquema. DEFAULT 0 es lo que hace que la migracion sea
-- inofensiva: las 5 filas existentes quedan en 0 -- ningun usuario real cambia
-- de comportamiento -- y cualquier alta futura nace en 0 salvo que alguien pida
-- lo contrario explicitamente. La bandera se ENCIENDE a mano (o con
-- scripts/sembrar_demo.php), nunca por defecto.
--
-- Sin indice: se consulta SIEMPRE por clave primaria (WHERE id = :usuarioId),
-- nunca filtrando por demo. Un indice aqui solo costaria escrituras.
--
-- Es TINYINT, no texto: no hereda character set ni collation, asi que no aplica
-- la regla de no cruzar las dos familias de collation del esquema que anotaron
-- la 026 y la 027.
--
--
-- IDEMPOTENCIA
-- -----------------------------------------------------------------------------
-- Mismo patron que la 025, la 026, la 027 y la 028: ADD COLUMN no admite
-- IF NOT EXISTS en MySQL 8 de Oracle (esa variante es exclusiva de MariaDB), asi
-- que se consulta information_schema y se arma el ALTER solo si la columna
-- falta. Si ya esta, se ejecuta un SELECT 1 que no hace nada. Nunca borra ni
-- pisa una columna existente, y no toca ni una fila de datos.
-- =============================================================================

SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE usuario
            ADD COLUMN demo TINYINT(1) NOT NULL DEFAULT 0
                COMMENT ''1 = usuario de demostracion: sesion de SOLO LECTURA, todo POST bloqueado en el router''
                AFTER rol',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuario' AND COLUMN_NAME = 'demo'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =============================================================================
-- VERIFICACION POSTERIOR (solo lectura):
--
--   SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
--     FROM information_schema.COLUMNS
--    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuario' AND COLUMN_NAME = 'demo';
--   -- tinyint(1), NO, 0
--
--   SELECT COUNT(*) AS en_demo FROM usuario WHERE demo = 1;
--   -- debe dar 0 inmediatamente despues de aplicarla; 1 despues de sembrar_demo.php
-- =============================================================================
