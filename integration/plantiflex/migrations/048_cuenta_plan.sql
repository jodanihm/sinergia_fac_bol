-- =============================================================================
-- Migracion 048: la cuenta dice QUE PLAN tiene contratado.
--
-- ES EL SEGUNDO EJE, y el primero (migracion 047, cuenta.tipo) se queda como
-- esta. No son lo mismo y por eso son dos columnas:
--
--   tipo  QUE RELACION hay: paga, esta evaluando, es de la casa.
--   plan  QUE CONTRATO: Basico, Pyme o Pro.
--
-- Con un solo campo habria que mentir en uno de los dos ejes: una cuenta en
-- trial tambien esta evaluando un plan concreto -- es el dato que dice cuanto
-- va a pagar si se queda -- y una interna no tiene ninguno. Meter "trial" en la
-- misma lista que "Pyme" obliga a elegir cual de las dos cosas se guarda.
--
-- LOS CINCO VALORES:
--   sin_definir  Nadie dijo todavia. Igual que en tipo: es el valor con el que
--                quedan las cuentas comerciales que ya existian, y existe para
--                no inventar un plan que nadie contrato.
--   ninguno      No tiene plan, y es una afirmacion, no una laguna: una cuenta
--                interna o la de demostracion no contratan nada. Se distingue
--                de sin_definir a proposito -- "no tiene" y "no se" llevan a
--                dos acciones distintas, y confundirlas deja trabajo pendiente
--                escondido detras de algo que parece resuelto.
--   basico       0,5 UF al mes + IVA. Hasta 100 facturas al mes.
--   pyme         0,8 UF al mes + IVA. Hasta 400 facturas al mes.
--   pro          1,5 UF al mes + IVA. Facturacion ilimitada.
--
-- LOS PRECIOS Y LOS TOPES SALEN DE panel/public/planes.html, que es la pagina
-- publica de venta, y estan aqui SOLO como referencia para quien mire el panel.
-- El sistema NO cobra, NO controla el tope de facturas y NO cambia ningun
-- limite tecnico por esta columna: marcar una cuenta como 'basico' no la corta
-- en la factura 101. Si algun dia el sistema hace cumplir esos topes, sera una
-- decision visible y con su propia migracion, no un efecto de borde de haber
-- llenado un campo.
--
-- EL BACKFILL SOLO AFIRMA LO QUE SE PUEDE AFIRMAR: las cuentas que ya estan
-- marcadas como interna o demo pasan a 'ninguno', porque una cuenta de la casa
-- o de demostracion no contrata un plan por definicion. Las demas quedan en
-- 'sin_definir'; deducir el plan de un cliente mirando cuantos documentos
-- emitio seria inventarle un contrato.
--
-- 100% ADITIVA en el esquema (un ADD COLUMN con default). El unico UPDATE es el
-- backfill de arriba, que es idempotente. Sin IF NOT EXISTS en ADD COLUMN: esa
-- variante es exclusiva de MariaDB y MySQL de Oracle falla con error de
-- sintaxis (mismo criterio que las migraciones 013, 042, 043 y 047).
-- =============================================================================

ALTER TABLE cuenta
    ADD COLUMN plan ENUM('sin_definir','ninguno','basico','pyme','pro')
        NOT NULL DEFAULT 'sin_definir'
        COMMENT 'Plan comercial contratado, como referencia: el sistema no cobra ni controla topes (migracion 048)'
        AFTER tipo;

-- Lo unico deducible: una cuenta interna o de demostracion no contrata plan.
UPDATE cuenta
SET plan = 'ninguno'
WHERE tipo IN ('interna', 'demo') AND plan = 'sin_definir';
