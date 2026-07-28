<?php

declare(strict_types=1);

/**
 * Endpoint paginado server-side para a tabela de NFs do periodo.
 *
 * A consulta detecta colunas opcionais antes de montar o SQL. Isso permite
 * atender ambientes que ainda estejam concluindo as migrations canonicas sem
 * derrubar toda a tabela com HTTP 500.
 */

require __DIR__ . '/bootstrap.php';
\BIomie\Auth::requireCapability('sales.view', true);

use BIomie\Auth;
use BIomie\Commercial\CommercialFilterPreferenceStore;
use BIomie\Commercial\CommercialRequestScope;
use BIomie\Commercial\CommercialScopeDeniedException;
use BIomie\Config;
use BIomie\Database;
use BIomie\Http;
use BIomie\Support\BiVisibilityScope;
use BIomie\Support\SecretMask;
use BIomie\Support\TenantContext;
use BIomie\Support\VendorScopeFilter;

header('Content-Type: application/json; charset=utf-8');

$table = 'biomie_notas_fiscais_canonicas';

try {
    $config = new Config();
    $conn = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo = Database::connect($config, $conn);
} catch (\Throwable $e) {
    Http::json([
        'ok' => false,
        'error' => SecretMask::sanitizeErrorMessage($e->getMessage()),
    ], 500);
}

$range = Http::resolveDateRange();
[$startDate, $endDate] = [$range['start'], $range['end']];
$draw = max(1, (int) Http::query('draw', '1'));
$offset = max(0, (int) Http::query('offset', '0'));
$length = min(100, max(1, (int) Http::query('length', '25')));
$search = trim(Http::query('search', ''));
$orderCol = Http::query('order_col', 'data_ref');
$orderDir = strtolower(Http::query('order_dir', 'desc')) === 'asc' ? 'ASC' : 'DESC';
$scopeRaw = Http::query('scope', 'commercial');
$scope = in_array($scopeRaw, ['commercial', 'remittance', 'licitacao', 'all'], true)
    ? $scopeRaw
    : 'commercial';
$vendedor = trim(Http::query('vendedor', ''));
$cliente = trim(Http::query('cliente', ''));
$minValueRaw = trim(Http::query('min_value', ''));
$maxValueRaw = trim(Http::query('max_value', ''));
$hasFrete = Http::query('has_frete', '') === '1';
$semPedido = Http::query('sem_pedido', '') === '1';
$hasOutras = Http::query('has_outras', '') === '1';
$hasSeguro = Http::query('has_seguro', '') === '1';
$comDesconto = Http::query('com_desconto', '') === '1';

try {
    $columnsStmt = $pdo->prepare(
        'SELECT COLUMN_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
    );
    $columnsStmt->execute([':table' => $table]);
    $availableColumns = array_fill_keys($columnsStmt->fetchAll(\PDO::FETCH_COLUMN), true);
} catch (\Throwable $e) {
    Http::json([
        'ok' => false,
        'draw' => $draw,
        'error' => SecretMask::sanitizeErrorMessage($e->getMessage()),
    ], 500);
}

$columnExists = static fn (string $column): bool => isset($availableColumns[$column]);

$hasNotaKey = $columnExists('nota_key');
$hasId = $columnExists('id');
$hasDataCompetencia = $columnExists('data_competencia');
$hasOperationScope = $columnExists('operation_scope');
$hasBusinessUnitScope = $columnExists('business_unit_scope');
$hasValorBase = $columnExists('valor_base_comercial_nf');
$hasValorTotal = $columnExists('valor_total');
$hasValorMercadorias = $columnExists('valor_mercadorias');
$hasValorFrete = $columnExists('valor_frete');
$hasValorOutras = $columnExists('valor_outras_despesas');
$hasValorSeguro = $columnExists('valor_seguro');
$hasValorDescontos = $columnExists('valor_descontos');
$hasValorDesconto = $columnExists('valor_desconto');
$hasPedidoOmie = $columnExists('pedido_id_omie');
$hasPedidoCodigo = $columnExists('codigo_pedido_origem');
$hasClienteCodigo = $columnExists('codigo_cliente_omie');
$hasDocumentoCliente = $columnExists('documento_cliente');
$hasVendedorCodigo = $columnExists('codigo_vendedor_omie');
$hasChaveNfe = $columnExists('chave_nfe');
$hasInvoiceItems = $hasNotaKey && Http::tableExists($pdo, 'biomie_notas_fiscais_itens_canonicos');

$dateExpr = $hasDataCompetencia
    ? 'COALESCE(nf.data_competencia, nf.data_emissao)'
    : 'nf.data_emissao';

$baseFallbackExpr = $hasValorTotal
    ? 'nf.valor_total'
    : ($hasValorMercadorias ? 'nf.valor_mercadorias' : '0');

if ($hasValorTotal && $hasValorFrete && $hasValorOutras && $hasValorSeguro) {
    $baseFallbackExpr = '(nf.valor_total'
        . ' - COALESCE(nf.valor_frete, 0)'
        . ' - COALESCE(nf.valor_outras_despesas, 0)'
        . ' - COALESCE(nf.valor_seguro, 0))';
}

$baseExpr = $hasValorBase
    ? "COALESCE(nf.valor_base_comercial_nf, {$baseFallbackExpr})"
    : $baseFallbackExpr;
$merchandiseExpr = $hasValorMercadorias ? 'nf.valor_mercadorias' : $baseFallbackExpr;
$discountExpr = $hasValorDescontos
    ? 'nf.valor_descontos'
    : ($hasValorDesconto ? 'nf.valor_desconto' : '0');
$clientCountExpr = $hasClienteCodigo
    ? 'nf.codigo_cliente_omie'
    : 'nf.nome_cliente';
$notaKeyExpr = $hasNotaKey
    ? 'nf.nota_key'
    : ($hasId
        ? "CONCAT(COALESCE(nf.numero_nf, ''), '_', COALESCE(nf.id, ''))"
        : "COALESCE(nf.numero_nf, '')");
$pedidoExpr = $hasPedidoCodigo
    ? "COALESCE(NULLIF(TRIM(nf.codigo_pedido_origem), ''), "
        . ($hasPedidoOmie ? 'CAST(nf.pedido_id_omie AS CHAR)' : "''")
        . ')'
    : ($hasPedidoOmie ? 'CAST(nf.pedido_id_omie AS CHAR)' : "''");
$scopeExpr = $hasOperationScope ? "COALESCE(nf.operation_scope, 'commercial')" : "'commercial'";
if ($hasBusinessUnitScope) {
    $scopeExpr = "CASE WHEN nf.business_unit_scope = 'licitacao' THEN 'licitacao' ELSE {$scopeExpr} END";
}
$itemCountExpr = $hasInvoiceItems
    ? '(SELECT COUNT(*) FROM biomie_notas_fiscais_itens_canonicos nfi WHERE nfi.nota_key = nf.nota_key)'
    : '0';
$clienteCodigoExpr = $hasClienteCodigo ? 'nf.codigo_cliente_omie' : "''";
$documentoClienteExpr = $hasDocumentoCliente ? 'nf.documento_cliente' : "''";
$vendedorCodigoExpr = $hasVendedorCodigo ? 'nf.codigo_vendedor_omie' : "''";
$chaveNfeExpr = $hasChaveNfe ? 'nf.chave_nfe' : "''";
$valorTotalExpr = $hasValorTotal ? 'nf.valor_total' : $baseFallbackExpr;
$valorFreteExpr = $hasValorFrete ? 'nf.valor_frete' : '0';
$valorOutrasExpr = $hasValorOutras ? 'nf.valor_outras_despesas' : '0';
$valorSeguroExpr = $hasValorSeguro ? 'nf.valor_seguro' : '0';

$colMap = [
    'data_ref' => $dateExpr,
    'nome_cliente' => 'nf.nome_cliente',
    'nome_vendedor' => 'nf.nome_vendedor',
    'base_comercial' => $baseExpr,
    'numero_nf' => 'nf.numero_nf',
];
$orderExpr = $colMap[$orderCol] ?? $colMap['data_ref'];

// ── Filtro Comercial Inteligente (GLOBAL) — canal + preferência de vendedor ──
// Mesmo recorte do Dashboard: o toggle de operação definido lá propaga p/ cá via
// a preferência compartilhada. URL de vendedor vence a pref (e valida ⇒ 403).
$userId = (int) (Auth::currentUser()['id'] ?? 0);
$companyIds = TenantContext::allowedCompanyIds();
$sellerSelfOnly = !Auth::hasCapability('sales.quotes.followup.view')
    && Auth::hasCapability('sales.quotes.followup.self');
try {
    $commScope = CommercialRequestScope::fromRequest($pdo, CommercialFilterPreferenceStore::PAGE_COMMERCIAL);
} catch (CommercialScopeDeniedException $denied) {
    Http::json(['ok' => false, 'draw' => $draw, 'error' => $denied->getMessage()], 403);
}
$urlVendorIds = VendorScopeFilter::requestedIds();
$seedVendorIds = $urlVendorIds !== [] ? $urlVendorIds : $commScope->vendorScopeSeed();

$visCond = BiVisibilityScope::getCondition($pdo, $table, 'nf');
$conditions = [TenantContext::sqlFilter('nf'), "nf.status_fiscal = 'autorizada'", "{$dateExpr} BETWEEN :start AND :end"];
$params = [':start' => $startDate, ':end' => $endDate];

// Filtro global de vendedor (seletor por empresa) — match exato por codigo Omie.
// Semeado pela preferência quando a URL não pediu vendedor (pref age como filtro).
$conditions[] = $hasVendedorCodigo
    ? VendorScopeFilter::conditionForContext($pdo, $userId, $companyIds, $seedVendorIds, 'nf', $params, 'codigo_vendedor_omie', 'company_id', $sellerSelfOnly)
    : '1=0';
if ($commScope->forceEmptySellers()) {
    $conditions[] = '1=0';
}

switch ($scope) {
    case 'remittance':
        $conditions[] = $hasOperationScope ? "nf.operation_scope = 'remittance'" : '1 = 0';
        break;
    case 'licitacao':
        $conditions[] = $hasBusinessUnitScope
            ? "nf.business_unit_scope = 'licitacao'"
            : "NOT ({$visCond})";
        break;
    case 'all':
        break;
    default:
        if ($hasOperationScope) {
            $conditions[] = "nf.operation_scope = 'commercial'";
        }
        $conditions[] = $visCond;
        // Refino de canal (varejo/licitação) por cima do universo comercial.
        $conditions[] = $commScope->channelRefineSql($pdo, $table, 'nf');
        break;
}

if ($search !== '') {
    $searchExpressions = [
        'nf.numero_nf',
        'nf.nome_cliente',
        'nf.nome_vendedor',
    ];
    if ($hasDocumentoCliente) {
        $searchExpressions[] = 'nf.documento_cliente';
    }
    if ($hasPedidoCodigo) {
        $searchExpressions[] = 'nf.codigo_pedido_origem';
    }
    if ($hasPedidoOmie) {
        $searchExpressions[] = 'CAST(nf.pedido_id_omie AS CHAR)';
    }
    if ($hasChaveNfe) {
        $searchExpressions[] = 'nf.chave_nfe';
    }

    $searchConditions = [];
    $searchParamNames = [':search1', ':search2', ':search3', ':search4', ':search5', ':search6', ':search7'];
    foreach ($searchExpressions as $index => $expression) {
        $key = $searchParamNames[$index];
        $searchConditions[] = "{$expression} LIKE {$key}";
        $params[$key] = '%' . $search . '%';
    }
    $conditions[] = '(' . implode(' OR ', $searchConditions) . ')';
}

if ($vendedor !== '') {
    $conditions[] = 'nf.nome_vendedor LIKE :vendedor';
    $params[':vendedor'] = '%' . $vendedor . '%';
}
if ($cliente !== '') {
    $conditions[] = 'nf.nome_cliente LIKE :cliente';
    $params[':cliente'] = '%' . $cliente . '%';
}
if ($minValueRaw !== '' && is_numeric($minValueRaw)) {
    $conditions[] = "({$baseExpr}) >= :min_value";
    $params[':min_value'] = (float) $minValueRaw;
}
if ($maxValueRaw !== '' && is_numeric($maxValueRaw)) {
    $conditions[] = "({$baseExpr}) <= :max_value";
    $params[':max_value'] = (float) $maxValueRaw;
}
if ($hasFrete) {
    $conditions[] = $hasValorFrete ? 'COALESCE(nf.valor_frete, 0) > 0' : '1 = 0';
}
if ($hasOutras) {
    $conditions[] = $hasValorOutras ? 'COALESCE(nf.valor_outras_despesas, 0) > 0' : '1 = 0';
}
if ($hasSeguro) {
    $conditions[] = $hasValorSeguro ? 'COALESCE(nf.valor_seguro, 0) > 0' : '1 = 0';
}
if ($comDesconto) {
    $conditions[] = "COALESCE({$discountExpr}, 0) > 0";
}
if ($semPedido) {
    $missingOrderConditions = [];
    if ($hasPedidoCodigo) {
        $missingOrderConditions[] = "(nf.codigo_pedido_origem IS NULL OR TRIM(nf.codigo_pedido_origem) = '')";
    }
    if ($hasPedidoOmie) {
        $missingOrderConditions[] = '(nf.pedido_id_omie IS NULL OR nf.pedido_id_omie = 0)';
    }
    $conditions[] = $missingOrderConditions !== []
        ? '(' . implode(' AND ', $missingOrderConditions) . ')'
        : '1 = 1';
}

$whereStr = 'WHERE ' . implode("\n  AND ", $conditions);

try {
    $tenantFilter = \BIomie\Support\TenantContext::sqlFilter('nf');
    $totalParams = [':start' => $startDate, ':end' => $endDate];
    $totalSellerFilter = $hasVendedorCodigo
        ? \BIomie\Support\VendorScopeFilter::condition($pdo, 'nf', $totalParams)
        : '1=0';
    $stmtTotal = $pdo->prepare(
        "SELECT COUNT(*)
         FROM {$table} nf
         WHERE {$tenantFilter}
           AND nf.status_fiscal = 'autorizada'
           AND {$dateExpr} BETWEEN :start AND :end
           AND {$totalSellerFilter}"
    );
    $stmtTotal->execute($totalParams);
    $recordsTotal = (int) $stmtTotal->fetchColumn();

    $stmtFiltered = $pdo->prepare(
        "SELECT COUNT(*) AS cnt,
                COALESCE(SUM({$baseExpr}), 0) AS base_comercial,
                COUNT(DISTINCT {$clientCountExpr}) AS clientes
         FROM {$table} nf
         {$whereStr}"
    );
    $stmtFiltered->execute($params);
    $filteredRow = $stmtFiltered->fetch(\PDO::FETCH_ASSOC) ?: [];

    $stmtData = $pdo->prepare(
        "SELECT
            {$notaKeyExpr} AS nota_key,
            nf.numero_nf,
            {$chaveNfeExpr} AS chave_nfe,
            {$dateExpr} AS data_ref,
            nf.nome_cliente,
            nf.nome_vendedor,
            {$clienteCodigoExpr} AS codigo_cliente_omie,
            {$documentoClienteExpr} AS documento_cliente,
            {$vendedorCodigoExpr} AS codigo_vendedor_omie,
            {$baseExpr} AS base_comercial,
            {$valorTotalExpr} AS valor_total,
            {$merchandiseExpr} AS valor_mercadorias,
            {$discountExpr} AS valor_desconto,
            {$valorFreteExpr} AS valor_frete,
            {$valorOutrasExpr} AS valor_outras_despesas,
            {$valorSeguroExpr} AS valor_seguro,
            {$itemCountExpr} AS items_count,
            {$pedidoExpr} AS pedido_ref,
            {$scopeExpr} AS scope
         FROM {$table} nf
         {$whereStr}
         ORDER BY {$orderExpr} {$orderDir}
         LIMIT {$length} OFFSET {$offset}"
    );
    $stmtData->execute($params);
    $rows = $stmtData->fetchAll(\PDO::FETCH_ASSOC);

    $data = array_map(static function (array $row): array {
        return [
            'nota_key' => (string) ($row['nota_key'] ?? ''),
            'numero_nf' => (string) ($row['numero_nf'] ?? ''),
            'chave_nfe' => trim((string) ($row['chave_nfe'] ?? '')),
            'data_ref' => substr((string) ($row['data_ref'] ?? ''), 0, 10),
            'nome_cliente' => (string) ($row['nome_cliente'] ?? ''),
            'nome_vendedor' => (string) ($row['nome_vendedor'] ?? ''),
            'codigo_cliente_omie' => trim((string) ($row['codigo_cliente_omie'] ?? '')),
            'documento_cliente' => trim((string) ($row['documento_cliente'] ?? '')),
            'codigo_vendedor_omie' => trim((string) ($row['codigo_vendedor_omie'] ?? '')),
            'base_comercial' => (float) ($row['base_comercial'] ?? 0),
            'valor_total' => (float) ($row['valor_total'] ?? 0),
            'valor_mercadorias' => (float) ($row['valor_mercadorias'] ?? 0),
            'valor_desconto' => (float) ($row['valor_desconto'] ?? 0),
            'valor_frete' => (float) ($row['valor_frete'] ?? 0),
            'valor_outras_despesas' => (float) ($row['valor_outras_despesas'] ?? 0),
            'valor_seguro' => (float) ($row['valor_seguro'] ?? 0),
            'items_count' => (int) ($row['items_count'] ?? 0),
            'pedido_ref' => trim((string) ($row['pedido_ref'] ?? '')),
            'scope' => (string) ($row['scope'] ?? 'commercial'),
        ];
    }, $rows);

    Http::json([
        'ok' => true,
        'draw' => $draw,
        'scope' => $scope,
        'recordsTotal' => $recordsTotal,
        'recordsFiltered' => (int) ($filteredRow['cnt'] ?? 0),
        'data' => $data,
        'summary' => [
            'base_comercial_total' => (float) ($filteredRow['base_comercial'] ?? 0),
            'nfs_filtered' => (int) ($filteredRow['cnt'] ?? 0),
            'clientes_filtered' => (int) ($filteredRow['clientes'] ?? 0),
        ],
        // Mesmo recorte comercial do Dashboard (canal + preferência), para a UI
        // saber o que está efetivo e avisar sanitização.
        'commercial_filter' => $commScope->meta($startDate, $endDate, [
            'client' => $cliente !== '' ? $cliente : null,
        ]),
    ]);
} catch (\Throwable $e) {
    Http::json([
        'ok' => false,
        'draw' => $draw,
        'error' => SecretMask::sanitizeErrorMessage($e->getMessage()),
    ], 500);
}
