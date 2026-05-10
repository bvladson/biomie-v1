<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Database;
use BIomie\Http;
use BIomie\Intelligence\OpportunityRepository;
use BIomie\Support;

require __DIR__ . '/bootstrap.php';

Auth::requireModule('intelligence', true);

// ── Parâmetros ───────────────────────────────────────────────────────────────

$competencia = trim((string) ($_GET['competencia'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-\d{2}$/', $competencia)) {
    $competencia = date('Y-m');
}

// focus=all|clients|vendors|geo|alerts (padrão: all)
$focus = trim((string) ($_GET['focus'] ?? 'all'));
$focus = in_array($focus, ['all', 'clients', 'vendors', 'geo', 'alerts'], true)
    ? $focus : 'all';

$limit = min(50, max(1, (int) ($_GET['limit'] ?? 20)));

// ── Conexão e repositório ────────────────────────────────────────────────────

try {
    $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo        = Database::connect($config, $connection);
    $repo       = new OpportunityRepository($pdo);
    $available  = $repo->isAvailable();
    $hasData    = $available && $repo->hasDataForCompetencia($competencia);
} catch (Throwable $e) {
    $logger->warning('intelligence.php: falha ao conectar.', ['message' => $e->getMessage()]);
    Http::json([
        'ok'          => false,
        'error'       => 'intelligence_unavailable',
        'message'     => 'Inteligência comercial indisponível. Execute bin/compute_opportunities.php.',
        'competencia' => $competencia,
    ]);
}

// ── Helpers de formatação ────────────────────────────────────────────────────

$formatRow = static function (array $row): array {
    return [
        'tipo_entidade'      => $row['tipo_entidade'] ?? '',
        'tipo_oportunidade'  => $row['tipo_oportunidade'] ?? '',
        'codigo_entidade'    => $row['codigo_entidade'] ?? '',
        'nome'               => $row['nome_entidade'] ?? '',
        'score'              => (int) ($row['score'] ?? 0),
        'tipo_pessoa'        => $row['tipo_pessoa'] ?? null,
        'segmento'           => $row['segmento_cnae'] ?? null,
        'uf'                 => $row['uf'] ?? null,
        'vendedor_id'        => $row['codigo_vendedor_omie'] ?? null,
        'vendedor_nome'      => $row['nome_vendedor'] ?? null,
        'valor_referencia'   => Support::money((float) ($row['valor_referencia'] ?? 0)),
        'valor_comparativo'  => Support::money((float) ($row['valor_comparativo'] ?? 0)),
        'variacao_pct'       => (float) ($row['variacao_pct'] ?? 0),
        'dias_sem_compra'    => isset($row['dias_sem_compra']) ? (int) $row['dias_sem_compra'] : null,
        'clientes_inativos'  => isset($row['clientes_count']) ? (int) $row['clientes_count'] : null,
        'insight'            => $row['insight_resumo'] ?? '',
        'contexto'           => isset($row['contexto_json'])
            ? (is_string($row['contexto_json']) ? json_decode($row['contexto_json'], true) : $row['contexto_json'])
            : null,
        'detectado_em'       => $row['refreshed_at'] ?? null,
    ];
};

// ── Montagem do payload ──────────────────────────────────────────────────────

if (!$hasData) {
    Http::json([
        'ok'          => true,
        'competencia' => $competencia,
        'status'      => $available ? 'sem_dados' : 'mart_indisponivel',
        'message'     => $available
            ? "Nenhuma oportunidade detectada para {$competencia}. Execute bin/compute_opportunities.php."
            : 'Tabela mart_oportunidades não existe. Execute a migration Version20260417170000.',
        'summary'            => [],
        'top_opportunities'  => [],
        'dormant_clients'    => [],
        'declining_clients'  => [],
        'concentration_alerts' => [],
        'vendor_portfolio'   => [],
        'geo_movements'      => [],
        'meta' => [
            'generated_at' => date(DATE_ATOM),
            'competencia'  => $competencia,
            'focus'        => $focus,
        ],
    ]);
}

$summary = $repo->summary($competencia);

$payload = [
    'ok'          => true,
    'competencia' => $competencia,
    'status'      => 'pronto',
    'summary' => [
        'total_oportunidades'        => (int) ($summary['total'] ?? 0),
        'alta_prioridade'            => (int) ($summary['alta_prioridade'] ?? 0),
        'clientes_dormentes'         => (int) ($summary['clientes_dormentes'] ?? 0),
        'queda_de_compra'            => (int) ($summary['queda_de_compra'] ?? 0),
        'alertas_concentracao'       => (int) ($summary['alertas_concentracao'] ?? 0),
        'vendedores_com_problema'    => (int) ($summary['vendedores_com_problema'] ?? 0),
        'regioes_em_crescimento'     => (int) ($summary['regioes_em_crescimento'] ?? 0),
        'regioes_em_retracao'        => (int) ($summary['regioes_em_retracao'] ?? 0),
        'ultima_deteccao'            => $summary['ultima_deteccao'] ?? null,
    ],
];

// top N independente de tipo — sempre incluído
$payload['top_opportunities'] = array_map(
    $formatRow,
    $repo->topOpportunities($competencia, min($limit, 15))
);

// Seções condicionais por focus
$includeAll     = $focus === 'all';

if ($includeAll || $focus === 'clients') {
    $payload['dormant_clients']  = array_map($formatRow, $repo->dormantClients($competencia, $limit));
    $payload['declining_clients'] = array_map($formatRow, $repo->decliningClients($competencia, $limit));
}

if ($includeAll || $focus === 'alerts') {
    $payload['concentration_alerts'] = array_map($formatRow, $repo->concentrationAlerts($competencia));
}

if ($includeAll || $focus === 'vendors') {
    $payload['vendor_portfolio'] = array_map($formatRow, $repo->underutilizedVendors($competencia));
}

if ($includeAll || $focus === 'geo') {
    $geoRows = $repo->geoMovements($competencia);
    $payload['geo_movements'] = [
        'crescimento' => array_map($formatRow, array_filter(
            $geoRows,
            static fn (array $r): bool => ($r['tipo_oportunidade'] ?? '') === 'crescimento_geo'
        )),
        'retracao' => array_map($formatRow, array_filter(
            $geoRows,
            static fn (array $r): bool => ($r['tipo_oportunidade'] ?? '') === 'retracao_geo'
        )),
    ];
}

$payload['meta'] = [
    'generated_at' => date(DATE_ATOM),
    'competencia'  => $competencia,
    'focus'        => $focus,
    'served_from'  => 'mart_oportunidades',
    'thresholds'   => [
        'dormant_min_value_brl'   => 500,
        'recompra_min_value_brl'  => 2000,
        'decline_threshold_pct'   => 20,
        'concentration_pct'       => 25,
        'vendor_inactive_pct'     => 35,
        'geo_movement_pct'        => 15,
    ],
];

Http::json($payload);
