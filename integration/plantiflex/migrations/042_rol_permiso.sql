-- =============================================================================
-- Migracion 042: roles y permisos por cuenta (Fase 1).
--
-- MODELO, calcado del CONCEPTO de Brewer Manager:
--   rol      -- un rol POR CUENTA, con nombre libre ("Administrador", "Cajero").
--   permiso  -- una fila (modulo, accion) colgada de un rol. SIN tabla
--               intermedia: un permiso no es una entidad con identidad propia,
--               es un par que un rol tiene o no tiene.
--   usuario.rol_id -- UN rol por usuario (FK simple, no N a N).
--
-- LO QUE NO SE COPIA DE BREWER es su mecanismo opt-in: alli un endpoint sin
-- decorador pasa igual. Eso vive en el codigo (ver exigirPermisoDeRuta en
-- panel/public/index.php), no aqui.
--
--
-- POR QUE rol.cuenta_id, SI EN BREWER NO EXISTE
-- -----------------------------------------------------------------------------
-- Brewer aisla por SCHEMA: cada cervecera tiene su propio `rol`/`permiso` y no
-- hay forma de leer el de otra. Aqui todos los tenants comparten tabla, asi que
-- el aislamiento tiene que ser explicito y por fila. Es el riesgo mas grande de
-- este modelo: un WHERE olvidado no es un bug de pantalla, es un usuario viendo
-- (o heredando) permisos de otro contribuyente.
--
-- Por eso cuenta_id va en `rol` y NO en `permiso`: si estuviera en los dos
-- podrian contradecirse. La cuenta se resuelve SIEMPRE por el JOIN
-- usuario -> rol -> permiso, con el filtro puesto en `rol`, que es el unico
-- dueno del dato.
--
--
-- usuario.rol (VARCHAR) NO SE TOCA
-- -----------------------------------------------------------------------------
-- Sigue con sus valores actuales ('owner' | 'colaborador' | 'superadmin') y su
-- mismo tipo. Las dos columnas conviven a proposito y responden preguntas
-- distintas:
--
--   usuario.rol     QUE ES este usuario en la cuenta. owner y superadmin
--                   bypasean el gate entero, igual que hoy con
--                   exigirSuperadmin(). Es una propiedad estructural.
--   usuario.rol_id  QUE PUEDE HACER un colaborador. Configurable, es dato.
--
-- rol_id es NULLABLE justamente por eso: un owner o un superadmin no necesitan
-- rol asignado y tenerlo en NULL no es un dato faltante, es lo correcto.
--
--
-- ON DELETE
-- -----------------------------------------------------------------------------
-- permiso -> rol      CASCADE: los permisos no existen sin su rol.
-- usuario -> rol      por omision RESTRICT: borrar un rol que alguien esta
--                     usando tiene que FALLAR, no dejar al colaborador sin
--                     permisos en silencio (que ademas seria un 403 sorpresa).
-- rol -> cuenta       por omision RESTRICT, igual que fk_usuario_cuenta.
--
-- La PK de permiso es (rol_id, modulo, accion): la base impide un permiso
-- duplicado, no hace falta comprobarlo en PHP. Brewer inserta sin esa garantia.
--
-- 100% aditiva: no borra ni renombra nada, no toca ninguna fila existente.
--
-- Portable a MySQL 8.x y MariaDB 10.x: SIN "IF NOT EXISTS" en ADD COLUMN, que
-- es exclusivo de MariaDB (mismo criterio que la migracion 013).
-- =============================================================================

CREATE TABLE IF NOT EXISTS rol (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    cuenta_id  BIGINT UNSIGNED NOT NULL,
    nombre     VARCHAR(60) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- Dos roles con el mismo nombre en la misma cuenta serian indistinguibles
    -- en la pantalla de asignacion (Fase 2). En cuentas distintas si se repite:
    -- casi todas van a tener un "Administrador".
    UNIQUE KEY uk_rol_cuenta_nombre (cuenta_id, nombre),
    CONSTRAINT fk_rol_cuenta FOREIGN KEY (cuenta_id) REFERENCES cuenta (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permiso (
    rol_id BIGINT UNSIGNED NOT NULL,
    modulo VARCHAR(30) NOT NULL,
    accion VARCHAR(20) NOT NULL,
    PRIMARY KEY (rol_id, modulo, accion),
    CONSTRAINT fk_permiso_rol FOREIGN KEY (rol_id) REFERENCES rol (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- El catalogo de modulos y acciones NO vive en la base: es una constante de
-- codigo (CATALOGO_MODULOS / CATALOGO_ACCIONES). Aqui solo se guarda QUE rol
-- tiene QUE par. Mismo criterio que Brewer con provisioning/modulos.ts, y por
-- la misma razon: si un modulo desaparece del codigo, una fila huerfana en la
-- base no puede conceder nada.
ALTER TABLE usuario
    ADD COLUMN rol_id BIGINT UNSIGNED NULL AFTER rol;

ALTER TABLE usuario
    ADD CONSTRAINT fk_usuario_rol FOREIGN KEY (rol_id) REFERENCES rol (id);
