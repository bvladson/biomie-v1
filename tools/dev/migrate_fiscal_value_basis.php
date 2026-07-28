<?php

declare(strict_types=1);

/**
 * Fase O.1 — Migração de schema: base de cálculo fiscal (migrate_fiscal_value_basis.php)
 *
 * Adiciona 11 colunas + 4 índices a biomie_notas_fiscais_canonicas:
 *
 *   Componentes fiscais (Fase O — 4 colunas):
 *     valor_ipi, valor_frete, valor_outras_despesas, valor_seguro
 *
 *   Base pré-computada + flags (Fase O.1 — 7 colunas):
 *     valor_base_comercial_nf, has_frete, has_outras_despesas, has_seguro,
 *     has_componentes_nao_comissionaveis, fiscal_basis_source, fiscal_basis_checked_at
 *
 *   Índices (Fase O.1 — 4 índices):
 *     idx_nf_operation_scope_competencia, idx_nf_fiscal_basis_checked,
 *     idx_nf_has_frete, idx_nf_has_non_commissionable
 *
 * Idempotente: ignora colunas e índices que já existem.
 * Não altera dados — use backfill_fiscal_value_basis.php para popular.
 *
 * Uso:
 *   php tools/dev/migrate_fiscal_value_basis.php --status
 *   php tools/dev/migrate_fiscal_value_basis.php --apply
 */

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$opts    = getopt('', ['status', 'apply']);
$doApply = isset($opts['apply']);
$doStatus = isset($opts['status']) || !$doApply;

$config = Config::fromEnvironment();
$conn   = \BIomie\Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo    = \BIomie\Database::connect($config, $conn);
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

$table = 'biomie_notas_fiscais_canonicas';

// ── Column definitions in logical order ──────────────────────────────────────

$columns = [
    // Fase O — raw fiscal components from Omie ICMSTot
    'valor_ipi'                          => ["DECIMAL(15,2) DEFAULT NULL COMMENT 'Fase O: ICMSTot.vIPI'",              'valor_descontos'],
    'valor_frete'                        => ["DECIMAL(15,2) DEFAULT NULL COMMENT 'Fase O: ICMSTot.vFrete'",            'valor_ipi'],
    'valor_outras_despesas'              => ["DECIMAL(15,2) DEFAULT NULL COMMENT 'Fase O: ICMSTot.vOutro'",            'valor_frete'],
    'valor_seguro'                       => ["DECIMAL(15,2) DEFAULT NULL COMMENT 'Fase O: ICMSTot.vSeg'",             'valor_outras_despesas'],
    // Fase O.1 — pre-computed base and boolean flags
    'valor_base_comercial_nf'            => ["DECIMAL(15,2) DEFAULT NULL COMMENT 'Fase O.1: valor_total - frete - outras - seguro'", 'valor_seguro'],
    'has_frete'                          => ["TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Fase O.1: frete > 0'",           'valor_base_comercial_nf'],
    'has_outras_despesas'                => ["TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Fase O.1: outras_despesas > 0'", 'has_frete'],
    'has_seguro'                         => ["TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Fase O.1: seguro > 0'",          'has_outras_despesas'],
    'has_componentes_nao_comissionaveis' => ["TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Fase O.1: has_frete OR has_outras OR has_seguro'", 'has_seguro'],
    'fiscal_basis_source'                => ["VARCHAR(80) DEFAULT NULL COMMENT 'Fase O.1: origem do backfill'",       'has_componentes_nao_comissionaveis'],
    'fiscal_basis_checked_at'            => ["DATETIME DEFAULT NULL COMMENT 'Fase O.1: timestamp do backfill'",       'fiscal_basis_source'],
];

// ── Index definitions ─────────────────────────────────────────────────────────

$indices = [
    'idx_nf_operation_scope_competencia' => 'CREATE INDEX idx_nf_operation_scope_competencia ON `' . $table . '` (operation_scope, data_competencia)',
    'idx_nf_fiscal_basis_checked'        => 'CREATE INDEX idx_nf_fiscal_basis_checked ON `' . $table . '` (fiscal_basis_checked_at)',
    'idx_nf_has_frete'                   => 'CREATE INDEX idx_nf_has_frete ON `' . $table . '` (has_frete)',
    'idx_nf_has_non_commissionable'      => 'CREATE INDEX idx_nf_has_non_commissionable ON `' . $table . '` (has_componentes_nao_comissionaveis)',
];

// ── Helper functions ──────────────────────────────────────────────────────────

function mColExists(\PDO $pdo, string $table, string $col): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
    );
    $stmt->execute(['t' => $table, 'c' => $col]);
    return (int) $stmt->fetchColumn() > 0;
}

function mIdxExists(\PDO $pdo, string $table, string $indexName): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :i'
    );
    $stmt->execute(['t' => $table, 'i' => $indexName]);
    return (int) $stmt->fetchColumn() > 0;
}

// ── Status mode ───────────────────────────────────────────────────────────────

echo "\n\033[1mMigração Fase O.1 — Base Fiscal Comercial\033[0m\n";
echo "Tabela: $table\n";
echo "Modo  : " . ($doApply ? "\033[33mapply\033[0m" : "\033[36mstatus\033[0m") . "\n\n";

// Column inventory
echo "--- Colunas ---\n";
$missingCols  = [];
$existingCols = [];
foreach ($columns as $col => [$def, $after]) {
    $exists = mColExists($pdo, $table, $col);
    $icon   = $exists ? "\033[32m✔\033[0m" : "\033[31m✘\033[0m";
    echo "  $icon $col\n";
    if ($exists) {
        $existingCols[] = $col;
    } else {
        $missingCols[] = $col;
    }
}

// Index inventory
echo "\n--- Índices ---\n";
$missingIdxs  = [];
$existingIdxs = [];
foreach ($indices as $idxName => $ddl) {
    // Check if dependent column exists
    $depCol = match(true) {
        str_contains($idxName, 'fiscal_basis_checked') => 'fiscal_basis_checked_at',
        str_contains($idxName, 'has_frete')            => 'has_frete',
        str_contains($idxName, 'non_commissionable')   => 'has_componentes_nao_comissionaveis',
        default                                        => 'operation_scope',
    };
    $colReady = mColExists($pdo, $table, $depCol);
    $idxReady = mIdxExists($pdo, $table, $idxName);

    if ($idxReady) {
        echo "  \033[32m✔\033[0m $idxName\n";
        $existingIdxs[] = $idxName;
    } elseif (!$colReady) {
        echo "  \033[33m○\033[0m $idxName — aguardando coluna $depCol\n";
        $missingIdxs[] = $idxName;
    } else {
        echo "  \033[31m✘\033[0m $idxName — coluna presente, índice ausente\n";
        $missingIdxs[] = $idxName;
    }
}

// Backfill status
echo "\n--- Backfill status ---\n";
$checkedAtExists = mColExists($pdo, $table, 'fiscal_basis_checked_at');
if ($checkedAtExists) {
    $stmtPending = $pdo->query(
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN fiscal_basis_checked_at IS NULL THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN fiscal_basis_checked_at IS NOT NULL THEN 1 ELSE 0 END) AS done
         FROM $table
         WHERE status_fiscal NOT IN ('cancelada', 'denegada')"
    );
    $bfRow = $stmtPending->fetch(\PDO::FETCH_ASSOC);
    $total   = (int) $bfRow['total'];
    $pending = (int) $bfRow['pending'];
    $done    = (int) $bfRow['done'];
    $pct     = $total > 0 ? round($done / $total * 100, 1) : 0.0;
    echo "  Total NFs ativas : $total\n";
    echo "  Com backfill     : $done ({$pct}%)\n";
    echo "  Pendentes        : $pending\n";
    if ($pending > 0) {
        echo "  \033[33m→ Execute: php tools/dev/backfill_fiscal_value_basis.php --apply --start=... --end=...\033[0m\n";
    }
} else {
    echo "  \033[33m○\033[0m fiscal_basis_checked_at ausente — execute --apply primeiro\n";
}

if (!$doApply) {
    $totalMissing = count($missingCols) + count($missingIdxs);
    echo "\n--- Resumo ---\n";
    echo "  Colunas  — presentes: " . count($existingCols) . "/" . count($columns) . "\n";
    echo "  Índices  — presentes: " . count($existingIdxs) . "/" . count($indices) . "\n";
    if ($totalMissing > 0) {
        echo "  \033[33m→ Execute --apply para adicionar $totalMissing item(s) ausente(s).\033[0m\n";
    } else {
        echo "  \033[32m✔ Schema completamente atualizado.\033[0m\n";
    }
    echo "\n";
    exit(0);
}

// ── Apply mode ────────────────────────────────────────────────────────────────

echo "\n--- Aplicando migração ---\n";

$added   = 0;
$skipped = 0;
$errors  = [];

foreach ($columns as $col => [$def, $after]) {
    if (mColExists($pdo, $table, $col)) {
        echo "  \033[32m✔\033[0m $col — já existe\n";
        $skipped++;
        continue;
    }

    $afterClause = '';
    if ($after !== null && mColExists($pdo, $table, $after)) {
        $afterClause = " AFTER `$after`";
    }

    $ddl = "ALTER TABLE `$table` ADD COLUMN `$col` $def{$afterClause}";
    try {
        $pdo->exec($ddl);
        echo "  \033[32m+\033[0m $col — adicionada\n";
        $added++;
    } catch (\Throwable $e) {
        echo "  \033[31m✘\033[0m $col — ERRO: " . $e->getMessage() . "\n";
        $errors[] = $col;
    }
}

echo "\n--- Criando índices ---\n";
$idxAdded   = 0;
$idxSkipped = 0;

foreach ($indices as $idxName => $ddl) {
    if (mIdxExists($pdo, $table, $idxName)) {
        echo "  \033[32m✔\033[0m $idxName — já existe\n";
        $idxSkipped++;
        continue;
    }

    // Re-check dependent column now (may have been added above)
    $depCol = match(true) {
        str_contains($idxName, 'fiscal_basis_checked') => 'fiscal_basis_checked_at',
        str_contains($idxName, 'has_frete')            => 'has_frete',
        str_contains($idxName, 'non_commissionable')   => 'has_componentes_nao_comissionaveis',
        default                                        => 'operation_scope',
    };

    if (!mColExists($pdo, $table, $depCol)) {
        echo "  \033[33m○\033[0m $idxName — ignorado (coluna $depCol ausente)\n";
        $idxSkipped++;
        continue;
    }

    try {
        $pdo->exec($ddl);
        echo "  \033[32m+\033[0m $idxName — criado\n";
        $idxAdded++;
    } catch (\Throwable $e) {
        echo "  \033[31m✘\033[0m $idxName — ERRO: " . $e->getMessage() . "\n";
        $errors[] = $idxName;
    }
}

echo "\n--- Resultado ---\n";
echo "  Colunas adicionadas : $added\n";
echo "  Colunas já existiam : $skipped\n";
echo "  Índices criados     : $idxAdded\n";
echo "  Índices já existiam : $idxSkipped\n";

if ($errors !== []) {
    echo "  \033[31mErros: " . implode(', ', $errors) . "\033[0m\n";
}

if ($errors !== []) {
    echo "\n\033[31m✘ Migração com erros. Corrija e re-execute.\033[0m\n\n";
    exit(1);
}

echo "\n\033[32m✔ Migração Fase O.1 concluída.\033[0m\n";
echo "\nPróximos passos:\n";
echo "  1. Backfill histórico  : php tools/dev/backfill_fiscal_value_basis.php --status\n";
echo "  2. Dry-run             : php tools/dev/backfill_fiscal_value_basis.php --dry-run --start=2026-05-01 --end=2026-05-31\n";
echo "  3. Aplicar             : php tools/dev/backfill_fiscal_value_basis.php --apply --start=2026-05-01 --end=2026-05-31\n";
echo "  4. Validar             : php tools/dev/fiscal_value_basis_audit.php --start=2026-05-01 --end=2026-05-31\n";
echo "\n";
exit(0);
