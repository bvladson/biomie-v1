<?php

/**
 * tools/dev/vendor_360_debug.php — Diagnóstico do drawer Vendor 360.
 *
 * Uso:
 *   php tools/dev/vendor_360_debug.php --vendor=123456 --start=2026-05-01 --end=2026-05-31
 *   php tools/dev/vendor_360_debug.php --vendor=123456 --start=2026-05-01 --end=2026-05-31 --json
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

$opts = getopt('', ['vendor:', 'start:', 'end:', 'json']);
$vendorId = trim((string) ($opts['vendor'] ?? ''));
$start    = trim((string) ($opts['start']  ?? date('Y-m-01')));
$end      = trim((string) ($opts['end']    ?? date('Y-m-t')));
$asJson   = isset($opts['json']);

if ($vendorId === '') {
    fwrite(STDERR, "Uso: php vendor_360_debug.php --vendor=ID --start=YYYY-MM-DD --end=YYYY-MM-DD [--json]\n");
    exit(1);
}

try {
    $configPath = __DIR__ . '/../../config/env.php';
    if (!file_exists($configPath)) {
        throw new RuntimeException('config/env.php nao encontrado.');
    }
    $config = require $configPath;

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $config['db_host'] ?? 'localhost',
        $config['db_port'] ?? '3306',
        $config['db_name'] ?? ''
    );
    $pdo = new PDO($dsn, $config['db_user'] ?? '', $config['db_pass'] ?? '', [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, 'Falha ao conectar: ' . $e->getMessage() . "\n");
    exit(1);
}

function pdoCount(PDO $pdo, string $sql, array $params): int
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    } catch (Throwable) {
        return -1;
    }
}

function pdoVal(PDO $pdo, string $sql, array $params): mixed
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    } catch (Throwable) {
        return null;
    }
}

// ── 1. NFs autorizadas ───────────────────────────────────────────────────────
$nfCount = pdoCount($pdo, <<<SQL
    SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas
    WHERE status_fiscal = 'autorizada'
      AND codigo_vendedor_omie = :vendor_id
      AND data_competencia BETWEEN :start AND :end
SQL, ['vendor_id' => $vendorId, 'start' => $start, 'end' => $end]);

$nfTotal = pdoVal($pdo, <<<SQL
    SELECT COALESCE(SUM(valor_total), 0) FROM biomie_notas_fiscais_canonicas
    WHERE status_fiscal = 'autorizada'
      AND codigo_vendedor_omie = :vendor_id
      AND data_competencia BETWEEN :start AND :end
SQL, ['vendor_id' => $vendorId, 'start' => $start, 'end' => $end]);

// ── 2. Produtos nas NFs ──────────────────────────────────────────────────────
$prodCount = pdoCount($pdo, <<<SQL
    SELECT COUNT(DISTINCT i.codigo_produto)
    FROM biomie_notas_fiscais_itens_canonicos i
    INNER JOIN biomie_notas_fiscais_canonicas n ON n.nota_key = i.nota_key
    WHERE n.status_fiscal = 'autorizada'
      AND n.codigo_vendedor_omie = :vendor_id
      AND n.data_competencia BETWEEN :start AND :end
SQL, ['vendor_id' => $vendorId, 'start' => $start, 'end' => $end]);

// ── 3. Clientes nas NFs ──────────────────────────────────────────────────────
$clientCount = pdoCount($pdo, <<<SQL
    SELECT COUNT(DISTINCT codigo_cliente_omie)
    FROM biomie_notas_fiscais_canonicas
    WHERE status_fiscal = 'autorizada'
      AND codigo_vendedor_omie = :vendor_id
      AND data_competencia BETWEEN :start AND :end
SQL, ['vendor_id' => $vendorId, 'start' => $start, 'end' => $end]);

// ── 4. Pedidos vinculados (bridge, com NF) ───────────────────────────────────
$bridgeLinkedCount = pdoCount($pdo, <<<SQL
    SELECT COUNT(*) FROM mart_bridge_pedido_nfe_titulo
    WHERE codigo_vendedor_omie = :vendor_id
      AND bridge_status NOT IN ('pedido_sem_nf', 'cancelado')
      AND nota_key IS NOT NULL
      AND (
          data_competencia_nfe BETWEEN :start AND :end
          OR data_faturamento  BETWEEN :start AND :end
          OR data_emissao      BETWEEN :start AND :end
      )
SQL, ['vendor_id' => $vendorId, 'start' => $start, 'end' => $end]);

// ── 5. Carteira (bridge, sem NF) ─────────────────────────────────────────────
$walletCount = pdoCount($pdo, <<<SQL
    SELECT COUNT(*) FROM mart_bridge_pedido_nfe_titulo
    WHERE codigo_vendedor_omie = :vendor_id
      AND status_comercial = 'autorizado'
      AND bridge_status = 'pedido_sem_nf'
      AND data_emissao BETWEEN :start AND :end
SQL, ['vendor_id' => $vendorId, 'start' => $start, 'end' => $end]);

$walletTotal = pdoVal($pdo, <<<SQL
    SELECT COALESCE(SUM(valor_pedido), 0) FROM mart_bridge_pedido_nfe_titulo
    WHERE codigo_vendedor_omie = :vendor_id
      AND status_comercial = 'autorizado'
      AND bridge_status = 'pedido_sem_nf'
      AND data_emissao BETWEEN :start AND :end
SQL, ['vendor_id' => $vendorId, 'start' => $start, 'end' => $end]);

// ── 6. Meta do período ───────────────────────────────────────────────────────
$startMonth = substr($start, 0, 7);
$endMonth   = substr($end,   0, 7);
$metaTotal  = pdoVal($pdo, <<<SQL
    SELECT COALESCE(SUM(target_amount), 0)
    FROM biomie_sales_targets_monthly
    WHERE vendor_id = :vendor_id
      AND competencia BETWEEN :start_m AND :end_m
SQL, ['vendor_id' => $vendorId, 'start_m' => $startMonth, 'end_m' => $endMonth]);

// ── 7. Nome do vendedor ──────────────────────────────────────────────────────
$vendorName = pdoVal($pdo, <<<SQL
    SELECT COALESCE(MAX(nome_vendedor), '') FROM biomie_notas_fiscais_canonicas
    WHERE codigo_vendedor_omie = :vendor_id LIMIT 1
SQL, ['vendor_id' => $vendorId]);

if (!$vendorName) {
    $vendorName = pdoVal($pdo, <<<SQL
        SELECT COALESCE(MAX(nome_vendedor), '') FROM biomie_pedidos_canonicos
        WHERE codigo_vendedor_omie = :vendor_id LIMIT 1
    SQL, ['vendor_id' => $vendorId]);
}

// ── 8. Divergência NFs vs api/vendors.php (realizado via pedidos) ────────────
$realizedViaOrders = pdoVal($pdo, <<<SQL
    SELECT COALESCE(SUM(valor_total), 0) FROM biomie_pedidos_canonicos
    WHERE status_comercial = 'faturado'
      AND codigo_vendedor_omie = :vendor_id
      AND data_faturamento BETWEEN :start AND :end
SQL, ['vendor_id' => $vendorId, 'start' => $start, 'end' => $end]);

$realizedViaNfs = $nfTotal;
$divergence     = abs((float) $realizedViaNfs - (float) $realizedViaOrders);

$attainment = $metaTotal > 0 ? round((float) $realizedViaNfs / (float) $metaTotal * 100, 2) : 0;

// ── 9. Divergência bridge vs NFs (Phase 2) ───────────────────────────────────
// linked_orders_bridge deve ser >= nfs_autorizadas (pode ser maior se o pedido
// tiver múltiplas NFs). Se bridge < NFs, o mart não está materializado para o período.
$bridgeVsNfDivergence = $bridgeLinkedCount >= 0 && $nfCount > 0
    ? $nfCount - $bridgeLinkedCount  // positivo = NFs sem pedido vinculado
    : 0;
$bridgeVsNfOk = $bridgeLinkedCount < 0  // tabela indisponível — skip
    || $nfCount === 0                   // sem NFs — ok
    || $bridgeLinkedCount >= $nfCount;  // bridge cobre todas as NFs

$result = [
    'vendor_id'           => $vendorId,
    'vendor_name'         => $vendorName ?: '(nao encontrado)',
    'period'              => ['start' => $start, 'end' => $end],
    // Contadores semânticos (Phase 2)
    'invoices_count'      => $nfCount,          // NFs autorizadas
    'linked_orders_count' => $bridgeLinkedCount, // pedidos vinculados via bridge
    'wallet_orders_count' => $walletCount,       // carteira (autorizado sem NF)
    // Campos originais (backward compat)
    'nfs_autorizadas'     => $nfCount,
    'realizado_nfs'       => (float) $realizedViaNfs,
    'clientes'            => $clientCount,
    'produtos_distintos'  => $prodCount,
    'linked_orders_bridge'=> $bridgeLinkedCount,
    'carteira_bridge'     => $walletCount,
    'carteira_total'      => (float) $walletTotal,
    'meta_periodo'        => (float) $metaTotal,
    'atingimento_pct'     => $attainment,
    'realizado_via_pedidos_faturados' => (float) $realizedViaOrders,
    'divergencia_nfs_vs_pedidos'      => $divergence,
    'divergencia_ok'      => $divergence < 1.0,
    'bridge_table_check'  => $bridgeLinkedCount >= 0 ? 'ok' : 'tabela_indisponivel',
    // Phase 2: divergência bridge vs NFs
    'bridge_vs_nf_divergence' => $bridgeVsNfDivergence,
    'bridge_vs_nf_ok'         => $bridgeVsNfOk,
];

if ($asJson) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

echo "\n";
echo "=== Vendor 360 Debug (Phase 2) ===\n";
printf("Vendedor : %s (%s)\n", $result['vendor_name'], $result['vendor_id']);
printf("Periodo  : %s a %s\n", $start, $end);
echo str_repeat('-', 50) . "\n";

echo "── Contadores semanticos (invoices / linked / wallet) ──\n";
printf("invoices_count      (NFs autorizadas)    : %d\n", $result['invoices_count']);
printf("linked_orders_count (bridge com NF)      : %s\n",
    $result['linked_orders_count'] < 0 ? 'tabela indisponivel' : (string) $result['linked_orders_count']
);
printf("wallet_orders_count (carteira sem NF)    : %d\n", $result['wallet_orders_count']);
$divergLabel = $result['bridge_vs_nf_ok']
    ? 'OK'
    : 'ATENCAO — ' . $result['bridge_vs_nf_divergence'] . ' NF(s) sem pedido vinculado';
printf("bridge vs NFs                            : %s\n", $divergLabel);

echo str_repeat('-', 50) . "\n";
echo "── Realizado ──────────────────────────────────────────\n";
printf("Realizado (NFs)          : R$ %s\n",   number_format($result['realizado_nfs'], 2, ',', '.'));
printf("Realizado (pedidos fat.) : R$ %s\n",   number_format($result['realizado_via_pedidos_faturados'], 2, ',', '.'));
printf("Divergencia NF vs pedido : R$ %s %s\n",
    number_format($result['divergencia_nfs_vs_pedidos'], 2, ',', '.'),
    $result['divergencia_ok'] ? '(OK)' : '(DIVERGENCIA DETECTADA)'
);

echo str_repeat('-', 50) . "\n";
echo "── Composicao ─────────────────────────────────────────\n";
printf("Clientes distintos       : %d\n",      $result['clientes']);
printf("Produtos distintos       : %d\n",      $result['produtos_distintos']);
printf("Carteira total           : R$ %s\n",   number_format($result['carteira_total'], 2, ',', '.'));

echo str_repeat('-', 50) . "\n";
echo "── Metas ──────────────────────────────────────────────\n";
printf("Meta do periodo          : R$ %s\n",   number_format($result['meta_periodo'], 2, ',', '.'));
printf("Atingimento              : %.2f%%\n",  $result['atingimento_pct']);
echo str_repeat('-', 50) . "\n";

if (!$result['bridge_vs_nf_ok'] && $result['linked_orders_count'] >= 0) {
    echo "\nATENCAO [bridge_vs_nf]: " . $result['bridge_vs_nf_divergence'] . " NF(s) sem pedido vinculado.\n";
    echo "  O mart bridge pode estar desatualizado. Verifique:\n";
    echo "  1. Execute: php api/admin_geo_rebuild.php (ou gold_mart_refresh).\n";
    echo "  2. biomie_pedidos_canonicos.codigo_vendedor_omie deve estar sincronizado.\n";
    echo "  3. mart_bridge_pedido_nfe_titulo deve ser rematerializado apos a sincronia.\n";
}

if (!$result['divergencia_ok']) {
    echo "\nATENCAO [nf_vs_pedidos]: divergencia entre NFs e pedidos faturados. Verifique:\n";
    echo "  1. biomie_notas_fiscais_canonicas.codigo_vendedor_omie vs biomie_pedidos_canonicos.codigo_vendedor_omie\n";
    echo "  2. Se todos os pedidos faturados tem NF correspondente sincronizada.\n";
    echo "  3. Se data_competencia (NF) vs data_faturamento (pedido) estao no mesmo periodo.\n";
}

if ($result['linked_orders_bridge'] < 0) {
    echo "\nATENCAO [bridge]: mart_bridge_pedido_nfe_titulo nao disponivel. Execute o mart bridge primeiro.\n";
}

echo "\n";
