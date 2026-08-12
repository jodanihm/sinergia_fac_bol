-- =============================================================================
-- Migracion 040: el tope diario del chat deja de ser una constante de PHP y pasa
-- a ser un dato de la cuenta.
--
--
-- QUE CAMBIA
-- -----------------------------------------------------------------------------
-- Hoy el tope vive en MySqlChatUsoRepository::LIMITE_DIARIO = 30. Es el mismo
-- numero para todas las cuentas y cambiarlo exige tocar codigo y desplegar. La
-- necesidad declarada es poder diferenciarlo por plan comercial, o sea que el
-- valor tiene que ser un DATO y no una linea de programa.
--
--
-- POR QUE UNA COLUMNA EN cuenta, Y NO UNA TABLA DE CONFIGURACION
-- -----------------------------------------------------------------------------
-- La alternativa evidente es una tabla clave/valor por cuenta
-- (cuenta_configuracion: cuenta_id, clave, valor). Se descarta, y el motivo es el
-- de siempre en este repositorio: hoy hay UN solo ajuste por cuenta. Una tabla
-- clave/valor para un unico valor es una abstraccion sin segundo caso, y trae
-- costos reales que una columna no tiene:
--
--   - El valor deja de tener tipo: 'sesenta' entra igual que 60, y hay que
--     validar en PHP lo que la columna INT UNSIGNED garantiza sola.
--   - Deja de tener default: una cuenta sin fila necesita que alguien recuerde el
--     valor de respaldo, o sea que la constante vuelve por la ventana.
--   - Cada lectura es un JOIN o una consulta aparte, para responder algo que la
--     fila de la cuenta ya podia contestar.
--
-- Cuando aparezca el SEGUNDO ajuste por cuenta se decidira con dos casos a la
-- vista, que es cuando esa decision se puede tomar bien.
--
--
-- POR QUE NO UNA TABLA plan
-- -----------------------------------------------------------------------------
-- Es donde este valor va a terminar viviendo, y se sabe. Pero la tabla plan NO
-- EXISTE: crearla ahora significa inventar de una sentada el catalogo de planes,
-- sus nombres y sus precios, sin que nadie los haya definido -- y despues
-- mantenerlos sincronizados con lo comercial de verdad.
--
-- Y no se pierde nada por esperar. El dia que exista plan, la forma natural es
-- "el plan trae el valor por defecto y la cuenta lo puede sobreescribir", que es
-- exactamente lo que esta columna ya es. No hay que deshacerla: pasa a ser el
-- override, que es su papel definitivo.
--
-- Mismo razonamiento que la migracion 029 al elegir una columna sobre un rol: el
-- dato se cuelga de la fila que ya contesta la pregunta.
--
--
-- POR QUE EL DEFECTO ES 60 Y NO 30
-- -----------------------------------------------------------------------------
-- 30 era el tope cuando el chat solo consultaba: una pregunta, una llamada al
-- proveedor, una respuesta. El armado de facturas conversado gasta VARIAS
-- llamadas por factura -- una por turno --, asi que con 30 una cuenta que arme
-- tres o cuatro facturas se queda sin poder consultar el resto del dia.
--
-- 60 mantiene el orden de magnitud del costo (deepseek-chat, del orden de una
-- milesima de dolar por llamada: 60 diarias por cuenta siguen siendo centavos al
-- mes) y no deja a NINGUNA cuenta peor que antes, que es la condicion de esta
-- migracion. Sigue siendo una apuesta y no una medicion, igual que lo era el 30:
-- ver el docblock de MySqlChatUsoRepository.
--
--
-- INT UNSIGNED NOT NULL DEFAULT 60
-- -----------------------------------------------------------------------------
-- NOT NULL con DEFAULT es lo que hace la migracion inofensiva: todas las filas
-- existentes quedan en 60 sin un solo UPDATE, y toda cuenta nueva nace en 60.
-- NULL no significaria nada aqui -- "sin tope" seria un valor peligroso que nadie
-- pidio, y "usa el defecto" ya lo resuelve el DEFAULT.
--
-- El CERO SI ES UN VALOR VALIDO y significa "esta cuenta no puede usar el chat".
-- Es la unica forma de apagarlo por cuenta sin agregar otra bandera, y el
-- repositorio no lo trata distinto: 0 consultas usadas nunca es < 0.
--
-- Sin indice: se lee SIEMPRE por clave primaria (WHERE id = :cuentaId), nunca
-- filtrando por el tope.
--
-- Es INT, no texto: no hereda charset ni collation, asi que no aplica la regla de
-- no cruzar las dos familias de collation del esquema que anotaron la 026 y la
-- 027.
--
--
-- IDEMPOTENCIA
-- -----------------------------------------------------------------------------
-- Mismo patron que la 025 a la 030 y la 039: ADD COLUMN no admite IF NOT EXISTS
-- en MySQL 8 de Oracle, asi que se consulta information_schema y el ALTER se arma
-- solo si la columna falta. Nunca pisa una columna existente ni toca datos.
-- =============================================================================

SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE cuenta
            ADD COLUMN chat_limite_diario INT UNSIGNED NOT NULL DEFAULT 60
                COMMENT ''llamadas al proveedor de IA por dia; 0 = chat apagado para esta cuenta''
                AFTER estado',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'cuenta'
      AND COLUMN_NAME  = 'chat_limite_diario'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =============================================================================
-- VERIFICACION POSTERIOR (solo lectura):
--
--   SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
--     FROM information_schema.COLUMNS
--    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cuenta'
--      AND COLUMN_NAME = 'chat_limite_diario';
--   -- int unsigned, NO, 60
--
--   SELECT chat_limite_diario, COUNT(*) FROM cuenta GROUP BY chat_limite_diario;
--   -- todas las cuentas existentes en 60 inmediatamente despues de aplicarla.
--
-- PARA SUBIRLE EL TOPE A UNA CUENTA (a mano, hasta que exista la pantalla):
--   UPDATE cuenta SET chat_limite_diario = 200 WHERE id = :cuentaId;
-- =============================================================================
