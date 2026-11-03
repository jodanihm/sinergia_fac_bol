-- =============================================================================
-- Migracion 058: proveedor.nombre_fantasia
-- =============================================================================
--
-- Lo mismo que la 057 hizo con cliente: el nombre con el que se conoce al
-- proveedor, para que el buscador de la orden de compra lo encuentre sin
-- saberse el RUT ni la razon social. Opcional, solo para buscar; no se imprime
-- en la orden. Los proveedores existentes quedan en NULL.
-- =============================================================================

ALTER TABLE proveedor
    ADD COLUMN nombre_fantasia VARCHAR(255) NULL DEFAULT NULL
        COMMENT 'Nombre comercial, solo para buscar; no va en la orden'
        AFTER razon_social;
