-- =============================================================================
-- Migracion 047: la cuenta dice QUE CLASE de cuenta es.
--
-- QUE FALTABA
-- -----------------------------------------------------------------------------
-- La tabla cuenta tenia email, nombre, estado (activa/suspendida) y el cupo de
-- chat. Con eso, el panel de control no podia contestar la pregunta mas basica
-- que se le hace a un listado de clientes: cuales pagan. Hoy hay seis cuentas,
-- cuatro se llaman prueba@algo y son de la casa -- pero eso lo sabe quien mira
-- el email, no el sistema. Cualquier cifra que salga de ese listado (cuantos
-- clientes hay, cuantos documentos emitieron los clientes de verdad) esta
-- mezclando las pruebas internas con lo real.
--
-- LOS CINCO VALORES, Y POR QUE CADA UNO
-- -----------------------------------------------------------------------------
--   sin_definir  Nadie dijo todavia que es. Es el valor con el que quedan las
--                cuentas que ya existian, y existe justamente para eso: ver
--                mas abajo.
--   interna      De la casa: pruebas, desarrollo, las otras marcas del grupo.
--                No paga y no se le vende. Es lo que hay que poder EXCLUIR de
--                cualquier cifra comercial.
--   demo         La cuenta publica de demostracion, de solo lectura.
--   trial        Cliente evaluando el producto.
--   pago         Cliente que paga.
--
-- POR QUE 'sin_definir' Y NO UN DEFAULT QUE ADIVINE. Un ALTER con
-- DEFAULT 'trial' -- o 'interna' -- deja las seis cuentas etiquetadas de una
-- forma que nadie decidio, y a partir del segundo dia esa etiqueta se lee como
-- un dato confirmado. Es el mismo error que este proyecto ya evito en el
-- verificador de migraciones al no dar por aplicada una migracion cuyo efecto
-- solo estaba a medias: mas vale un estado que dice "no se" que uno que miente
-- con seguridad. La pantalla lo muestra en rojo y se arregla en cuatro clics.
--
-- LA UNICA QUE SI SE PUEDE AFIRMAR ES LA DEMO, y por eso es el unico backfill:
-- no sale de mirar un email a ojo, sale de un dato que la base ya tiene desde
-- la migracion 029 (usuario.demo = 1). Es deduccion, no adivinanza.
--
-- QUE NO HACE ESTA MIGRACION, A PROPOSITO:
--
--   NO guarda el plan comercial (Basico / Pyme / Pro). Se decidio dejarlo
--   afuera: la pregunta de hoy es quien paga y quien no. El plan es otro eje y
--   entra, si hace falta, con su propia migracion.
--
--   NO toca chat_limite_diario. Es tentador derivarlo del tipo, y seria un
--   error: cambiar la etiqueta comercial de una cuenta no puede apagarle en
--   silencio una funcion que esta usando. Si algun dia el cupo depende del
--   plan, que sea una decision visible y no un efecto de borde de este campo.
--
--   NO cambia ningun permiso ni ningun comportamiento del sistema. Por ahora
--   es un dato para mirar y filtrar. Que una cuenta diga 'trial' no la caduca
--   sola: para eso haria falta una fecha de termino, que tampoco esta aqui.
--
-- 100% ADITIVA en el esquema (un ADD COLUMN con default) y el unico UPDATE que
-- corre es el backfill de la demo, que toca como mucho una fila y es
-- idempotente. Sin IF NOT EXISTS en ADD COLUMN: esa variante es exclusiva de
-- MariaDB y MySQL de Oracle falla con error de sintaxis (mismo criterio que las
-- migraciones 013, 042 y 043).
-- =============================================================================

ALTER TABLE cuenta
    ADD COLUMN tipo ENUM('sin_definir','interna','demo','trial','pago')
        NOT NULL DEFAULT 'sin_definir'
        COMMENT 'Que clase de cuenta es, para separar lo comercial de lo interno (migracion 047)'
        AFTER estado;

-- El unico backfill que no adivina: la cuenta cuyo usuario esta marcado como de
-- demostracion desde la migracion 029. Se puede repetir sin efecto.
UPDATE cuenta c
    JOIN usuario u ON u.cuenta_id = c.id AND u.demo = 1
SET c.tipo = 'demo'
WHERE c.tipo = 'sin_definir';
