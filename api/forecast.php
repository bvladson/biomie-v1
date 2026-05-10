<?php

/**
 * Camada preditiva — Fase 8.
 *
 * Retorna projeções de faturamento para o mês indicado com confiança declarada.
 * Todos os métodos são transparentes: linear simples ou proporção de dias.
 *
 * Parâmetros GET:
 *   competencia  YYYY-MM  (padrão: mês corrente)
 *   focus        all | mes_corrente | vendedor | regiao | mix_categoria | carteira | risco
 *                (padrão: all)
 *
 * Execute bin/compute_forecasts.php antes de consultar este endpoint.
 */

declare(strict_types=1);

use BIomie\Database;
use BIomie\Http;
use BIomie\Predictive\ForecastRepository;
use BIomie\Support;

require __DIR__ . '/bootstrap.php';
\BIomie\Auth::requireCapability('sales.view', true);

// ── Parâmetros ───────────────────────────────────────────────────────────────

$competencia = trim((string) ($_GET['competencia'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-\d{2}$/', $competencia)) {
    $competencia = date('Y-m');
}

$focus = trim((string) ($_GET['focus'] ?? 'all'));
$focus = in_array($focus, ['all', 'mes_corrente', 'vendedor', 'regiao', 'mix_categoria', 'carteira', 'risco'], true)
    ? $focus : 'all';

// ── Conexão e repositório ────────────────────────────────────────────────────

try {
    $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo        = Database::connect($config, $connection);
    $repo       = new ForecastRepository($pdo);
    $available  = $repo->isAvailable();
    $hasData    = $available && $repo->hasDataForCompetencia($competencia);
} catch (Throwable $e) {
    $logger->warning('forecast.php: falha ao conectar.', ['message' => $e->getMessage()]);
    Http::json([
        'ok'          => false,
        'error'       => 'forecast_unavailable',
        'message'     => 'Camada preditiva indisponível. Execute bin/compute_forecasts.php.',
        'competencia' => $competencia,
    ]);
}

// ── Helpers de formatação ────────────────────────────────────────────────────

$formatRow = static function (array $row): array {
    $meta  = (float) ($row['valor_meta']      ?? 0);
    $proj  = (float) ($row['valor_projetado'] ?? 0);
    $real  = (float) ($row['valor_realizado'] ?? 0);

    return [
        'tipo'            => $row['tipo_forecast']          ?? '',
        'codigo'          => $row['codigo_entidade']        ?? '',
        'nome'            => $row['nome_entidade']          ?? '',
        'realizado'       => Support::money($real),
        'projetado'       => Support::money($proj),
        'meta'            => $meta > 0 ? Support::money($meta) : null,
        'confianca_pct'   => (int)   ($row['confianca_pct']   ?? 0),
        'metodo'          => $row['metodo']                 ?? '',
        'dias_base'       => (int)   ($row['dias_base']       ?? 0),
        'dias_restantes'  => (int)   ($row['dias_restantes']  ?? 0),
        'tendencia'       => $row['tendencia']              ?? 'inconclusivo',
        'tendencia_pct'   => (float) ($row['tendencia_pct']   ?? 0),
        'gap_para_meta'   => isset($row['gap_para_meta']) && $row['gap_para_meta'] !== null
            ? Support::money((float) $row['gap_para_meta']) : null,
        'gap_pct'         => isset($row['gap_pct']) ? (float) $row['gap_pct'] : null,
        'risco'           => $row['risco_level']            ?? 'sem_meta',
        'atualizado_em'   => $row['refreshed_at']           ?? null,
    ];
};

// ── Sem dados ────────────────────────────────────────────────────────────────

if (!$hasData) {
    Http::json([
        'ok'          => true,
        'competencia' => $competencia,
        'status'      => $available ? 'sem_dados' : 'mart_indisponivel',
        'message'     => $available
            ? "Nenhuma projeção disponível para {$competencia}. Execute bin/compute_forecasts.php."
            : 'Tabela mart_forecasts não existe. Execute a migration Version20260417190000.',
        'summary'        => [],
        'mes_corrente'   => null,
        'vendedores'     => [],
        'regioes'        => [],
        'mix_categoria'  => [],
        'carteira'       => null,
        'em_risco'       => [],
        'meta' => [
            'generated_at' => date(DATE_ATOM),
            'competencia'  => $competencia,
            'focus'        => $focus,
        ],
    ]);
}

// ── Resumo ───────────────────────────────────────────────────────────────────

$summary = $repo->summary($competencia);

$payload = [
    'ok'          => true,
    'competencia' => $competencia,
    'status'      => 'pronto',
    'aviso'       => 'Projeções de baixa confiança (<45%) devem ser interpretadas como indicativo, não compromisso.',
    'summary' => [
        'total_forecasts'      => (int)   ($summary['total_forecasts']       ?? 0),
        'em_risco'             => (int)   ($summary['em_risco']              ?? 0),
        'vendedores'           => (int)   ($summary['vendedores']            ?? 0),
        'regioes'              => (int)   ($summary['regioes']               ?? 0),
        'segmentos'            => (int)   ($summary['segmentos']             ?? 0),
        'confianca_media_pct'  => (int)   ($summary['confianca_media']       ?? 0),
        'tendencia_queda'      => (int)   ($summary['tendencia_queda']       ?? 0),
        'tendencia_crescimento'=> (int)   ($summary['tendencia_crescimento'] ?? 0),
        'ultima_projecao'      => $summary['ultima_projecao'] ?? null,
    ],
];

// ── Seções condicionais por focus ────────────────────────────────────────────

$includeAll = $focus === 'all';

if ($includeAll || $focus === 'mes_corrente') {
    $row = $repo->mesCorriente($competencia);
    $payload['mes_corrente'] = $row ? $formatRow($row) : null;
}

if ($includeAll || $focus === 'vendedor') {
    $payload['vendedores'] = array_map($formatRow, $repo->byVendor($competencia));
}

if ($includeAll || $focus === 'regiao') {
    $payload['regioes'] = array_map($formatRow, $repo->byRegiao($competencia));
}

if ($includeAll || $focus === 'mix_categoria') {
    $payload['mix_categoria'] = array_map($formatRow, $repo->mixCategoria($competencia));
}

if ($includeAll || $focus === 'carteira') {
    $row = $repo->carteira($competencia);
    $payload['carteira'] = $row ? $formatRow($row) : null;
}

if ($includeAll || $focus === 'risco') {
    $payload['em_risco'] = array_map($formatRow, $repo->atRisk($competencia));
}

$payload['meta'] = [
    'generated_at'  => date(DATE_ATOM),
    'competencia'   => $competencia,
    'focus'         => $focus,
    'served_from'   => 'mart_forecasts',
    'metodos' => [
        'linear_simples'               => 'Realizado ÷ dias com dados × dias no mês',
        'proporcional_mes'             => 'Realizado ÷ (dias corridos ÷ dias no mês)',
        'clientes_ativos_x_ticket_medio' => 'Clientes ativos × (faturado_12m ÷ 12)',
    ],
    'confianca_tiers' => [
        ['dias_minimos' =>  0, 'confianca_pct' => 20, 'label' => 'muito cedo'],
        ['dias_minimos' =>  7, 'confianca_pct' => 45, 'label' => 'tendência inicial'],
        ['dias_minimos' => 14, 'confianca_pct' => 65, 'label' => 'metade do mês'],
        ['dias_minimos' => 21, 'confianca_pct' => 80, 'label' => 'reta final'],
        ['dias_minimos' => 28, 'confianca_pct' => 95, 'label' => 'quase fechado'],
    ],
];

Http::json($payload);
