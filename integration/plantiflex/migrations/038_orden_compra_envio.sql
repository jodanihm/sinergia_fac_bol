-- =============================================================================
-- Migracion 038: cola de envio por correo de las ordenes de compra.
--
-- POR QUE UNA COLA NUEVA Y NO dte_envio_correo. No es preferencia: esa tabla
-- tiene
--
--     dte_emitido_id BIGINT UNSIGNED NOT NULL,
--     UNIQUE KEY uk_envio_documento (dte_emitido_id),
--     CONSTRAINT fk_envio_documento FOREIGN KEY (dte_emitido_id)
--         REFERENCES dte_emitido (id)
--
-- o sea una FK OBLIGATORIA a dte_emitido, y EncoladorCorreo::encolarUno()
-- resuelve ese id por (rut_emisor, ambiente, tipo_dte, folio). Una orden de
-- compra no tiene folio, ni ambiente, ni fila en dte_emitido: no hay de donde
-- colgarla. Tampoco sirve PreparadorEnvio, que hace JOIN a dte_emitido, exige el
-- XML guardado y arma el asunto con TipoDte::nombreDe().
--
-- QUE SI SE COPIA DE AQUELLA, porque ya esta pensado:
--
--   1. EL UNIQUE ES LA IDEMPOTENCIA. Una orden no se encola dos veces, y esa
--      garantia vive en el esquema y no en un if de PHP: un "consultar y despues
--      insertar" deja una ventana en la que dos peticiones simultaneas encolan
--      lo mismo dos veces.
--   2. Los estados y el contador de intentos, para que el runner sepa que
--      reintentar sin releer todo.
--   3. cuenta_id COPIADO en la fila, aunque se pueda llegar por orden_compra:
--      evita un JOIN en la consulta del runner.
--
-- LA DIFERENCIA CON aquella: aqui el UNIQUE NO es sobre la orden, es sobre
-- (orden_compra_id, intento_de). Una orden de compra SI se puede reenviar -- el
-- proveedor perdio el correo, cambio la direccion --, cosa que un DTE no
-- necesita. Se resuelve dejando reenviar explicitamente: cada reenvio es una
-- fila nueva con su propio destinatario, y el historial queda.
--
-- QUE SIGNIFICA estado='enviado', Y HAY QUE LEERLO ANTES DE CONFIAR EN ESA
-- COLUMNA -- la advertencia es la misma que dejo PreparadorEnvio y vale igual:
--
--   'enviado' quiere decir "BREVO ACEPTO EL MENSAJE", NO "el proveedor lo
--   recibio". Si el destinatario esta en la lista de bloqueo de Brevo (rebote
--   duro previo, queja de spam, baja voluntaria), la API responde 2xx igual y el
--   correo NUNCA se entrega. La mitigacion es que el runner deja el message_id
--   de Brevo en la fila: convierte un "no me llego" en una busqueda exacta en el
--   panel de Brevo.
--
-- 100% aditiva: tabla nueva. FK a orden_compra y a cuenta con ON DELETE /
-- ON UPDATE RESTRICT explicitos.
-- =============================================================================

CREATE TABLE IF NOT EXISTS orden_compra_envio (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    orden_compra_id BIGINT UNSIGNED NOT NULL,
    cuenta_id       BIGINT UNSIGNED NOT NULL COMMENT 'copiado al encolar para que el runner no necesite JOIN',
    intento_de      INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '1 = primer envio; 2+ = reenvios explicitos',
    destinatario    VARCHAR(255) NULL COMMENT 'foto del correo al encolar; NULL si no habia',
    estado          ENUM('pendiente','enviado','error','sin_destinatario') NOT NULL DEFAULT 'pendiente',
    intentos        INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'cuantas veces lo intento el runner',
    message_id      VARCHAR(255) NULL COMMENT 'el de Brevo; es lo que permite rastrear un "no me llego"',
    error_mensaje   VARCHAR(500) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_oc_envio (orden_compra_id, intento_de),
    KEY ix_oc_envio_estado (estado),
    CONSTRAINT fk_oc_envio_orden FOREIGN KEY (orden_compra_id)
        REFERENCES orden_compra (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_oc_envio_cuenta FOREIGN KEY (cuenta_id)
        REFERENCES cuenta (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
