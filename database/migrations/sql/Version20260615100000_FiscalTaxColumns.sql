-- Migration: Version20260615100000_FiscalTaxColumns
-- Adds fiscal tax columns to biomie_notas_fiscais_canonicas.
-- Values are extracted from XML payload in biomie_documentos_xml_canonicos
-- by FiscalTaxExtractorService (run manually via CLI after migration).

ALTER TABLE biomie_notas_fiscais_canonicas
    ADD COLUMN IF NOT EXISTS valor_icms   DECIMAL(14,2) NULL DEFAULT NULL COMMENT 'ICMS total da NF (ICMSTot/vICMS)',
    ADD COLUMN IF NOT EXISTS valor_pis    DECIMAL(14,2) NULL DEFAULT NULL COMMENT 'PIS total da NF (ICMSTot/vPIS)',
    ADD COLUMN IF NOT EXISTS valor_cofins DECIMAL(14,2) NULL DEFAULT NULL COMMENT 'COFINS total da NF (ICMSTot/vCOFINS)',
    ADD COLUMN IF NOT EXISTS valor_ipi    DECIMAL(14,2) NULL DEFAULT NULL COMMENT 'IPI total da NF (ICMSTot/vIPI)',
    ADD COLUMN IF NOT EXISTS valor_iss    DECIMAL(14,2) NULL DEFAULT NULL COMMENT 'ISS total da NF (NFS-e vIss ou XML vISS)',
    ADD COLUMN IF NOT EXISTS valor_trib_total DECIMAL(14,2) NULL DEFAULT NULL COMMENT 'Total aproximado de tributos (ICMSTot/vTotTrib)',
    ADD COLUMN IF NOT EXISTS tax_extracted_at DATETIME NULL DEFAULT NULL COMMENT 'Timestamp da ultima extracao de impostos do XML';

-- Index to find NFs without tax extraction yet (for incremental backfill)
ALTER TABLE biomie_notas_fiscais_canonicas
    ADD INDEX IF NOT EXISTS idx_nf_tax_extracted (tax_extracted_at);
