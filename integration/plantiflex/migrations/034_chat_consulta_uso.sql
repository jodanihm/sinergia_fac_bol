-- =============================================================================
-- Migracion 034: contador de consultas del chat por cuenta y por dia.
--
-- POR QUE EXISTE: CADA PREGUNTA CUESTA DINERO. Una pregunta = una llamada a
-- DeepSeek. Sin tope, un bucle en el navegador o alguien probando el juguete
-- gasta saldo real sin que nadie se entere hasta la factura.
--
-- UNA FILA POR (cuenta, dia), no una fila por pregunta. No se quiere un log de
-- lo que la gente pregunta: seria dato personal del cliente guardado sin motivo,
-- y el tope solo necesita un numero. Si algun dia hace falta auditar las
-- preguntas, sera otra tabla y otra decision, tomada a proposito.
--
-- SE CUENTAN LAS LLAMADAS AL PROVEEDOR, NO LAS RESPUESTAS UTILES. Una pregunta
-- que el modelo no entendio igual costo. Lo unico que NO cuenta es lo que nunca
-- llego a salir: si falta la clave, no hay llamada y no se descuenta nada.
--
-- SIN columna ambiente: el chat consulta produccion, igual que los informes.
--
-- 100% aditiva: tabla nueva. FK a cuenta(id) con ON DELETE / ON UPDATE RESTRICT
-- explicitos, igual que 015/016/020/032/033.
-- =============================================================================

CREATE TABLE IF NOT EXISTS chat_consulta_uso (
    cuenta_id  BIGINT UNSIGNED NOT NULL,
    dia        DATE NOT NULL COMMENT 'fecha del servidor; el tope se reinicia solo al cambiar de dia',
    consultas  INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (cuenta_id, dia),
    CONSTRAINT fk_chat_uso_cuenta FOREIGN KEY (cuenta_id)
        REFERENCES cuenta (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
