<?php

declare(strict_types=1);

/**
 * Fase H — T3: Setup da tabela biomie_operation_scope_rules
 *
 * Cria a tabela, semeia regras iniciais e permite gestão via CLI.
 *
 * Uso:
 *   php tools/dev/operation_scope_rules_setup.php --create   (cria tabela)
 *   php tools/dev/operation_scope_rules_setup.php --seed     (insere/atualiza seeds)
 *   php tools/dev/operation_scope_rules_setup.php --list     (lista regras ativas)
 *   php tools/dev/operation_scope_rules_setup.php --json     (saída JSON)
 *   php tools/dev/operation_scope_rules_setup.php --create --seed
 */

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$opts   = getopt('', ['create', 'seed', 'list', 'json', 'deactivate:']);
$create = isset($opts['create']);
$seed   = isset($opts['seed']);
$list   = isset($opts['list']) || (!$create && !$seed && !isset($opts['deactivate']));
$isJson = isset($opts['json']);

$config     = Config::fromEnvironment();
$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
if (!Database::isConfigured($config, $connection)) {
    fwrite(STDERR, "ERRO: Banco nao configurado.\n");
    exit(1);
}
$pdo = Database::connect($config, $connection);
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

// ── Create table ───────────────────────────────────────────────────────────────

if ($create) {
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS biomie_operation_scope_rules (
            id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
            raw_pattern         VARCHAR(160) NOT NULL,
            normalized_pattern  VARCHAR(160) NOT NULL,
            scope               VARCHAR(32)  NOT NULL,
            source_type         VARCHAR(32)  NOT NULL DEFAULT 'any',
            match_type          VARCHAR(32)  NOT NULL DEFAULT 'contains',
            priority            INT          NOT NULL DEFAULT 100,
            is_active           TINYINT(1)   NOT NULL DEFAULT 1,
            notes               VARCHAR(255)          DEFAULT NULL,
            created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_scope_rule_pattern (normalized_pattern, source_type),
            KEY idx_scope_rules_active (is_active, priority),
            KEY idx_scope_rules_scope (scope)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
          COMMENT='Regras configuráveis de classificação de escopo de operação Omie'
    SQL);
    echo "Tabela biomie_operation_scope_rules criada/verificada.\n";
}

// ── Seed rules ─────────────────────────────────────────────────────────────────

if ($seed) {
    // Seeds: [raw_pattern, normalized_pattern, scope, source_type, priority, notes]
    $seeds = [
        // ── Commercial (Orçamento - PD) ───────────────────────────────────
        ['Orçamento - PD',           'orçamento - pd',       'commercial', 'any', 10, 'Operação padrão de venda MAG'],
        ['Orcamento - PD',           'orcamento - pd',       'commercial', 'any', 11, 'Variante sem acento'],
        ['ORÇAMENTO - PD',           'orçamento - pd',       'commercial', 'any', 12, 'Variante maiúscula (deduplicada por normalized_pattern)'],
        ['Orçamento PD',             'orçamento pd',         'commercial', 'any', 15, 'Variante sem traço'],
        ['Orcamento PD',             'orcamento pd',         'commercial', 'any', 16, ''],
        ['Orçamento-PD',             'orçamento-pd',         'commercial', 'any', 17, ''],
        ['Orcamento-PD',             'orcamento-pd',         'commercial', 'any', 18, ''],
        ['VENDA DE MERCADORIAS',     'venda de mercadorias', 'commercial', 'nf', 20, 'natOp padrão NF-e de venda'],
        ['Venda de Mercadorias',     'venda de mercadorias', 'commercial', 'nf', 20, 'Deduplicado'],
        ['VENDA',                    'venda',                'commercial', 'any', 50, 'Padrão genérico de venda'],
        ['Pedido de Venda',          'pedido de venda',      'commercial', 'order', 30, ''],

        // ── Remittance (Remessa de Produto) ───────────────────────────────
        ['Remessa de Produto',       'remessa de produto',   'remittance', 'any', 10, 'Operação de remessa fiscal MAG'],
        ['REMESSA DE PRODUTO',       'remessa de produto',   'remittance', 'any', 10, 'Deduplicado'],
        ['Remessa de Produtos',      'remessa de produtos',  'remittance', 'any', 11, 'Plural'],
        ['Remessa Produto',          'remessa produto',      'remittance', 'any', 12, ''],
        ['REMESSA',                  'remessa',              'remittance', 'any', 50, 'Padrão genérico de remessa'],
        ['Simples Remessa',          'simples remessa',      'remittance', 'nf', 20, 'natOp NF-e remessa simples'],
        ['Transferência',            'transferência',        'remittance', 'any', 40, ''],
        ['Devolução',                'devolução',            'remittance', 'any', 40, ''],
        ['Devolucao',                'devolucao',            'remittance', 'any', 40, ''],
        ['Bonificação',              'bonificação',          'remittance', 'any', 40, ''],
        ['Demonstração',             'demonstração',         'remittance', 'any', 40, ''],
    ];

    $inserted = $updated = 0;
    $stmtCheck = $pdo->prepare(
        "SELECT id, scope FROM biomie_operation_scope_rules
         WHERE normalized_pattern = ? AND source_type = ?"
    );
    $stmtInsert = $pdo->prepare(
        "INSERT INTO biomie_operation_scope_rules
         (raw_pattern, normalized_pattern, scope, source_type, match_type, priority, notes)
         VALUES (?, ?, ?, ?, 'contains', ?, ?)"
    );
    $stmtUpdate = $pdo->prepare(
        "UPDATE biomie_operation_scope_rules
         SET raw_pattern = ?, scope = ?, priority = ?, notes = ?, is_active = 1
         WHERE normalized_pattern = ? AND source_type = ?"
    );

    foreach ($seeds as [$raw, $norm, $scope, $srcType, $prio, $notes]) {
        $stmtCheck->execute([$norm, $srcType]);
        $existing = $stmtCheck->fetch(\PDO::FETCH_ASSOC);

        if ($existing) {
            $stmtUpdate->execute([$raw, $scope, $prio, $notes, $norm, $srcType]);
            $updated++;
        } else {
            $stmtInsert->execute([$raw, $norm, $scope, $srcType, $prio, $notes]);
            $inserted++;
        }
    }

    echo "Seeds: $inserted inseridos, $updated atualizados.\n";
}

// ── List rules ─────────────────────────────────────────────────────────────────

if ($list) {
    $tableExists = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biomie_operation_scope_rules'"
    )->fetchColumn();

    if (!$tableExists) {
        $msg = 'Tabela biomie_operation_scope_rules nao existe. Execute --create --seed primeiro.';
        if ($isJson) {
            echo json_encode(['error' => $msg]) . "\n";
        } else {
            echo "ERRO: $msg\n";
        }
        exit(1);
    }

    $rows = $pdo->query(
        "SELECT id, raw_pattern, normalized_pattern, scope, source_type, match_type, priority, is_active, notes
         FROM biomie_operation_scope_rules
         ORDER BY scope ASC, priority ASC, id ASC"
    )->fetchAll(\PDO::FETCH_ASSOC);

    if ($isJson) {
        echo json_encode(['rules' => $rows, 'total' => count($rows)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        echo "\n=== biomie_operation_scope_rules ===\n\n";
        $fmt = "  %-4s %-40s %-12s %-8s %-6s %s\n";
        printf($fmt, 'ID', 'normalized_pattern', 'scope', 'src_type', 'prio', 'active');
        echo str_repeat('-', 90) . "\n";
        foreach ($rows as $r) {
            printf($fmt,
                $r['id'],
                mb_substr($r['normalized_pattern'], 0, 40),
                $r['scope'],
                $r['source_type'],
                $r['priority'],
                $r['is_active'] ? 'yes' : 'no'
            );
        }
        echo "\nTotal: " . count($rows) . " regras\n\n";
    }
}
