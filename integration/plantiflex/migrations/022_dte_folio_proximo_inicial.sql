-- =============================================================================
-- Migracion 022: columna proximo_folio_inicial en dte_folio (folio inicial
-- declarado al cargar un CAF).
--
-- POR QUE: hasta ahora dte_folio.proximo_folio nacia SIEMPRE igual a
-- dte_caf.folio_desde -- lo hardcodean los dos unicos lugares que insertan en
-- esta tabla: panel/public/index.php (procesarCafPost) y scripts/cargar_caf.php.
-- Por eso "folios usados" se podia calcular como proximo_folio - folio_desde.
--
-- Eso deja de ser cierto cuando un emisor MIGRA desde otro proveedor
-- conservando su CAF: ahi el contador arranca a mitad de rango, y esa resta
-- contaria como consumo propio los folios que el emisor gasto ANTES de llegar
-- a Sinergia.
--
-- La columna guarda el valor con el que arranco el contador. Se llena en TODOS
-- los CAF, no solo en los migrados: en un CAF normal vale exactamente
-- folio_desde, de modo que la formula del dashboard es UNA SOLA y no necesita
-- distinguir casos con un IF o un COALESCE.
--
-- Tipo INT UNSIGNED para calzar con proximo_folio, folio_hasta y
-- dte_caf.folio_desde, que ya lo son.
--
-- POR QUE NULL Y NO NOT NULL AQUI: esta migracion tiene que poder convivir con
-- el codigo ANTERIOR al cambio (cuyo INSERT no menciona la columna) durante la
-- ventana de despliegue. Con NOT NULL y sin default, ese INSERT viejo fallaria.
-- El paso a NOT NULL va en la migracion 023, que se aplica DESPUES de confirmar
-- que el codigo nuevo ya esta en produccion.
--
-- Sin IF NOT EXISTS en ADD COLUMN (esa variante es exclusiva de MariaDB; MySQL
-- 8.x de Oracle falla con error de sintaxis si se usa ahi).
--
-- Sin CHECK constraint: un CHECK (proximo_folio_inicial >= folio_desde)
-- necesitaria consultar dte_caf, y MySQL no admite subconsultas ni referencias
-- a otras tablas en un CHECK. La validacion de rango vive en PHP, donde ademas
-- puede devolver un mensaje util al usuario.
--
-- Sin indice nuevo: la columna solo se lee en agregaciones que ya filtran por
-- rut_emisor/tipo_dte/ambiente, cubiertos por ix_folio_busqueda.
-- =============================================================================

-- 1. Agregar la columna como NULL (compatible con el codigo viejo y el nuevo).
ALTER TABLE dte_folio
    ADD COLUMN proximo_folio_inicial INT UNSIGNED NULL AFTER proximo_folio;

-- 2. Backfill de las filas existentes.
--
-- No se adivina nada: se reconstruye un hecho. Los dos unicos INSERT que han
-- existido sobre dte_folio fijan proximo_folio = dte_caf.folio_desde, asi que
-- NINGUNA fila pudo nacer con otro valor inicial.
UPDATE dte_folio f
INNER JOIN dte_caf c ON c.id = f.caf_id
SET f.proximo_folio_inicial = c.folio_desde
WHERE f.proximo_folio_inicial IS NULL;

-- =============================================================================
-- VERIFICACION POSTERIOR (solo lectura). Debe devolver nulos = 0 y
-- distintos_de_folio_desde = 0 inmediatamente despues de aplicar esta
-- migracion:
--
--   SELECT COUNT(*)                                     AS filas,
--          SUM(f.proximo_folio_inicial IS NULL)         AS nulos,
--          SUM(f.proximo_folio_inicial <> c.folio_desde) AS distintos_de_folio_desde
--   FROM dte_folio f
--   INNER JOIN dte_caf c ON c.id = f.caf_id;
-- =============================================================================
