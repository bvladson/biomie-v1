<?php

declare(strict_types=1);

namespace BIomie\Modules\Fiscal\Infrastructure;

use Closure;
use PDO;

/**
 * Extracts fiscal tax values from Omie bronze (ListarNF) JSON payloads
 * and materializes them into biomie_notas_fiscais_canonicas tax columns.
 *
 * Source: biomie_omie_bronze WHERE omie_method = 'ListarNF'
 * Join:   compl.cChaveNFe = biomie_notas_fiscais_canonicas.chave_nfe
 *
 * Tax fields extracted from total.ICMSTot (NF-e standard totals block):
 *   vICMS    → valor_icms
 *   vPIS     → valor_pis
 *   vCOFINS  → valor_cofins
 *   vIPI     → valor_ipi
 *   vTotTrib → valor_trib_total
 * ISS from total.ISSQNtot.vISS  → valor_iss
 */
final class FiscalTaxExtractorService
{
    public function __construct(
        private readonly Closure $pdoResolver,
    ) {
    }

    private function pdo(): PDO
    {
        return ($this->pdoResolver)();
    }

    /**
     * Backfill tax values for NFs not yet extracted, up to $limit at a time.
     * Reads from biomie_omie_bronze ListarNF JSON (no XML download needed).
     *
     * @return array{found:int,updated:int,skipped:int}
     */
    public function extractBatch(int $limit = 500): array
    {
        $pdo = $this->pdo();
        $cid = \BIomie\Support\TenantContext::companyId();
        if ($cid <= 0) {
            throw new \RuntimeException('Selecione uma empresa antes de extrair tributos fiscais.');
        }

        $affected = $pdo->exec(<<<SQL
            UPDATE biomie_notas_fiscais_canonicas nf
            INNER JOIN (
                SELECT
                    JSON_UNQUOTE(JSON_EXTRACT(b.payload_json, '$.compl.cChaveNFe')) AS chave,
                    JSON_EXTRACT(b.payload_json, '$.total.ICMSTot.vICMS')            AS icms,
                    JSON_EXTRACT(b.payload_json, '$.total.ICMSTot.vPIS')             AS pis,
                    JSON_EXTRACT(b.payload_json, '$.total.ICMSTot.vCOFINS')          AS cofins,
                    JSON_EXTRACT(b.payload_json, '$.total.ICMSTot.vIPI')             AS ipi,
                    JSON_EXTRACT(b.payload_json, '$.total.ISSQNtot.vISS')            AS iss
                FROM biomie_omie_bronze b
                WHERE b.omie_method = 'ListarNF'
                  AND b.company_id = {$cid}
                  AND JSON_EXTRACT(b.payload_json, '$.compl.cChaveNFe') IS NOT NULL
                GROUP BY chave
            ) src ON src.chave = nf.chave_nfe
            SET
                nf.valor_icms       = src.icms,
                nf.valor_pis        = src.pis,
                nf.valor_cofins     = src.cofins,
                nf.valor_ipi        = src.ipi,
                nf.valor_iss        = src.iss,
                nf.valor_trib_total = COALESCE(src.icms, 0) + COALESCE(src.pis, 0)
                                    + COALESCE(src.cofins, 0) + COALESCE(src.ipi, 0)
                                    + COALESCE(src.iss, 0),
                nf.tax_extracted_at = NOW()
            WHERE nf.company_id = {$cid}
              AND nf.chave_nfe IS NOT NULL
              AND nf.chave_nfe != ''
              AND nf.tax_extracted_at IS NULL
            LIMIT {$limit}
        SQL);

        $pending = (int) $pdo->query(<<<SQL
            SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas
            WHERE company_id = {$cid} AND tax_extracted_at IS NULL AND chave_nfe IS NOT NULL AND chave_nfe != ''
        SQL)->fetchColumn();

        return [
            'found'   => $pending + (int) $affected,
            'updated' => (int) $affected,
            'skipped' => 0,
            'pending' => $pending,
            'errors'  => [],
        ];
    }

    /**
     * Also backfills forma_pagamento in biomie_titulos_financeiros_canonicos
     * by mapping codigo_conta_corrente_omie → account description from ListarContasCorrentes bronze.
     *
     * @return int Number of titles updated
     */
    public function backfillPaymentMethods(): int
    {
        $pdo = $this->pdo();
        $cid = \BIomie\Support\TenantContext::companyId();
        if ($cid <= 0) {
            throw new \RuntimeException('Selecione uma empresa antes de atualizar formas de pagamento.');
        }

        return (int) $pdo->exec(<<<SQL
            UPDATE biomie_titulos_financeiros_canonicos t
            INNER JOIN (
                SELECT
                    JSON_UNQUOTE(JSON_EXTRACT(b.payload_json, '$.nCodCC')) AS cod_cc,
                    CASE
                        WHEN JSON_UNQUOTE(JSON_EXTRACT(b.payload_json, '$.pix_sn')) = 'S' THEN 'PIX'
                        WHEN JSON_UNQUOTE(JSON_EXTRACT(b.payload_json, '$.bol_sn')) = 'S' THEN 'Boleto'
                        WHEN JSON_UNQUOTE(JSON_EXTRACT(b.payload_json, '$.descricao')) LIKE '%Mercado Pago%' THEN 'Mercado Pago'
                        WHEN JSON_UNQUOTE(JSON_EXTRACT(b.payload_json, '$.descricao')) LIKE '%Visa%' THEN 'Cartao Visa'
                        WHEN JSON_UNQUOTE(JSON_EXTRACT(b.payload_json, '$.descricao')) LIKE '%MasterCard%' THEN 'Cartao Master'
                        WHEN JSON_UNQUOTE(JSON_EXTRACT(b.payload_json, '$.descricao')) LIKE '%Elo%' THEN 'Cartao Elo'
                        WHEN JSON_UNQUOTE(JSON_EXTRACT(b.payload_json, '$.descricao')) LIKE '%American%' THEN 'Cartao Amex'
                        WHEN JSON_UNQUOTE(JSON_EXTRACT(b.payload_json, '$.descricao')) LIKE '%Stone%' THEN 'Maquininha Stone'
                        WHEN JSON_UNQUOTE(JSON_EXTRACT(b.payload_json, '$.descricao')) LIKE '%Cielo%' THEN 'Maquininha Cielo'
                        WHEN JSON_UNQUOTE(JSON_EXTRACT(b.payload_json, '$.descricao')) LIKE '%PicPay%' THEN 'PicPay'
                        WHEN JSON_UNQUOTE(JSON_EXTRACT(b.payload_json, '$.descricao')) LIKE '%Caixinha%' THEN 'Dinheiro'
                        WHEN JSON_UNQUOTE(JSON_EXTRACT(b.payload_json, '$.descricao')) LIKE '%Caixa%' THEN 'Dinheiro'
                        WHEN JSON_UNQUOTE(JSON_EXTRACT(b.payload_json, '$.descricao')) LIKE '%Bradesco%' THEN 'Boleto/TED'
                        WHEN JSON_UNQUOTE(JSON_EXTRACT(b.payload_json, '$.descricao')) LIKE '%Inter%' THEN 'Transferencia'
                        WHEN JSON_UNQUOTE(JSON_EXTRACT(b.payload_json, '$.descricao')) LIKE '%Neon%' THEN 'Transferencia'
                        WHEN JSON_UNQUOTE(JSON_EXTRACT(b.payload_json, '$.descricao')) LIKE '%Nordeste%' THEN 'Transferencia'
                        WHEN JSON_UNQUOTE(JSON_EXTRACT(b.payload_json, '$.descricao')) LIKE '%Omie.CASH%' THEN 'PIX/Digital'
                        ELSE JSON_UNQUOTE(JSON_EXTRACT(b.payload_json, '$.descricao'))
                    END AS forma_pag
                FROM biomie_omie_bronze b
                WHERE b.omie_method = 'ListarContasCorrentes'
                  AND b.company_id = {$cid}
                GROUP BY cod_cc
            ) cc ON cc.cod_cc = t.codigo_conta_corrente_omie
            SET t.forma_pagamento = cc.forma_pag
            WHERE t.codigo_conta_corrente_omie IS NOT NULL
              AND t.company_id = {$cid}
              AND t.codigo_conta_corrente_omie != ''
              AND t.forma_pagamento IS NULL
        SQL);
    }

    /**
     * Returns aggregated tax summary for a given date range.
     * Used by api/finance_tax_overview.php.
     *
     * @return array{icms:float,pis:float,cofins:float,ipi:float,iss:float,trib_total:float,faturamento_total:float,carga_tributaria:float,nfs_com_imposto:int,nfs_sem_imposto:int,total_nfs:int}
     */
    public function summarizeTaxes(string $start, string $end, ?int $clientId = null): array
    {
        $pdo    = $this->pdo();
        $where  = \BIomie\Support\TenantContext::sqlFilter()
            . " AND data_emissao BETWEEN :start AND :end AND status_fiscal = 'autorizada'";
        $params = [':start' => $start, ':end' => $end];

        if ($clientId !== null) {
            $where .= ' AND codigo_cliente_omie = :client_id';
            $params[':client_id'] = $clientId;
        }

        $stmt = $pdo->prepare(<<<SQL
            SELECT
                COALESCE(SUM(valor_icms),   0) AS icms,
                COALESCE(SUM(valor_pis),    0) AS pis,
                COALESCE(SUM(valor_cofins), 0) AS cofins,
                COALESCE(SUM(valor_ipi),    0) AS ipi,
                COALESCE(SUM(valor_iss),    0) AS iss,
                COALESCE(SUM(valor_icms),   0)
                    + COALESCE(SUM(valor_pis),    0)
                    + COALESCE(SUM(valor_cofins), 0)
                    + COALESCE(SUM(valor_ipi),    0)
                    + COALESCE(SUM(valor_iss),    0) AS trib_total,
                COALESCE(SUM(valor_total),  0) AS faturamento_total,
                COUNT(*)                       AS total_nfs,
                SUM(CASE WHEN tax_extracted_at IS NOT NULL THEN 1 ELSE 0 END) AS nfs_com_imposto
            FROM biomie_notas_fiscais_canonicas
            WHERE {$where}
        SQL);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $totalNfs    = (int) ($row['total_nfs'] ?? 0);
        $nfsComImp   = (int) ($row['nfs_com_imposto'] ?? 0);
        $faturamento = (float) ($row['faturamento_total'] ?? 0);
        $tribTotal   = (float) ($row['trib_total'] ?? 0);
        $cargaTrib   = $faturamento > 0 ? round(($tribTotal / $faturamento) * 100, 2) : 0.0;

        return [
            'icms'              => round((float) ($row['icms']   ?? 0), 2),
            'pis'               => round((float) ($row['pis']    ?? 0), 2),
            'cofins'            => round((float) ($row['cofins'] ?? 0), 2),
            'ipi'               => round((float) ($row['ipi']    ?? 0), 2),
            'iss'               => round((float) ($row['iss']    ?? 0), 2),
            'trib_total'        => round($tribTotal, 2),
            'faturamento_total' => round($faturamento, 2),
            'carga_tributaria'  => $cargaTrib,
            'nfs_com_imposto'   => $nfsComImp,
            'nfs_sem_imposto'   => $totalNfs - $nfsComImp,
            'total_nfs'         => $totalNfs,
        ];
    }

    /**
     * Full monthly breakdown table (all taxes + carga%).
     * Used by the tax detail drawer.
     *
     * @return array<array{month:string,label:string,total_nfs:int,faturamento:float,icms:float,pis:float,cofins:float,ipi:float,iss:float,trib_total:float,carga_pct:float}>
     */
    public function monthlyBreakdown(string $start, string $end): array
    {
        $pdo  = $this->pdo();
        $tenantFilter = \BIomie\Support\TenantContext::sqlFilter();
        $stmt = $pdo->prepare(<<<SQL
            SELECT
                DATE_FORMAT(data_emissao, '%Y-%m')  AS month,
                COUNT(*)                             AS total_nfs,
                COALESCE(SUM(valor_total),   0)      AS faturamento,
                COALESCE(SUM(valor_icms),    0)      AS icms,
                COALESCE(SUM(valor_pis),     0)      AS pis,
                COALESCE(SUM(valor_cofins),  0)      AS cofins,
                COALESCE(SUM(valor_ipi),     0)      AS ipi,
                COALESCE(SUM(valor_iss),     0)      AS iss,
                COALESCE(SUM(valor_icms),    0)
                    + COALESCE(SUM(valor_pis),    0)
                    + COALESCE(SUM(valor_cofins), 0)
                    + COALESCE(SUM(valor_ipi),    0)
                    + COALESCE(SUM(valor_iss),    0) AS trib_total
            FROM biomie_notas_fiscais_canonicas
            WHERE {$tenantFilter}
              AND data_emissao BETWEEN :start AND :end
              AND status_fiscal = 'autorizada'
            GROUP BY month
            ORDER BY month ASC
        SQL);
        $stmt->execute([':start' => $start, ':end' => $end]);

        return array_map(static function (array $row): array {
            $parts  = explode('-', (string) ($row['month'] ?? ''));
            $label  = count($parts) === 2 ? $parts[1] . '/' . $parts[0] : ($row['month'] ?? '');
            $fat    = (float) ($row['faturamento'] ?? 0);
            $trib   = (float) ($row['trib_total']  ?? 0);
            return array_merge($row, [
                'label'     => $label,
                'carga_pct' => $fat > 0 ? round($trib / $fat * 100, 2) : 0.0,
                'total_nfs' => (int) ($row['total_nfs'] ?? 0),
            ]);
        }, $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Top NFs by total tax burden in the period.
     * Used by the tax detail drawer Top NFs tab.
     *
     * @return array<array{numero_nf:string,data_emissao:string,nome_cliente:string,nome_vendedor:string,valor_total:float,valor_icms:float,valor_pis:float,valor_cofins:float,valor_ipi:float,valor_iss:float,valor_trib_total:float,carga_pct:float}>
     */
    public function topNFsByTax(string $start, string $end, int $limit = 50): array
    {
        $pdo  = $this->pdo();
        $tenantFilter = \BIomie\Support\TenantContext::sqlFilter();
        $stmt = $pdo->prepare(<<<SQL
            SELECT
                numero_nf,
                DATE_FORMAT(data_emissao, '%d/%m/%Y')  AS data_emissao,
                nome_cliente,
                nome_vendedor,
                COALESCE(valor_total,      0) AS valor_total,
                COALESCE(valor_icms,       0) AS valor_icms,
                COALESCE(valor_pis,        0) AS valor_pis,
                COALESCE(valor_cofins,     0) AS valor_cofins,
                COALESCE(valor_ipi,        0) AS valor_ipi,
                COALESCE(valor_iss,        0) AS valor_iss,
                COALESCE(valor_trib_total, 0) AS valor_trib_total,
                CASE WHEN valor_total > 0
                    THEN ROUND(COALESCE(valor_trib_total, 0) / valor_total * 100, 1)
                    ELSE 0
                END AS carga_pct
            FROM biomie_notas_fiscais_canonicas
            WHERE {$tenantFilter}
              AND data_emissao BETWEEN :start AND :end
              AND status_fiscal = 'autorizada'
              AND tax_extracted_at IS NOT NULL
              AND COALESCE(valor_trib_total, 0) > 0
            ORDER BY valor_trib_total DESC
            LIMIT :lim
        SQL);
        $stmt->bindValue(':start', $start);
        $stmt->bindValue(':end',   $end);
        $stmt->bindValue(':lim',   $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Fetch monthly tax trend for chart rendering.
     *
     * @return array<array{month:string,label:string,icms:float,pis:float,cofins:float,ipi:float,iss:float,trib_total:float,faturamento:float}>
     */
    public function taxTrend(string $start, string $end): array
    {
        $pdo  = $this->pdo();
        $tenantFilter = \BIomie\Support\TenantContext::sqlFilter();
        $stmt = $pdo->prepare(<<<SQL
            SELECT
                DATE_FORMAT(data_emissao, '%Y-%m') AS month,
                COALESCE(SUM(valor_icms),   0) AS icms,
                COALESCE(SUM(valor_pis),    0) AS pis,
                COALESCE(SUM(valor_cofins), 0) AS cofins,
                COALESCE(SUM(valor_ipi),    0) AS ipi,
                COALESCE(SUM(valor_iss),    0) AS iss,
                COALESCE(SUM(valor_icms),   0)
                    + COALESCE(SUM(valor_pis),    0)
                    + COALESCE(SUM(valor_cofins), 0)
                    + COALESCE(SUM(valor_ipi),    0)
                    + COALESCE(SUM(valor_iss),    0) AS trib_total,
                COALESCE(SUM(valor_total),  0) AS faturamento
            FROM biomie_notas_fiscais_canonicas
            WHERE {$tenantFilter}
              AND data_emissao BETWEEN :start AND :end
              AND status_fiscal = 'autorizada'
              AND tax_extracted_at IS NOT NULL
            GROUP BY month
            ORDER BY month ASC
        SQL);
        $stmt->execute([':start' => $start, ':end' => $end]);

        return array_map(static function (array $row): array {
            $parts = explode('-', (string) ($row['month'] ?? ''));
            $label = count($parts) === 2 ? $parts[1] . '/' . $parts[0] : ($row['month'] ?? '');
            return array_merge($row, ['label' => $label]);
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}
