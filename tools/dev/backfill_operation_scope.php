<?php

declare(strict_types=1);

/**
 * Fase H — H3: Migration + Backfill de operation_scope + lineage
 *
 * Colunas adicionadas:
 *   omie_operation_name       VARCHAR(120) NULL
 *   operation_scope           VARCHAR(32)  NOT NULL DEFAULT 'unknown'
 *   operation_scope_source    VARCHAR(120) NULL       (de onde veio a classificação)
 *   operation_scope_confidence VARCHAR(32) NULL       (high/medium/low/unknown)
 *   operation_scope_rule      VARCHAR(120) NULL       (qual regra casou)
 *   operation_scope_checked_at DATETIME   NULL
 *
 * Tabelas alvo:
 *   biomie_notas_fiscais_canonicas
 *   biomie_pedidos_canonicos
 *   biomie_notas_fiscais_itens_canonicos (somente colunas de lineage)
 *
 * Uso:
 *   php tools/dev/backfill_operation_scope.php --dry-run
 *   php tools/dev/backfill_operation_scope.php --migrate
 *   php tools/dev/backfill_operation_scope.php --apply
 *   php tools/dev/backfill_operation_scope.php --status
 *   php tools/dev/backfill_operation_scope.php --apply --json
 */

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\Support\OmieOperationScope;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

// ── Args ───────────────────────────────────────────────────────────────────────

$opts    = getopt('', ['dry-run', 'migrate', 'apply', 'status', 'json', 'batch:', 'pretty']);
$dry     = !isset($opts['apply']) && !isset($opts['migrate']) && !isset($opts['status']);
$migrate = isset($opts['apply']) || isset($opts['migrate']);
$apply   = isset($opts['apply']);
$status  = isset($opts['status']);
$isJson  = isset($opts['json']);
$batch   = max(100, (int) ($opts['batch'] ?? 500));

// ── DB ─────────────────────────────────────────────────────────────────────────

$config     = Config::fromEnvironment();
$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
if (!Database::isConfigured($config, $connection)) {
    fwrite(STDERR, "ERRO: Banco nao configurado.\n");
    exit(1);
}
$pdo = Database::connect($config, $connection);
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

// Load DB rules (silently, won't break if table absent)
OmieOperationScope::loadRulesFromDb($pdo);

// ── Helpers ────────────────────────────────────────────────────────────────────

function bOut(mixed $data, bool $json): void
{
    if ($json) {
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    } elseif (is_string($data)) {
        echo $data . "\n";
    } else {
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    }
}

function bColExists(\PDO $pdo, string $table, string $col): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $stmt->execute([$table, $col]);
    return (int) $stmt->fetchColumn() > 0;
}

function bIdxExists(\PDO $pdo, string $table, string $idx): bool
{
    return (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table' AND INDEX_NAME = '$idx'"
    )->fetchColumn() > 0;
}

// ── Status ─────────────────────────────────────────────────────────────────────

if ($status) {
    $report = ['rules_source' => OmieOperationScope::rulesSource(), 'tables' => []];

    foreach (['biomie_notas_fiscais_canonicas', 'biomie_pedidos_canonicos'] as $tbl) {
        $hasScope     = bColExists($pdo, $tbl, 'operation_scope');
        $hasName      = bColExists($pdo, $tbl, 'omie_operation_name');
        $hasLineage   = bColExists($pdo, $tbl, 'operation_scope_source');

        $dist = $lineageDist = [];
        if ($hasScope) {
            $rows = $pdo->query("SELECT operation_scope, COUNT(*) n FROM `$tbl` GROUP BY operation_scope ORDER BY n DESC")->fetchAll(\PDO::FETCH_ASSOC);
            $dist = array_column($rows, 'n', 'operation_scope');
        }
        if ($hasLineage) {
            $rows = $pdo->query("SELECT operation_scope_confidence, COUNT(*) n FROM `$tbl` GROUP BY operation_scope_confidence ORDER BY n DESC")->fetchAll(\PDO::FETCH_ASSOC);
            $lineageDist = array_column($rows, 'n', 'operation_scope_confidence');
        }

        $report['tables'][$tbl] = [
            'scope_col'        => $hasScope   ? 'ok' : 'missing',
            'name_col'         => $hasName    ? 'ok' : 'missing',
            'lineage_cols'     => $hasLineage ? 'ok' : 'missing',
            'scope_dist'       => $dist,
            'confidence_dist'  => $lineageDist,
        ];
    }

    bOut($report, $isJson);
    exit(0);
}

// ── Build DDL ──────────────────────────────────────────────────────────────────

$ddls = [];
$log  = [];

$coreColDefs = [
    // [table, column, definition, after]
    ['biomie_notas_fiscais_canonicas',      'omie_operation_name',         "VARCHAR(120) NULL DEFAULT NULL",                             'fonte_sync'],
    ['biomie_notas_fiscais_canonicas',      'operation_scope',             "VARCHAR(32) NOT NULL DEFAULT 'unknown'",                    'omie_operation_name'],
    ['biomie_notas_fiscais_canonicas',      'operation_scope_source',      "VARCHAR(120) NULL DEFAULT NULL",                             'operation_scope'],
    ['biomie_notas_fiscais_canonicas',      'operation_scope_confidence',  "VARCHAR(32) NULL DEFAULT NULL",                              'operation_scope_source'],
    ['biomie_notas_fiscais_canonicas',      'operation_scope_rule',        "VARCHAR(120) NULL DEFAULT NULL",                             'operation_scope_confidence'],
    ['biomie_notas_fiscais_canonicas',      'operation_scope_checked_at',  "DATETIME NULL DEFAULT NULL",                                 'operation_scope_rule'],
    ['biomie_pedidos_canonicos',            'omie_operation_name',         "VARCHAR(120) NULL DEFAULT NULL",                             'fonte_sync'],
    ['biomie_pedidos_canonicos',            'operation_scope',             "VARCHAR(32) NOT NULL DEFAULT 'unknown'",                    'omie_operation_name'],
    ['biomie_pedidos_canonicos',            'operation_scope_source',      "VARCHAR(120) NULL DEFAULT NULL",                             'operation_scope'],
    ['biomie_pedidos_canonicos',            'operation_scope_confidence',  "VARCHAR(32) NULL DEFAULT NULL",                              'operation_scope_source'],
    ['biomie_pedidos_canonicos',            'operation_scope_rule',        "VARCHAR(120) NULL DEFAULT NULL",                             'operation_scope_confidence'],
    ['biomie_pedidos_canonicos',            'operation_scope_checked_at',  "DATETIME NULL DEFAULT NULL",                                 'operation_scope_rule'],
    ['biomie_notas_fiscais_itens_canonicos','operation_scope_source',      "VARCHAR(120) NULL DEFAULT NULL",                             'valor_desconto'],
    ['biomie_notas_fiscais_itens_canonicos','operation_scope_confidence',  "VARCHAR(32) NULL DEFAULT NULL",                              'operation_scope_source'],
    ['biomie_notas_fiscais_itens_canonicos','operation_scope_rule',        "VARCHAR(120) NULL DEFAULT NULL",                             'operation_scope_confidence'],
    ['biomie_notas_fiscais_itens_canonicos','operation_scope_checked_at',  "DATETIME NULL DEFAULT NULL",                                 'operation_scope_rule'],
];

foreach ($coreColDefs as [$tbl, $col, $def, $after]) {
    if (!bColExists($pdo, $tbl, $col)) {
        $ddls[] = "ALTER TABLE `$tbl` ADD COLUMN `$col` $def AFTER `$after`";
        $log[]  = "ADD $col to $tbl";
    }
}

// Indexes
$indexes = [
    ['biomie_notas_fiscais_canonicas', 'idx_nfc_scope',      "CREATE INDEX `idx_nfc_scope`      ON `biomie_notas_fiscais_canonicas` (`operation_scope`)"],
    ['biomie_notas_fiscais_canonicas', 'idx_nfc_op_name',    "CREATE INDEX `idx_nfc_op_name`    ON `biomie_notas_fiscais_canonicas` (`omie_operation_name`(32))"],
    ['biomie_notas_fiscais_canonicas', 'idx_nfc_confidence', "CREATE INDEX `idx_nfc_confidence` ON `biomie_notas_fiscais_canonicas` (`operation_scope_confidence`)"],
    ['biomie_pedidos_canonicos',       'idx_pc_scope',       "CREATE INDEX `idx_pc_scope`       ON `biomie_pedidos_canonicos` (`operation_scope`)"],
    ['biomie_pedidos_canonicos',       'idx_pc_op_name',     "CREATE INDEX `idx_pc_op_name`     ON `biomie_pedidos_canonicos` (`omie_operation_name`(32))"],
];

foreach ($indexes as [$tbl, $idxName, $ddl]) {
    if (!bIdxExists($pdo, $tbl, $idxName)) {
        $ddls[] = $ddl;
        $log[]  = "CREATE INDEX $idxName on $tbl";
    }
}

// ── Dry run ────────────────────────────────────────────────────────────────────

if ($dry) {
    echo "\n=== Fase H — Backfill operation_scope (DRY RUN) ===\n\n";
    echo "Rules source: " . OmieOperationScope::rulesSource() . "\n\n";

    if (empty($ddls)) {
        echo "DDL: Todas as colunas e índices já existem.\n";
    } else {
        echo "DDL pendente (" . count($ddls) . " instruções):\n";
        foreach ($log as $m) {
            echo "  + $m\n";
        }
    }
    echo "\nExecute --apply para aplicar migration + backfill.\n\n";
    exit(0);
}

// ── Apply DDL ──────────────────────────────────────────────────────────────────

if ($migrate && !empty($ddls)) {
    bOut("Aplicando " . count($ddls) . " instruções DDL...", false);
    foreach ($ddls as $ddl) {
        $pdo->exec($ddl);
    }
    bOut("DDL concluído.", false);
}

if (!$apply) {
    exit(0);
}

// ── Verify columns exist ───────────────────────────────────────────────────────

if (!bColExists($pdo, 'biomie_notas_fiscais_canonicas', 'operation_scope')) {
    fwrite(STDERR, "ERRO: Colunas ausentes. Execute --migrate primeiro.\n");
    exit(1);
}

$hasLineage = bColExists($pdo, 'biomie_notas_fiscais_canonicas', 'operation_scope_source');
$now        = date('Y-m-d H:i:s');

$result = [
    'rules_source'        => OmieOperationScope::rulesSource(),
    'has_lineage_cols'    => $hasLineage,
    'bronze_nf_rows'      => 0,
    'bronze_order_rows'   => 0,
    'nf_classified'       => 0,
    'nf_unknown'          => 0,
    'order_classified'    => 0,
    'order_unknown'       => 0,
    'items_lineage_filled' => 0,
];

// ── Check bronze ───────────────────────────────────────────────────────────────

$hasBronze = false;
try {
    $hasBronze = (bool) $pdo->query("SELECT COUNT(*) FROM biomie_omie_bronze LIMIT 1")->fetchColumn();
} catch (\Throwable) {}

if (!$hasBronze) {
    bOut("AVISO: Bronze layer vazio. Nenhum backfill possível sem dados bronze.", false);
}

// ── Backfill NFs ───────────────────────────────────────────────────────────────

if ($hasBronze) {
    $stmt = $pdo->query(
        "SELECT provider_key, payload_json FROM biomie_omie_bronze
         WHERE domain_name = 'invoices' AND entity_name = 'invoice'
         ORDER BY ingested_at DESC"
    );

    $nfMap = [];
    while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
        $result['bronze_nf_rows']++;
        $payload = json_decode((string) $row['payload_json'], true);
        if (!is_array($payload)) {
            continue;
        }

        $noteIdOmie = (int) $row['provider_key'];
        // Fallback: some sync paths leave provider_key NULL — extract from payload
        if ($noteIdOmie <= 0) {
            $noteIdOmie = (int) ($payload['compl']['nIdNF'] ?? 0);
        }
        if ($noteIdOmie <= 0) {
            continue;
        }

        $cls = OmieOperationScope::classifyInvoice($payload);
        $nfMap[$noteIdOmie] = $cls;
    }

    foreach (array_chunk(array_keys($nfMap), $batch) as $chunk) {
        foreach ($chunk as $id) {
            $cls = $nfMap[$id];

            if ($hasLineage) {
                $pdo->prepare(
                    "UPDATE biomie_notas_fiscais_canonicas
                     SET omie_operation_name = ?,
                         operation_scope = ?,
                         operation_scope_source = ?,
                         operation_scope_confidence = ?,
                         operation_scope_rule = ?,
                         operation_scope_checked_at = ?
                     WHERE nota_id_omie = ? AND operation_scope = 'unknown'"
                )->execute([
                    $cls['raw_value'] ?: null,
                    $cls['scope'],
                    $cls['source'],
                    $cls['confidence'],
                    $cls['rule'],
                    $now,
                    $id,
                ]);
            } else {
                $pdo->prepare(
                    "UPDATE biomie_notas_fiscais_canonicas
                     SET omie_operation_name = ?, operation_scope = ?
                     WHERE nota_id_omie = ? AND operation_scope = 'unknown'"
                )->execute([$cls['raw_value'] ?: null, $cls['scope'], $id]);
            }

            if ($cls['scope'] !== OmieOperationScope::UNKNOWN) {
                $result['nf_classified']++;
            } else {
                $result['nf_unknown']++;
            }
        }
    }

    // ── Backfill itens (lineage only — items inherit scope from NF) ────────────

    if ($hasLineage && bColExists($pdo, 'biomie_notas_fiscais_itens_canonicos', 'operation_scope_source')) {
        $updated = $pdo->exec(
            "UPDATE biomie_notas_fiscais_itens_canonicos i
             INNER JOIN biomie_notas_fiscais_canonicas nf USING (nota_key)
             SET i.operation_scope_source = nf.operation_scope_source,
                 i.operation_scope_confidence = nf.operation_scope_confidence,
                 i.operation_scope_rule = nf.operation_scope_rule,
                 i.operation_scope_checked_at = '$now'
             WHERE i.operation_scope_source IS NULL AND nf.operation_scope_source IS NOT NULL"
        );
        $result['items_lineage_filled'] = (int) $updated;
    }

    // ── Backfill orders ────────────────────────────────────────────────────────

    $stmt = $pdo->query(
        "SELECT provider_key, payload_json FROM biomie_omie_bronze
         WHERE domain_name = 'orders' AND entity_name = 'order'
         ORDER BY ingested_at DESC"
    );

    $ordMap = [];
    while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
        $result['bronze_order_rows']++;
        $payload = json_decode((string) $row['payload_json'], true);
        if (!is_array($payload)) {
            continue;
        }

        $cab   = is_array($payload['cabecalho'] ?? null) ? $payload['cabecalho'] : [];
        $ordId = (int) ($cab['codigo_pedido'] ?? 0);
        if ($ordId <= 0) {
            continue;
        }

        $cls = OmieOperationScope::classifyOrder($payload);
        $ordMap[$ordId] = $cls;
    }

    foreach (array_chunk(array_keys($ordMap), $batch) as $chunk) {
        foreach ($chunk as $id) {
            $cls = $ordMap[$id];

            if ($hasLineage) {
                $pdo->prepare(
                    "UPDATE biomie_pedidos_canonicos
                     SET omie_operation_name = ?,
                         operation_scope = ?,
                         operation_scope_source = ?,
                         operation_scope_confidence = ?,
                         operation_scope_rule = ?,
                         operation_scope_checked_at = ?
                     WHERE pedido_id_omie = ? AND operation_scope = 'unknown'"
                )->execute([
                    $cls['raw_value'] ?: null,
                    $cls['scope'],
                    $cls['source'],
                    $cls['confidence'],
                    $cls['rule'],
                    $now,
                    $id,
                ]);
            } else {
                $pdo->prepare(
                    "UPDATE biomie_pedidos_canonicos
                     SET omie_operation_name = ?, operation_scope = ?
                     WHERE pedido_id_omie = ? AND operation_scope = 'unknown'"
                )->execute([$cls['raw_value'] ?: null, $cls['scope'], $id]);
            }

            if ($cls['scope'] !== OmieOperationScope::UNKNOWN) {
                $result['order_classified']++;
            } else {
                $result['order_unknown']++;
            }
        }
    }

    // ── Propagate NF scope to linked orders (no nome_tipo_atividade accounts) ──
    // Orders whose scope stayed 'unknown' but have a linked commercial NF inherit scope.
    if (bColExists($pdo, 'biomie_notas_fiscais_canonicas', 'operation_scope')
        && bColExists($pdo, 'biomie_pedidos_canonicos', 'operation_scope')) {
        $propagated = $pdo->exec(
            "UPDATE biomie_pedidos_canonicos p
             INNER JOIN biomie_notas_fiscais_canonicas nf ON nf.pedido_id_omie = p.pedido_id_omie
             SET p.operation_scope             = nf.operation_scope,
                 p.omie_operation_name         = nf.omie_operation_name,
                 p.operation_scope_source      = 'nf_propagation',
                 p.operation_scope_confidence  = 'low',
                 p.operation_scope_rule        = 'nf_link',
                 p.operation_scope_checked_at  = '$now'
             WHERE p.operation_scope = 'unknown'
               AND nf.operation_scope != 'unknown'
               AND nf.pedido_id_omie > 0"
        );
        $result['order_propagated_from_nf'] = (int) $propagated;

        // Fallback: faturado orders with unknown scope and no remittance NF linked → commercial.
        // Handles accounts where canonical NF/order tables have different time-window coverage.
        $propagatedFaturado = $pdo->exec(
            "UPDATE biomie_pedidos_canonicos p
             LEFT JOIN biomie_notas_fiscais_canonicas nf_rem
                 ON nf_rem.pedido_id_omie = p.pedido_id_omie
                 AND nf_rem.operation_scope = 'remittance'
             SET p.operation_scope             = 'commercial',
                 p.operation_scope_source      = 'status_faturado_fallback',
                 p.operation_scope_confidence  = 'low',
                 p.operation_scope_rule        = 'faturado_default_commercial',
                 p.operation_scope_checked_at  = '$now'
             WHERE p.operation_scope = 'unknown'
               AND p.status_comercial = 'faturado'
               AND nf_rem.id IS NULL"
        );
        $result['order_propagated_faturado_fallback'] = (int) $propagatedFaturado;
    }
}

// ── Final distributions ────────────────────────────────────────────────────────

$result['nf_scope_dist'] = array_column(
    $pdo->query("SELECT operation_scope, COUNT(*) n FROM biomie_notas_fiscais_canonicas GROUP BY operation_scope")->fetchAll(\PDO::FETCH_ASSOC),
    'n', 'operation_scope'
);
$result['order_scope_dist'] = array_column(
    $pdo->query("SELECT operation_scope, COUNT(*) n FROM biomie_pedidos_canonicos GROUP BY operation_scope")->fetchAll(\PDO::FETCH_ASSOC),
    'n', 'operation_scope'
);
if ($hasLineage) {
    $result['nf_confidence_dist'] = array_column(
        $pdo->query("SELECT operation_scope_confidence, COUNT(*) n FROM biomie_notas_fiscais_canonicas GROUP BY operation_scope_confidence")->fetchAll(\PDO::FETCH_ASSOC),
        'n', 'operation_scope_confidence'
    );
}

bOut("\n=== Backfill concluído ===", $isJson);
bOut($result, $isJson);

if (!$isJson) {
    $unknownNf = (int) ($result['nf_scope_dist']['unknown'] ?? 0);
    if ($unknownNf > 0) {
        echo "\nATENCAO: $unknownNf NFs ainda com scope='unknown'.\n";
        echo "1. Rode operation_scope_debug.php --raw para ver natOp reais.\n";
        echo "2. Adicione padroes em OmieOperationScope::COMMERCIAL_NAMES / REMITTANCE_NAMES.\n";
        echo "3. Ou insira regras em biomie_operation_scope_rules e rode --apply novamente.\n\n";
    } else {
        echo "\nOK: Todas as NFs classificadas.\n\n";
    }
}
