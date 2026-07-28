<?php

declare(strict_types=1);

/**
 * PROVAS DOS CHECK CONSTRAINTS de biomie_quote_order_refresh_log.
 *
 *   php tests/probe_qorl_constraints.php test_db     # valida no banco de testes
 *   php tests/probe_qorl_constraints.php prod        # valida em produção (só SELECT
 *                                                    #   + INSERTs que DEVEM falhar)
 *
 * Em produção: NÃO cria nem altera constraint (isso é papel da migration). Só
 * verifica que os 4 CHECKs existem e que INSERTs inválidos são REJEITADOS,
 * deixando a tabela como estava (0 linhas). Cada INSERT roda em transação com
 * rollback — se um probe inválido passar (falha do gate), nada persiste.
 */

require_once __DIR__ . '/TestSupport.php';

use BIomie\Config;
use BIomie\Database;

$target = strtolower((string) ($argv[1] ?? ''));
if (!in_array($target, ['test_db', 'prod'], true)) {
    fwrite(STDERR, "uso: php tests/probe_qorl_constraints.php [test_db|prod]\n");
    exit(2);
}

$config = new Config();
$T = 'biomie_quote_order_refresh_log';

if ($target === 'test_db') {
    $pdo = test_pdo(); // guard: só o test_db
} else {
    // Produção: conexão de leitura/prova. NÃO passa por test_pdo (é prod mesmo).
    $pdo = Database::connect($config, Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db');
}
$dbName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

$checks = [];
$check = static function (string $name, bool $ok, mixed $detail = null) use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};

// No test_db, esta prova também APLICA os CHECKs (re-executável): drop-if-exists
// + add. Em produção quem aplica é a migration — aqui só provamos.
$constraints = [
    'chk_qorl_source' => "source IN ('webhook','manual','cli','scanner')",
    'chk_qorl_decision' => "decision IN ('keep','resolve','vanished','retry')",
    'chk_qorl_execution_state' => "is_dry_run IN (0,1) AND applied IN (0,1) AND NOT (is_dry_run = 1 AND applied = 1)",
    'chk_qorl_resolution' => "(decision IN ('keep','retry') AND resolution IS NULL)"
        . " OR (decision IN ('resolve','vanished') AND resolution IS NOT NULL AND resolution <> '')",
];

if ($target === 'test_db') {
    foreach ($constraints as $name => $expr) {
        $pdo->exec("ALTER TABLE {$T} DROP CONSTRAINT IF EXISTS {$name}");
        $pdo->exec("ALTER TABLE {$T} ADD CONSTRAINT {$name} CHECK ({$expr})");
    }
}

// ── 1. Os 4 CHECKs existem? ─────────────────────────────────────────────────
$found = $pdo->query(
    "SELECT CONSTRAINT_NAME FROM information_schema.CHECK_CONSTRAINTS
      WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = '{$T}'"
)->fetchAll(PDO::FETCH_COLUMN);
foreach (array_keys($constraints) as $name) {
    $check("constraint presente: {$name}", in_array($name, $found, true), implode(',', $found));
}

$rowsBefore = (int) $pdo->query("SELECT COUNT(*) FROM {$T}")->fetchColumn();

/**
 * Executa um INSERT dentro de transação e devolve se foi REJEITADO. Sempre faz
 * rollback: nada persiste, mesmo que (erroneamente) tenha passado.
 * @param array<string,mixed> $row
 */
$tryInsert = static function (PDO $pdo, string $T, array $row): array {
    $cols = implode(',', array_map(static fn ($k) => "`{$k}`", array_keys($row)));
    $ph = implode(',', array_map(static fn ($k) => ":{$k}", array_keys($row)));
    $rejected = false;
    $err = '';
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("INSERT INTO {$T} ({$cols}) VALUES ({$ph})");
        $st->execute($row);
    } catch (\PDOException $e) {
        $rejected = true;
        $err = $e->getMessage();
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
    return ['rejected' => $rejected, 'error' => $err];
};

$base = [
    'company_id' => 424242,
    'omie_order_id' => 999999999,
    'decision' => 'keep',
    'resolution' => null,
    'reason' => 'probe',
    'source' => 'cli',
    'is_dry_run' => 1,
    'applied' => 0,
];

// ── 2. Probes que DEVEM ser rejeitados ──────────────────────────────────────
$neg = [
    "source vazio ('') é rejeitado" => ['source' => ''],
    "source inventado é rejeitado" => ['source' => 'origem_inventada'],
    "simulação-que-mutou (is_dry_run=1,applied=1) é rejeitada" => ['is_dry_run' => 1, 'applied' => 1],
    "keep com resolution preenchida é rejeitado" => ['decision' => 'keep', 'resolution' => 'invoiced'],
    "resolve sem resolution é rejeitado" => ['decision' => 'resolve', 'resolution' => null],
    "decisão inventada é rejeitada" => ['decision' => 'seila', 'resolution' => null],
];
foreach ($neg as $label => $override) {
    $r = $tryInsert($pdo, $T, array_merge($base, $override));
    $check("REJEITA: {$label}", $r['rejected'], $r['rejected'] ? null : 'PASSOU (deveria falhar!)');
}

// ── 3. Probes VÁLIDOS que DEVEM passar (e são revertidos) ───────────────────
$pos = [
    "keep válido (resolution NULL) é aceito" => ['decision' => 'keep', 'resolution' => null, 'is_dry_run' => 1, 'applied' => 0],
    "resolve válido aplicado é aceito" => ['decision' => 'resolve', 'resolution' => 'invoiced', 'source' => 'webhook', 'is_dry_run' => 0, 'applied' => 1],
    "retry válido (resolution NULL) é aceito" => ['decision' => 'retry', 'resolution' => null, 'source' => 'scanner', 'is_dry_run' => 0, 'applied' => 0],
];
foreach ($pos as $label => $override) {
    $r = $tryInsert($pdo, $T, array_merge($base, $override));
    $check("ACEITA: {$label}", $r['rejected'] === false, $r['error']);
}

// ── 4. A tabela ficou como estava ───────────────────────────────────────────
$rowsAfter = (int) $pdo->query("SELECT COUNT(*) FROM {$T}")->fetchColumn();
$check('tabela inalterada (rollback de todos os probes)', $rowsBefore === $rowsAfter, "{$rowsBefore}→{$rowsAfter}");

$passed = count(array_filter($checks, static fn (array $r): bool => $r['ok']));
$result = [
    'ok' => $passed === count($checks),
    'target' => $target,
    'db' => $dbName,
    'total' => count($checks),
    'passed' => $passed,
    'rows' => ['before' => $rowsBefore, 'after' => $rowsAfter],
    'checks' => $checks,
];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
exit($result['ok'] ? 0 : 1);
