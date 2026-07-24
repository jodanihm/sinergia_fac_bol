-- =============================================================================
-- Migracion 021: activacion de un solo uso para invitar un segundo usuario
-- a una cuenta (M6, pieza 1).
--
-- Contexto (ver PASO 0/1 de M6): hoy solo puede existir UN usuario por
-- cuenta (creado junto con la cuenta en /registro). No hay SMTP en el
-- proyecto (confirmado en M5), asi que invitar por correo queda descartado
-- sin agregar una dependencia nueva. El patron elegido es un link de
-- activacion de un solo uso (mismo criterio que un "reset de contrasena"):
-- el owner invita por email, copia el link generado y lo comparte por
-- cualquier canal fuera de la app; quien lo abre define SU PROPIA
-- contrasena -- el owner nunca la conoce ni la elige.
--
--   activacion_token   -> aleatorio (bin2hex(random_bytes(32)) = 64 hex),
--                         UNIQUE para poder buscar por el sin ambiguedad.
--                         NULL una vez activado (el link deja de servir,
--                         de un solo uso) o para cualquier usuario que ya
--                         nacio activo (ej. el owner original via /registro).
--   activacion_expira  -> ventana de validez del link (la app la fija en
--                         48 horas al invitar). NULL junto con el token.
--
-- Mientras el usuario esta invitado-pero-no-activado, queda con
-- estado='inactivo' (columna ya existente): el login YA rechaza
-- estado != 'activo' (ver handleLoginPost(), sin cambios ahi), asi que no
-- hace falta un estado nuevo para bloquear el acceso antes de activar --
-- se reusa el mismo criterio que ya usa la baja logica de un usuario.
--
-- 100% aditiva: ALTER TABLE ADD COLUMN, ambas NULL, no se toca ninguna fila
-- existente (quedan con activacion_token/activacion_expira NULL, coherente
-- con "ya estan activos, no necesitan link"). Sin IF NOT EXISTS en ADD
-- COLUMN (exclusivo de MariaDB; MySQL 8.x de Oracle falla con error de
-- sintaxis si se usa ahi).
-- =============================================================================

ALTER TABLE usuario
    ADD COLUMN activacion_token VARCHAR(64) NULL AFTER estado;

ALTER TABLE usuario
    ADD UNIQUE KEY uk_usuario_activacion_token (activacion_token);

ALTER TABLE usuario
    ADD COLUMN activacion_expira DATETIME NULL AFTER activacion_token;
