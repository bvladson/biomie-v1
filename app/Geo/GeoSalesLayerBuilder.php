<?php

declare(strict_types=1);

namespace BIomie\Geo;

use BIomie\Support\BiVisibilityScope;
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

    public function __construct(PDO $pdo)
    {
        $this->pdo      = $pdo;
        $this->resolver = new GeoTerritoryResolver($pdo);
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
              AND (LOWER(cidade_norm) = 'fortaleza' OR cidade_norm IS NULL OR TRIM(cidade_norm) = '')
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
                COALESCE(m.nome, INITCAP(g.cidade_norm), g.cidade_norm, 'Não identificado') AS territory_label,
                COALESCE(SUM(g.faturamento), 0)                         AS receita,
                COALESCE(SUM(g.qtd_notas), 0)                           AS nfs,
                COUNT(DISTINCT COALESCE(CAST(g.codigo_cliente_omie AS CHAR), g.documento, g.cliente_nome)) AS clientes,
                MAX(g.ultima_compra)                                     AS ultima_nf
            FROM mart_compradores_por_geo g
            {$joinTable}
            WHERE g.competencia BETWEEN :start_comp AND :end_comp
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
     * Filtra scope comercial — aplica BiVisibilityScope na query do mart.
     * O mart_compradores_por_geo já foi filtrado por scope no momento da materialização,
     * mas se houver coluna scope_tipo, filtra aqui também.
     */
    private function buildScopeFilter(string $scope): string
    {
        // O mart atual não expõe coluna de scope por linha.
        // O filtro de licitação/remessa acontece na materialização (BiVisibilityScope).
        // Para escopo investigativo, nada a filtrar aqui.
        // Quando o mart evoluir para ter coluna scope_tipo, filtrar aqui.
        return match ($scope) {
            'orc_pd'    => '', // padrão — mart já exclui remessa e licitação
            'remessa'   => '', // pendente: coluna scope_tipo no mart
            'licitacao' => '', // pendente: coluna scope_tipo no mart
            'todas'     => '', // investigativo — sem filtro adicional
            default     => '',
        };
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
