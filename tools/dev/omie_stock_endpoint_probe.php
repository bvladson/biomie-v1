<?php

declare(strict_types=1);

/**
 * tools/dev/omie_stock_endpoint_probe.php
 *
 * EST.R1 Fase 1 — Sonda read-only dos endpoints de estoque da Omie.
 *
 * Chama a API Omie (requer credenciais válidas) para confirmar quais
 * endpoints de estoque estão disponíveis e quais campos retornam.
 *
 * Sempre --dry-run por padrão (não persiste nada no DB).
 * Salva amostras sanitizadas somente em /storage/debug/ quando --save-sample.
 *
 * Endpoints investigados:
 *   - ConsultarLocaisEstoque  (geral/locaisestoque/)
 *   - ListarLocaisEstoque     (geral/locaisestoque/)
 *   - ConsultarEstoque        (geral/estoque/)
 *   - ResumoEstoque           (geral/estoque/)
 *   - ConsultarMovimentoEstoque (estoque/movimentacao/)
 *
 * Usage:
 *   php tools/dev/omie_stock_endpoint_probe.php --dry-run --pretty
 *   php tools/dev/omie_stock_endpoint_probe.php --dry-run --sample=3 --pretty
 *   php tools/dev/omie_stock_endpoint_probe.php --dry-run --product-code=123 --pretty
 *   php tools/dev/omie_stock_endpoint_probe.php --dry-run --date-from=2026-01-01 --date-to=2026-05-29 --pretty
 *   php tools/dev/omie_stock_endpoint_probe.php --dry-run --json
 *
 * Exit: 0=OK 1=falha parcial 2=erro credencial/DB
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Logger;
use BIomie\OmieClient;

$opts        = getopt('', ['dry-run', 'pretty', 'json', 'sample:', 'product-code:', 'date-from:', 'date-to:', 'save-sample']);
$isDryRun    = isset($opts['dry-run']);
$isPretty    = isset($opts['pretty']);
$isJson      = isset($opts['json']);
$sampleN     = isset($opts['sample']) ? max(1, min(20, (int) $opts['sample'])) : 3;
$productCode = isset($opts['product-code']) ? trim((string) $opts['product-code']) : '';
$dateFrom    = isset($opts['date-from']) ? trim((string) $opts['date-from']) : date('Y-m-01');
$dateTo      = isset($opts['date-to'])   ? trim((string) $opts['date-to'])   : date('Y-m-d');
$saveSample  = isset($opts['save-sample']);

if (!$isDryRun) {
    fwrite(STDERR, "AVISO: --dry-run obrigatório nesta fase. Rode com --dry-run.\n");
    exit(2);
}

// ── Credenciais ───────────────────────────────────────────────────────────────

try {
    $config    = new Config();
    $appKey    = (string) $config->get('omie.app_key', '');
    $appSecret = (string) $config->get('omie.app_secret', '');
    if ($appKey === '' || $appSecret === '') {
        fwrite(STDERR, "ERRO: omie.app_key / omie.app_secret não configurados.\n");
        exit(2);
    }
    $logger = new Logger(Bootstrap::rootPath('storage/logs/stock_probe.log'));
    $client = new OmieClient($appKey, $appSecret, 30, $logger, null, null, 'stock_probe');
} catch (\Throwable $e) {
    fwrite(STDERR, 'ERRO ao inicializar OmieClient: ' . $e->getMessage() . "\n");
    exit(2);
}

// ── Endpoints de estoque Omie ─────────────────────────────────────────────────

const EP_LOCAIS    = 'https://app.omie.com.br/api/v1/geral/locaisestoque/';
const EP_ESTOQUE   = 'https://app.omie.com.br/api/v1/geral/estoque/';
const EP_MOVIMENT  = 'https://app.omie.com.br/api/v1/geral/estoque/';

$probeResults = [];
$fieldsFound  = [];
$anyFail      = false;

// ── Helper: probe one call ────────────────────────────────────────────────────

function probeCall(OmieClient $client, string $endpoint, string $call, array $params, int $sampleN): array
{
    try {
        $resp = $client->post($endpoint, $call, $params);
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage(), 'fields' => [], 'sample' => []];
    }

    // Find the list key (varies per call)
    $listKeys = ['locais_cadastro', 'estoque', 'movimentos', 'registros', 'lista'];
    $items = [];
    foreach ($listKeys as $k) {
        if (isset($resp[$k]) && is_array($resp[$k])) {
            $items = $resp[$k];
            break;
        }
    }
    if (empty($items) && isset($resp['total_de_registros'])) {
        $items = [];
    }

    $sample = array_slice($items, 0, $sampleN);
    $fields = !empty($sample) ? array_keys($sample[0]) : array_keys($resp);

    return [
        'ok'           => true,
        'call'         => $call,
        'total'        => (int) ($resp['total_de_registros'] ?? count($items)),
        'returned'     => count($items),
        'fields'       => $fields,
        'sample_count' => count($sample),
        'sample'       => $sample,
        'raw_keys'     => array_keys($resp),
        'error'        => null,
    ];
}

// ── Probe 1: ListarLocaisEstoque ──────────────────────────────────────────────

$r = probeCall($client, EP_LOCAIS, 'ListarLocaisEstoque', [
    'nPagina'         => 1,
    'nRegPorPagina'   => $sampleN,
    'lSoAtivos'       => 'S',
], $sampleN);

$probeResults['ListarLocaisEstoque'] = $r;
if ($r['ok']) {
    $fieldsFound['locais_fields'] = $r['fields'];
} else {
    $anyFail = true;
}

// ── Probe 2: ConsultarEstoque (por produto ou listagem) ───────────────────────

$estoqueParams = $productCode !== ''
    ? ['codigo_produto_integracao' => '', 'codigo_produto' => $productCode]
    : ['nPagina' => 1, 'nRegPorPagina' => $sampleN, 'lSoAtivos' => 'S'];

$estoqueCall = $productCode !== '' ? 'ConsultarEstoque' : 'ListarEstoque';

$r = probeCall($client, EP_ESTOQUE, $estoqueCall, $estoqueParams, $sampleN);
$probeResults[$estoqueCall] = $r;
if ($r['ok']) {
    $fieldsFound['estoque_fields'] = $r['fields'];
} else {
    $anyFail = true;
}

// ── Probe 3: ResumoEstoque ────────────────────────────────────────────────────

$resumoParams = [
    'nPagina'       => 1,
    'nRegPorPagina' => $sampleN,
];
if ($productCode !== '') {
    $resumoParams['codigo_produto'] = $productCode;
}

$r = probeCall($client, EP_ESTOQUE, 'ResumoEstoque', $resumoParams, $sampleN);
$probeResults['ResumoEstoque'] = $r;
if ($r['ok']) {
    $fieldsFound['resumo_fields'] = $r['fields'];
} else {
    $anyFail = true;
}

// ── Probe 4: ConsultarMovimentoEstoque ────────────────────────────────────────

$movParams = [
    'nPagina'       => 1,
    'nRegPorPagina' => $sampleN,
    'dDtInicio'     => $dateFrom,
    'dDtFim'        => $dateTo,
];
if ($productCode !== '') {
    $movParams['codigo_produto'] = $productCode;
}

$r = probeCall($client, EP_MOVIMENT, 'ConsultarMovimentoEstoque', $movParams, $sampleN);
$probeResults['ConsultarMovimentoEstoque'] = $r;
if ($r['ok']) {
    $fieldsFound['movimento_fields'] = $r['fields'];
} else {
    $anyFail = true;
}

// ── Recommended mapping ───────────────────────────────────────────────────────

$recommendedMapping = [
    'biomie_estoque_locais_dim' => [
        'codigo_local   ← codigo_local_estoque (ListarLocaisEstoque)',
        'nome_local     ← nome_local',
        'ativo          ← nAtivo',
        'permite_venda  ← nPermiteVenda',
    ],
    'biomie_produtos_estoque_saldos' => [
        'codigo_produto ← codigo_produto (ConsultarEstoque / ResumoEstoque)',
        'codigo_local   ← codigo_local_estoque',
        'estoque_atual  ← saldo / saldo_atual / estoque',
        'estoque_minimo ← estoque_minimo',
        'unidade        ← unidade',
        'custo_medio    ← custo_unitario / valor_unitario',
        'atualizado_em  ← data_posicao / now()',
    ],
    'biomie_produtos_estoque_movimentos' => [
        'codigo_produto ← codigo_produto',
        'codigo_local   ← codigo_local_estoque',
        'data_movimento ← dDtMovimento',
        'tipo_movimento ← cTipoMov (E=entrada, S=saída)',
        'quantidade     ← nQuantidade',
        'valor          ← nValorUnitario × nQuantidade',
        'documento      ← cDocumento / nNumeroNF',
        'nota_key       ← derivado via nota_key_lookup',
    ],
];

// ── Output ────────────────────────────────────────────────────────────────────

$output = [
    'tool'                 => 'omie_stock_endpoint_probe',
    'dry_run'              => true,
    'date'                 => date('Y-m-d H:i:s'),
    'period'               => ['from' => $dateFrom, 'to' => $dateTo],
    'product_code_filter'  => $productCode ?: null,
    'endpoints_probed'     => array_keys($probeResults),
    'endpoints_available'  => array_keys(array_filter($probeResults, fn($r) => $r['ok'] ?? false)),
    'endpoints_failed'     => array_keys(array_filter($probeResults, fn($r) => !($r['ok'] ?? false))),
    'fields_found'         => $fieldsFound,
    'recommended_mapping'  => $recommendedMapping,
    'probe_results'        => $probeResults,
    'summary'              => [
        'pass'       => !$anyFail,
        'next_steps' => $anyFail
            ? 'Alguns endpoints falharam. Verificar credenciais Omie e plano do cliente.'
            : 'Todos endpoints acessíveis. Prosseguir com StockSyncCoordinator --apply.',
    ],
];

if ($isJson) {
    echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit($anyFail ? 1 : 0);
}

$bar = str_repeat('═', 70);
$sep = str_repeat('─', 70);
echo "\n{$bar}\n Omie Stock — Sonda de Endpoints (dry-run)\n{$bar}\n";
printf(" Período: %s → %s  |  Produto: %s\n{$sep}\n\n", $dateFrom, $dateTo, $productCode ?: '*');

foreach ($probeResults as $call => $r) {
    $icon = ($r['ok'] ?? false) ? "\033[32mOK\033[0m  " : "\033[31mFAIL\033[0m";
    printf(" [%s] %s\n", $icon, $call);
    if ($r['ok'] ?? false) {
        printf("       total=%d  fields=%d  [%s]\n", $r['total'] ?? 0, count($r['fields'] ?? []), implode(', ', array_slice($r['fields'] ?? [], 0, 8)));
    } else {
        printf("       \033[31m→ %s\033[0m\n", $r['error'] ?? 'unknown');
    }
}

echo "\n Mapeamento recomendado:\n";
foreach ($recommendedMapping as $table => $lines) {
    echo "  {$table}:\n";
    foreach ($lines as $l) echo "    {$l}\n";
}

echo "\n{$sep}\n";
printf(" %s  —  %s\n",
    $anyFail ? "\033[31m✗ FAIL\033[0m" : "\033[32m✔ PASS\033[0m",
    $output['summary']['next_steps']
);
echo "{$bar}\n\n";

exit($anyFail ? 1 : 0);
