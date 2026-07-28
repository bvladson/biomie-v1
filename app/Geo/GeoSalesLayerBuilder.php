<?php

declare(strict_types=1);

namespace BIomie\Geo;

use BIomie\Support\BiVisibilityScope;
use BIomie\Support\GeoLocationScope;
use BIomie\Support\TenantContext;
use PDO;
use Throwable;

/**
 * GEO.R1 — Builder de vendas geográficas por camada territorial.
 *
 * Lê exclusivamente da fonte oficial BIomie:
 *   - mart_compradores_por_geo (gold)
 *   - biomie_notas_fiscais_canonicas (canônico — para diagnóstico)
 *   - biomie_geo_municipios_ce (dimensão territorial)
 *
 * BiVisibilityScope aplicado sempre.
 * COALESCE(data_competencia, data_emissao) padrão.
 * Sem ViaCEP. Sem CSV. Sem tabela paralela de vendas.
 *
 * Escopos comerciais:
 *   - orc_pd    : Orç-PD (exclui Remessa e Licitação) — padrão
 *   - remessa   : Remessas
 *   - licitacao : Licitações
 *   - todas     : investigativa (inclui tudo)
 */
final class GeoSalesLayerBuilder
{
    private PDO $pdo;
    private GeoTerritoryResolver $resolver;
    private int $companyId;

    public function __construct(PDO $pdo, int $companyId)
    {
        $this->pdo       = $pdo;
        $this->companyId = $companyId;
        $this->resolver  = new GeoTerritoryResolver($pdo);
    }

    /**
     * Agrega vendas por território para a camada solicitada.
     *
     * @param array<string, mixed> $filters
     *   start, end, scope, vendor, product, city
     * @return array<string, array{
     *   territory_key: string, territory_label: string, layer: string,
     *   receita: float, nfs: int, clientes: int, ticket_medio: float,
     *   vendedores: int, top_vendedor: string, top_produto: string,
     *   ultima_nf: string|null, coverage_status: string
     * }>
     */
    public function buildLayer(string $layerId, array $filters = []): array
    {
        $start  = (string) ($filters['start'] ?? date('Y-m-01'));
        $end    = (string) ($filters['end']   ?? date('Y-m-d'));
        $scope  = (string) ($filters['scope'] ?? 'orc_pd');
        $vendor = isset($filters['vendor']) ? trim((string) $filters['vendor']) : '';
        $city   = isset($filters['city'])   ? trim((string) $filters['city'])   : '';

        $startComp = substr($start, 0, 7);
        $endComp   = substr($end,   0, 7);

        // BiVisibilityScope — filtra scope comercial
        $scopeFilter = $this->buildScopeFilter($scope);

        // Vendor filter
        $vendorSql = '';
        $params = [
            ':start_comp' => $startComp,
            ':end_comp'   => $endComp,
        ];
        if ($vendor !== '') {
            $vendorSql = ' AND LOWER(nome_vendedor) LIKE :vendor_pct';
            $params[':vendor_pct'] = '%' . mb_strtolower($vendor) . '%';
        }

        return match ($layerId) {
            'fortaleza_bairros'        => $this->buildFortalezaBairros($params, $scopeFilter, $vendorSql),
            'grande_fortaleza_bairros' => $this->buildGrandeFortaleza($params, $scopeFilter, $vendorSql),
            'rmf_municipios'           => $this->buildMunicipios($params, $scopeFilter, $vendorSql, rmfOnly: true),
            'ceara_municipios'         => $this->buildMunicipios($params, $scopeFilter, $vendorSql, rmfOnly: false),
            'ceara_regioes'            => $this->buildRegioes($params, $scopeFilter, $vendorSql),
            default                    => [],
        };
    }

    // ── Fortaleza bairros (camada original — comportamento preservado) ────────

    /** @return array<string, array<string, mixed>> */
    private function buildFortalezaBairros(array $params, string $scopeFilter, string $vendorSql): array
    {
        $sql = "
            SELECT
                COALESCE(NULLIF(bairro_norm,''), 'nao_informado')       AS territory_key,
                COALESCE(SUM(faturamento), 0)                          AS receita,
                COALESCE(SUM(qtd_notas), 0)                            AS nfs,
                COUNT(DISTINCT COALESCE(CAST(codigo_cliente_omie AS CHAR), documento, cliente_nome)) AS clientes,
                MAX(ultima_compra)                                      AS ultima_nf
            FROM mart_compradores_por_geo
            WHERE competencia BETWEEN :start_comp AND :end_comp
              AND {$this->companyFilter()}
              AND " . GeoLocationScope::cityPredicate('mart_compradores_por_geo', "'fortaleza'") . "
              {$scopeFilter}
              {$vendorSql}
            GROUP BY territory_key
            HAVING receita > 0
            ORDER BY receita DESC
        ";
        return $this->fetchAndMap($sql, $params, 'fortaleza_bairros', fn($row) => [
            'territory_key'   => (string) ($row['territory_key'] ?? 'nao_informado'),
            'territory_label' => $this->bairroDisplayName((string) ($row['territory_key'] ?? '')),
        ]);
    }

    // ── Grande Fortaleza — bairros onde disponível, município onde não ────────

    /** @return array<string, array<string, mixed>> */
    private function buildGrandeFortaleza(array $params, string $scopeFilter, string $vendorSql): array
    {
        $bairroLayerCodes = $this->resolver->listBairroLayerCodes();
        $codePlaceholders = implode(',', array_fill(0, count($bairroLayerCodes), '?'));

        // Usar bairro_norm para municípios com camada, cidade_norm para os demais
        $sql = "
            SELECT
                m.ibge_code,
                m.nome_norm AS cidade_norm_db,
                COALESCE(NULLIF(g.bairro_norm,''), '') AS bairro_norm,
                COALESCE(SUM(g.faturamento), 0)         AS receita,
                COALESCE(SUM(g.qtd_notas), 0)           AS nfs,
                COUNT(DISTINCT COALESCE(CAST(g.codigo_cliente_omie AS CHAR), g.documento, g.cliente_nome)) AS clientes,
                MAX(g.ultima_compra)                    AS ultima_nf
            FROM mart_compradores_por_geo g
            LEFT JOIN biomie_geo_municipios_ce m ON LOWER(g.cidade_norm) = m.nome_norm
            WHERE g.competencia BETWEEN :start_comp AND :end_comp
              AND {$this->companyFilter('g')}
              AND m.is_rmf = 1
              {$scopeFilter}
              {$vendorSql}
            GROUP BY m.ibge_code, g.bairro_norm
            HAVING receita > 0
            ORDER BY receita DESC
        ";

        // Fallback se tabela biomie_geo_municipios_ce não existe
        if (!$this->tableExists('biomie_geo_municipios_ce')) {
            return $this->buildFortalezaBairros($params, $scopeFilter, $vendorSql);
        }

        return $this->fetchAndMap($sql, $params, 'grande_fortaleza_bairros', function ($row) use ($bairroLayerCodes) {
            $ibge      = (string) ($row['ibge_code'] ?? '');
            $bairro    = (string) ($row['bairro_norm'] ?? '');
            $cidade    = (string) ($row['cidade_norm_db'] ?? '');

            if (in_array($ibge, $bairroLayerCodes, true) && $bairro !== '') {
                $key   = $ibge . '::' . $bairro;
                $label = $this->bairroDisplayName($bairro) . ' (' . $this->municipioLabel($ibge) . ')';
            } else {
                $key   = 'mun::' . $ibge;
                $label = $this->municipioLabel($ibge) . ' (sem bairro mapeado)';
            }
            return ['territory_key' => $key, 'territory_label' => $label];
        });
    }

    // ── Municípios (RMF ou Ceará inteiro) ────────────────────────────────────

    /** @return array<string, array<string, mixed>> */
    private function buildMunicipios(array $params, string $scopeFilter, string $vendorSql, bool $rmfOnly): array
    {
        $rmfFilter = $rmfOnly ? ' AND m.is_rmf = 1' : '';
        $joinTable = $this->tableExists('biomie_geo_municipios_ce')
            ? "LEFT JOIN biomie_geo_municipios_ce m ON LOWER(g.cidade_norm) = m.nome_norm"
            : "LEFT JOIN (SELECT '2304400' AS ibge_code, 'fortaleza' AS nome_norm, 1 AS is_rmf) m ON LOWER(g.cidade_norm) = m.nome_norm";

        $sql = "
            SELECT
                COALESCE(m.ibge_code, '0')                              AS territory_key,
                COALESCE(m.nome, g.cidade_norm, 'Não identificado')     AS territory_label,
                COALESCE(SUM(g.faturamento), 0)                         AS receita,
                COALESCE(SUM(g.qtd_notas), 0)                           AS nfs,
                COUNT(DISTINCT COALESCE(CAST(g.codigo_cliente_omie AS CHAR), g.documento, g.cliente_nome)) AS clientes,
                MAX(g.ultima_compra)                                     AS ultima_nf
            FROM mart_compradores_por_geo g
            {$joinTable}
            WHERE g.competencia BETWEEN :start_comp AND :end_comp
              AND {$this->companyFilter('g')}
              {$rmfFilter}
              {$scopeFilter}
              {$vendorSql}
            GROUP BY territory_key, territory_label
            HAVING receita > 0
            ORDER BY receita DESC
        ";

        return $this->fetchAndMap($sql, $params, $rmfOnly ? 'rmf_municipios' : 'ceara_municipios', fn($row) => [
            'territory_key'   => (string) ($row['territory_key']   ?? ''),
            'territory_label' => (string) ($row['territory_label'] ?? ''),
        ]);
    }

    // ── Regiões IPECE ─────────────────────────────────────────────────────────

    /** @return array<string, array<string, mixed>> */
    private function buildRegioes(array $params, string $scopeFilter, string $vendorSql): array
    {
        if (!$this->tableExists('biomie_geo_municipios_ce') || !$this->tableExists('biomie_geo_regioes_ce')) {
            return []; // camada indisponível sem tabelas
        }

        $sql = "
            SELECT
                COALESCE(CAST(m.regiao_planejamento_id AS CHAR), '0') AS territory_key,
                COALESCE(r.nome, 'Região não mapeada')                AS territory_label,
                COALESCE(SUM(g.faturamento), 0)                       AS receita,
                COALESCE(SUM(g.qtd_notas), 0)                         AS nfs,
                COUNT(DISTINCT COALESCE(CAST(g.codigo_cliente_omie AS CHAR), g.documento, g.cliente_nome)) AS clientes,
                MAX(g.ultima_compra)                                   AS ultima_nf
            FROM mart_compradores_por_geo g
            LEFT JOIN biomie_geo_municipios_ce m ON LOWER(g.cidade_norm) = m.nome_norm
            LEFT JOIN biomie_geo_regioes_ce r ON r.id = m.regiao_planejamento_id
            WHERE g.competencia BETWEEN :start_comp AND :end_comp
              AND {$this->companyFilter('g')}
              {$scopeFilter}
              {$vendorSql}
            GROUP BY territory_key, territory_label
            HAVING receita > 0
            ORDER BY receita DESC
        ";

        return $this->fetchAndMap($sql, $params, 'ceara_regioes', fn($row) => [
            'territory_key'   => (string) ($row['territory_key']   ?? ''),
            'territory_label' => (string) ($row['territory_label'] ?? ''),
        ]);
    }

    // ── Helpers internos ─────────────────────────────────────────────────────

    /**
     * @param callable(array<string,mixed>): array{territory_key:string,territory_label:string} $keyFn
     * @param array<string, string> $params
     * @return array<string, array<string, mixed>>
     */
    private function fetchAndMap(string $sql, array $params, string $layerId, callable $keyFn): array
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v);
            }
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }

        $totalReceita = array_sum(array_column($rows, 'receita'));
        $result = [];

        foreach ($rows as $row) {
            $keyData = $keyFn($row);
            $key     = $keyData['territory_key'];
            if ($key === '') continue;

            $receita = (float) ($row['receita'] ?? 0);
            $nfs     = (int) ($row['nfs']     ?? 0);
            $clientes = (int) ($row['clientes'] ?? 0);

            $result[$key] = [
                'territory_key'    => $key,
                'territory_label'  => $keyData['territory_label'],
                'layer'            => $layerId,
                'receita'          => round($receita, 2),
                'nfs'              => $nfs,
                'clientes'         => $clientes,
                'ticket_medio'     => $nfs > 0 ? round($receita / $nfs, 2) : 0.0,
                'vendedores'       => 0,
                'top_vendedor'     => '',
                'top_produto'      => '',
                'ultima_nf'        => ($row['ultima_nf'] ?? null) ?: null,
                'share_pct'        => $totalReceita > 0 ? round($receita / $totalReceita * 100, 1) : 0.0,
                'coverage_status'  => $receita > 0 ? 'com_venda' : 'sem_venda',
                'source'           => 'mart_compradores_por_geo',
            ];
        }

        return $result;
    }

    /**
     * Resumo executivo da camada (summary KPIs).
     *
     * @param array<string, array<string, mixed>> $layerData resultado de buildLayer()
     * @return array<string, mixed>
     */
    public function buildSummary(array $layerData): array
    {
        if (empty($layerData)) {
            return [
                'total_receita'       => 0.0,
                'total_nfs'           => 0,
                'total_clientes'      => 0,
                'total_territories'   => 0,
                'with_sales'          => 0,
                'top_territory'       => null,
                'top_territory_receita' => 0.0,
                'average_ticket'      => 0.0,
            ];
        }

        $totalReceita = 0.0;
        $totalNfs     = 0;
        $maxReceita   = 0.0;
        $topKey       = null;
        $allClientes  = [];

        foreach ($layerData as $key => $t) {
            $r = (float) ($t['receita'] ?? 0);
            $totalReceita += $r;
            $totalNfs     += (int) ($t['nfs'] ?? 0);
            if ($r > $maxReceita) { $maxReceita = $r; $topKey = $key; }
            $allClientes[] = (int) ($t['clientes'] ?? 0);
        }

        return [
            'total_receita'         => round($totalReceita, 2),
            'total_nfs'             => $totalNfs,
            'total_clientes'        => array_sum($allClientes),
            'total_territories'     => count($layerData),
            'with_sales'            => count(array_filter($layerData, fn($t) => ($t['receita'] ?? 0) > 0)),
            'top_territory'         => $topKey !== null ? $layerData[$topKey]['territory_label'] : null,
            'top_territory_receita' => round($maxReceita, 2),
            'average_ticket'        => $totalNfs > 0 ? round($totalReceita / $totalNfs, 2) : 0.0,
        ];
    }

    /**
     * Ranking ordenado dos territórios.
     *
     * @param array<string, array<string, mixed>> $layerData
     * @param string $metric 'receita'|'nfs'|'clientes'|'ticket_medio'
     * @param int $limit
     * @return list<array<string, mixed>>
     */
    public function buildRanking(array $layerData, string $metric = 'receita', int $limit = 50): array
    {
        $valid = ['receita', 'nfs', 'clientes', 'ticket_medio'];
        if (!in_array($metric, $valid, true)) $metric = 'receita';

        $list = array_values($layerData);
        usort($list, fn($a, $b) => (float)($b[$metric] ?? 0) <=> (float)($a[$metric] ?? 0));
        return array_slice($list, 0, $limit);
    }

    /**
     * Calcula variação percentual (Δ) entre período atual e anterior por território.
     *
     * @param array<string, array<string, mixed>> $current
     * @param array<string, array<string, mixed>> $previous
     * @return array<string, array{trend_receita_pct: float, trend_nfs_pct: float, trend_clientes_pct: float, trend_dir: string}>
     */
    public static function buildTrends(array $current, array $previous): array
    {
        $trends = [];
        foreach ($current as $key => $cur) {
            $prev = $previous[$key] ?? null;
            $prevReceita  = $prev !== null ? (float) ($prev['receita'] ?? 0) : null;
            $prevNfs      = $prev !== null ? (int)   ($prev['nfs']    ?? 0) : null;
            $prevClientes = $prev !== null ? (int)   ($prev['clientes'] ?? 0) : null;

            $curReceita  = (float) ($cur['receita']  ?? 0);
            $curNfs      = (int)   ($cur['nfs']      ?? 0);
            $curClientes = (int)   ($cur['clientes'] ?? 0);

            $pctReceita = self::pct($curReceita, $prevReceita);
            $trends[$key] = [
                'trend_receita_pct'  => $pctReceita,
                'trend_nfs_pct'      => self::pct((float) $curNfs,      $prevNfs      !== null ? (float) $prevNfs      : null),
                'trend_clientes_pct' => self::pct((float) $curClientes, $prevClientes !== null ? (float) $prevClientes : null),
                'trend_dir'          => $pctReceita === null ? 'new' : ($pctReceita >= 0 ? 'up' : 'down'),
                'prev_receita'       => $prevReceita,
                'is_new_territory'   => $prev === null,
            ];
        }
        return $trends;
    }

    /**
     * Variação percentual no summary (KPIs executivos).
     *
     * @param array<string, mixed> $current
     * @param array<string, mixed> $previous
     * @return array<string, float|null>
     */
    public static function buildSummaryTrend(array $current, array $previous): array
    {
        return [
            'total_receita_pct'    => self::pct((float) ($current['total_receita']  ?? 0), (float) ($previous['total_receita']  ?? 0) ?: null),
            'total_nfs_pct'        => self::pct((float) ($current['total_nfs']      ?? 0), (float) ($previous['total_nfs']      ?? 0) ?: null),
            'total_clientes_pct'   => self::pct((float) ($current['total_clientes'] ?? 0), (float) ($previous['total_clientes'] ?? 0) ?: null),
            'average_ticket_pct'   => self::pct((float) ($current['average_ticket'] ?? 0), (float) ($previous['average_ticket'] ?? 0) ?: null),
        ];
    }

    /**
     * Cobertura de vendedores por território.
     *
     * @param array<string, string> $params [:start_comp, :end_comp]
     * @param string $scopeFilter SQL scope clause
     * @param string $layerId
     * @return array<string, array{
     *   territory_key: string,
     *   vendors: list<array{vendor_name: string, receita: float, share_pct: float}>,
     *   dominant_vendor: string,
     *   vendor_count: int
     * }>
     */
    public function buildVendorCoverage(string $layerId, array $filters = []): array
    {
        $start  = (string) ($filters['start'] ?? date('Y-m-01'));
        $end    = (string) ($filters['end']   ?? date('Y-m-d'));

        $startComp = substr($start, 0, 7);
        $endComp   = substr($end,   0, 7);
        $params    = [':start_comp' => $startComp, ':end_comp' => $endComp];

        $hasNomeVendedor = $this->columnExists('mart_compradores_por_geo', 'nome_vendedor');
        if (!$hasNomeVendedor) return [];

        $groupExpr = $this->territoryGroupExpr($layerId);
        if ($groupExpr === '') return [];

        $sql = "
            SELECT
                {$groupExpr}                                         AS territory_key,
                COALESCE(NULLIF(TRIM(nome_vendedor),''), 'Sem vendedor') AS vendor_name,
                COALESCE(SUM(faturamento), 0)                        AS receita,
                COALESCE(SUM(qtd_notas), 0)                          AS nfs
            FROM mart_compradores_por_geo
            WHERE competencia BETWEEN :start_comp AND :end_comp
              AND {$this->companyFilter()}
              {$this->buildScopeFilter('orc_pd')}
              AND faturamento > 0
            GROUP BY {$groupExpr}, COALESCE(NULLIF(TRIM(nome_vendedor),''), 'Sem vendedor')
            ORDER BY territory_key ASC, receita DESC
        ";

        try {
            $stmt = $this->pdo->prepare($sql);
            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v);
            }
            $stmt->execute();
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
            return [];
        }

        // Group by territory
        $byTerritory = [];
        foreach ($rows as $row) {
            $tKey = (string) ($row['territory_key'] ?? '');
            if ($tKey === '') continue;
            $byTerritory[$tKey][] = [
                'vendor_name' => (string) ($row['vendor_name'] ?? 'Sem vendedor'),
                'receita'     => (float)  ($row['receita']     ?? 0),
                'nfs'         => (int)    ($row['nfs']         ?? 0),
            ];
        }

        $result = [];
        foreach ($byTerritory as $tKey => $vendors) {
            $totalReceita = array_sum(array_column($vendors, 'receita'));
            $vendorsWithShare = array_map(function ($v) use ($totalReceita) {
                return [
                    'vendor_name' => $v['vendor_name'],
                    'receita'     => round($v['receita'], 2),
                    'nfs'         => $v['nfs'],
                    'share_pct'   => $totalReceita > 0 ? round($v['receita'] / $totalReceita * 100, 1) : 0.0,
                ];
            }, $vendors);

            $result[$tKey] = [
                'territory_key'   => $tKey,
                'vendors'         => $vendorsWithShare,
                'dominant_vendor' => $vendorsWithShare[0]['vendor_name'] ?? 'Sem vendedor',
                'vendor_count'    => count($vendorsWithShare),
                'total_receita'   => round($totalReceita, 2),
            ];
        }
        return $result;
    }

    private function columnExists(string $table, string $column): bool
    {
        static $colCache = [];
        $key = $table . '.' . $column;
        if (isset($colCache[$key])) return $colCache[$key];
        try {
            $stmt = $this->pdo->prepare(
                "SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1"
            );
            $stmt->execute([$table, $column]);
            return $colCache[$key] = (bool) $stmt->fetchColumn();
        } catch (\Throwable) {
            return $colCache[$key] = false;
        }
    }

    private function companyFilter(string $alias = ''): string
    {
        $prefix = $alias !== '' ? rtrim($alias, '.') . '.' : '';
        if ($this->companyId > 0) {
            return "{$prefix}company_id = {$this->companyId}";
        }

        return TenantContext::sqlFilter($alias);
    }

    private function territoryGroupExpr(string $layerId): string
    {
        return match ($layerId) {
            'fortaleza_bairros'        => "COALESCE(NULLIF(bairro_norm,''), 'nao_informado')",
            'grande_fortaleza_bairros' => "COALESCE(NULLIF(bairro_norm,''), 'nao_informado')",
            'rmf_municipios'           => "LOWER(TRIM(COALESCE(NULLIF(cidade_norm,''), 'sem_cidade')))",
            'ceara_municipios'         => "LOWER(TRIM(COALESCE(NULLIF(cidade_norm,''), 'sem_cidade')))",
            'ceara_regioes'            => "LOWER(TRIM(COALESCE(NULLIF(cidade_norm,''), 'sem_cidade')))",
            default                    => '',
        };
    }

    private static function pct(float $current, ?float $previous): ?float
    {
        if ($previous === null || $previous == 0.0) return null;
        return round(($current - $previous) / abs($previous) * 100, 1);
    }

    /**
     * Filtra scope comercial — aplica BiVisibilityScope na query do mart.
     * O mart_compradores_por_geo já foi filtrado por scope no momento da materialização,
     * mas se houver coluna scope_tipo, filtra aqui também.
     */
    private function buildScopeFilter(string $scope): string
    {
        // A request-level scope must never broaden the active tenant channel.
        // Licitacao reads Licitacao; Varejo and consolidated read Varejo.
        unset($scope);
        $condition = BiVisibilityScope::getCondition(
            $this->pdo,
            'mart_compradores_por_geo'
        );

        return $condition !== '' ? ' AND ' . $condition : ' AND 1 = 0';
    }

    private function bairroDisplayName(string $bairroNorm): string
    {
        if ($bairroNorm === '' || $bairroNorm === 'nao_informado') return 'Não informado';
        return ucwords(str_replace(['_', '-'], [' ', '-'], $bairroNorm));
    }

    private function municipioLabel(string $ibgeCode): string
    {
        if (!$this->tableExists('biomie_geo_municipios_ce')) return $ibgeCode;
        static $cache = [];
        if (isset($cache[$ibgeCode])) return $cache[$ibgeCode];
        try {
            $stmt = $this->pdo->prepare("SELECT nome FROM biomie_geo_municipios_ce WHERE ibge_code = :c LIMIT 1");
            $stmt->execute([':c' => $ibgeCode]);
            $nome = $stmt->fetchColumn();
            return $cache[$ibgeCode] = $nome ? (string) $nome : $ibgeCode;
        } catch (\Throwable) {
            return $cache[$ibgeCode] = $ibgeCode;
        }
    }

    private function tableExists(string $table): bool
    {
        static $cache = [];
        if (isset($cache[$table])) return $cache[$table];
        try {
            $stmt = $this->pdo->prepare(
                "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1"
            );
            $stmt->execute([$table]);
            return $cache[$table] = (bool) $stmt->fetchColumn();
        } catch (\Throwable) {
            return $cache[$table] = false;
        }
    }
}
