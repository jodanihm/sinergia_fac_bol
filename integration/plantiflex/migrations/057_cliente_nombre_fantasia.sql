-- =============================================================================
-- Migracion 057: cliente.nombre_fantasia
-- =============================================================================
--
-- El nombre con el que se conoce al cliente en el dia a dia ("Ferreteria Don
-- Pepe") casi nunca es su razon social ("Inversiones Gonzalez y Cia Ltda").
-- El buscador de clientes de los formularios (factura, cotizacion, filtros)
-- busca por RUT, razon social Y por este nombre, para no tener que saberse ni
-- el RUT ni la razon social.
--
-- Opcional y SOLO para buscar: no viaja al SII (el DTE no tiene campo para el).
-- Nullable, sin default: los clientes existentes quedan en NULL y se buscan
-- igual por lo demas.
-- =============================================================================

ALTER TABLE cliente
    ADD COLUMN nombre_fantasia VARCHAR(255) NULL DEFAULT NULL
        COMMENT 'Nombre comercial, solo para buscar; no va al SII'
        AFTER razon_social;
