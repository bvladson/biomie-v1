<?php

declare(strict_types=1);

/**
 * Fase H.3 — T5: Re-classificação de escopo por proveniência de origem (CFOP-priority).
 *
 * Diferença crítica do backfill_operation_scope.php original:
 *   - Atualiza TODAS as NFs (não apenas as com scope='unknown')
 *   - Remove classificações incorretas finNFe=1→commercial
 *   - Usa nova cadeia: natOp → finNFe=4 → CFOP → finNFe=1=unknown → unknown
 *
 * Para pedidos: propaga o scope da NF vinculada (já que nome_tipo_atividade=null nesta conta).
 *
 * Uso:
 *   php tools/dev/backfill_operation_origin_scope.php --dry-run
 *   php tools/dev/backfill_operation_origin_scope.php --apply
 *   php tools/dev/backfill_operation_origin_scope.php --apply --json
 *   php tools/dev/backfill_operation_origin_scope.php --dry-run --limit=50
 */

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\Support\OmieOperationScope;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$opts     = getopt('', ['dry-run', 'apply', 'json', 'limit:']);
$isDry    = !isset($opts['apply']);
$isJson   = isset($opts['json']);
$limit    = isset($opts['limit']) ? max(1, (int) $opts['limit']) : 0;

// ── DB ─────────────────────────────────────────────────────────────────────────

$config     = Config::fromEnvironment();
$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
if (!Database::isConfigured($config, $connection)) {
    fwrite(STDERR, "ERRO: Banco nao configurado.\n");
    exit(2);
}
$pdo = Database::connect($config, $connection);
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

OmieOperationScope::loadRulesFromDb($pdo);

// ── Build bronze map: nota_id_omie → classification ───────────────────────────

$bronzeMap = []; // int nota_id_omie → classifyInvoice() result

$sqlBronze = "
    SELECT
        CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.compl.nIdNF')) AS UNSIGNED) AS nota_id_omie,
        payload_json
    FROM biomie_omie_bronze
    WHERE domain_name = 'invoices'
      AND JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.compl.nIdNF')) IS NOT NULL
    ORDER BY ingested_at DESC
";
$stmtBronze = $pdo->query($sqlBronze);

while ($row = $stmtBronze->fetch(\PDO::FETCH_ASSOC)) {
    $id = (int) $row['nota_id_omie'];
    if ($id <= 0 || isset($bronzeMap[$id])) {
        continue; // keep most recent ingested version
    }
    $payload = json_decode((string) $row['payload_json'], true);
    if (!is_array($payload)) {
        continue;
    }
    $bronzeMap[$id] = OmieOperationScope::classifyInvoice($payload);
}

// ── Fetch all canonical NFs ────────────────────────────────────────────────────

$sqlNfs = "
    SELECT nota_id_omie, nota_key, numero_nf,
           operation_scope, operation_scope_rule, operation_scope_confidence
    FROM biomie_notas_fiscais_canonicas
    WHERE status_fiscal = 'autorizada'
      AND (operation_scope_rule IS NULL OR operation_scope_rule NOT LIKE 'manual_override%')
    ORDER BY nota_id_omie
";
if ($limit > 0) {
    $sqlNfs .= " LIMIT $limit";
}

$canonNfs = $pdo->query($sqlNfs)->fetchAll(\PDO::FETCH_ASSOC);

// ── Compute transitions ────────────────────────────────────────────────────────

$now = date('Y-m-d H:i:s');

$stats = [
    'total'        => count($canonNfs),
    'in_bronze'    => 0,
    'not_in_bronze'=> 0,
    'changed'      => 0,
    'unchanged'    => 0,
    'transitions'  => [],
    'by_new_scope' => [],
    'by_new_rule'  => [],
];

$changes = []; // list of {nota_id_omie, old_scope, new_cls}

foreach ($canonNfs as $nf) {
    $id     = (int) $nf['nota_id_omie'];
    $oldScope = (string) $nf['operation_scope'];

    if (!isset($bronzeMap[$id])) {
        $stats['not_in_bronze']++;
        // No bronze data: keep existing scope as-is (no update)
        continue;
    }

    $stats['in_bronze']++;
    $newCls   = $bronzeMap[$id];
    $newScope = $newCls['scope'];
    $newRule  = $newCls['rule'];

    $transKey = "$oldScope→$newScope";
    $stats['transitions'][$transKey] = ($stats['transitions'][$transKey] ?? 0) + 1;
    $stats['by_new_scope'][$newScope] = ($stats['by_new_scope'][$newScope] ?? 0) + 1;
    $stats['by_new_rule'][$newRule]   = ($stats['by_new_rule'][$newRule]   ?? 0) + 1;

    if ($newScope !== $oldScope) {
        $stats['changed']++;
        $changes[] = [
            'nota_id_omie' => $id,
            'nota_key'     => $nf['nota_key'],
            'numero_nf'    => $nf['numero_nf'],
            'old_scope'    => $oldScope,
            'old_rule'     => $nf['operation_scope_rule'],
            'new_scope'    => $newScope,
            'new_rule'     => $newRule,
            'new_conf'     => $newCls['confidence'],
            'new_source'   => $newCls['source'],
            'raw_value'    => $newCls['raw_value'],
        ];
    } else {
        $stats['unchanged']++;
    }
}

// ── Apply ──────────────────────────────────────────────────────────────────────

$updateStmt = $pdo->prepare("
    UPDATE biomie_notas_fiscais_canonicas
    SET operation_scope             = ?,
        operation_scope_source      = ?,
        operation_scope_confidence  = ?,
        operation_scope_rule        = ?,
        omie_operation_name         = ?,
        operation_scope_checked_at  = ?
    WHERE nota_id_omie = ?
");

if (!$isDry) {
    foreach ($changes as $c) {
        $newCls = $bronzeMap[$c['nota_id_omie']];
        $updateStmt->execute([
            $newCls['scope'],
            $newCls['source'],
            $newCls['confidence'],
            $newCls['rule'],
            $newCls['raw_value'] ?: null,
            $now,
            $c['nota_id_omie'],
        ]);
    }

    // Propagate updated NF scope to linked orders (nome_tipo_atividade=null account)
    $propagated = $pdo->exec("
        UPDATE biomie_pedidos_canonicos p
        INNER JOIN biomie_notas_fiscais_canonicas nf ON nf.pedido_id_omie = p.pedido_id_omie
        SET p.operation_scope             = nf.operation_scope,
            p.omie_operation_name         = nf.omie_operation_name,
            p.operation_scope_source      = 'nf_propagation',
            p.operation_scope_confidence  = 'low',
            p.operation_scope_rule        = 'nf_link',
            p.operation_scope_checked_at  = '$now'
        WHERE nf.operation_scope != 'unknown'
          AND nf.pedido_id_omie > 0
    ");
    $stats['orders_propagated'] = (int) $propagated;
}

// ── Output ─────────────────────────────────────────────────────────────────────

if ($isJson) {
    echo json_encode([
        'mode'    => $isDry ? 'dry-run' : 'apply',
        'stats'   => $stats,
        'changes' => $changes,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

$mode = $isDry ? '[DRY-RUN]' : '[APPLY]';
echo "\n=== backfill_operation_origin_scope (Fase H.3) $mode ===\n";
echo "Rules source: " . OmieOperationScope::rulesSource() . "\n\n";

echo "ESTATÍSTICAS:\n";
printf("  Total NFs canonical:   %d\n", $stats['total']);
printf("  Encontradas no bronze: %d\n", $stats['in_bronze']);
printf("  Sem bronze:            %d\n", $stats['not_in_bronze']);
printf("  Com mudança de scope:  %d\n", $stats['changed']);
printf("  Sem mudança:           %d\n", $stats['unchanged']);
if (!$isDry) {
    printf("  Pedidos propagados:    %d\n", $stats['orders_propagated'] ?? 0);
}

if ($stats['transitions'] !== []) {
    echo "\n  Transições de scope:\n";
    arsort($stats['transitions']);
    foreach ($stats['transitions'] as $tr => $n) {
        $marker = str_contains($tr, '→') && explode('→', $tr)[0] !== explode('→', $tr)[1] ? '  <<< MUDOU' : '';
        printf("    %-30s  %d%s\n", $tr, $n, $marker);
    }
}

if ($stats['by_new_scope'] !== []) {
    echo "\n  Novo escopo após recalibração:\n";
    arsort($stats['by_new_scope']);
    foreach ($stats['by_new_scope'] as $s => $n) {
        printf("    %-15s  %d\n", $s, $n);
    }
}

if ($changes !== []) {
    echo "\n  Detalhes das NFs que mudam:\n";
    printf("  %-12s %-8s %-15s %-15s %-30s\n", 'nota_key', 'numero_nf', 'old_scope', 'new_scope', 'new_rule');
    foreach ($changes as $c) {
        printf("  %-12s %-8s %-15s %-15s %-30s\n",
            substr((string) $c['nota_key'], 0, 12),
            $c['numero_nf'],
            $c['old_scope'],
            $c['new_scope'],
            substr((string) $c['new_rule'], 0, 30),
        );
    }
}

if ($isDry && $stats['changed'] > 0) {
    echo "\nExecute --apply para aplicar as alterações.\n";
} elseif (!$isDry) {
    echo "\nOK: backfill aplicado.\n";
    echo "Próximo passo: executar commercial_recalibration_audit.php para verificar marts.\n";
}

echo "\n";
