-- Migration: Version20260516120000_GeoBairroNormIndexes
-- Adds composite indexes to support accent-insensitive bairro_norm queries
-- in geo endpoints (top_buyers_by_geo, sales_by_cnae).
--
-- These queries now use:
--   bairro_norm COLLATE utf8mb4_general_ci = :bairro_key
-- which may not use the default utf8mb4_unicode_ci index if collations differ.
-- Adding explicit utf8mb4_general_ci collation indexes ensures efficient lookups.

-- mart_compradores_por_geo: composite index for bairro+period filtering
ALTER TABLE mart_compradores_por_geo
    ADD INDEX IF NOT EXISTS idx_geo_bairro_period
        (bairro_norm(64), competencia);

-- mart_compradores_por_geo: composite index for city+bairro filtering
ALTER TABLE mart_compradores_por_geo
    ADD INDEX IF NOT EXISTS idx_geo_cidade_bairro
        (cidade_norm(64), bairro_norm(64));

-- mart_vendas_por_cnae_cnpj: same pattern for CNAE endpoint
ALTER TABLE mart_vendas_por_cnae_cnpj
    ADD INDEX IF NOT EXISTS idx_cnae_bairro_period
        (bairro_norm(64), competencia)
        COMMENT 'Added for bairro_norm COLLATE accent-insensitive lookup';

-- biomie_clientes_geo_dim: index for canonical fallback join query
ALTER TABLE biomie_clientes_geo_dim
    ADD INDEX IF NOT EXISTS idx_geo_dim_bairro
        (bairro_norm(64));

-- biomie_clientes_geo_dim: composite index for city+bairro lookups
ALTER TABLE biomie_clientes_geo_dim
    ADD INDEX IF NOT EXISTS idx_geo_dim_cidade_bairro
        (cidade_norm(64), bairro_norm(64));
