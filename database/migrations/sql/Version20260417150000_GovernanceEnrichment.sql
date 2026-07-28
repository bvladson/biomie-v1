-- ============================================================
-- Fase 6: Governança de dados — colunas de classificação
--
-- Adiciona:
--   biomie_clientes_dim:
--     tipo_pessoa ENUM canônico (normaliza o VARCHAR tipo_documento)
--     documento_normalizado (apenas dígitos, para deduplicação)
--
--   mart_cliente_periodo:
--     tipo_pessoa  — copiado de biomie_clientes_dim no refresh
--     segmento_cnae — nome canônico do SegmentDictionary
--
-- Não altera estrutura existente; usa IF NOT EXISTS / ADD COLUMN IF NOT EXISTS
-- via ALTER TABLE condicional (compatível com MySQL 8+).
-- ============================================================

-- ── biomie_clientes_dim ───────────────────────────────────────────────────────

ALTER TABLE biomie_clientes_dim
    ADD COLUMN IF NOT EXISTS tipo_pessoa ENUM(
        'pf',
        'pj',
        'pj_sem_cnae',
        'governo',
        'associacao',
        'desconhecido'
    ) NOT NULL DEFAULT 'desconhecido'
    COMMENT 'Classificação canônica derivada de documento + CNAE (ver ClientClassifier)'
    AFTER tipo_documento,

    ADD COLUMN IF NOT EXISTS documento_normalizado VARCHAR(20) NULL
    COMMENT 'CPF/CNPJ sem pontuação (apenas dígitos) — base para deduplicação'
    AFTER cpf_cnpj;

-- Índices de suporte a queries de governança e deduplicação
ALTER TABLE biomie_clientes_dim
    ADD INDEX IF NOT EXISTS idx_cliente_tipo_pessoa (tipo_pessoa),
    ADD INDEX IF NOT EXISTS idx_cliente_doc_norm (documento_normalizado);

-- ── mart_cliente_periodo ──────────────────────────────────────────────────────

ALTER TABLE mart_cliente_periodo
    ADD COLUMN IF NOT EXISTS tipo_pessoa ENUM(
        'pf',
        'pj',
        'pj_sem_cnae',
        'governo',
        'associacao',
        'desconhecido'
    ) NOT NULL DEFAULT 'desconhecido'
    COMMENT 'Copiado de biomie_clientes_dim no GoldMartBuilder::refreshClientePeriodo'
    AFTER documento_cliente,

    ADD COLUMN IF NOT EXISTS segmento_cnae VARCHAR(120) NOT NULL DEFAULT 'Não classificado'
    COMMENT 'Nome canônico do segmento (SegmentDictionary::fromCnae)'
    AFTER tipo_pessoa;

-- Índices para filtros por tipo_pessoa e segmento no dashboard
ALTER TABLE mart_cliente_periodo
    ADD INDEX IF NOT EXISTS idx_cliente_periodo_tipo (competencia, tipo_pessoa),
    ADD INDEX IF NOT EXISTS idx_cliente_periodo_segmento (competencia, segmento_cnae);

-- ── Backfill inicial de documento_normalizado ─────────────────────────────────
-- Popula a coluna para registros já existentes sem necessidade de reprocessamento.

UPDATE biomie_clientes_dim
SET documento_normalizado = REGEXP_REPLACE(cpf_cnpj, '[^0-9]', '')
WHERE cpf_cnpj IS NOT NULL
  AND documento_normalizado IS NULL;
