<?php

declare(strict_types=1);

/**
 * Fase H.4 — T2: Override manual de escopo para NFs 00001285 e 00001286.
 *
 * Diagnóstico confirmado:
 *   - CFOP 6.116 está na lista COMMERCIAL_CFOPS, mas para a MAG Móveis
 *     esse CFOP é usado exclusivamente para faturamento de remessa de produto.
 *   - natOp = null (não disponível para esta conta).
 *   - Pedidos vinculados (8862538088, 8862544549) não estão na tabela
 *     biomie_pedidos_canonicos (remessa, não sincronizada como pedido canônico).
 *   - omie_operation_name = "1" (valor bruto finNFe, não um nome de operação).
 *
 * Solução: override manual protegido com rule='manual_override_remittance_cfop6116'.
 * O backfill_operation_origin_scope.php pula NFs com esse rule (guarda NOT LIKE 'manual_override%').
 *
 * Uso:
 *   php tools/dev/fix_nf1285_1286_remittance.php --dry-run
 *   php tools/dev/fix_nf1285_1286_remittance.php --apply
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

$opts  = getopt('', ['dry-run', 'apply']);
$isDry = !isset($opts['apply']);

$config     = \BIomie\Config::fromEnvironment();
$connection = \BIomie\Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo        = \BIomie\Database::connect($config, $connection);
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

$targetNfs = ['00001285', '00001286'];

echo "\n=== fix_nf1285_1286_remittance " . ($isDry ? '[DRY-RUN]' : '[APPLY]') . " ===\n\n";

// ── Show current state ──────────────────────────────────────────────────────

$in = implode(',', array_fill(0, count($targetNfs), '?'));
$rows = $pdo->prepare("
    SELECT nota_key, numero_nf, serie_nf,
           operation_scope, operation_scope_rule, operation_scope_confidence,
           omie_operation_name, valor_total
    FROM biomie_notas_fiscais_canonicas
    WHERE numero_nf IN ($in)
    ORDER BY numero_nf
");
$rows->execute($targetNfs);
$before = $rows->fetchAll(\PDO::FETCH_ASSOC);

echo "Estado ANTES:\n";
foreach ($before as $r) {
    printf(
        "  NF %-9s serie=%-3s scope=%-12s rule=%-30s conf=%-6s valor=%s op_name=%s\n",
        $r['numero_nf'],
        $r['serie_nf'] ?? '?',
        $r['operation_scope'],
        $r['operation_scope_rule'] ?? '(null)',
        $r['operation_scope_confidence'] ?? '(null)',
        $r['valor_total'],
        $r['omie_operation_name'] ?? '(null)',
    );
}

if (!$before) {
    echo "  ERRO: NFs não encontradas na tabela canonicas.\n\n";
    exit(1);
}

// ── Apply override ──────────────────────────────────────────────────────────

$now = date('Y-m-d H:i:s');

if (!$isDry) {
    $stmt = $pdo->prepare("
        UPDATE biomie_notas_fiscais_canonicas
        SET operation_scope             = 'remittance',
            operation_scope_source      = 'manual_override',
            operation_scope_confidence  = 'high',
            operation_scope_rule        = 'manual_override_remittance_cfop6116',
            omie_operation_name         = 'Remessa de Produto',
            operation_scope_checked_at  = ?
        WHERE numero_nf = ?
          AND (operation_scope_rule IS NULL OR operation_scope_rule NOT LIKE 'manual_override%' OR operation_scope = 'commercial')
    ");

    $updated = 0;
    foreach ($targetNfs as $nf) {
        $stmt->execute([$now, $nf]);
        $updated += $stmt->rowCount();
    }

    echo "\nLinhas atualizadas: $updated\n";
}

// ── Show new state ──────────────────────────────────────────────────────────

if (!$isDry) {
    $rows->execute($targetNfs);
    $after = $rows->fetchAll(\PDO::FETCH_ASSOC);

    echo "\nEstado DEPOIS:\n";
    foreach ($after as $r) {
        printf(
            "  NF %-9s serie=%-3s scope=%-12s rule=%-40s conf=%-6s op_name=%s\n",
            $r['numero_nf'],
            $r['serie_nf'] ?? '?',
            $r['operation_scope'],
            $r['operation_scope_rule'] ?? '(null)',
            $r['operation_scope_confidence'] ?? '(null)',
            $r['omie_operation_name'] ?? '(null)',
        );
    }

    echo "\nOK: Override manual aplicado.\n";
    echo "Próximos passos:\n";
    echo "  1. php tools/dev/commercial_recalibration_audit.php\n";
    echo "  2. Rematerializar marts (GeoRebuildPeriod ou GeoMaterialize)\n";
} else {
    echo "\n[DRY-RUN] Nenhuma alteração aplicada.\n";
    echo "Execute --apply para aplicar o override.\n";
    echo "\nO que será feito para cada NF acima:\n";
    echo "  operation_scope             = remittance\n";
    echo "  operation_scope_source      = manual_override\n";
    echo "  operation_scope_confidence  = high\n";
    echo "  operation_scope_rule        = manual_override_remittance_cfop6116\n";
    echo "  omie_operation_name         = Remessa de Produto\n";
    echo "  operation_scope_checked_at  = NOW()\n";
}

echo "\n";
