<?php

declare(strict_types=1);

namespace BIomie\Geo;

use BIomie\Support\CommercialMartScope;
use PDO;
use Throwable;

/**
 * GEO.R2.2 — Alertas comerciais automáticos por território.
 *
 * Detecta:
 *   - QUEDA: receita caiu > 20% vs período equivalente anterior
 *   - DORMENTES: clientes com compra no período mas sem nova compra há 60d+
 *   - OPORTUNIDADE_CNAE: CNAE com múltiplas empresas na área, mas apenas 1–2 ativas
 *   - NOVO_TERRITORIO: primeiro registro de venda neste território
 */
final class GeoTerritoryAlertsService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Gera alertas para um território.
     *
     * @param array<string, string> $territory  ['territory_key', 'territory_type', 'cidade_key', 'bairro_key', ...]
     * @param string $start  YYYY-MM-DD
     * @param string $end    YYYY-MM-DD
     * @return array{alerts: list<array<string,mixed>>, alert_count: int, has_critical: bool}
     */
    public function getAlerts(array $territory, string $start, string $end): array
    {
        $alerts = [];

        // ── 1. Queda de faturamento ──────────────────────────────────────────
        $queda = $this->checkQueda($territory, $start, $end);
        if ($queda !== null) $alerts[] = $queda;

        // ── 2. Clientes dormentes ────────────────────────────────────────────
        $dormentes = $this->checkDormentes($territory, $start, $end);
        if ($dormentes !== null) $alerts[] = $dormentes;

        // ── 3. Oportunidade CNAE (concentração) ─────────────────────────────
        $oportunidadesCnae = $this->checkOportunidadeCnae($territory, $start, $end);
        foreach ($oportunidadesCnae as $op) {
            $alerts[] = $op;
        }

        // ── 4. Território novo (primeira venda em 6+ meses) ──────────────────
        $novo = $this->checkNovoTerritorio($territory, $start, $end);
        if ($novo !== null) $alerts[] = $novo;

        $hasCritical = !empty(array_filter($alerts, fn($a) => ($a['severity'] ?? '') === 'critical'));

        return [
            'alerts'       => $alerts,
            'alert_count'  => count($alerts),
            'has_critical' => $hasCritical,
        ];
    }

    // ── Helpers privados ─────────────────────────────────────────────────────

    /** @param array<string, string> $territory */
    private function checkQueda(array $territory, string $start, string $end): ?array
    {
        [$whereCur, $params] = $this->buildWhere($territory, $start, $end, 'cur');
        if ($whereCur === '') return null;

        // Período anterior de mesma duração
        $startTs  = strtotime($start);
        $endTs    = strtotime($end);
        $duration = max(1, $endTs - $startTs);
        $prevEnd  = date('Y-m-d', $startTs - 86400);
        $prevStart = date('Y-m-d', strtotime($prevEnd) - $duration);

        [$wherePrev, $prevParams] = $this->buildWhere($territory, $prevStart, $prevEnd, 'prv');

        if ($wherePrev === '') return null;

        try {
            $sql = "
                SELECT
                    (SELECT COALESCE(SUM(faturamento),0) FROM mart_compradores_por_geo WHERE {$whereCur}) AS cur_receita,
                    (SELECT COALESCE(SUM(faturamento),0) FROM mart_compradores_por_geo WHERE {$wherePrev}) AS prv_receita
            ";
            $stmt = $this->pdo->prepare($sql);
            foreach (array_merge($params, $prevParams) as $k => $v) {
                $stmt->bindValue(':' . ltrim($k, ':'), $v);
            }
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return null;
        }

        $cur = (float) ($row['cur_receita'] ?? 0);
        $prv = (float) ($row['prv_receita'] ?? 0);
        if ($prv <= 0 || $cur >= $prv) return null;

        $quedaPct = round(($prv - $cur) / $prv * 100, 1);
        if ($quedaPct < 15.0) return null; // ruído

        $severity = $quedaPct >= 30.0 ? 'critical' : 'warning';

        return [
            'type'     => 'queda',
            'severity' => $severity,
            'title'    => "Queda de {$quedaPct}% vs período anterior",
            'message'  => sprintf(
                'Faturamento caiu de %s para %s (-%s%%).',
                $this->formatMoney($prv),
                $this->formatMoney($cur),
                $quedaPct
            ),
            'data' => [
                'queda_pct'   => $quedaPct,
                'cur_receita' => $cur,
                'prv_receita' => $prv,
                'prev_start'  => $prevStart,
                'prev_end'    => $prevEnd,
            ],
        ];
    }

    /** @param array<string, string> $territory */
    private function checkDormentes(array $territory, string $start, string $end): ?array
    {
        [$where, $params] = $this->buildWhere($territory, $start, $end, 'dm');
        if ($where === '') return null;

        $dormantThreshold = date('Y-m-d', strtotime($end . ' -60 days'));

        try {
            $sql = "
                SELECT
                    MAX(m.cliente_nome) AS cliente_nome,
                    MAX(m.codigo_cliente_omie) AS codigo_cliente_omie,
                    COALESCE(SUM(m.faturamento), 0) AS faturamento_periodo,
                    MAX(m.ultima_compra) AS ultima_compra
                FROM mart_compradores_por_geo m
                WHERE {$where}
                  AND m.ultima_compra IS NOT NULL
                  AND m.ultima_compra < :dormant_threshold
                GROUP BY COALESCE(CAST(m.codigo_cliente_omie AS CHAR), m.documento, m.cliente_nome)
                ORDER BY faturamento_periodo DESC
                LIMIT 5
            ";
            $stmt = $this->pdo->prepare($sql);
            foreach ($params as $k => $v) {
                $stmt->bindValue(':' . ltrim($k, ':'), $v);
            }
            $stmt->bindValue(':dormant_threshold', $dormantThreshold);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return null;
        }

        if (empty($rows)) return null;

        $totalDormantRevenue = array_sum(array_column($rows, 'faturamento_periodo'));
        $clients = array_map(fn($r) => [
            'nome'       => (string) ($r['cliente_nome'] ?? ''),
            'faturamento' => (float) ($r['faturamento_periodo'] ?? 0),
            'ultima_compra' => (string) ($r['ultima_compra'] ?? ''),
        ], $rows);

        return [
            'type'     => 'dormentes',
            'severity' => 'warning',
            'title'    => count($rows) . ' cliente(s) sem compra há 60+ dias',
            'message'  => sprintf(
                '%d cliente(s) compraram neste período mas não reaparecem há mais de 60 dias. Potencial de reativação: %s.',
                count($rows),
                $this->formatMoney($totalDormantRevenue)
            ),
            'data' => [
                'dormant_count'   => count($rows),
                'total_revenue'   => $totalDormantRevenue,
                'clients'         => $clients,
                'dormant_since'   => $dormantThreshold,
            ],
        ];
    }

    /**
     * @param array<string, string> $territory
     * @return list<array<string,mixed>>
     */
    private function checkOportunidadeCnae(array $territory, string $start, string $end): array
    {
        [$where, $params] = $this->buildWhere($territory, $start, $end, 'op');
        if ($where === '') return [];

        try {
            $sql = "
                SELECT
                    COALESCE(NULLIF(TRIM(m.cnae_codigo),''), 'SEM_CNAE') AS cnae_codigo,
                    COALESCE(NULLIF(TRIM(m.cnae_descricao),''), 'Sem descrição') AS cnae_descricao,
                    COUNT(DISTINCT COALESCE(CAST(m.codigo_cliente_omie AS CHAR), m.documento, m.cliente_nome)) AS clientes_ativos,
                    COALESCE(SUM(m.faturamento), 0) AS faturamento
                FROM mart_compradores_por_geo m
                WHERE {$where}
                  AND m.cnae_codigo IS NOT NULL AND TRIM(m.cnae_codigo) <> ''
                GROUP BY COALESCE(NULLIF(TRIM(m.cnae_codigo),''), 'SEM_CNAE'), COALESCE(NULLIF(TRIM(m.cnae_descricao),''), 'Sem descrição')
                HAVING clientes_ativos <= 2
                ORDER BY faturamento DESC
                LIMIT 3
            ";
            $stmt = $this->pdo->prepare($sql);
            foreach ($params as $k => $v) {
                $stmt->bindValue(':' . ltrim($k, ':'), $v);
            }
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }

        $alerts = [];
        foreach ($rows as $row) {
            $desc    = (string) ($row['cnae_descricao'] ?? 'Segmento');
            $clientes = (int) ($row['clientes_ativos'] ?? 0);
            $fat     = (float) ($row['faturamento'] ?? 0);

            $alerts[] = [
                'type'     => 'oportunidade_cnae',
                'severity' => 'info',
                'title'    => "Oportunidade: {$desc}",
                'message'  => sprintf(
                    'Apenas %d cliente(s) ativos neste segmento. Potencial de expansão no território.',
                    $clientes
                ),
                'data' => [
                    'cnae_codigo'    => (string) ($row['cnae_codigo'] ?? ''),
                    'cnae_descricao' => $desc,
                    'clientes_ativos' => $clientes,
                    'faturamento'    => $fat,
                ],
            ];
        }
        return $alerts;
    }

    /** @param array<string, string> $territory */
    private function checkNovoTerritorio(array $territory, string $start, string $end): ?array
    {
        [$where, $params] = $this->buildWhere($territory, $start, $end, 'nv');
        if ($where === '') return null;

        // Verificar se havia venda nos 6 meses anteriores
        $sixMonthsAgo = date('Y-m', strtotime($start . ' -6 months'));
        $startComp    = substr($start, 0, 7);

        [$wherePast, $pastParams] = $this->buildWherePast($territory, $sixMonthsAgo, $startComp, 'np');
        if ($wherePast === '') return null;

        try {
            $stmt = $this->pdo->prepare(
                "SELECT COALESCE(SUM(faturamento),0) AS fat FROM mart_compradores_por_geo WHERE {$wherePast}"
            );
            foreach ($pastParams as $k => $v) {
                $stmt->bindValue(':' . ltrim($k, ':'), $v);
            }
            $stmt->execute();
            $pastFat = (float) ($stmt->fetchColumn() ?: 0);
        } catch (Throwable) {
            return null;
        }

        if ($pastFat > 0) return null; // não é novo

        try {
            $stmt = $this->pdo->prepare(
                "SELECT COALESCE(SUM(faturamento),0) AS fat FROM mart_compradores_por_geo WHERE {$where}"
            );
            foreach ($params as $k => $v) {
                $stmt->bindValue(':' . ltrim($k, ':'), $v);
            }
            $stmt->execute();
            $curFat = (float) ($stmt->fetchColumn() ?: 0);
        } catch (Throwable) {
            return null;
        }

        if ($curFat <= 0) return null;

        return [
            'type'     => 'novo_territorio',
            'severity' => 'success',
            'title'    => 'Território com venda inédita',
            'message'  => sprintf(
                'Primeira venda registrada neste território nos últimos 6 meses: %s.',
                $this->formatMoney($curFat)
            ),
            'data' => ['faturamento' => $curFat],
        ];
    }

    /**
     * @param array<string, string> $territory
     * @return array{0: string, 1: array<string, string>}
     */
    private function buildWhere(array $territory, string $start, string $end, string $prefix): array
    {
        $startComp = substr($start, 0, 7);
        $endComp   = substr($end,   0, 7);
        $params    = [
            "{$prefix}_s" => $startComp,
            "{$prefix}_e" => $endComp,
        ];
        $where = [
            CommercialMartScope::condition($this->pdo, 'mart_compradores_por_geo'),
            "competencia BETWEEN :{$prefix}_s AND :{$prefix}_e",
        ];

        $type     = trim((string) ($territory['territory_type'] ?? ''));
        $cidadeKey = strtolower(trim((string) ($territory['cidade_key'] ?? '')));
        $bairroKey = strtolower(trim((string) ($territory['bairro_key'] ?? '')));

        if ($type === 'sem_geo') {
            $where[] = "(cidade_norm IS NULL OR TRIM(cidade_norm) = '')";
        } elseif ($type === 'sem_bairro') {
            $where[] = "cidade_norm IS NOT NULL AND TRIM(cidade_norm) <> ''";
            $where[] = "(bairro_norm IS NULL OR TRIM(bairro_norm) = '')";
            if ($cidadeKey !== '') {
                $where[] = "LOWER(TRIM(cidade_norm)) = :{$prefix}_cidade";
                $params["{$prefix}_cidade"] = $cidadeKey;
            }
        } elseif (in_array($type, ['fortaleza_bairro', 'metro_bairro'], true) && $bairroKey !== '') {
            $where[] = "LOWER(TRIM(cidade_norm)) = :{$prefix}_cidade";
            $where[] = "LOWER(TRIM(bairro_norm)) = :{$prefix}_bairro";
            $params["{$prefix}_cidade"] = $cidadeKey !== '' ? $cidadeKey : 'fortaleza';
            $params["{$prefix}_bairro"] = $bairroKey;
        } elseif ($cidadeKey !== '') {
            $where[] = "LOWER(TRIM(cidade_norm)) = :{$prefix}_cidade";
            $params["{$prefix}_cidade"] = $cidadeKey;
        } else {
            return ['', []];
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * @param array<string, string> $territory
     * @return array{0: string, 1: array<string, string>}
     */
    private function buildWherePast(array $territory, string $fromComp, string $toComp, string $prefix): array
    {
        [$where, $params] = $this->buildWhere($territory, $fromComp . '-01', $toComp . '-28', $prefix);
        // Substituir parâmetros de competência pelo range passado
        unset($params["{$prefix}_s"], $params["{$prefix}_e"]);
        $params["{$prefix}_s"] = $fromComp;
        $params["{$prefix}_e"] = $toComp;
        return [$where, $params];
    }

    private function formatMoney(float $value): string
    {
        return 'R$ ' . number_format($value, 2, ',', '.');
    }
}
