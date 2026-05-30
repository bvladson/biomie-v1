<?php

declare(strict_types=1);

/**
 * api/product_stock_intelligence.php — EST.R1.1
 *
 * Endpoint de inteligência de estoque por produto.
 * Usa 3 camadas em ordem de prioridade:
 *   1. official_stock_api  — biomie_produtos_estoque_saldos (Omie módulo ativo)
 *   2. product_snapshot    — saldo de cadastro de produto Omie
 *   3. omie_document_ledger — análise operacional por NFs/pedidos/remessas
 *
 * Nunca afirma saldo físico real quando fonte é omie_document_ledger.
 * Sempre inclui source_confidence e source_note no payload.
 *
 * Parâmetros:
 *   product_code — obrigatório
 *   start, end   — período (default: ano atual)
 *   tab          — intelligence | velocity | risk | source | diagnostics
 *   limit        — max itens por seção (default 50, max 100)
 *
 * Permissão: sales.view
 */

require __DIR__ . '/bootstrap.php';
\BIomie\Auth::requireCapability('sales.view', true);

use BIomie\Database;
use BIomie\Http;
use BIomie\RequestContext;
use BIomie\Stock\OmieDocumentStockLedgerBuilder;
use BIomie\Stock\OmieStockSourceResolver;
use BIomie\Stock\StockReadRepository;

header('Content-Type: application/json; charset=utf-8');

try {
    $productCode = trim((string) Http::query('product_code', ''));
    if ($productCode === '') {
        Http::json(['ok' => false, 'message' => 'product_code obrigatório.'], 400);
    }

    $today  = date('Y-m-d');
    $start  = trim((string) Http::query('start', date('Y-01-01')));
    $end    = trim((string) Http::query('end', $today));
    $tab    = trim((string) Http::query('tab', 'intelligence'));
    $limit  = max(1, min(100, (int) Http::query('limit', '50')));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) $start = date('Y-01-01');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end))   $end   = $today;
    if ($end < $start) $end = $start;

    $conn = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo  = Database::connect($config, $conn);

    $sourceResolver = new OmieStockSourceResolver($pdo);
    $source         = $sourceResolver->resolveForProduct($productCode);

    $payload = [
        'ok'               => true,
        'product_code'     => $productCode,
        'period'           => ['start' => $start, 'end' => $end],
        'stock_source'     => $source['source'],
        'source_label'     => $source['label'],
        'source_confidence' => $source['confidence'],
        'source_note'      => $source['note'],
        'official_unavailable_reason' => $source['official_unavailable_reason'],
        'meta'             => ['request_id' => RequestContext::id(), 'generated_at' => date(DATE_ATOM), 'tab' => $tab],
    ];

    // ── Tab: source / diagnostics ─────────────────────────────────────────────

    if ($tab === 'source' || $tab === 'diagnostics') {
        $payload['diagnostics'] = $sourceResolver->diagnostics();
        Http::json($payload);
    }

    // ── Official stock (Camada 1) ─────────────────────────────────────────────

    if ($source['source'] === OmieStockSourceResolver::SOURCE_OFFICIAL) {
        $stockRepo = new StockReadRepository($pdo);
        $stockData = $stockRepo->saldosPorProduto($productCode);
        $stockMovs = $stockRepo->movimentos($productCode, $start, $end, $limit);

        $totalAtual  = array_sum(array_column($stockData, 'estoque_atual'));
        $totalMinimo = array_sum(array_column($stockData, 'estoque_minimo'));

        $payload['official_stock'] = [
            'available'        => !empty($stockData),
            'total_atual'      => round((float)$totalAtual, 4),
            'total_minimo'     => round((float)$totalMinimo, 4),
            'n_locais'         => count($stockData),
            'saldos_por_local' => $stockData,
            'movimentos'       => $stockMovs,
            'empty_state'      => empty($stockData) ? 'Produto sem saldo no período' : null,
        ];
        $payload['document_ledger'] = null;
        Http::json($payload);
    }

    // ── Document ledger (Camada 3 — caso padrão) ──────────────────────────────

    $builder = new OmieDocumentStockLedgerBuilder($pdo);

    if ($tab === 'intelligence' || $tab === 'velocity') {
        $payload['document_ledger'] = $builder->buildForProduct($productCode, $start, $end, $limit);
    }

    if ($tab === 'risk') {
        $payload['document_ledger'] = $builder->buildForProduct($productCode, $start, $end, $limit);
        $payload['rupture_risk_list'] = null;
    }

    if ($tab === 'intelligence') {
        // Also include global context
        $payload['global_stats'] = $builder->statsGerais($start, $end);
    }

    $payload['official_stock'] = null;

    Http::json($payload);

} catch (\Throwable $e) {
    $logger?->warning('product_stock_intelligence: erro.', ['msg' => $e->getMessage(), 'product' => $productCode ?? '']);
    Http::json(['ok' => false, 'message' => 'Erro interno ao carregar inteligência de estoque.', 'request_id' => \BIomie\RequestContext::id()], 500);
}
