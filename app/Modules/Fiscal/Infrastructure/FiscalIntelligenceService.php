<?php

declare(strict_types=1);

namespace BIomie\Modules\Fiscal\Infrastructure;

use BIomie\Modules\Fiscal\Domain\TaxRegime;
use Closure;
use PDO;

/**
 * Todas as queries de leitura do módulo Fiscal Intelligence.
 *
 * Fontes:
 *  - biomie_notas_fiscais_canonicas (NF-level, aggregations rápidas)
 *  - mart_fiscal_items              (item-level: NCM, CFOP, CBS/IBS)
 *  - biomie_bridge_nf_titulo        (gap fiscal×financeiro)
 */
final class FiscalIntelligenceService
{
    private ?PDO $pdo = null;
    private string $tenant;
    private string $tenantN;
    private string $tenantM;
    private string $tenantE;
    private string $tenantS;

    public function __construct(private readonly Closure $connect)
    {
        $this->tenant = \BIomie\Support\TenantContext::sqlFilter();
        $this->tenantN = \BIomie\Support\TenantContext::sqlFilter('n');
        $this->tenantM = \BIomie\Support\TenantContext::sqlFilter('m');
        $this->tenantE = \BIomie\Support\TenantContext::sqlFilter('e');
        $this->tenantS = \BIomie\Support\TenantContext::sqlFilter('s');
    }

    private function pdo(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = ($this->connect)();
        }
        return $this->pdo;
    }

    private function cid(): int
    {
        return \BIomie\Support\TenantContext::companyId();
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo()->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
        );
        $stmt->execute([':table' => $table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function missingTitleCondition(string $nfAlias = 'n'): string
    {
        if ($this->tableExists('biomie_bridge_nf_titulo_items')) {
            return "NOT EXISTS (
                SELECT 1 FROM biomie_bridge_nf_titulo_items bni
                WHERE bni.nota_key = {$nfAlias}.nota_key
                  AND bni.company_id = {$nfAlias}.company_id
            )";
        }

        if ($this->tableExists('biomie_bridge_nf_titulo')) {
            return "NOT EXISTS (
                SELECT 1 FROM biomie_bridge_nf_titulo b
                WHERE b.nota_key = {$nfAlias}.nota_key
                  AND b.company_id = {$nfAlias}.company_id
            )";
        }

        return '0 = 1';
    }

    private function hasTitleExpression(string $nfAlias = 'n'): string
    {
        if ($this->tableExists('biomie_bridge_nf_titulo_items')) {
            return "EXISTS (
                SELECT 1 FROM biomie_bridge_nf_titulo_items bni
                WHERE bni.nota_key = {$nfAlias}.nota_key
                  AND bni.company_id = {$nfAlias}.company_id
            )";
        }

        if ($this->tableExists('biomie_bridge_nf_titulo')) {
            return "EXISTS (
                SELECT 1 FROM biomie_bridge_nf_titulo b
                WHERE b.nota_key = {$nfAlias}.nota_key
                  AND b.company_id = {$nfAlias}.company_id
            )";
        }

        return '1 = 1';
    }

    // ── Verificações de estrutura ────────────────────────────────────────

    public function martExists(): bool
    {
        return $this->tableExists('mart_fiscal_items');
    }

    public function martStats(): array
    {
        if (!$this->martExists()) {
            return ['exists' => false, 'rows' => 0, 'built_at' => null];
        }
        $r = $this->pdo()->query(
            "SELECT COUNT(*) AS c, MAX(built_at) AS last
             FROM mart_fiscal_items
             WHERE {$this->tenant}"
        )->fetch(PDO::FETCH_ASSOC);

        return [
            'exists'   => true,
            'rows'     => (int) ($r['c'] ?? 0),
            'built_at' => $r['last'] ?? null,
        ];
    }

    public function fiscalContext(): array
    {
        // Estabelecimento da EMPRESA ATIVA (cada empresa do grupo tem regime
        // próprio: Real / Presumido / Simples-EPP). Antes isso era um LIMIT 1
        // sobre todas as empresas, então o painel mostrava sempre o mesmo regime.
        $companyId = (string) \BIomie\Support\TenantContext::companyId();
        $companySlug = \BIomie\Support\TenantContext::slug();

        $establishment = [];
        if ($this->tableExists('fiscal_establishments')) {
            $stmt = $this->pdo()->prepare(
                "SELECT source_system, source_company_id, cnpj, razao_social, uf, municipio,
                        codigo_ibge, regime_tributario, operation_profile,
                        inscricao_estadual, inscricao_municipal
                 FROM fiscal_establishments
                 WHERE source_company_id = :cid
                 ORDER BY is_primary DESC, id
                 LIMIT 1"
            );
            $stmt->execute(['cid' => $companyId]);
            $establishment = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        }

        // Regime: o cadastrado no estabelecimento manda; sem cadastro, cai no
        // mapa oficial do grupo (por slug) — nunca "assume Lucro Real" cegamente.
        $regimeCode = (string) ($establishment['regime_tributario'] ?? '');
        if ($regimeCode === '') {
            $regimeCode = TaxRegime::forSlug($companySlug);
        }
        $regime = TaxRegime::profile($regimeCode);

        $sources = [];
        if ($this->tableExists('fiscal_source_registry')) {
            $sources = $this->pdo()->query(
                "SELECT source_code, source_label, direction, document_scope,
                        connection_mode, status, priority, notes, last_sync_at
                 FROM fiscal_source_registry
                 WHERE {$this->tenant}
                 ORDER BY priority, source_code"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        $inputSource = null;
        foreach ($sources as $source) {
            if (($source['source_code'] ?? '') === 'omie_entrada') {
                $inputSource = $source;
                break;
            }
        }
        $inputStatus = ($inputSource['status'] ?? '') === 'active' ? 'active' : 'last_phase';

        $martActive = $this->martExists();

        // Módulos aplicáveis dependem do regime: no Simples os tributos vão no
        // DAS unificado (não se apura PIS/COFINS/ICMS nota a nota).
        $isSimples = $regime['code'] === TaxRegime::SIMPLES_NACIONAL;
        $modules = $isSimples
            ? [
                ['code' => 'das',           'label' => 'DAS (Anexo I)',  'status' => 'prepared'],
                ['code' => 'icms_ce',       'label' => 'ICMS Ceará',     'status' => $martActive ? 'active' : 'pending'],
                ['code' => 'reform',        'label' => 'CBS/IBS',        'status' => $martActive ? 'active' : 'pending'],
                ['code' => 'inputs',        'label' => 'Entradas Omie',  'status' => $inputStatus],
            ]
            : [
                ['code' => 'federal',       'label' => 'PIS/COFINS',     'status' => $martActive ? 'active' : 'pending'],
                ['code' => 'icms_ce',       'label' => 'ICMS Ceará',     'status' => $martActive ? 'active' : 'pending'],
                ['code' => 'iss_fortaleza', 'label' => 'ISS Fortaleza',  'status' => 'prepared'],
                ['code' => $regime['code'], 'label' => $regime['label'], 'status' => 'prepared'],
                ['code' => 'reform',        'label' => 'CBS/IBS',        'status' => $martActive ? 'active' : 'pending'],
                ['code' => 'inputs',        'label' => 'Entradas Omie',  'status' => $inputStatus],
            ];

        return [
            'establishment' => $establishment,
            'regime' => $regime,
            'sources' => $sources,
            'modules' => $modules,
        ];
    }

    public function sphereSummary(string $start, string $end): array
    {
        $empty = [
            'federal' => ['pis' => 0.0, 'cofins' => 0.0, 'pis_retido' => 0.0, 'cofins_retido' => 0.0, 'csll_retida' => 0.0, 'irrf' => 0.0],
            'state' => ['icms' => 0.0, 'icms_st' => 0.0, 'difal_destino' => 0.0, 'difal_origem' => 0.0, 'fecop' => 0.0],
            'municipal' => ['servicos' => 0.0, 'iss' => 0.0, 'iss_retido' => 0.0, 'nfse' => 0],
            'reform' => ['cbs' => 0.0, 'ibs_uf' => 0.0, 'ibs_municipio' => 0.0],
            'coverage' => ['outputs' => true, 'inputs' => false, 'nfse' => false, 'accounting' => false],
        ];
        if (!$this->martExists()) return $empty;

        $stmt = $this->pdo()->prepare(
            "SELECT COALESCE(SUM(valor_pis),0) pis, COALESCE(SUM(valor_cofins),0) cofins,
                    COALESCE(SUM(valor_icms),0) icms, COALESCE(SUM(valor_icms_st),0) icms_st,
                    COALESCE(SUM(valor_difal_destino),0) difal_destino,
                    COALESCE(SUM(valor_difal_origem),0) difal_origem,
                    COALESCE(SUM(valor_fecop),0) fecop,
                    COALESCE(SUM(valor_iss),0) iss,
                    COALESCE(SUM(valor_cbs),0) cbs,
                    COALESCE(SUM(valor_ibs_uf),0) ibs_uf,
                    COALESCE(SUM(valor_ibs_municipio),0) ibs_municipio
             FROM mart_fiscal_items
             WHERE {$this->tenant}
               AND status_fiscal='autorizada' AND direction='saida'
               AND data_emissao BETWEEN :s AND :e"
        );
        $stmt->execute([':s' => $start, ':e' => $end]);
        $items = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $snap = [];
        if ($this->tableExists('fiscal_document_tax_snapshot')) {
            $snapshot = $this->pdo()->prepare(
                "SELECT COALESCE(SUM(s.valor_servicos),0) servicos,
                        COALESCE(SUM(s.valor_iss),0) iss,
                        COALESCE(SUM(s.valor_iss_retido),0) iss_retido,
                        COALESCE(SUM(s.valor_pis_retido),0) pis_retido,
                        COALESCE(SUM(s.valor_cofins_retido),0) cofins_retido,
                        COALESCE(SUM(s.valor_csll_retido),0) csll_retida,
                        COALESCE(SUM(s.valor_irrf),0) irrf,
                        COALESCE(SUM(s.valor_ibs_municipio),0) ibs_municipio,
                        SUM(CASE WHEN s.document_model='nfse' THEN 1 ELSE 0 END) nfse
                 FROM fiscal_document_tax_snapshot s
                 JOIN biomie_notas_fiscais_canonicas n
                   ON n.nota_key=s.nota_key
                  AND n.company_id=s.company_id
                 WHERE {$this->tenantN}
                   AND {$this->tenantS}
                   AND n.status_fiscal='autorizada' AND n.data_emissao BETWEEN :s AND :e"
            );
            $snapshot->execute([':s' => $start, ':e' => $end]);
            $snap = $snapshot->fetch(PDO::FETCH_ASSOC) ?: [];
        }

        $hasInputs = $this->tableExists('fiscal_purchase_entries')
            && (int) $this->pdo()->query(
                "SELECT COUNT(*) FROM fiscal_purchase_entries WHERE {$this->tenant}"
            )->fetchColumn() > 0;

        return [
            'federal' => [
                'pis' => (float) ($items['pis'] ?? 0),
                'cofins' => (float) ($items['cofins'] ?? 0),
                'pis_retido' => (float) ($snap['pis_retido'] ?? 0),
                'cofins_retido' => (float) ($snap['cofins_retido'] ?? 0),
                'csll_retida' => (float) ($snap['csll_retida'] ?? 0),
                'irrf' => (float) ($snap['irrf'] ?? 0),
            ],
            'state' => [
                'icms' => (float) ($items['icms'] ?? 0),
                'icms_st' => (float) ($items['icms_st'] ?? 0),
                'difal_destino' => (float) ($items['difal_destino'] ?? 0),
                'difal_origem' => (float) ($items['difal_origem'] ?? 0),
                'fecop' => (float) ($items['fecop'] ?? 0),
            ],
            'municipal' => [
                'servicos' => (float) ($snap['servicos'] ?? 0),
                'iss' => max((float) ($items['iss'] ?? 0), (float) ($snap['iss'] ?? 0)),
                'iss_retido' => (float) ($snap['iss_retido'] ?? 0),
                'nfse' => (int) ($snap['nfse'] ?? 0),
            ],
            'reform' => [
                'cbs' => (float) ($items['cbs'] ?? 0),
                'ibs_uf' => (float) ($items['ibs_uf'] ?? 0),
                'ibs_municipio' => max((float) ($items['ibs_municipio'] ?? 0), (float) ($snap['ibs_municipio'] ?? 0)),
            ],
            'coverage' => ['outputs' => true, 'inputs' => $hasInputs, 'nfse' => (int) ($snap['nfse'] ?? 0) > 0, 'accounting' => false],
        ];
    }

    public function monthlySettlement(string $start, string $end): array
    {
        if (!$this->tableExists('mart_fiscal_monthly_settlement')) {
            return ['rows' => [], 'totals' => [], 'coverage' => ['inputs' => 'pending', 'accounting' => 'pending']];
        }

        $startMonth = substr($start, 0, 7);
        $endMonth = substr($end, 0, 7);
        $stmt = $this->pdo()->prepare(
            "SELECT * FROM mart_fiscal_monthly_settlement
             WHERE {$this->tenant}
               AND competencia BETWEEN :s AND :e ORDER BY competencia"
        );
        $stmt->execute([':s' => $startMonth, ':e' => $endMonth]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $totals = [
            'faturamento' => 0.0,
            'tributos_brutos' => 0.0,
            'creditos_totais' => 0.0,
            'retencoes_totais' => 0.0,
            'saldo_liquido_estimado' => 0.0,
        ];
        foreach ($rows as $row) {
            foreach (array_keys($totals) as $key) {
                $totals[$key] += (float) ($row[$key] ?? 0);
            }
        }
        $totals['carga_bruta_pct'] = $totals['faturamento'] > 0
            ? $totals['tributos_brutos'] / $totals['faturamento'] * 100 : 0.0;
        $totals['carga_liquida_pct'] = $totals['faturamento'] > 0
            ? $totals['saldo_liquido_estimado'] / $totals['faturamento'] * 100 : 0.0;

        return [
            'rows' => $rows,
            'totals' => $totals,
            'coverage' => [
                'inputs' => $rows === [] ? 'pending' : (string) ($rows[0]['input_coverage'] ?? 'pending'),
                'accounting' => $rows === [] ? 'pending' : (string) ($rows[0]['accounting_coverage'] ?? 'pending'),
                'credits_are_confirmed' => $rows !== [] && !in_array('pending', array_column($rows, 'input_coverage'), true),
            ],
        ];
    }

    public function purchaseEntries(
        string $start,
        string $end,
        int $page,
        int $perPage,
        string $search = '',
        string $sort = 'data_entrada',
        string $direction = 'desc',
    ): array {
        if (!$this->tableExists('fiscal_purchase_entries')) {
            return [
                'summary' => $this->emptyPurchaseSummary(),
                'rows' => [],
                'total' => 0,
                'page' => 1,
                'pages' => 0,
                'source' => ['status' => 'pending', 'last_sync_at' => null],
            ];
        }

        $sortColumns = [
            'data_entrada' => 'e.data_entrada',
            'numero_documento' => 'e.numero_documento',
            'fornecedor_nome' => 'e.fornecedor_nome',
            'valor_total' => 'e.valor_total',
            'credito_potencial' => 'e.credito_potencial',
        ];
        $orderBy = $sortColumns[$sort] ?? $sortColumns['data_entrada'];
        $orderDirection = strtolower($direction) === 'asc' ? 'ASC' : 'DESC';
        $where = [$this->tenantE, 'e.data_entrada BETWEEN :start AND :end'];
        $params = ['start' => $start, 'end' => $end];
        if ($search !== '') {
            $where[] = '(e.numero_documento LIKE :search OR e.fornecedor_nome LIKE :search
                         OR EXISTS (
                           SELECT 1 FROM fiscal_purchase_entry_items i
                           WHERE i.entry_key=e.entry_key
                             AND i.company_id=e.company_id
                             AND (i.ncm LIKE :search OR i.cfop LIKE :search)
                         ))';
            $params['search'] = '%' . $search . '%';
        }
        $whereSql = implode(' AND ', $where);

        $summaryStmt = $this->pdo()->prepare(
            "SELECT COUNT(*) documentos,
                    COALESCE(SUM(valor_total),0) compras,
                    COALESCE(SUM(credito_potencial),0) credito_potencial,
                    COALESCE(SUM(valor_icms),0) icms,
                    COALESCE(SUM(valor_pis),0) pis,
                    COALESCE(SUM(valor_cofins),0) cofins,
                    COALESCE(SUM(valor_ipi),0) ipi,
                    COALESCE(SUM(valor_cbs),0) cbs,
                    COALESCE(SUM(valor_ibs),0) ibs,
                    COALESCE(SUM(itens_count),0) itens,
                    COUNT(DISTINCT fornecedor_id) fornecedores
             FROM fiscal_purchase_entries e
             WHERE {$whereSql}"
        );
        $summaryStmt->execute($params);
        $summary = array_merge($this->emptyPurchaseSummary(), $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: []);

        $countStmt = $this->pdo()->prepare(
            "SELECT COUNT(*) FROM fiscal_purchase_entries e WHERE {$whereSql}"
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $page = max(1, $page);
        $perPage = max(10, min(100, $perPage));
        $pages = $total > 0 ? (int) ceil($total / $perPage) : 0;
        if ($pages > 0) {
            $page = min($page, $pages);
        }
        $offset = ($page - 1) * $perPage;

        $stmt = $this->pdo()->prepare(
            "SELECT e.*,
                    (SELECT GROUP_CONCAT(DISTINCT i.ncm ORDER BY i.ncm SEPARATOR ', ')
                     FROM fiscal_purchase_entry_items i
                     WHERE i.entry_key=e.entry_key AND i.company_id=e.company_id) ncm_list,
                    (SELECT GROUP_CONCAT(DISTINCT i.cfop ORDER BY i.cfop SEPARATOR ', ')
                     FROM fiscal_purchase_entry_items i
                     WHERE i.entry_key=e.entry_key AND i.company_id=e.company_id) cfop_list
             FROM fiscal_purchase_entries e
             WHERE {$whereSql}
             ORDER BY {$orderBy} {$orderDirection}, e.entry_key {$orderDirection}
             LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $source = ['status' => 'pending', 'last_sync_at' => null];
        if ($this->tableExists('fiscal_source_registry')) {
            $sourceStmt = $this->pdo()->query(
                "SELECT status, last_sync_at, notes FROM fiscal_source_registry
                 WHERE {$this->tenant}
                   AND source_code='omie_entrada' LIMIT 1"
            );
            $source = array_merge($source, $sourceStmt->fetch(PDO::FETCH_ASSOC) ?: []);
        }

        return [
            'summary' => $summary,
            'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'source' => $source,
        ];
    }

    private function emptyPurchaseSummary(): array
    {
        return [
            'documentos' => 0,
            'compras' => 0.0,
            'credito_potencial' => 0.0,
            'icms' => 0.0,
            'pis' => 0.0,
            'cofins' => 0.0,
            'ipi' => 0.0,
            'cbs' => 0.0,
            'ibs' => 0.0,
            'itens' => 0,
            'fornecedores' => 0,
        ];
    }

    // ── Resumo Executivo ────────────────────────────────────────────────

    public function executiveSummary(string $start, string $end): array
    {
        // Basic NF stats. Fiscal load uses authorized NFs as the denominator;
        // cancelled notes are tracked separately for audit.
        $stmt = $this->pdo()->prepare("
            SELECT
                COUNT(*) AS total_nfs,
                SUM(CASE WHEN status_fiscal='autorizada' THEN 1 ELSE 0 END) AS autorizadas,
                SUM(CASE WHEN status_fiscal='cancelada' THEN 1 ELSE 0 END) AS canceladas,
                COALESCE(SUM(CASE WHEN status_fiscal='autorizada' THEN valor_total ELSE 0 END), 0) AS faturamento,
                COALESCE(SUM(CASE WHEN status_fiscal='cancelada' THEN valor_total ELSE 0 END), 0) AS faturamento_cancelado,
                SUM(CASE WHEN status_fiscal='autorizada' AND (chave_nfe IS NULL OR TRIM(chave_nfe) = '') THEN 1 ELSE 0 END) AS xml_pendente
            FROM biomie_notas_fiscais_canonicas
            WHERE {$this->tenant}
              AND data_emissao BETWEEN :s AND :e
        ");
        $stmt->execute([':s' => $start, ':e' => $end]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $fat = (float) ($row['faturamento'] ?? 0);

        // Tax data + CBS/IBS from mart (all tax columns live in mart_fiscal_items)
        $taxes  = [
            'icms' => 0.0, 'pis' => 0.0, 'cofins' => 0.0,
            'ipi'  => 0.0, 'iss' => 0.0,
            'trib_total' => 0.0, 'carga_pct' => 0.0,
            'media_trib_nf' => 0.0, 'alerta_icms_zero' => 0,
        ];
        $cbsIbs = ['cbs' => 0.0, 'ibs' => 0.0, 'has_mart' => false];

        if ($this->martExists()) {
            $ms = $this->pdo()->prepare("
                SELECT
                    COALESCE(SUM(valor_icms),   0)   AS icms,
                    COALESCE(SUM(valor_pis),    0)   AS pis,
                    COALESCE(SUM(valor_cofins), 0)   AS cofins,
                    COALESCE(SUM(valor_ipi),    0)   AS ipi,
                    COALESCE(SUM(valor_iss),    0)   AS iss,
                    COALESCE(SUM(valor_trib_total), 0) AS trib_total,
                    COALESCE(SUM(valor_cbs),    0)   AS cbs,
                    COALESCE(SUM(valor_ibs),    0)   AS ibs,
                    COALESCE(AVG(valor_trib_total), 0) AS media_trib_nf,
                    COUNT(DISTINCT CASE WHEN valor_icms = 0 AND valor_total_item > 500 THEN nota_key END) AS alerta_icms_zero,
                    COUNT(DISTINCT CASE WHEN ncm IS NULL OR TRIM(ncm) = '' THEN nota_key END) AS sem_ncm,
                    COUNT(DISTINCT CASE WHEN YEAR(data_emissao) = 2026 AND valor_total_item > 0 AND COALESCE(valor_cbs,0) + COALESCE(valor_ibs,0) = 0 THEN nota_key END) AS cbs_ibs_zero
                FROM mart_fiscal_items
                WHERE {$this->tenant}
                  AND status_fiscal = 'autorizada'
                  AND data_emissao BETWEEN :s AND :e
            ");
            $ms->execute([':s' => $start, ':e' => $end]);
            $mr   = $ms->fetch(PDO::FETCH_ASSOC) ?: [];
            $trib = (float) ($mr['trib_total'] ?? 0);

            $taxes = [
                'icms'             => (float) ($mr['icms']   ?? 0),
                'pis'              => (float) ($mr['pis']    ?? 0),
                'cofins'           => (float) ($mr['cofins'] ?? 0),
                'ipi'              => (float) ($mr['ipi']    ?? 0),
                'iss'              => (float) ($mr['iss']    ?? 0),
                'trib_total'       => $trib,
                'carga_pct'        => $fat > 0 ? round($trib / $fat * 100, 2) : 0.0,
                'media_trib_nf'    => (float) ($mr['media_trib_nf'] ?? 0),
                'alerta_icms_zero' => (int) ($mr['alerta_icms_zero'] ?? 0),
                'sem_ncm'          => (int) ($mr['sem_ncm'] ?? 0),
                'cbs_ibs_zero'     => (int) ($mr['cbs_ibs_zero'] ?? 0),
            ];
            $cbsIbs = [
                'cbs'      => (float) ($mr['cbs'] ?? 0),
                'ibs'      => (float) ($mr['ibs'] ?? 0),
                'has_mart' => true,
            ];

            $maxStmt = $this->pdo()->prepare("
                SELECT COALESCE(MAX(carga_pct), 0) AS max_carga_pct
                FROM (
                    SELECT
                        nota_key,
                        ROUND(SUM(valor_trib_total) / NULLIF(SUM(valor_total_item), 0) * 100, 2) AS carga_pct
                    FROM mart_fiscal_items
                    WHERE {$this->tenant}
                      AND status_fiscal = 'autorizada'
                      AND data_emissao BETWEEN :s AND :e
                    GROUP BY company_id, nota_key
                ) nf_carga
            ");
            $maxStmt->execute([':s' => $start, ':e' => $end]);
            $taxes['max_carga_pct'] = (float) ($maxStmt->fetchColumn() ?: 0);
        }

        // Gap fiscal×financeiro
        $missingTitleCondition = $this->missingTitleCondition('n');
        $gap = $this->pdo()->prepare("
            SELECT COUNT(*) AS sem_titulo,
                   COALESCE(SUM(n.valor_total), 0) AS valor_sem_titulo
            FROM biomie_notas_fiscais_canonicas n
            WHERE {$this->tenantN}
              AND n.status_fiscal='autorizada'
              AND n.data_emissao BETWEEN :s AND :e
              AND {$missingTitleCondition}
        ");
        $gap->execute([':s' => $start, ':e' => $end]);
        $gapRow = $gap->fetch(PDO::FETCH_ASSOC) ?: [];

        return array_merge($row, $taxes, $gapRow, $cbsIbs);
    }

    // ── Tendência Mensal ────────────────────────────────────────────────

    public function monthlyTrend(string $start, string $end): array
    {
        if ($this->martExists()) {
            $stmt = $this->pdo()->prepare("
                SELECT
                    DATE_FORMAT(data_emissao,'%Y-%m')                          AS month,
                    COUNT(DISTINCT CONCAT(company_id, ':', nota_key))           AS total_nfs,
                    COALESCE(SUM(valor_total_item), 0)                         AS faturamento,
                    COALESCE(SUM(valor_icms),   0)                             AS icms,
                    COALESCE(SUM(valor_pis),    0)                             AS pis,
                    COALESCE(SUM(valor_cofins), 0)                             AS cofins,
                    COALESCE(SUM(valor_ipi),    0)                             AS ipi,
                    COALESCE(SUM(valor_trib_total), 0)                         AS trib_total,
                    ROUND(
                        COALESCE(SUM(valor_trib_total), 0) /
                        NULLIF(COALESCE(SUM(valor_total_item), 0), 0) * 100, 2
                    )                                                           AS carga_pct
                FROM mart_fiscal_items
                WHERE {$this->tenant}
                  AND status_fiscal = 'autorizada'
                  AND data_emissao BETWEEN :s AND :e
                GROUP BY month
                ORDER BY month
            ");
            $stmt->execute([':s' => $start, ':e' => $end]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        // Fallback: canonical only (no tax columns)
        $stmt = $this->pdo()->prepare("
            SELECT
                DATE_FORMAT(data_emissao,'%Y-%m') AS month,
                COUNT(*)                           AS total_nfs,
                COALESCE(SUM(valor_total), 0)      AS faturamento,
                0 AS icms, 0 AS pis, 0 AS cofins, 0 AS ipi,
                0 AS trib_total, 0 AS carga_pct
            FROM biomie_notas_fiscais_canonicas
            WHERE {$this->tenant}
              AND status_fiscal = 'autorizada'
              AND data_emissao BETWEEN :s AND :e
            GROUP BY month
            ORDER BY month
        ");
        $stmt->execute([':s' => $start, ':e' => $end]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // ── Ranking de Vendedores ───────────────────────────────────────────

    public function vendorRanking(string $start, string $end): array
    {
        if ($this->martExists()) {
            $stmt = $this->pdo()->prepare("
                SELECT
                    nome_vendedor,
                    codigo_vendedor_omie,
                    COUNT(DISTINCT CONCAT(company_id, ':', nota_key)) AS total_nfs,
                    COALESCE(SUM(valor_total_item), 0)          AS faturamento,
                    COALESCE(SUM(valor_icms),   0)              AS icms,
                    COALESCE(SUM(valor_pis),    0)              AS pis,
                    COALESCE(SUM(valor_cofins), 0)              AS cofins,
                    COALESCE(SUM(valor_ipi),    0)              AS ipi,
                    COALESCE(SUM(valor_trib_total), 0)          AS trib_total,
                    ROUND(
                        COALESCE(SUM(valor_trib_total), 0) /
                        NULLIF(COALESCE(SUM(valor_total_item), 0), 0) * 100, 2
                    )                                           AS carga_pct,
                    COALESCE(AVG(valor_trib_total), 0)          AS media_trib_nf
                FROM mart_fiscal_items
                WHERE {$this->tenant}
                  AND status_fiscal = 'autorizada'
                  AND data_emissao BETWEEN :s AND :e
                GROUP BY nome_vendedor, codigo_vendedor_omie
                ORDER BY trib_total DESC
            ");
            $stmt->execute([':s' => $start, ':e' => $end]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        // Fallback: canonical only (no tax columns)
        $stmt = $this->pdo()->prepare("
            SELECT
                nome_vendedor,
                codigo_vendedor_omie,
                COUNT(*)                         AS total_nfs,
                COALESCE(SUM(valor_total), 0)    AS faturamento,
                0 AS icms, 0 AS pis, 0 AS cofins, 0 AS ipi,
                0 AS trib_total, 0 AS carga_pct, 0 AS media_trib_nf
            FROM biomie_notas_fiscais_canonicas
            WHERE {$this->tenant}
              AND status_fiscal = 'autorizada'
              AND data_emissao BETWEEN :s AND :e
            GROUP BY nome_vendedor, codigo_vendedor_omie
            ORDER BY faturamento DESC
        ");
        $stmt->execute([':s' => $start, ':e' => $end]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // ── Lista de NFs paginada ───────────────────────────────────────────

    public function nfList(string $start, string $end, int $page, int $perPage, array $filters): array
    {
        $where = [$this->tenantN, 'n.data_emissao BETWEEN :s AND :e'];
        $params = [
            ':s'      => $start,
            ':e'      => $end,
        ];

        $status = (string) ($filters['status'] ?? 'autorizada');
        if ($status !== 'todos') {
            $where[] = 'n.status_fiscal = :status';
            $params[':status'] = $status;
        }

        if (!empty($filters['search'])) {
            // Buscar pelo numero do PEDIDO tambem: quem cobra imposto costuma
            // chegar pelo pedido, nao pelo numero da NF.
            $where[] = '(n.nome_cliente LIKE :search OR n.nome_vendedor LIKE :search'
                . ' OR n.numero_nf LIKE :search OR n.codigo_pedido_origem LIKE :search)';
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        // Recorte de confiabilidade: ver so o que ainda nao amarrou com pedido
        // (ou so o que amarrou), em vez de auditar a lista inteira no olho.
        $vinculo = (string) ($filters['vinculo'] ?? '');
        if ($vinculo === 'sem_pedido') {
            $where[] = "(n.pedido_id_omie IS NULL OR n.pedido_id_omie = 0)";
        } elseif ($vinculo === 'com_pedido') {
            $where[] = 'n.pedido_id_omie IS NOT NULL AND n.pedido_id_omie <> 0';
        }
        if (!empty($filters['vendedor'])) {
            $where[]         = 'n.nome_vendedor LIKE :vend';
            $params[':vend'] = '%' . $filters['vendedor'] . '%';
        }
        if (!empty($filters['cliente'])) {
            $where[]         = 'n.nome_cliente LIKE :cli';
            $params[':cli']  = '%' . $filters['cliente'] . '%';
        }

        $whereSQL = implode(' AND ', $where);
        $offset   = max(0, ($page - 1) * $perPage);

        $allowedSorts = [
            'data_emissao'     => 'n.data_emissao',
            'numero_nf'        => 'n.numero_nf',
            'nome_cliente'     => 'n.nome_cliente',
            'nome_vendedor'    => 'n.nome_vendedor',
            'valor_total'      => 'n.valor_total',
            'valor_trib_total' => 'valor_trib_total',
            'carga_pct'        => 'carga_pct',
        ];
        $sortCol = $allowedSorts[$filters['sort'] ?? ''] ?? 'n.data_emissao';
        $sortDir = ($filters['direction'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
        $orderSQL = "{$sortCol} {$sortDir}, n.numero_nf DESC";

        // Count
        $cStmt = $this->pdo()->prepare("SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas n WHERE {$whereSQL}");
        $cStmt->execute($params);
        $total = (int) $cStmt->fetchColumn();

        // Tax columns live in mart_fiscal_items, not in canonical
        if ($this->martExists()) {
            $taxSelect = "
                COALESCE(m.valor_icms,       0) AS valor_icms,
                COALESCE(m.valor_pis,        0) AS valor_pis,
                COALESCE(m.valor_cofins,     0) AS valor_cofins,
                COALESCE(m.valor_ipi,        0) AS valor_ipi,
                COALESCE(m.valor_iss,        0) AS valor_iss,
                COALESCE(m.valor_trib_total, 0) AS valor_trib_total,
                ROUND(COALESCE(m.valor_trib_total,0)/NULLIF(n.valor_total,0)*100,2) AS carga_pct,
                COALESCE(m.sem_ncm_count, 0) AS sem_ncm_count,
                COALESCE(m.icms_zero_count, 0) AS icms_zero_count,
                COALESCE(m.principal_cfop, '') AS principal_cfop
            ";
            $martJoin = "
                LEFT JOIN (
                    SELECT company_id, nota_key,
                           SUM(valor_icms)       AS valor_icms,
                           SUM(valor_pis)        AS valor_pis,
                           SUM(valor_cofins)     AS valor_cofins,
                           SUM(valor_ipi)        AS valor_ipi,
                           SUM(valor_iss)        AS valor_iss,
                           SUM(valor_trib_total) AS valor_trib_total,
                           SUM(CASE WHEN ncm IS NULL OR TRIM(ncm) = '' THEN 1 ELSE 0 END) AS sem_ncm_count,
                           SUM(CASE WHEN valor_icms = 0 AND valor_total_item > 500 THEN 1 ELSE 0 END) AS icms_zero_count,
                           SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(cfop, '') ORDER BY valor_total_item DESC SEPARATOR ','), ',', 1) AS principal_cfop
                    FROM mart_fiscal_items
                    WHERE {$this->tenant}
                      AND data_emissao BETWEEN :mart_s AND :mart_e
                    GROUP BY company_id, nota_key
                ) m ON m.nota_key = n.nota_key
                   AND m.company_id = n.company_id
            ";
            $params[':mart_s'] = $start;
            $params[':mart_e'] = $end;
        } else {
            $taxSelect = "0 AS valor_icms, 0 AS valor_pis, 0 AS valor_cofins,
                           0 AS valor_ipi, 0 AS valor_iss, 0 AS valor_trib_total,
                           0 AS carga_pct, 0 AS sem_ncm_count, 0 AS icms_zero_count,
                           '' AS principal_cfop";
            $martJoin  = '';
        }

        $hasTitleExpression = $this->hasTitleExpression('n');

        $stmt = $this->pdo()->prepare("
            SELECT
                n.nota_key, n.numero_nf, n.serie_nf, n.chave_nfe,
                n.data_emissao, n.nome_cliente, n.documento_cliente,
                n.nome_vendedor, n.valor_total, n.status_fiscal,
                -- O pedido faturado que originou a NF. Vem do canonico, onde
                -- esta preenchido em 100% das notas; o mart fiscal so o tinha
                -- para ~5% delas porque dependia de uma bridge quase vazia.
                n.pedido_id_omie,
                NULLIF(TRIM(COALESCE(n.codigo_pedido_origem, '')), '') AS codigo_pedido_origem,
                {$taxSelect},
                ({$hasTitleExpression}) AS has_titulo
            FROM biomie_notas_fiscais_canonicas n
            {$martJoin}
            WHERE {$whereSQL}
            ORDER BY {$orderSQL}
            LIMIT :lim OFFSET :off
        ");
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset,  PDO::PARAM_INT);
        $stmt->execute();
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => (int) ceil($total / $perPage),
            'rows'     => $data,
        ];
    }

    // ── Itens de uma NF (para drawer) ──────────────────────────────────

    public function nfItems(string $notaKey): array
    {
        if (!$this->martExists()) {
            return [];
        }
        $stmt = $this->pdo()->prepare("
            SELECT
                sequencia_item, descricao_produto, codigo_produto,
                ncm, cfop, origem_produto, quantidade,
                valor_produto, valor_desconto, valor_total_item,
                aliq_icms, aliq_pis, aliq_cofins, aliq_ipi,
                valor_icms, valor_pis, valor_cofins, valor_ipi, valor_iss,
                valor_trib_total,
                aliq_cbs, aliq_ibs, valor_cbs, valor_ibs
            FROM mart_fiscal_items
            WHERE {$this->tenant}
              AND nota_key = :nk
            ORDER BY sequencia_item
        ");
        $stmt->execute([':nk' => $notaKey]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // ── Ranking por NCM ─────────────────────────────────────────────────

    public function ncmRanking(string $start, string $end, int $limit = 30): array
    {
        if (!$this->martExists()) {
            return [];
        }
        $stmt = $this->pdo()->prepare("
            SELECT
                COALESCE(ncm,'(sem NCM)')          AS ncm,
                LEFT(MAX(descricao_produto),80)    AS produto_exemplo,
                COUNT(DISTINCT CONCAT(company_id, ':', nota_key)) AS total_nfs,
                SUM(quantidade)                    AS quantidade,
                SUM(valor_total_item)              AS faturamento,
                SUM(valor_icms)                    AS icms,
                SUM(valor_pis)                     AS pis,
                SUM(valor_cofins)                  AS cofins,
                SUM(valor_ipi)                     AS ipi,
                SUM(valor_trib_total)              AS trib_total,
                ROUND(SUM(valor_trib_total)/NULLIF(SUM(valor_total_item),0)*100,2) AS carga_pct,
                SUM(valor_cbs)                     AS cbs_total,
                SUM(valor_ibs)                     AS ibs_total,
                AVG(aliq_icms)                     AS aliq_icms_media,
                SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(cfop, '') ORDER BY valor_total_item DESC SEPARATOR ','), ',', 1) AS principal_cfop
            FROM mart_fiscal_items
            WHERE {$this->tenant}
              AND status_fiscal='autorizada'
              AND data_emissao BETWEEN :s AND :e
            GROUP BY ncm
            ORDER BY trib_total DESC
            LIMIT :lim
        ");
        $stmt->bindValue(':s', $start);
        $stmt->bindValue(':e', $end);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // ── NCM Detail (para drawer) ────────────────────────────────────────

    public function ncmProducts(string $ncm, string $start, string $end): array
    {
        if (!$this->martExists()) {
            return [];
        }
        $stmt = $this->pdo()->prepare("
            SELECT
                codigo_produto, descricao_produto,
                SUM(quantidade) AS quantidade,
                SUM(valor_total_item) AS faturamento,
                SUM(valor_icms) AS icms,
                SUM(valor_trib_total) AS trib_total,
                ROUND(SUM(valor_trib_total)/NULLIF(SUM(valor_total_item),0)*100,2) AS carga_pct,
                AVG(aliq_icms) AS aliq_icms_media
            FROM mart_fiscal_items
            WHERE {$this->tenant}
              AND ncm = :ncm
              AND status_fiscal='autorizada'
              AND data_emissao BETWEEN :s AND :e
            GROUP BY codigo_produto, descricao_produto
            ORDER BY faturamento DESC
            LIMIT 50
        ");
        $stmt->execute([':ncm' => $ncm, ':s' => $start, ':e' => $end]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // ── Reforma Tributária CBS/IBS ──────────────────────────────────────

    public function reformSimulation(string $start, string $end): array
    {
        if (!$this->martExists()) {
            return ['has_mart' => false];
        }

        $stmt = $this->pdo()->prepare("
            SELECT
                DATE_FORMAT(data_emissao,'%Y-%m')      AS month,
                SUM(valor_produto)                      AS faturamento,
                SUM(valor_icms)                         AS icms,
                SUM(valor_pis)                          AS pis,
                SUM(valor_cofins)                       AS cofins,
                SUM(valor_ipi)                          AS ipi,
                SUM(valor_trib_total)                   AS carga_atual,
                SUM(valor_cbs)                          AS cbs,
                SUM(valor_ibs)                          AS ibs,
                SUM(valor_cbs)+SUM(valor_ibs)           AS carga_nova_info,
                COUNT(DISTINCT CONCAT(company_id, ':', nota_key)) AS total_nfs
            FROM mart_fiscal_items
            WHERE {$this->tenant}
              AND status_fiscal='autorizada'
              AND data_emissao BETWEEN :s AND :e
            GROUP BY month
            ORDER BY month
        ");
        $stmt->execute([':s' => $start, ':e' => $end]);
        $trend = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Totals + CFOP breakdown
        $totStmt = $this->pdo()->prepare("
            SELECT
                SUM(valor_produto)      AS faturamento,
                SUM(valor_icms)         AS icms,
                SUM(valor_pis)          AS pis,
                SUM(valor_cofins)       AS cofins,
                SUM(valor_ipi)          AS ipi,
                SUM(valor_trib_total)   AS carga_atual,
                SUM(valor_cbs)          AS cbs,
                SUM(valor_ibs)          AS ibs,
                AVG(aliq_cbs)           AS aliq_cbs_media,
                AVG(aliq_ibs)           AS aliq_ibs_media,
                COUNT(DISTINCT CONCAT(company_id, ':', nota_key)) AS total_nfs
            FROM mart_fiscal_items
            WHERE {$this->tenant}
              AND status_fiscal='autorizada'
              AND data_emissao BETWEEN :s AND :e
        ");
        $totStmt->execute([':s' => $start, ':e' => $end]);
        $totals = $totStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        // Top NCMs por CBS/IBS
        $ncmStmt = $this->pdo()->prepare("
            SELECT ncm, LEFT(MAX(descricao_produto),60) AS produto,
                   SUM(valor_cbs) AS cbs, SUM(valor_ibs) AS ibs,
                   SUM(valor_trib_total) AS carga_atual,
                   SUM(valor_total_item) AS faturamento
            FROM mart_fiscal_items
            WHERE {$this->tenant}
              AND status_fiscal='autorizada'
              AND data_emissao BETWEEN :s AND :e
            GROUP BY ncm
            ORDER BY cbs DESC
            LIMIT 15
        ");
        $ncmStmt->execute([':s' => $start, ':e' => $end]);
        $ncmRank = $ncmStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'has_mart' => true,
            'trend'    => $trend,
            'totals'   => $totals,
            'ncm_rank' => $ncmRank,
        ];
    }

    // ── Alertas Fiscais ─────────────────────────────────────────────────

    public function fiscalAlerts(string $start, string $end): array
    {
        $pdo = $this->pdo();

        $empty = ['count' => 0, 'total' => 0.0, 'rows' => []];

        $fetchCountTotalRows = static function (PDO $pdo, string $countSql, string $rowsSql, array $params): array {
            $countStmt = $pdo->prepare($countSql);
            $countStmt->execute($params);
            $countRow = $countStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $rowsStmt = $pdo->prepare($rowsSql);
            $rowsStmt->execute($params);

            return [
                'count' => (int) ($countRow['count'] ?? 0),
                'total' => (float) ($countRow['total'] ?? 0),
                'rows'  => $rowsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
            ];
        };

        // ICMS zerado com valor relevante — only available from mart.
        $icmsZero = $empty;
        if ($this->martExists()) {
            $icmsZero = $fetchCountTotalRows(
                $pdo,
                "
                    SELECT COUNT(*) AS count, COALESCE(SUM(valor_total), 0) AS total
                    FROM (
                        SELECT m.company_id, m.nota_key, MAX(n.valor_total) AS valor_total
                        FROM mart_fiscal_items m
                        JOIN biomie_notas_fiscais_canonicas n
                          ON n.nota_key = m.nota_key
                         AND n.company_id = m.company_id
                        WHERE {$this->tenantM}
                          AND m.status_fiscal = 'autorizada'
                          AND m.valor_icms = 0
                          AND m.valor_total_item > 500
                          AND m.data_emissao BETWEEN :s AND :e
                        GROUP BY m.company_id, m.nota_key
                    ) x
                ",
                "
                    SELECT DISTINCT m.company_id, m.nota_key, n.numero_nf, n.data_emissao, n.nome_cliente,
                                    n.nome_vendedor, n.valor_total
                    FROM mart_fiscal_items m
                    JOIN biomie_notas_fiscais_canonicas n
                      ON n.nota_key = m.nota_key
                     AND n.company_id = m.company_id
                    WHERE {$this->tenantM}
                      AND m.status_fiscal = 'autorizada'
                      AND m.valor_icms = 0
                      AND m.valor_total_item > 500
                      AND m.data_emissao BETWEEN :s AND :e
                    ORDER BY n.valor_total DESC LIMIT 50
                ",
                [':s' => $start, ':e' => $end]
            );
        }

        // NFs canceladas
        $canceladas = $fetchCountTotalRows(
            $pdo,
            "
                SELECT COUNT(*) AS count, COALESCE(SUM(valor_total), 0) AS total
                FROM biomie_notas_fiscais_canonicas
                WHERE {$this->tenant}
                  AND status_fiscal='cancelada'
                  AND data_emissao BETWEEN :s AND :e
            ",
            "
            SELECT company_id, nota_key, numero_nf, data_emissao, nome_cliente, nome_vendedor, valor_total, data_cancelamento
            FROM biomie_notas_fiscais_canonicas
            WHERE {$this->tenant}
              AND status_fiscal='cancelada'
              AND data_emissao BETWEEN :s AND :e
            ORDER BY data_emissao DESC LIMIT 50
            ",
            [':s' => $start, ':e' => $end]
        );

        // Gap NF sem título
        $missingTitleCondition = $this->missingTitleCondition('n');
        $semTitulo = $fetchCountTotalRows(
            $pdo,
            "
                SELECT COUNT(*) AS count, COALESCE(SUM(n.valor_total), 0) AS total
                FROM biomie_notas_fiscais_canonicas n
                WHERE {$this->tenantN}
                  AND n.status_fiscal='autorizada'
                  AND n.data_emissao BETWEEN :s AND :e
                  AND {$missingTitleCondition}
            ",
            "
            SELECT n.company_id, n.nota_key, n.numero_nf, n.data_emissao, n.nome_cliente,
                   n.nome_vendedor, n.valor_total
            FROM biomie_notas_fiscais_canonicas n
            WHERE {$this->tenantN}
              AND n.status_fiscal='autorizada'
              AND n.data_emissao BETWEEN :s AND :e
              AND {$missingTitleCondition}
            ORDER BY n.valor_total DESC LIMIT 100
            ",
            [':s' => $start, ':e' => $end]
        );

        $semNcm = $empty;
        $cbsIbsZero = $empty;
        $cargaAcimaMedia = $empty + ['threshold' => 0.0, 'media' => 0.0];
        $cfopIncomum = $empty;
        $pisCofinsDivergente = $empty + ['media_pct' => 0.0];
        if ($this->martExists()) {
            $semNcm = $fetchCountTotalRows(
                $pdo,
                "
                    SELECT COUNT(*) AS count, COALESCE(SUM(valor_total), 0) AS total
                    FROM (
                        SELECT m.company_id, m.nota_key, MAX(n.valor_total) AS valor_total
                        FROM mart_fiscal_items m
                        JOIN biomie_notas_fiscais_canonicas n
                          ON n.nota_key = m.nota_key
                         AND n.company_id = m.company_id
                        WHERE {$this->tenantM}
                          AND m.status_fiscal = 'autorizada'
                          AND (m.ncm IS NULL OR TRIM(m.ncm) = '')
                          AND m.data_emissao BETWEEN :s AND :e
                        GROUP BY m.company_id, m.nota_key
                    ) x
                ",
                "
                    SELECT DISTINCT m.company_id, m.nota_key, n.numero_nf, n.data_emissao, n.nome_cliente,
                                    n.nome_vendedor, n.valor_total
                    FROM mart_fiscal_items m
                    JOIN biomie_notas_fiscais_canonicas n
                      ON n.nota_key = m.nota_key
                     AND n.company_id = m.company_id
                    WHERE {$this->tenantM}
                      AND m.status_fiscal = 'autorizada'
                      AND (m.ncm IS NULL OR TRIM(m.ncm) = '')
                      AND m.data_emissao BETWEEN :s AND :e
                    ORDER BY n.valor_total DESC LIMIT 50
                ",
                [':s' => $start, ':e' => $end]
            );

            $cbsIbsZero = $fetchCountTotalRows(
                $pdo,
                "
                    SELECT COUNT(*) AS count, COALESCE(SUM(valor_total), 0) AS total
                    FROM (
                        SELECT m.company_id, m.nota_key, MAX(n.valor_total) AS valor_total
                        FROM mart_fiscal_items m
                        JOIN biomie_notas_fiscais_canonicas n
                          ON n.nota_key = m.nota_key
                         AND n.company_id = m.company_id
                        WHERE {$this->tenantM}
                          AND m.status_fiscal = 'autorizada'
                          AND YEAR(m.data_emissao) = 2026
                          AND m.valor_total_item > 0
                          AND COALESCE(m.valor_cbs, 0) + COALESCE(m.valor_ibs, 0) = 0
                          AND m.data_emissao BETWEEN :s AND :e
                        GROUP BY m.company_id, m.nota_key
                    ) x
                ",
                "
                    SELECT DISTINCT m.company_id, m.nota_key, n.numero_nf, n.data_emissao, n.nome_cliente,
                                    n.nome_vendedor, n.valor_total
                    FROM mart_fiscal_items m
                    JOIN biomie_notas_fiscais_canonicas n
                      ON n.nota_key = m.nota_key
                     AND n.company_id = m.company_id
                    WHERE {$this->tenantM}
                      AND m.status_fiscal = 'autorizada'
                      AND YEAR(m.data_emissao) = 2026
                      AND m.valor_total_item > 0
                      AND COALESCE(m.valor_cbs, 0) + COALESCE(m.valor_ibs, 0) = 0
                      AND m.data_emissao BETWEEN :s AND :e
                    ORDER BY n.valor_total DESC LIMIT 50
                ",
                [':s' => $start, ':e' => $end]
            );

            $avgStmt = $pdo->prepare("
                SELECT COALESCE(AVG(carga_pct), 0)
                FROM (
                    SELECT ROUND(SUM(valor_trib_total) / NULLIF(SUM(valor_total_item), 0) * 100, 2) AS carga_pct
                    FROM mart_fiscal_items
                    WHERE {$this->tenant}
                      AND status_fiscal = 'autorizada'
                      AND data_emissao BETWEEN :s AND :e
                    GROUP BY company_id, nota_key
                ) x
            ");
            $avgStmt->execute([':s' => $start, ':e' => $end]);
            $avgCarga = (float) $avgStmt->fetchColumn();
            $threshold = $avgCarga * 1.25;

            $cargaAcimaMedia = $fetchCountTotalRows(
                $pdo,
                "
                    SELECT COUNT(*) AS count, COALESCE(SUM(valor_total), 0) AS total
                    FROM (
                        SELECT m.company_id, m.nota_key, MAX(n.valor_total) AS valor_total,
                               ROUND(SUM(m.valor_trib_total) / NULLIF(SUM(m.valor_total_item), 0) * 100, 2) AS carga_pct
                        FROM mart_fiscal_items m
                        JOIN biomie_notas_fiscais_canonicas n
                          ON n.nota_key = m.nota_key
                         AND n.company_id = m.company_id
                        WHERE {$this->tenantM}
                          AND m.status_fiscal = 'autorizada'
                          AND m.data_emissao BETWEEN :s AND :e
                        GROUP BY m.company_id, m.nota_key
                        HAVING carga_pct > :threshold
                    ) x
                ",
                "
                    SELECT m.company_id, m.nota_key, MAX(n.numero_nf) AS numero_nf, MAX(n.data_emissao) AS data_emissao,
                           MAX(n.nome_cliente) AS nome_cliente, MAX(n.nome_vendedor) AS nome_vendedor,
                           MAX(n.valor_total) AS valor_total,
                           ROUND(SUM(m.valor_trib_total) / NULLIF(SUM(m.valor_total_item), 0) * 100, 2) AS carga_pct
                    FROM mart_fiscal_items m
                    JOIN biomie_notas_fiscais_canonicas n
                      ON n.nota_key = m.nota_key
                     AND n.company_id = m.company_id
                    WHERE {$this->tenantM}
                      AND m.status_fiscal = 'autorizada'
                      AND m.data_emissao BETWEEN :s AND :e
                    GROUP BY m.company_id, m.nota_key
                    HAVING carga_pct > :threshold
                    ORDER BY carga_pct DESC LIMIT 50
                ",
                [':s' => $start, ':e' => $end, ':threshold' => $threshold]
            );
            $cargaAcimaMedia['threshold'] = $threshold;
            $cargaAcimaMedia['media'] = $avgCarga;

            // CFOP raro: utilizado por no máximo uma NF e com participação inferior a 1% das NFs do período.
            $cfopIncomum = $fetchCountTotalRows(
                $pdo,
                "
                    SELECT COUNT(*) AS count, COALESCE(SUM(valor_total), 0) AS total
                    FROM (
                        SELECT m.company_id, m.nota_key, MAX(n.valor_total) AS valor_total
                        FROM mart_fiscal_items m
                        JOIN biomie_notas_fiscais_canonicas n
                          ON n.nota_key = m.nota_key
                         AND n.company_id = m.company_id
                        JOIN (
                            SELECT cfop
                            FROM mart_fiscal_items
                            WHERE {$this->tenant}
                              AND status_fiscal='autorizada'
                              AND data_emissao BETWEEN :s2 AND :e2
                              AND cfop IS NOT NULL AND TRIM(cfop) <> ''
                            GROUP BY cfop
                            HAVING COUNT(DISTINCT CONCAT(company_id, ':', nota_key)) <= 1
                        ) rare ON rare.cfop = m.cfop
                        WHERE {$this->tenantM}
                          AND m.status_fiscal='autorizada'
                          AND m.data_emissao BETWEEN :s AND :e
                        GROUP BY m.company_id, m.nota_key
                    ) x
                ",
                "
                    SELECT m.company_id, m.nota_key, MAX(n.numero_nf) AS numero_nf, MAX(n.data_emissao) AS data_emissao,
                           MAX(n.nome_cliente) AS nome_cliente, MAX(n.nome_vendedor) AS nome_vendedor,
                           MAX(n.valor_total) AS valor_total, MAX(m.cfop) AS cfop
                    FROM mart_fiscal_items m
                    JOIN biomie_notas_fiscais_canonicas n
                      ON n.nota_key = m.nota_key
                     AND n.company_id = m.company_id
                    JOIN (
                        SELECT cfop
                        FROM mart_fiscal_items
                        WHERE {$this->tenant}
                          AND status_fiscal='autorizada'
                          AND data_emissao BETWEEN :s2 AND :e2
                          AND cfop IS NOT NULL AND TRIM(cfop) <> ''
                        GROUP BY cfop
                        HAVING COUNT(DISTINCT CONCAT(company_id, ':', nota_key)) <= 1
                    ) rare ON rare.cfop = m.cfop
                    WHERE {$this->tenantM}
                      AND m.status_fiscal='autorizada'
                      AND m.data_emissao BETWEEN :s AND :e
                    GROUP BY m.company_id, m.nota_key
                    ORDER BY valor_total DESC LIMIT 50
                ",
                [':s' => $start, ':e' => $end, ':s2' => $start, ':e2' => $end]
            );

            // PIS/COFINS fora do padrão: taxa efetiva da NF desvia mais de 25% da média
            // ponderada do período, com diferença mínima de 0,5 ponto percentual.
            $pcAvgStmt = $pdo->prepare("
                SELECT COALESCE((SUM(valor_pis) + SUM(valor_cofins)) / NULLIF(SUM(valor_total_item), 0) * 100, 0)
                FROM mart_fiscal_items
                WHERE {$this->tenant}
                  AND status_fiscal='autorizada' AND data_emissao BETWEEN :s AND :e
            ");
            $pcAvgStmt->execute([':s' => $start, ':e' => $end]);
            $pcAvg = (float) $pcAvgStmt->fetchColumn();
            $pcDelta = max(0.5, $pcAvg * 0.25);
            $pcLow = max(0.0, $pcAvg - $pcDelta);
            $pcHigh = $pcAvg + $pcDelta;

            $pisCofinsDivergente = $fetchCountTotalRows(
                $pdo,
                "
                    SELECT COUNT(*) AS count, COALESCE(SUM(valor_total), 0) AS total
                    FROM (
                        SELECT m.company_id, m.nota_key, MAX(n.valor_total) AS valor_total,
                               (SUM(m.valor_pis) + SUM(m.valor_cofins)) / NULLIF(SUM(m.valor_total_item),0) * 100 AS taxa
                        FROM mart_fiscal_items m
                        JOIN biomie_notas_fiscais_canonicas n
                          ON n.nota_key=m.nota_key
                         AND n.company_id=m.company_id
                        WHERE {$this->tenantM}
                          AND m.status_fiscal='autorizada' AND m.data_emissao BETWEEN :s AND :e
                        GROUP BY m.company_id, m.nota_key
                        HAVING SUM(m.valor_total_item) > 500 AND (taxa < :low OR taxa > :high)
                    ) x
                ",
                "
                    SELECT m.company_id, m.nota_key, MAX(n.numero_nf) AS numero_nf, MAX(n.data_emissao) AS data_emissao,
                           MAX(n.nome_cliente) AS nome_cliente, MAX(n.nome_vendedor) AS nome_vendedor,
                           MAX(n.valor_total) AS valor_total,
                           ROUND((SUM(m.valor_pis) + SUM(m.valor_cofins)) / NULLIF(SUM(m.valor_total_item),0) * 100, 2) AS carga_pct
                    FROM mart_fiscal_items m
                    JOIN biomie_notas_fiscais_canonicas n
                      ON n.nota_key=m.nota_key
                     AND n.company_id=m.company_id
                    WHERE {$this->tenantM}
                      AND m.status_fiscal='autorizada' AND m.data_emissao BETWEEN :s AND :e
                    GROUP BY m.company_id, m.nota_key
                    HAVING SUM(m.valor_total_item) > 500 AND (carga_pct < :low OR carga_pct > :high)
                    ORDER BY carga_pct DESC LIMIT 50
                ",
                [':s' => $start, ':e' => $end, ':low' => $pcLow, ':high' => $pcHigh]
            );
            $pisCofinsDivergente['media_pct'] = $pcAvg;
        }

        $xmlPendente = $fetchCountTotalRows(
            $pdo,
            "
                SELECT COUNT(*) AS count, COALESCE(SUM(valor_total), 0) AS total
                FROM biomie_notas_fiscais_canonicas
                WHERE {$this->tenant}
                  AND status_fiscal='autorizada'
                  AND data_emissao BETWEEN :s AND :e
                  AND (chave_nfe IS NULL OR TRIM(chave_nfe) = '')
            ",
            "
                SELECT company_id, nota_key, numero_nf, data_emissao, nome_cliente, nome_vendedor, valor_total
                FROM biomie_notas_fiscais_canonicas
                WHERE {$this->tenant}
                  AND status_fiscal='autorizada'
                  AND data_emissao BETWEEN :s AND :e
                  AND (chave_nfe IS NULL OR TRIM(chave_nfe) = '')
                ORDER BY valor_total DESC LIMIT 50
            ",
            [':s' => $start, ':e' => $end]
        );

        return [
            'icms_zero'        => $icmsZero,
            'canceladas'       => $canceladas,
            'sem_titulo'       => $semTitulo,
            'sem_ncm'          => $semNcm,
            'xml_pendente'     => $xmlPendente,
            'cbs_ibs_zero'     => $cbsIbsZero,
            'carga_acima_media'=> $cargaAcimaMedia,
            'cfop_incomum'     => $cfopIncomum,
            'pis_cofins_divergente' => $pisCofinsDivergente,
        ];
    }

    public function alertedInvoiceCount(string $start, string $end): int
    {
        $missingTitleCondition = $this->missingTitleCondition('n');
        $parts = [
            "SELECT company_id, nota_key FROM biomie_notas_fiscais_canonicas
             WHERE {$this->tenant} AND status_fiscal='cancelada' AND data_emissao BETWEEN :s1 AND :e1",
            "SELECT n.company_id, n.nota_key FROM biomie_notas_fiscais_canonicas n
             WHERE {$this->tenantN} AND n.status_fiscal='autorizada' AND n.data_emissao BETWEEN :s2 AND :e2
               AND (n.chave_nfe IS NULL OR TRIM(n.chave_nfe)='')",
            "SELECT n.company_id, n.nota_key FROM biomie_notas_fiscais_canonicas n
             WHERE {$this->tenantN} AND n.status_fiscal='autorizada' AND n.data_emissao BETWEEN :s3 AND :e3
               AND {$missingTitleCondition}",
        ];
        $params = [
            ':s1' => $start, ':e1' => $end,
            ':s2' => $start, ':e2' => $end,
            ':s3' => $start, ':e3' => $end,
        ];

        if ($this->martExists()) {
            $parts[] = "SELECT DISTINCT company_id, nota_key FROM mart_fiscal_items
                        WHERE {$this->tenant}
                          AND status_fiscal='autorizada' AND data_emissao BETWEEN :s4 AND :e4
                          AND (
                            ncm IS NULL OR TRIM(ncm)='' OR
                            (valor_icms=0 AND valor_total_item>500) OR
                            (YEAR(data_emissao)=2026 AND valor_total_item>0
                             AND COALESCE(valor_cbs,0)+COALESCE(valor_ibs,0)=0)
                          )";
            $params[':s4'] = $start;
            $params[':e4'] = $end;
        }

        $stmt = $this->pdo()->prepare(
            'SELECT COUNT(DISTINCT CONCAT(company_id, ":", nota_key))
             FROM (' . implode(' UNION ALL ', $parts) . ') alerts'
        );
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    // ── CFOP Distribution ───────────────────────────────────────────────

    public function cfopDistribution(string $start, string $end): array
    {
        if (!$this->martExists()) {
            return [];
        }
        $stmt = $this->pdo()->prepare("
            SELECT
                cfop,
                COUNT(DISTINCT CONCAT(company_id, ':', nota_key)) AS nfs,
                SUM(valor_total_item)    AS faturamento,
                SUM(valor_icms)          AS icms,
                SUM(valor_trib_total)    AS trib_total,
                ROUND(SUM(valor_trib_total) / NULLIF(SUM(valor_total_item), 0) * 100, 2) AS carga_pct
            FROM mart_fiscal_items
            WHERE {$this->tenant}
              AND status_fiscal='autorizada'
              AND data_emissao BETWEEN :s AND :e
            GROUP BY cfop
            ORDER BY faturamento DESC
            LIMIT 20
        ");
        $stmt->execute([':s' => $start, ':e' => $end]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
