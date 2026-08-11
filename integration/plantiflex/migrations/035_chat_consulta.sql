-- =============================================================================
-- Migracion 035: historial de preguntas del chat.
--
-- REVIERTE UNA DECISION ANTERIOR, A PROPOSITO Y CON EL MOTIVO A LA VISTA.
--
-- La migracion 034 dice: "UNA FILA POR (cuenta, dia), no una fila por pregunta.
-- No se quiere un log de lo que la gente pregunta: seria dato personal del
-- cliente guardado sin motivo, y el tope solo necesita un numero. Si algun dia
-- hace falta auditar las preguntas, sera otra tabla y otra decision, tomada a
-- proposito."
--
-- Este es ese dia, y esta es esa otra tabla. El motivo que faltaba ya existe: la
-- pantalla muestra "Actividad reciente" para que el usuario pueda volver sobre
-- lo que ya pregunto sin reescribirlo -- y sin gastar otra consulta. O sea que
-- el dato deja de ser "guardado sin motivo".
--
-- -----------------------------------------------------------------------------
-- QUE SE GUARDA Y QUE NO
-- -----------------------------------------------------------------------------
--
-- SE GUARDA: la pregunta tal como se escribio, quien la hizo, cuando, y como
-- termino (si se pudo responder o no). Con eso la tarjeta se pinta y, si algun
-- dia hace falta, se puede ver que preguntas no se estan pudiendo responder --
-- que es la unica forma de saber que le falta al chat.
--
-- NO SE GUARDA LA RESPUESTA. Los numeros ya estan en dte_emitido y volver a
-- guardarlos aqui seria tener dos versiones de la misma cifra: la de ahora y la
-- congelada. Si el usuario repite la pregunta, se recalcula.
--
-- NO SE GUARDAN LAS PERILLAS. Son un detalle interno; lo que el usuario
-- reconoce es su propia frase.
--
-- -----------------------------------------------------------------------------
-- RETENCION: NO HAY PURGA, Y ESO ES UNA DEUDA DECLARADA
-- -----------------------------------------------------------------------------
--
-- Esta tabla CRECE SIN LIMITE. Con el tope de 30 consultas por cuenta y por dia
-- el crecimiento es acotado -- 30 filas de texto corto por cuenta al dia en el
-- peor caso --, asi que no es urgente. Pero es texto escrito por el cliente y
-- alguna vez habra que decidir cuanto se conserva. No se inventa una politica
-- aqui sin que nadie la haya pedido; queda anotado para que se decida a
-- proposito, como se decidio esto.
--
-- usuario_id SIN FK: la fila del historial no puede impedir dar de baja a un
-- usuario, y si el usuario desaparece la pregunta sigue siendo de la cuenta. Se
-- guarda para poder distinguir quien pregunto dentro de una misma empresa.
--
-- 100% aditiva: tabla nueva. FK a cuenta(id) con ON DELETE / ON UPDATE RESTRICT
-- explicitos, igual que 015/016/020/032/033/034.
-- =============================================================================

CREATE TABLE IF NOT EXISTS chat_consulta (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    cuenta_id  BIGINT UNSIGNED NOT NULL,
    usuario_id BIGINT UNSIGNED NULL COMMENT 'quien pregunto; sin FK a proposito',
    pregunta   VARCHAR(500) NOT NULL COMMENT 'tal como la escribio el usuario',
    desenlace  ENUM('respondida','imposible','no_entendida','error') NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- El indice que pinta la tarjeta: las ultimas N de esta cuenta.
    KEY ix_chat_consulta_cuenta (cuenta_id, created_at),
    CONSTRAINT fk_chat_consulta_cuenta FOREIGN KEY (cuenta_id)
        REFERENCES cuenta (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
