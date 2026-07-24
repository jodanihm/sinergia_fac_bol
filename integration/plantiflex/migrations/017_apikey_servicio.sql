-- =============================================================================
-- Migracion 017: API key de SERVICIO en api_key (secreto recuperable, cifrado).
--
-- Las api_key existentes son de consumo EXTERNO: su secreto se muestra una vez
-- y NUNCA se persiste (solo key_hash). Eso impide que el panel se llame a si
-- mismo por HTTP contra el motor (M3, emision desde el panel).
--
-- Se agrega el concepto de key de SERVICIO (interna del panel), cuyo secreto SI
-- se guarda, pero CIFRADO con el MISMO envelope encryption que protege los
-- certificados de tenant (dte_certificado): una DEK aleatoria por key cifra el
-- secreto (secreto_cifrado), y la DEK se envuelve con la KEK maestra
-- (dek_envuelta). El panel la desencripta al emitir para ponerla en el header
-- X-Api-Key. NO se inventa criptografia nueva: se reutiliza CertificadoCrypto.
--
--   tipo = 'externa'  (default): key de consumo externo. secreto_cifrado y
--                     dek_envuelta quedan NULL (el secreto no se recupera).
--   tipo = 'servicio': key interna del panel. secreto_cifrado + dek_envuelta
--                     con valor. Invisible para el usuario (no se lista en
--                     /apikeys). Invariante de app: una sola activa por
--                     (cuenta_id, ambiente='produccion').
--
-- resolverTenant() del motor NO cambia: sigue validando por prefijo + hash
-- (hash_equals(key_hash, sha256(secreto))) sin importar el tipo.
--
-- 100% aditiva: no se borra ni renombra ninguna columna, no se toca ninguna
-- fila (las existentes quedan tipo='externa', ambos cifrados NULL). Sin IF NOT
-- EXISTS en ADD COLUMN (exclusivo de MariaDB; MySQL 8.4 de Oracle falla si se
-- usa ahi).
-- =============================================================================

ALTER TABLE api_key
    ADD COLUMN tipo ENUM('externa','servicio') NOT NULL DEFAULT 'externa' AFTER estado;

ALTER TABLE api_key
    ADD COLUMN secreto_cifrado LONGTEXT NULL AFTER tipo;

ALTER TABLE api_key
    ADD COLUMN dek_envuelta TEXT NULL AFTER secreto_cifrado;
