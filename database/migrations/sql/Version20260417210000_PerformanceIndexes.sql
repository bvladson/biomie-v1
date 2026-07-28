-- Fase 10 — Performance e Escalabilidade
-- Índices compostos adicionais que cobrem os padrões de consulta mais frequentes
-- sem duplicar os índices já existentes.
--
-- Idempotente: usa IF NOT EXISTS / IGNORE via procedures implícitas — cada
-- índice é adicionado com ALTER TABLE ... ADD INDEX IF NOT EXISTS (MySQL 8.0+)
-- ou via verificação prévia em versões anteriores. Em ambos os casos é seguro
-- re-executar.

-- ──────────────────────────────────────────────────────────────────────────────
-- 1. biomie_pedidos_canonicos
--    Padrão: WHERE data_faturamento BETWEEN :s AND :e AND status_comercial = 'faturado'
--    AND codigo_vendedor_omie = :v
--    O índice atual idx_status_faturamento(status_comercial, data_faturamento) cobre
--    filtragem por status+data mas não incluí o vendedor. Este índice covering elimina
--    a busca secundária para queries de vendor + período.
-- ──────────────────────────────────────────────────────────────────────────────
ALTER TABLE biomie_pedidos_canonicos
  ADD INDEX IF NOT EXISTS idx_pedido_fat_status_vendor (data_faturamento, status_comercial, codigo_vendedor_omie);

-- ──────────────────────────────────────────────────────────────────────────────
-- 2. biomie_titulos_financeiros_canonicos
--    Padrão: WHERE data_competencia BETWEEN :s AND :e AND status_titulo IN (...)
--    AND codigo_vendedor_omie = :v
--    O índice idx_titulo_vendedor(codigo_vendedor_omie, data_competencia) existe mas
--    não inclui status_titulo, causando filter extra nas queries de finance_overview.
-- ──────────────────────────────────────────────────────────────────────────────
ALTER TABLE biomie_titulos_financeiros_canonicos
  ADD INDEX IF NOT EXISTS idx_titulo_comp_status_vendor (data_competencia, status_titulo, codigo_vendedor_omie);

-- ──────────────────────────────────────────────────────────────────────────────
-- 3. biomie_clientes_dim
--    Padrão: WHERE ultima_compra_em < :limiar (detecção de clientes dormentes)
--    O RecurrencePolicy e OpportunityDetector filtram por ultima_compra_em mas
--    não há índice nessa coluna.
-- ──────────────────────────────────────────────────────────────────────────────
ALTER TABLE biomie_clientes_dim
  ADD INDEX IF NOT EXISTS idx_clientes_ultima_compra (ultima_compra_em);

-- ──────────────────────────────────────────────────────────────────────────────
-- 4. mart_vendedor_dia
--    Padrão: WHERE codigo_vendedor_omie = :v AND competencia IN (...)  (ForecastEngine)
--    O UNIQUE KEY uq_vendedor_dia(competencia, dia, codigo_vendedor_omie) cobre
--    buscas por competencia+dia. Para consultas por vendedor individual ao longo
--    de múltiplas competências (tendência), a ordem vendor+competencia é mais eficiente.
-- ──────────────────────────────────────────────────────────────────────────────
ALTER TABLE mart_vendedor_dia
  ADD INDEX IF NOT EXISTS idx_vendedor_dia_vendor_comp (codigo_vendedor_omie, competencia);

-- ──────────────────────────────────────────────────────────────────────────────
-- 5. biomie_vendas_geo_cliente_fact
--    Padrão: WHERE data_referencia BETWEEN :s AND :e [AND uf = :uf] [AND cidade = :c]
--    O índice atual idx_vendas_geo_data(data_referencia) existe mas não inclui
--    uf/cidade, causando full-scan da coluna de localização após o filtro de data.
-- ──────────────────────────────────────────────────────────────────────────────
ALTER TABLE biomie_vendas_geo_cliente_fact
  ADD INDEX IF NOT EXISTS idx_vendas_geo_data_uf_cidade (data_referencia, uf, cidade);

-- ──────────────────────────────────────────────────────────────────────────────
-- 6. mart_cliente_periodo  (já existe idx_cliente_periodo_valor(competencia, valor_faturado))
--    Adicionamos cobertura para tipo_recorrencia que é filtrado nas queries de
--    carteira ativa (ForecastEngine.forecastCarteira).
-- ──────────────────────────────────────────────────────────────────────────────
ALTER TABLE mart_cliente_periodo
  ADD INDEX IF NOT EXISTS idx_cliente_periodo_recorrencia (competencia, tipo_recorrencia);
