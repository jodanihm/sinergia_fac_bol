-- =============================================================================
-- Migracion 049: un sexto tipo de cuenta, 'cortesia'.
--
-- QUE RESUELVE. Con los cinco valores de la 047 hay cuentas que no tienen donde
-- caer: la que no paga y tampoco es de la casa. Un socio, un contador aliado,
-- una cuenta liberada por un acuerdo. Hasta hoy habia que elegir entre marcarla
-- 'interna' -- que es falso, no es de la casa y ademas la sacaria de cualquier
-- lectura sobre cuentas externas -- o dejarla en 'pago', que es peor: dice que
-- cobra algo que no se cobra.
--
-- 'cortesia' contesta ademas la pregunta que las otras dos esconden: POR QUE
-- esta cuenta no factura. "Interna" y "cortesia" se ven parecido en una tabla y
-- significan cosas muy distintas cuando alguien pregunta por que el ingreso no
-- cuadra con el numero de clientes.
--
-- NO CUENTA COMO CUENTA COMERCIAL: la cifra "cuantas pagan o van a pagar"
-- (TipoCuenta::comerciales) sigue siendo pago + trial. Una cortesia no paga hoy
-- y no hay nada que diga que va a pagar manana.
--
-- TAMPOCO SE LE EXIGE PLAN. Una cortesia puede tener uno -- se le libera el
-- Pyme, por ejemplo -- o ninguno, y las dos cosas son ciertas. Por eso no entra
-- en la lista de las que la pantalla marca en rojo cuando no declaran plan.
--
-- EL VALOR NUEVO VA AL FINAL DE LA LISTA Y ESO NO ES ESTETICA
-- -----------------------------------------------------------------------------
-- MySQL guarda un ENUM como un numero: la posicion del valor en la lista, no su
-- texto. Agregar 'cortesia' AL FINAL deja intactas las posiciones de los cinco
-- que ya estaban, asi que ninguna fila existente cambia de significado y el
-- ALTER puede ser instantaneo.
--
-- Meterlo en el medio -- por ejemplo despues de 'trial', que es donde se leeria
-- mejor -- correria una posicion a 'pago' y obligaria a MySQL a reinterpretar
-- las filas guardadas. Es la clase de error que no falla al aplicarse: no da
-- ningun mensaje, simplemente un dia las cuentas dicen otra cosa.
--
-- El orden que se ve en pantalla lo decide TipoCuenta::catalogo(), en PHP,
-- donde cambiarlo no le cuesta nada a nadie. Que la lista de la base y la de la
-- pantalla esten en distinto orden es deliberado, y hay un test que comprueba
-- que los VALORES sean los mismos aunque el orden no lo sea.
--
-- ADITIVA: solo amplia el conjunto de valores permitidos. Ninguna fila se
-- actualiza -- no hay a quien asignarle 'cortesia' automaticamente sin
-- inventarlo -- y no se puede perder informacion.
-- =============================================================================

ALTER TABLE cuenta
    MODIFY COLUMN tipo ENUM('sin_definir','interna','demo','trial','pago','cortesia')
        NOT NULL DEFAULT 'sin_definir'
        COMMENT 'Que clase de cuenta es, para separar lo comercial de lo interno (migraciones 047 y 049)';
