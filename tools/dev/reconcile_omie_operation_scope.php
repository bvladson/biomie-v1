<?php

declare(strict_types=1);

/**
 * Fase H — H9: Reconciliação de escopo de operação Omie
 *
 * Verifica consistência entre bronze layer e tabelas canônicas:
 * - NFs canônicas sem operation_scope no bronze
 * - Pedidos canônicos sem operation_scope no bronze
 * - Divergências de natOp entre bronze e canônico
 * - NFs com operation_scope='unknown' pós-backfill
 *
 * Uso:
 *   php tools/dev/reconcile_omie_operation_scope.php
 *   php tools/dev/reconcile_omie_operation_scope.php --json
 *   php tools/dev/reconcile_omie_operation_scope.php --fix-unknown   (re-aplica backfill nos unknown)
 */

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\Support\OmieOperationScope;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$opts       = getopt('', ['json', 'fix-unknown', 'limit:']);
$isJson     = isset($opts['json']);
$fixUnknown = isset($opts['fix-unknown']);
$limit      = max(10, (int) ($opts['limit'] ?? 200));

$config     = Config::fromEnvironment();
$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
if (!Database::isConfigured($config, $connection)) {
    fwrite(STDERR, "ERRO: Banco nao configurado.\n");
    exit(1);
}

$pdo = Database::connect($config, $connection);
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

function colExists(\PDO $pdo, string $tbl, string $col): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $stmt->execute([$tbl, $col]);
    return (int)$stmt->fetchColumn() > 0;
}

// ── Pré-cheque ─────────────────────────────────────────────────────────────────

$hasScopeNf  = colExists($pdo, 'biomie_notas_fiscais_canonicas', 'operation_scope');
$hasScopeOrd = colExists($pdo, 'biomie_pedidos_canonicos', 'operation_scope');

if (!$hasScopeNf || !$hasScopeOrd) {
    $msg = ['error' => 'Colunas operation_scope ausentes. Execute backfill_operation_scope.php --migrate primeiro.'];
    if ($isJson) {
        echo json_encode($msg, JSON_PRETTY_PRINT) . "\n";
    } else {
        echo "ERRO: " . $msg['error'] . "\n";
    }
    exit(1);
}

// ── Coleta de dados ─────────────────────────────────────────────────────────────

$report = [
    'generated_at' => date('c'),
    'nf'           => [],
    'orders'       => [],
    'bronze'       => [],
    'fix_applied'  => false,
];

// NFs — distribuição de escopo
$nfDist = $pdo->query(
    "SELECT operation_scope, COUNT(*) as n FROM biomie_notas_fiscais_canonicas GROUP BY operation_scope ORDER BY n DESC"
)->fetchAll(\PDO::FETCH_ASSOC);

$report['nf']['scope_distribution'] = array_column($nfDist, 'n', 'operation_scope');

// NFs unknown amostradas
$unknownNfRows = $pdo->query(
    "SELECT nota_key, nota_id_omie, numero_nf, omie_operation_name, data_emissao, valor_total
     FROM biomie_notas_fiscais_canonicas
     WHERE operation_scope = 'unknown'
     LIMIT $limit"
)->fetchAll(\PDO::FETCH_ASSOC);

$report['nf']['unknown_sample'] = $unknownNfRows;
$report['nf']['unknown_total']  = (int) $pdo->query(
    "SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas WHERE operation_scope = 'unknown'"
)->fetchColumn();

// Pedidos — distribuição
$ordDist = $pdo->query(
    "SELECT operation_scope, COUNT(*) as n FROM biomie_pedidos_canonicos GROUP BY operation_scope ORDER BY n DESC"
)->fetchAll(\PDO::FETCH_ASSOC);

$report['orders']['scope_distribution'] = array_column($ordDist, 'n', 'operation_scope');
$report['orders']['unknown_total']      = (int) $pdo->query(
    "SELECT COUNT(*) FROM biomie_pedidos_canonicos WHERE operation_scope = 'unknown'"
)->fetchColumn();

// Bronze — cobertura
$hasBronze = false;
try {
    $hasBronze = (bool) $pdo->query("SELECT COUNT(*) FROM biomie_omie_bronze LIMIT 1")->fetchColumn();
} catch (\Throwable) {}

if ($hasBronze) {
    $bronzeNfCount  = (int) $pdo->query("SELECT COUNT(*) FROM biomie_omie_bronze WHERE domain_name='invoices'")->fetchColumn();
    $bronzeOrdCount = (int) $pdo->query("SELECT COUNT(*) FROM biomie_omie_bronze WHERE domain_name='orders'")->fetchColumn();

    // NFs no canônico sem correspondência no bronze
    $nfNotInBronze = (int) $pdo->query(
        "SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas nfc
         LEFT JOIN biomie_omie_bronze b ON b.domain_name='invoices' AND b.provider_key = CAST(nfc.nota_id_omie AS CHAR)
         WHERE b.id IS NULL AND nfc.nota_id_omie IS NOT NULL"
    )->fetchColumn();

    $report['bronze'] = [
        'nf_records'        => $bronzeNfCount,
        'order_records'     => $bronzeOrdCount,
        'nf_not_in_bronze'  => $nfNotInBronze,
    ];
} else {
    $report['bronze'] = ['available' => false];
}

// ── Fix-unknown mode ────────────────────────────────────────────────────────────

if ($fixUnknown && $hasBronze && $report['nf']['unknown_total'] > 0) {
    $bronzeRows = $pdo->query(
        "SELECT provider_key, payload_json FROM biomie_omie_bronze WHERE domain_name='invoices' ORDER BY ingested_at DESC"
    )->fetchAll(\PDO::FETCH_ASSOC);

    $fixed = 0;
    foreach ($bronzeRows as $row) {
        $noteIdOmie = (int) $row['provider_key'];
        if ($noteIdOmie <= 0) {
            continue;
        }

        $payload = json_decode((string) $row['payload_json'], true);
        if (!is_array($payload)) {
            continue;
        }

        $scope = OmieOperationScope::scopeForInvoice($payload);
        if ($scope === OmieOperationScope::UNKNOWN) {
            continue;
        }

        $natOp = trim((string) ($payload['ide']['natOp'] ?? ''));
        $stmt  = $pdo->prepare(
            "UPDATE biomie_notas_fiscais_canonicas
             SET omie_operation_name = ?, operation_scope = ?
             WHERE nota_id_omie = ? AND operation_scope = 'unknown'"
        );
        $stmt->execute([$natOp ?: null, $scope, $noteIdOmie]);
        $fixed += $stmt->rowCount();
    }

    $report['fix_applied']   = true;
    $report['fixed_count']   = $fixed;
    $report['unknown_after'] = (int) $pdo->query(
        "SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas WHERE operation_scope='unknown'"
    )->fetchColumn();
}

// ── Saída ───────────────────────────────────────────────────────────────────────

if ($isJson) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

echo "\n=== Fase H — Reconciliação de Escopo de Operação ===\n\n";

echo "NFs:\n";
foreach ($report['nf']['scope_distribution'] as $scope => $cnt) {
    echo "  $scope: $cnt\n";
}
echo "  Total unknown: {$report['nf']['unknown_total']}\n\n";

echo "Pedidos:\n";
foreach ($report['orders']['scope_distribution'] as $scope => $cnt) {
    echo "  $scope: $cnt\n";
}
echo "  Total unknown: {$report['orders']['unknown_total']}\n\n";

if (isset($report['bronze']['nf_records'])) {
    echo "Bronze:\n";
    echo "  NF records: {$report['bronze']['nf_records']}\n";
    echo "  Order records: {$report['bronze']['order_records']}\n";
    echo "  NFs canônicas sem bronze: {$report['bronze']['nf_not_in_bronze']}\n\n";
}

if ($report['fix_applied']) {
    echo "Fix aplicado: {$report['fixed_count']} NFs corrigidas.\n";
    echo "Unknown restantes: {$report['unknown_after']}\n\n";
}

if ($report['nf']['unknown_total'] > 0 && !$fixUnknown) {
    echo "ATENCAO: {$report['nf']['unknown_total']} NFs com operation_scope=unknown.\n";
    echo "Opcoes:\n";
    echo "  1. Execute --fix-unknown para re-aplicar backfill.\n";
    echo "  2. Rode operation_scope_debug.php --raw para ver campos disponíveis.\n";
    echo "  3. Atualize OmieOperationScope::COMMERCIAL_NAMES ou REMITTANCE_NAMES.\n\n";
}

if ($report['nf']['unknown_total'] === 0) {
    echo "OK: Todas as NFs com escopo classificado.\n\n";
}
