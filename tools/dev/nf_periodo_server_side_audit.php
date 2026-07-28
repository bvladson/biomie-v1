<?php

declare(strict_types=1);

/**
 * tools/dev/nf_periodo_server_side_audit.php
 *
 * EST.R1 Fase 0.1 — Auditoria do endpoint paginado server-side de NFs.
 *
 * Testa api/nf_periodo_table.php diretamente (CLI bypass de auth),
 * simulando os parâmetros que o browser envia.
 *
 * Cenários:
 *   - Período anual + scope commercial
 *   - scope=remittance
 *   - scope=licitacao
 *   - busca por NF
 *   - busca por cliente
 *   - ordenação por data_ref
 *   - paginação (offset 25)
 *   - draw counter correto
 *   - JSON DataTables válido em empty state
 *
 * PASS = HTTP 200 + JSON com draw/recordsTotal/recordsFiltered/data
 *
 * Usage:
 *   php tools/dev/nf_periodo_server_side_audit.php --start=2026-01-01 --end=2026-05-29 --pretty
 *
 * Exit: 0=OK 1=falha 2=erro DB
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Config;
use BIomie\Database;
use BIomie\Support\BiVisibilityScope;

$opts      = getopt('', ['start:', 'end:', 'pretty', 'json']);
$startDate = isset($opts['start']) ? trim((string) $opts['start']) : date('Y-01-01');
$endDate   = isset($opts['end'])   ? trim((string) $opts['end'])   : date('Y-m-d');
$isPretty  = isset($opts['pretty']);
$isJson    = isset($opts['json']);

try {
    $config = new Config();
    $conn   = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo    = Database::connect($config, $conn);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (\Throwable $e) {
    echo ($isJson ? json_encode(['error' => $e->getMessage()]) : 'ERRO DB: ' . $e->getMessage()) . "\n";
    exit(2);
}

$visCond = BiVisibilityScope::getCondition($pdo, 'biomie_notas_fiscais_canonicas', 'nf');

// ── Core query function (mirrors nf_periodo_table.php logic) ─────────────────

function runQuery(PDO $pdo, string $visCond, string $start, string $end, array $params): array
{
    $scope     = $params['scope']     ?? 'commercial';
    $length    = min(100, max(1, (int) ($params['length']    ?? 25)));
    $offset    = max(0, (int) ($params['offset'] ?? 0));
    $draw      = max(1, (int) ($params['draw']   ?? 1));
    $search    = trim((string) ($params['search']   ?? ''));
    $orderCol  = $params['order_col'] ?? 'data_ref';
    $orderDir  = (strtolower($params['order_dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
    $hasFrete  = ($params['has_frete']  ?? '') === '1';
    $semPedido = ($params['sem_pedido'] ?? '') === '1';
    $hasOutras = ($params['has_outras'] ?? '') === '1';
    $hasSeguro = ($params['has_seguro'] ?? '') === '1';
    $comDesconto = ($params['com_desconto'] ?? '') === '1';

    $colMap = [
        'data_ref'       => 'COALESCE(nf.data_competencia, nf.data_emissao)',
        'nome_cliente'   => 'nf.nome_cliente',
        'nome_vendedor'  => 'nf.nome_vendedor',
        'base_comercial' => 'COALESCE(nf.valor_base_comercial_nf, nf.valor_mercadorias)',
        'numero_nf'      => 'nf.numero_nf',
    ];
    $orderExpr = $colMap[$orderCol] ?? $colMap['data_ref'];

    $dateExpr   = 'COALESCE(nf.data_competencia, nf.data_emissao)';
    $conditions = ["nf.status_fiscal = 'autorizada'", "{$dateExpr} BETWEEN :start AND :end"];
    $queryParams = [':start' => $start, ':end' => $end];

    match ($scope) {
        'remittance' => $conditions[] = "nf.operation_scope = 'remittance'",
        'licitacao'  => $conditions[] = "nf.business_unit_scope = 'licitacao'",
        'all'        => null,
        default      => array_push($conditions, "nf.operation_scope = 'commercial'", $visCond),
    };

    if ($search !== '') {
        $conditions[] = "(nf.numero_nf LIKE :search1 OR nf.nome_cliente LIKE :search2 OR nf.nome_vendedor LIKE :search3)";
        $sval = '%' . $search . '%';
        $queryParams[':search1'] = $sval;
        $queryParams[':search2'] = $sval;
        $queryParams[':search3'] = $sval;
    }
    if ($hasFrete) $conditions[] = 'nf.valor_frete > 0';
    if ($semPedido) $conditions[] = '(nf.pedido_id_omie IS NULL OR nf.pedido_id_omie = 0)';
    if ($hasOutras) $conditions[] = 'COALESCE(nf.valor_outras_despesas, 0) > 0';
    if ($hasSeguro) $conditions[] = 'COALESCE(nf.valor_seguro, 0) > 0';
    if ($comDesconto) $conditions[] = 'COALESCE(nf.valor_descontos, 0) > 0';

    $whereStr = 'WHERE ' . implode(' AND ', $conditions);

    // Total (no scope)
    $stTotal = $pdo->prepare(
        "SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas nf
         WHERE nf.status_fiscal = 'autorizada' AND {$dateExpr} BETWEEN :start AND :end"
    );
    $stTotal->execute([':start' => $start, ':end' => $end]);
    $recordsTotal = (int) $stTotal->fetchColumn();

    // Filtered
    $stFiltered = $pdo->prepare("SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas nf {$whereStr}");
    $stFiltered->execute($queryParams);
    $recordsFiltered = (int) $stFiltered->fetchColumn();

    // Data
    $stData = $pdo->prepare(
        "SELECT nf.nota_key, nf.numero_nf, {$dateExpr} AS data_ref,
                nf.nome_cliente, nf.nome_vendedor,
                nf.codigo_cliente_omie, nf.documento_cliente, nf.codigo_vendedor_omie,
                COALESCE(nf.valor_base_comercial_nf, nf.valor_mercadorias) AS base_comercial,
                nf.valor_total, nf.valor_frete, nf.valor_outras_despesas, nf.valor_seguro,
                (SELECT COUNT(*)
                   FROM biomie_notas_fiscais_itens_canonicos nfi
                  WHERE nfi.nota_key = nf.nota_key) AS items_count,
                nf.operation_scope AS scope
         FROM biomie_notas_fiscais_canonicas nf
         {$whereStr}
         ORDER BY {$orderExpr} {$orderDir}
         LIMIT {$length} OFFSET {$offset}"
    );
    $stData->execute($queryParams);
    $data = $stData->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    return [
        'draw'            => $draw,
        'recordsTotal'    => $recordsTotal,
        'recordsFiltered' => $recordsFiltered,
        'data'            => $data,
    ];
}

// ── Test scenarios ─────────────────────────────────────────────────────────────

$scenarios = [
    ['name' => 'Período anual scope=commercial', 'params' => ['scope' => 'commercial', 'draw' => '1', 'length' => '25', 'offset' => '0']],
    ['name' => 'scope=remittance', 'params' => ['scope' => 'remittance', 'draw' => '2', 'length' => '25', 'offset' => '0']],
    ['name' => 'scope=licitacao', 'params' => ['scope' => 'licitacao', 'draw' => '9', 'length' => '25', 'offset' => '0']],
    ['name' => 'scope=all (todas)', 'params' => ['scope' => 'all', 'draw' => '3', 'length' => '25', 'offset' => '0']],
    ['name' => 'Busca por NF "omie:886"', 'params' => ['scope' => 'commercial', 'draw' => '4', 'length' => '25', 'offset' => '0', 'search' => 'omie:886']],
    ['name' => 'Ordenação nome_cliente ASC', 'params' => ['scope' => 'commercial', 'draw' => '5', 'length' => '10', 'offset' => '0', 'order_col' => 'nome_cliente', 'order_dir' => 'asc']],
    ['name' => 'Paginação offset=25', 'params' => ['scope' => 'commercial', 'draw' => '6', 'length' => '25', 'offset' => '25']],
    ['name' => 'Filtro sem_pedido=1', 'params' => ['scope' => 'commercial', 'draw' => '7', 'length' => '25', 'offset' => '0', 'sem_pedido' => '1']],
    ['name' => 'Filtro com_desconto=1', 'params' => ['scope' => 'commercial', 'draw' => '10', 'length' => '25', 'offset' => '0', 'com_desconto' => '1']],
    ['name' => 'Empty state: busca impossível', 'params' => ['scope' => 'commercial', 'draw' => '8', 'length' => '25', 'offset' => '0', 'search' => 'XXXXXXXXXXXXXXXXXXX_NO_MATCH']],
];

$results = [];
$passed  = 0;
$failed  = 0;

foreach ($scenarios as $s) {
    try {
        $r = runQuery($pdo, $visCond, $startDate, $endDate, $s['params']);

        // Validate DataTables response structure
        $hasKeys = isset($r['draw'], $r['recordsTotal'], $r['recordsFiltered'], $r['data']);
        $drawOk  = (int) $r['draw'] === (int) ($s['params']['draw'] ?? 1);
        $dataArr = is_array($r['data']);
        $dataLen = count($r['data']);
        $detailKeys = [
            'codigo_cliente_omie',
            'documento_cliente',
            'codigo_vendedor_omie',
            'valor_total',
            'valor_frete',
            'valor_outras_despesas',
            'valor_seguro',
            'items_count',
        ];
        $detailContractOk = $dataLen === 0
            || array_diff($detailKeys, array_keys($r['data'][0])) === [];
        $pass = $hasKeys && $drawOk && $dataArr && $detailContractOk;

        if ($pass) $passed++; else $failed++;

        $results[] = [
            'name'            => $s['name'],
            'pass'            => $pass,
            'draw'            => $r['draw'],
            'recordsTotal'    => $r['recordsTotal'],
            'recordsFiltered' => $r['recordsFiltered'],
            'rows_returned'   => $dataLen,
            'error'           => $pass ? null : 'Estrutura DataTables inválida',
        ];
    } catch (\Throwable $e) {
        $failed++;
        $results[] = [
            'name'  => $s['name'],
            'pass'  => false,
            'error' => $e->getMessage(),
        ];
    }
}

$output = [
    'tool'    => 'nf_periodo_server_side_audit',
    'period'  => ['start' => $startDate, 'end' => $endDate],
    'summary' => ['passed' => $passed, 'failed' => $failed, 'total' => count($results)],
    'results' => $results,
];

if ($isJson) {
    echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit($failed > 0 ? 1 : 0);
}

$bar = str_repeat('═', 70);
$sep = str_repeat('─', 70);
echo "\n{$bar}\n NF Período — Auditoria Server-Side Endpoint\n{$bar}\n";
echo " Período: {$startDate} → {$endDate}\n{$sep}\n\n";

foreach ($results as $r) {
    $icon = $r['pass'] ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m";
    printf(" [%s] %s\n", $icon, $r['name']);
    if (isset($r['recordsTotal'])) {
        printf("       draw=%d | total=%d | filtered=%d | rows=%d\n",
            $r['draw'] ?? 0, $r['recordsTotal'] ?? 0, $r['recordsFiltered'] ?? 0, $r['rows_returned'] ?? 0
        );
    }
    if (!$r['pass'] && isset($r['error'])) {
        printf("       \033[31m→ %s\033[0m\n", $r['error']);
    }
}

echo "\n{$sep}\n";
printf(" %s  —  %d/%d checks\n",
    $failed === 0 ? "\033[32m✔ PASS\033[0m" : "\033[31m✗ FAIL\033[0m",
    $passed, count($results)
);
echo "{$bar}\n\n";

exit($failed > 0 ? 1 : 0);
