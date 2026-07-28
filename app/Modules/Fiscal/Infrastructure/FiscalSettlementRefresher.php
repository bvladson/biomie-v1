<?php

declare(strict_types=1);

namespace BIomie\Modules\Fiscal\Infrastructure;

use BIomie\Support\TenantContext;
use PDO;

final class FiscalSettlementRefresher
{
    public function __construct(private readonly PDO $pdo) {}

    public function refresh(): void
    {
        if (!$this->tableExists('mart_fiscal_monthly_settlement')
            || !$this->tableExists('mart_fiscal_items')
        ) {
            return;
        }

        $hasInputs = $this->tableExists('fiscal_purchase_entries')
            && $this->tableExists('fiscal_purchase_entry_items');
        $companyId = TenantContext::companyId();
        if ($companyId <= 0) {
            throw new \RuntimeException('Selecione uma empresa antes de recalcular a apuracao fiscal.');
        }

        $inputMonths = $hasInputs
            ? "SELECT competencia FROM fiscal_purchase_entries WHERE company_id={$companyId}"
            : "SELECT NULL AS competencia WHERE 1=0";
        $inputAggregate = $hasInputs
            ? <<<SQL
SELECT
  e.competencia,
  COUNT(DISTINCT e.entry_key) AS documentos_entrada,
  SUM(i.valor_icms) AS credito_icms,
  SUM(i.valor_pis) AS credito_pis,
  SUM(i.valor_cofins) AS credito_cofins,
  SUM(i.valor_ipi) AS credito_ipi,
  0 AS credito_iss
FROM fiscal_purchase_entries e
JOIN fiscal_purchase_entry_items i
  ON i.company_id=e.company_id
 AND i.entry_key=e.entry_key
WHERE e.company_id={$companyId}
  AND i.company_id={$companyId}
GROUP BY e.competencia
SQL
            : <<<'SQL'
SELECT NULL AS competencia, 0 documentos_entrada, 0 credito_icms, 0 credito_pis,
       0 credito_cofins, 0 credito_ipi, 0 credito_iss
WHERE 1=0
SQL;

        $this->pdo->exec(
            "DELETE FROM mart_fiscal_monthly_settlement WHERE company_id={$companyId}"
        );
        $this->pdo->exec(<<<SQL
INSERT INTO mart_fiscal_monthly_settlement (
  company_id, competencia, faturamento,
  debito_icms, debito_icms_st, debito_difal, debito_fecop,
  debito_pis, debito_cofins, debito_ipi, debito_iss,
  retencao_pis, retencao_cofins, retencao_csll, retencao_irrf,
  credito_icms, credito_pis, credito_cofins, credito_ipi, credito_iss,
  tributos_brutos, creditos_totais, retencoes_totais, saldo_liquido_estimado,
  carga_bruta_pct, carga_liquida_pct, input_coverage, accounting_coverage
)
SELECT
  {$companyId},
  months.competencia,
  COALESCE(o.faturamento,0),
  COALESCE(o.debito_icms,0),
  COALESCE(o.debito_icms_st,0),
  COALESCE(o.debito_difal,0),
  COALESCE(o.debito_fecop,0),
  COALESCE(o.debito_pis,0),
  COALESCE(o.debito_cofins,0),
  COALESCE(o.debito_ipi,0),
  COALESCE(o.debito_iss,0),
  COALESCE(r.retencao_pis,0),
  COALESCE(r.retencao_cofins,0),
  COALESCE(r.retencao_csll,0),
  COALESCE(r.retencao_irrf,0),
  COALESCE(i.credito_icms,0),
  COALESCE(i.credito_pis,0),
  COALESCE(i.credito_cofins,0),
  COALESCE(i.credito_ipi,0),
  COALESCE(i.credito_iss,0),
  COALESCE(o.tributos_brutos,0),
  COALESCE(i.credito_icms,0)+COALESCE(i.credito_pis,0)+COALESCE(i.credito_cofins,0)
    +COALESCE(i.credito_ipi,0)+COALESCE(i.credito_iss,0),
  COALESCE(r.retencoes_totais,0),
  GREATEST(
    COALESCE(o.tributos_brutos,0)
    - COALESCE(i.credito_icms,0)-COALESCE(i.credito_pis,0)-COALESCE(i.credito_cofins,0)
    - COALESCE(i.credito_ipi,0)-COALESCE(i.credito_iss,0)
    - COALESCE(r.retencoes_totais,0),
    0
  ),
  COALESCE(o.tributos_brutos,0)/NULLIF(COALESCE(o.faturamento,0),0)*100,
  GREATEST(
    COALESCE(o.tributos_brutos,0)
    - COALESCE(i.credito_icms,0)-COALESCE(i.credito_pis,0)-COALESCE(i.credito_cofins,0)
    - COALESCE(i.credito_ipi,0)-COALESCE(i.credito_iss,0)
    - COALESCE(r.retencoes_totais,0),
    0
  )/NULLIF(COALESCE(o.faturamento,0),0)*100,
  CASE WHEN COALESCE(i.documentos_entrada,0)>0 THEN 'partial' ELSE 'pending' END,
  'pending'
FROM (
  SELECT competencia FROM mart_fiscal_items
  WHERE company_id={$companyId}
    AND status_fiscal='autorizada' AND direction='saida' AND competencia IS NOT NULL
  UNION
  {$inputMonths}
) months
LEFT JOIN (
  SELECT
    competencia,
    SUM(valor_total_item) faturamento,
    SUM(valor_icms) debito_icms,
    SUM(valor_icms_st) debito_icms_st,
    SUM(valor_difal_destino) debito_difal,
    SUM(valor_fecop) debito_fecop,
    SUM(valor_pis) debito_pis,
    SUM(valor_cofins) debito_cofins,
    SUM(valor_ipi) debito_ipi,
    SUM(valor_iss) debito_iss,
    SUM(valor_icms+valor_icms_st+valor_difal_destino+valor_fecop
        +valor_pis+valor_cofins+valor_ipi+valor_iss) tributos_brutos
  FROM mart_fiscal_items
  WHERE company_id={$companyId}
    AND status_fiscal='autorizada' AND direction='saida' AND competencia IS NOT NULL
  GROUP BY competencia
) o ON o.competencia=months.competencia
LEFT JOIN (
  {$inputAggregate}
) i ON i.competencia=months.competencia
LEFT JOIN (
  SELECT DATE_FORMAT(n.data_emissao,'%Y-%m') competencia,
         SUM(x.valor_pis_retido) retencao_pis,
         SUM(x.valor_cofins_retido) retencao_cofins,
         SUM(x.valor_csll_retido) retencao_csll,
         SUM(x.valor_irrf) retencao_irrf,
         SUM(x.valor_pis_retido+x.valor_cofins_retido+x.valor_csll_retido+x.valor_irrf) retencoes_totais
  FROM fiscal_document_tax_snapshot x
  JOIN biomie_notas_fiscais_canonicas n
    ON n.company_id=x.company_id
   AND n.nota_key=x.nota_key
  WHERE x.company_id={$companyId}
    AND n.company_id={$companyId}
    AND n.status_fiscal='autorizada'
  GROUP BY DATE_FORMAT(n.data_emissao,'%Y-%m')
) r ON r.competencia=months.competencia
ORDER BY months.competencia
SQL);
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table'
        );
        $stmt->execute(['table' => $table]);

        return (int) $stmt->fetchColumn() > 0;
    }
}
