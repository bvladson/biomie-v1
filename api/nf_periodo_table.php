<?php

declare(strict_types=1);

/**
 * api/nf_periodo_table.php — COM.R3 Fase 5
 *
 * Endpoint paginado server-side para a tabela de NFs do período.
 * Formato compatível com DataTables server-side processing.
 *
 * Parâmetros:
 *   start, end       — período (obrigatório)
 *   draw             — counter DataTables (passado de volta)
 *   offset           — registro inicial (default 0)
 *   length           — registros por página (default 25, max 200)
 *   search           — busca textual (NF, cliente, vendedor, pedido)
 *   order_col        — coluna para ordenação (whitelist abaixo)
 *   order_dir        — asc|desc (default desc)
 *   scope            — commercial|remittance|all (default commercial)
 *   vendedor         — filtro por nome de vendedor (LIKE)
 *   cliente          — filtro por nome de cliente (LIKE)
 *   has_frete        — 1 = apenas NFs com frete
 *   sem_pedido       — 1 = apenas NFs sem pedido vinculado
 *
 * Response:
 *   {draw, recordsTotal, recordsFiltered, data[], summary{base_comercial_total, nfs_filtered, clientes_filtered}}
 *
 * Permission: sales.view
 */

require __DIR__ . '/bootstrap.php';
\BIomie\Auth::requireCapability('sales.view', true);

use BIomie\Config;
use BIomie\Database;
use BIomie\Http;
use BIomie\Support\BiVisibilityScope;
use BIomie\Support\SecretMask;

header('Content-Type: application/json; charset=utf-8');

// ── DB ────────────────────────────────────────────────────────────────────────

try {
    $config = new Config();
    $conn   = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo    = Database::connect($config, $conn);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => SecretMask::sanitizeErrorMessage($e->getMessage())], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Params ────────────────────────────────────────────────────────────────────

$range     = Http::resolveDateRange();
[$startDate, $endDate] = [$range['start'], $range['end']];
$draw      = max(1, (int) Http::query('draw', '1'));
$offset    = max(0, (int) Http::query('offset', '0'));
$length    = min(100, max(1, (int) Http::query('length', '25')));
$search    = trim(Http::query('search', ''));
$orderCol  = Http::query('order_col', 'data_ref');
$orderDir  = strtolower(Http::query('order_dir', 'desc')) === 'asc' ? 'ASC' : 'DESC';
$scopeRaw  = Http::query('scope', 'commercial');
$vendedor  = trim(Http::query('vendedor', ''));
$cliente   = trim(Http::query('cliente', ''));
$hasFrete   = Http::query('has_frete',    '') === '1';
$semPedido  = Http::query('sem_pedido',  '') === '1';
$hasOutras  = Http::query('has_outras',  '') === '1';
$hasSeguro  = Http::query('has_seguro',  '') === '1';
$comDesconto = Http::query('com_desconto', '') === '1';

// Column whitelist for ORDER BY
$colMap = [
    'data_ref'       => 'COALESCE(nf.data_competencia, nf.data_emissao)',
    'nome_cliente'   => 'nf.nome_cliente',
    'nome_vendedor'  => 'nf.nome_vendedor',
    'base_comercial' => 'COALESCE(nf.valor_base_comercial_nf, nf.valor_mercadorias)',
    'numero_nf'      => 'nf.numero_nf',
];
$orderExpr = $colMap[$orderCol] ?? $colMap['data_ref'];

// ── Build WHERE ───────────────────────────────────────────────────────────────

$visCond = BiVisibilityScope::getCondition($pdo, 'biomie_notas_fiscais_canonicas', 'nf');
$dateExpr = 'COALESCE(nf.data_competencia, nf.data_emissao)';

$conditions = ["nf.status_fiscal = 'autorizada'", "{$dateExpr} BETWEEN :start AND :end"];
$params = [':start' => $startDate, ':end' => $endDate];

// Scope filter
match ($scopeRaw) {
    'remittance' => $conditions[] = "nf.operation_scope = 'remittance'",
    'all'        => null,
    default      => array_push($conditions, "nf.operation_scope = 'commercial'", $visCond),
};

// Text search
if ($search !== '') {
    $conditions[] = "(nf.numero_nf LIKE :search1 OR nf.nome_cliente LIKE :search2 OR nf.nome_vendedor LIKE :search3)";
    $sval = '%' . $search . '%';
    $params[':search1'] = $sval;
    $params[':search2'] = $sval;
    $params[':search3'] = $sval;
}

// Column filters
if ($vendedor !== '') {
    $conditions[] = 'nf.nome_vendedor LIKE :vendedor';
    $params[':vendedor'] = '%' . $vendedor . '%';
}
if ($cliente !== '') {
    $conditions[] = 'nf.nome_cliente LIKE :cliente';
    $params[':cliente'] = '%' . $cliente . '%';
}
if ($hasFrete)    $conditions[] = 'COALESCE(nf.valor_frete, 0) > 0';
if ($semPedido)   $conditions[] = '(nf.pedido_id_omie IS NULL OR nf.pedido_id_omie = 0)';
if ($hasOutras)   $conditions[] = 'COALESCE(nf.valor_outras_despesas, 0) > 0';
if ($hasSeguro)   $conditions[] = 'COALESCE(nf.valor_seguro, 0) > 0';
if ($comDesconto) $conditions[] = 'COALESCE(nf.valor_desconto, 0) > 0';

$whereStr = 'WHERE ' . implode("\n  AND ", $conditions);

// ── Count total ───────────────────────────────────────────────────────────────

try {
    // Total (no scope filter)
    $stmtTotal = $pdo->prepare(
        "SELECT COUNT(*)
         FROM biomie_notas_fiscais_canonicas nf
         WHERE nf.status_fiscal = 'autorizada'
           AND {$dateExpr} BETWEEN :start AND :end"
    );
    $stmtTotal->execute([':start' => $startDate, ':end' => $endDate]);
    $recordsTotal = (int) $stmtTotal->fetchColumn();

    // Filtered count + summary
    $stmtFiltered = $pdo->prepare(
        "SELECT COUNT(*) AS cnt,
                COALESCE(SUM(COALESCE(nf.valor_base_comercial_nf, nf.valor_mercadorias)), 0) AS base_comercial,
                COUNT(DISTINCT nf.codigo_cliente_omie) AS clientes
         FROM biomie_notas_fiscais_canonicas nf
         {$whereStr}"
    );
    $stmtFiltered->execute($params);
    $filtRow = $stmtFiltered->fetch(\PDO::FETCH_ASSOC) ?: [];
    $recordsFiltered  = (int)($filtRow['cnt'] ?? 0);
    $baseTotalFiltered = (float)($filtRow['base_comercial'] ?? 0);
    $clientesFiltered  = (int)($filtRow['clientes'] ?? 0);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => SecretMask::sanitizeErrorMessage($e->getMessage())], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Data page ─────────────────────────────────────────────────────────────────

$data = [];
try {
    $stmtData = $pdo->prepare(
        "SELECT
            nf.nota_key,
            nf.numero_nf,
            {$dateExpr} AS data_ref,
            nf.nome_cliente,
            nf.nome_vendedor,
            COALESCE(nf.valor_base_comercial_nf, nf.valor_mercadorias) AS base_comercial,
            nf.valor_mercadorias,
            nf.valor_desconto,
            nf.pedido_id_omie,
            nf.operation_scope AS scope
         FROM biomie_notas_fiscais_canonicas nf
         {$whereStr}
         ORDER BY {$orderExpr} {$orderDir}
         LIMIT {$length} OFFSET {$offset}"
    );
    $stmtData->execute($params);
    $rows = $stmtData->fetchAll(\PDO::FETCH_ASSOC);

    $data = array_map(function (array $row): array {
        $scope = (string)($row['scope'] ?? 'commercial');
        $scopeBadge = match ($scope) {
            'remittance' => '<span class="scope-badge scope-badge--remessa">Remessa</span>',
            default      => '<span class="scope-badge scope-badge--orc-pd">Orç-PD</span>',
        };

        return [
            'nota_key'       => (string)($row['nota_key'] ?? ''),
            'numero_nf'      => (string)($row['numero_nf'] ?? ''),
            'data_ref'       => substr((string)($row['data_ref'] ?? ''), 0, 10),
            'nome_cliente'   => (string)($row['nome_cliente'] ?? ''),
            'nome_vendedor'  => (string)($row['nome_vendedor'] ?? ''),
            'base_comercial' => (float)($row['base_comercial'] ?? 0),
            'valor_mercadorias' => (float)($row['valor_mercadorias'] ?? 0),
            'valor_desconto' => (float)($row['valor_desconto'] ?? 0),
            'pedido_ref'     => $row['pedido_id_omie'] > 0 ? (string)$row['pedido_id_omie'] : '',
            'scope'          => $scope,
            'scope_badge'    => $scopeBadge,
        ];
    }, $rows);
} catch (\Throwable $e) {
    $data = [];
}

// ── Response ──────────────────────────────────────────────────────────────────

echo json_encode([
    'draw'            => $draw,
    'recordsTotal'    => $recordsTotal,
    'recordsFiltered' => $recordsFiltered,
    'data'            => $data,
    'summary' => [
        'base_comercial_total' => $baseTotalFiltered,
        'nfs_filtered'         => $recordsFiltered,
        'clientes_filtered'    => $clientesFiltered,
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
