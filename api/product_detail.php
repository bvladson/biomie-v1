<?php

/**
 * api/product_detail.php — Product 360.
 *
 * Retorna resumo completo de um produto: NFs autorizadas, clientes, vendedores,
 * pedidos vinculados, evolução temporal e LTV observado.
 *
 * Parâmetros:
 *   product_code  — obrigatório; código interno do produto
 *   start         — YYYY-MM-DD (default: 1º dia do mês atual)
 *   end           — YYYY-MM-DD (default: hoje)
 *   limit         — máx. linhas por seção (default 50, máx 200)
 *   tab           — carregamento sob demanda: resumo|nfs|clientes|vendedores|pedidos|evolucao|ltv
 *
 * Permissão: sales.view
 * Nunca chama API Omie.
 */

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Database;
use BIomie\Http;
use BIomie\Modules\Sales\Application\Products\Product360ReadService;
use BIomie\Modules\Sales\Infrastructure\Product360ReadRepository;
use BIomie\RequestContext;
use BIomie\Stock\OmieDocumentStockLedgerBuilder;
use BIomie\Stock\OmieStockSourceResolver;
use BIomie\Support\ProductCommercialIdentityResolver;

require __DIR__ . '/bootstrap.php';

Auth::requireCapability('sales.view', true);

try {
    $productCode = trim((string) Http::query('product_code'));
    if ($productCode === '') {
        Http::json(['ok' => false, 'message' => 'Parametro product_code e obrigatorio.', 'request_id' => RequestContext::id()], 400);
    }

    $today     = date('Y-m-d');
    $monthStart = date('Y-m-01');
    $start     = trim((string) Http::query('start'));
    $end       = trim((string) Http::query('end'));
    $limitRaw  = (int) Http::query('limit', '50');
    $limit     = max(1, min($limitRaw, 200));
    $tab       = trim((string) Http::query('tab'));
    $tab       = $tab !== '' ? $tab : null;

    if ($start === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
        $start = $monthStart;
    }
    if ($end === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
        $end = $today;
    }
    if ($end < $start) {
        $end = $start;
    }

    $conn = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    if (!Database::isConfigured($config, $conn)) {
        Http::json(['ok' => false, 'message' => 'Banco de dados nao configurado.', 'request_id' => RequestContext::id()], 503);
    }
    $pdo = Database::connect($config, $conn);

    // EST.R1.2 — tab=estoque via document ledger (sem Product360ReadService)
    if ($tab === 'estoque') {
        $builder  = new OmieDocumentStockLedgerBuilder($pdo);
        $resolver = new OmieStockSourceResolver($pdo);
        $source   = $resolver->resolveForProduct($productCode);
        $ledger   = $builder->buildForProduct($productCode, $start, $end, $limit);
        $vendors  = $builder->buildTopVendorsForProduct($productCode, $start, $end, 10);

        Http::json([
            'ok'               => true,
            'product_code'     => $productCode,
            'period'           => ['start' => $start, 'end' => $end],
            'stock_source'     => $source['source'],
            'source_label'     => $source['label'],
            'source_confidence' => $source['confidence'],
            'source_note'      => $source['note'],
            'document_ledger'  => $ledger,
            'top_vendors'      => $vendors,
            'meta'             => ['request_id' => RequestContext::id(), 'generated_at' => date(DATE_ATOM)],
        ]);
    }

    $repository      = new Product360ReadRepository($pdo);
    $identityResolver = new ProductCommercialIdentityResolver($pdo);
    $service         = new Product360ReadService($repository, $identityResolver);

    $payload = $service->build($productCode, $start, $end, $limit, $tab);

    Http::json($payload);
} catch (Throwable $e) {
    $logger->warning('product_detail: erro inesperado.', [
        'product_code' => $productCode ?? '',
        'message'      => $e->getMessage(),
        'request_id'   => RequestContext::id(),
    ]);
    Http::json([
        'ok'         => false,
        'message'    => 'Erro interno ao carregar Product 360.',
        'request_id' => RequestContext::id(),
    ], 500);
}
