<?php

declare(strict_types=1);

namespace BIomie\Modules\Fiscal\Infrastructure;

use BIomie\Modules\Fiscal\Domain\RegimeTaxProjector;
use BIomie\Modules\Fiscal\Domain\TaxRegime;
use PDO;
use Throwable;

/**
 * Reune os insumos da projecao fiscal por regime e devolve o resultado pronto.
 *
 * Fonte unica para a ferramenta CLI e para a API — se as duas montassem as
 * proprias queries, divergiriam no primeiro ajuste de filtro, e a diferenca
 * apareceria como "o painel nao bate com o relatorio".
 *
 * REGRA CRITICA: o bloco de imposto destacado usa EXATAMENTE o mesmo filtro do
 * FiscalSettlementRefresher (status autorizada + direction saida). Sem isso os
 * dois lados medem universos diferentes e a comparacao "apuracao x projetado"
 * perde o sentido — foi assim que a primeira versao projetou R$ 22 mil ABAIXO
 * da propria apuracao na MAG_FILIAL.
 */
final class FiscalRegimeProjectionService
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * @return array<string,mixed>
     */
    public function forCompany(int $companyId, string $slug, string $start, string $end): array
    {
        $regime = $this->regimeFor($companyId, $slug);

        $receita = $this->row(
            "SELECT COALESCE(SUM(valor_total),0) receita,
                    COUNT(DISTINCT DATE_FORMAT(data_competencia,'%Y-%m')) meses,
                    COUNT(DISTINCT nota_key) notas
               FROM biomie_notas_fiscais_canonicas
              WHERE company_id=:c AND data_competencia BETWEEN :s AND :e
                AND status_fiscal='autorizada'",
            [':c' => $companyId, ':s' => $start, ':e' => $end]
        );

        $rbt12 = (float) ($this->row(
            "SELECT COALESCE(SUM(valor_total),0) rbt12
               FROM biomie_notas_fiscais_canonicas
              WHERE company_id=:c AND status_fiscal='autorizada'
                AND data_competencia > DATE_SUB(:e1, INTERVAL 12 MONTH)
                AND data_competencia <= :e2",
            [':c' => $companyId, ':e1' => $end, ':e2' => $end]
        )['rbt12'] ?? 0);

        $dest = $this->row(
            "SELECT COALESCE(SUM(valor_pis),0) pis,
                    COALESCE(SUM(valor_cofins),0) cofins,
                    COALESCE(SUM(valor_icms),0) icms,
                    COALESCE(SUM(valor_icms_st),0)+COALESCE(SUM(valor_difal_destino),0)
                      +COALESCE(SUM(valor_fecop),0)+COALESCE(SUM(valor_ipi),0)
                      +COALESCE(SUM(valor_iss),0) outros
               FROM mart_fiscal_items
              WHERE company_id=:c AND data_emissao BETWEEN :s AND :e
                AND direction='saida' AND status_fiscal='autorizada'",
            [':c' => $companyId, ':s' => $start, ':e' => $end]
        );

        $cred = $this->row(
            "SELECT COALESCE(SUM(i.valor_pis),0) pis, COALESCE(SUM(i.valor_cofins),0) cofins,
                    COALESCE(SUM(i.valor_icms),0) icms, COUNT(DISTINCT e.entry_key) docs
               FROM fiscal_purchase_entries e
               JOIN fiscal_purchase_entry_items i
                 ON i.company_id=e.company_id AND i.entry_key=e.entry_key
              WHERE e.company_id=:c AND e.competencia BETWEEN :s AND :e",
            [':c' => $companyId, ':s' => substr($start, 0, 7), ':e' => substr($end, 0, 7)]
        );

        $projection = RegimeTaxProjector::project($regime, [
            'receita_mes' => (float) ($receita['receita'] ?? 0),
            'rbt12' => $rbt12,
            'meses_no_periodo' => max(1, (int) ($receita['meses'] ?? 1)),
            'destacado_pis' => (float) ($dest['pis'] ?? 0),
            'destacado_cofins' => (float) ($dest['cofins'] ?? 0),
            'destacado_icms' => (float) ($dest['icms'] ?? 0),
            'destacado_outros' => (float) ($dest['outros'] ?? 0),
            'credito_pis' => (float) ($cred['pis'] ?? 0),
            'credito_cofins' => (float) ($cred['cofins'] ?? 0),
            'credito_icms' => (float) ($cred['icms'] ?? 0),
        ]);

        $atual = (float) ($this->row(
            'SELECT COALESCE(SUM(tributos_brutos),0) t FROM mart_fiscal_monthly_settlement
              WHERE company_id=:c AND competencia BETWEEN :s AND :e',
            [':c' => $companyId, ':s' => substr($start, 0, 7), ':e' => substr($end, 0, 7)]
        )['t'] ?? 0);

        // Confiabilidade: quanto do faturamento amarra com pedido faturado.
        $vinculo = $this->row(
            "SELECT COUNT(*) notas,
                    SUM(pedido_id_omie IS NOT NULL AND pedido_id_omie <> 0) com_pedido
               FROM biomie_notas_fiscais_canonicas
              WHERE company_id=:c AND data_competencia BETWEEN :s AND :e
                AND status_fiscal='autorizada'",
            [':c' => $companyId, ':s' => $start, ':e' => $end]
        );
        $totalNotas = (int) ($vinculo['notas'] ?? 0);
        $comPedido = (int) ($vinculo['com_pedido'] ?? 0);

        return [
            'company_id' => $companyId,
            'company' => $slug,
            'regime' => $regime,
            'regime_label' => (string) TaxRegime::profile($regime)['label'],
            'regime_short' => (string) TaxRegime::profile($regime)['short'],
            'regime_badge' => (string) TaxRegime::profile($regime)['badge'],
            'regime_reading' => (string) TaxRegime::profile($regime)['reading'],
            'notas' => $totalNotas,
            'notas_com_pedido' => $comPedido,
            'vinculo_pedido_pct' => $totalNotas > 0 ? round($comPedido * 100 / $totalNotas, 1) : 0.0,
            'documentos_entrada' => (int) ($cred['docs'] ?? 0),
            'apuracao_atual' => round($atual, 2),
            'projecao' => $projection,
            'diferenca' => round((float) $projection['tributo_total'] - $atual, 2),
        ];
    }

    /**
     * @param list<array{id:int,slug:string}> $companies
     * @return array<string,mixed>
     */
    public function forCompanies(array $companies, string $start, string $end): array
    {
        $rows = [];
        $totalAtual = 0.0;
        $totalProj = 0.0;

        foreach ($companies as $c) {
            $r = $this->forCompany((int) $c['id'], (string) $c['slug'], $start, $end);
            $rows[] = $r;
            $totalAtual += (float) $r['apuracao_atual'];
            $totalProj += (float) $r['projecao']['tributo_total'];
        }

        return [
            'period' => ['start' => $start, 'end' => $end],
            'companies' => $rows,
            'total_apuracao_atual' => round($totalAtual, 2),
            'total_projetado' => round($totalProj, 2),
            'total_invisivel' => round($totalProj - $totalAtual, 2),
            'disclaimer' => 'Estimativa gerencial a partir das notas autorizadas e do regime '
                . 'de cada empresa. Não substitui a apuração do contador.',
        ];
    }

    private function regimeFor(int $companyId, string $slug): string
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT regime_tributario FROM fiscal_establishments WHERE source_company_id = :c LIMIT 1'
            );
            $stmt->execute([':c' => (string) $companyId]);
            $found = $stmt->fetchColumn();
            if (is_string($found) && $found !== '') {
                return $found;
            }
        } catch (Throwable) {
            // Sem a tabela, o dominio resolve pelo slug.
        }

        return TaxRegime::forSlug($slug);
    }

    /**
     * @param  array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function row(string $sql, array $params): array
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }
}
