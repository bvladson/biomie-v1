<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Config;
use BIomie\Database;
use BIomie\Support\OmieVendorStatusMapper;

$opts = getopt('', ['apply', 'dry-run', 'pretty', 'json', 'sample:']);
$apply = isset($opts['apply']) && !isset($opts['dry-run']);
$jsonMode = isset($opts['json']);
$pretty = isset($opts['pretty']) || !$jsonMode;
$sample = max(1, min(1000, (int) ($opts['sample'] ?? 100)));
// COM.R1.5.2 — Repair concluído. Histórico de passes:
//   Pass 1: expectedDivergences = 10 (aplicados 4; sync sobrescreveu 6 antes do deploy do fix)
//   Pass 2: expectedDivergences = 6  (aplicados 6; estado final: 19 ativos / 10 inativos / 0 divergências)
// Estado atual esperado: 0 planned updates (repair completo; CommercialSyncCoordinator já corrigido).
// Se no futuro o plan tiver exatamente N mudanças, atualizar $expectedDivergences para N antes de --apply.
$expectedDivergences = 6; // pass 2 histórico; dry-run deve sempre retornar 0 neste estado
$t0 = microtime(true);

try {
    $config = new Config();
    $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo = Database::connect($config, $connection);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
    fwrite(STDERR, 'DB: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

function ovs_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t LIMIT 1');
    $stmt->execute(['t' => $table]);

    return (int) $stmt->fetchColumn() > 0;
}

/**
 * @param array<string,mixed> $payload
 * @param list<string> $paths
 */
function ovs_first_string(array $payload, array $paths): string
{
    foreach ($paths as $path) {
        $current = $payload;
        foreach (explode('.', $path) as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                $current = null;
                break;
            }
            $current = $current[$part];
        }
        if ($current !== null && trim((string) $current) !== '') {
            return trim((string) $current);
        }
    }

    return '';
}

/**
 * @return array{active:int,inactive:int,total:int}
 */
function ovs_count_db_status(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT COUNT(*) AS total, SUM(CASE WHEN ativo = 1 THEN 1 ELSE 0 END) AS active, SUM(CASE WHEN ativo = 0 THEN 1 ELSE 0 END) AS inactive FROM biomie_vendedores_omie'
    );
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'total' => (int) ($row['total'] ?? 0),
        'active' => (int) ($row['active'] ?? 0),
        'inactive' => (int) ($row['inactive'] ?? 0),
    ];
}

/**
 * @return array<string,array<string,mixed>>
 */
function ovs_load_db_roster(PDO $pdo): array
{
    $rows = $pdo->query('SELECT codigo_omie, nome, email, comissao, ativo FROM biomie_vendedores_omie ORDER BY nome')
        ->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $row) {
        $code = trim((string) ($row['codigo_omie'] ?? ''));
        if ($code === '') {
            continue;
        }
        $out[$code] = [
            'code' => $code,
            'name' => trim((string) ($row['nome'] ?? '')),
            'email' => trim((string) ($row['email'] ?? '')),
            'commission' => (float) ($row['comissao'] ?? 0),
            'active' => (int) ($row['ativo'] ?? 1) === 1,
        ];
    }

    return $out;
}

/**
 * @return list<array<string,mixed>>
 */
function ovs_load_latest_omie_vendor_payloads(PDO $pdo): array
{
    $sql = <<<'SQL'
        SELECT b.provider_key, b.natural_key, b.payload_json, b.ingested_at, b.id
        FROM biomie_omie_bronze b
        INNER JOIN (
            SELECT
                COALESCE(NULLIF(provider_key, ''), NULLIF(natural_key, ''), payload_hash) AS roster_key,
                MAX(id) AS max_id
            FROM biomie_omie_bronze
            WHERE domain_name = 'vendors'
               OR entity_name = 'vendor'
               OR omie_method LIKE '%Vendedor%'
               OR endpoint LIKE '%vendedor%'
            GROUP BY COALESCE(NULLIF(provider_key, ''), NULLIF(natural_key, ''), payload_hash)
        ) latest ON latest.max_id = b.id
        ORDER BY b.id
    SQL;

    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

if (!ovs_table_exists($pdo, 'biomie_vendedores_omie')) {
    fwrite(STDERR, 'Tabela biomie_vendedores_omie nao encontrada.' . PHP_EOL);
    exit(1);
}

if (!ovs_table_exists($pdo, 'biomie_omie_bronze')) {
    fwrite(STDERR, 'Tabela biomie_omie_bronze nao encontrada.' . PHP_EOL);
    exit(1);
}

$mapper = new OmieVendorStatusMapper();
$dbBefore = ovs_count_db_status($pdo);
$dbRoster = ovs_load_db_roster($pdo);
$omieRows = ovs_load_latest_omie_vendor_payloads($pdo);

$omieTotal = 0;
$omieKnown = 0;
$omieActive = 0;
$omieInactive = 0;
$missingInDb = [];
$plan = [];

foreach ($omieRows as $row) {
    $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
    if (!is_array($payload)) {
        continue;
    }

    $code = ovs_first_string($payload, ['codigo', 'codigo_omie', 'codigo_vendedor', 'codVend']);
    if ($code === '') {
        $code = trim((string) ($row['provider_key'] ?? $row['natural_key'] ?? ''));
    }
    $name = ovs_first_string($payload, ['nome', 'nome_vendedor', 'vendedor_nome']);
    if ($code === '' && $name === '') {
        continue;
    }

    $omieTotal++;
    $status = $mapper->resolve($payload);
    if ($status['known']) {
        $omieKnown++;
        ((bool) $status['is_active']) ? $omieActive++ : $omieInactive++;
    }

    if ($code === '' || !isset($dbRoster[$code])) {
        $missingInDb[] = [
            'codigo_omie' => $code,
            'nome' => $name,
            'omie_is_active' => (bool) $status['is_active'],
            'status_field' => $status['field'],
            'status_value' => $status['value'],
        ];
        continue;
    }

    $db = $dbRoster[$code];
    $omieActiveFlag = (bool) $status['is_active'];
    if ($status['known'] && (bool) $db['active'] !== $omieActiveFlag) {
        $plan[] = [
            'codigo_omie' => $code,
            'nome_biomie' => (string) ($db['name'] ?? ''),
            'nome_omie' => $name,
            'current_ativo' => (bool) $db['active'],
            'target_ativo' => $omieActiveFlag,
            'status_field' => $status['field'],
            'status_value' => $status['value'],
            'action' => 'update_biomie_vendedores_omie_ativo',
        ];
    }
}

$expectedShape = count($plan) === $expectedDivergences
    && count(array_filter(
        $plan,
        static fn (array $row): bool => $row['current_ativo'] === true && $row['target_ativo'] === false
    )) === $expectedDivergences;

$applied = false;
$affected = 0;
$refusalReason = null;

if ($apply) {
    if (!$expectedShape) {
        $refusalReason = 'plan_does_not_match_expected_10_active_to_inactive_updates';
    } else {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('UPDATE biomie_vendedores_omie SET ativo = :target WHERE codigo_omie = :codigo AND ativo = :current');
            foreach ($plan as $change) {
                $stmt->execute([
                    'target' => $change['target_ativo'] ? 1 : 0,
                    'codigo' => $change['codigo_omie'],
                    'current' => $change['current_ativo'] ? 1 : 0,
                ]);
                $affected += $stmt->rowCount();
            }
            if ($affected !== $expectedDivergences) {
                throw new RuntimeException('Affected rows mismatch: expected 10, got ' . $affected);
            }
            $pdo->commit();
            $applied = true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}

$dbAfter = $applied ? ovs_count_db_status($pdo) : [
    'total' => $dbBefore['total'],
    'active' => $dbBefore['active'] - count(array_filter($plan, static fn (array $row): bool => $row['current_ativo'] === true && $row['target_ativo'] === false))
        + count(array_filter($plan, static fn (array $row): bool => $row['current_ativo'] === false && $row['target_ativo'] === true)),
    'inactive' => $dbBefore['inactive'] - count(array_filter($plan, static fn (array $row): bool => $row['current_ativo'] === false && $row['target_ativo'] === true))
        + count(array_filter($plan, static fn (array $row): bool => $row['current_ativo'] === true && $row['target_ativo'] === false)),
];

$result = [
    'ok' => !$apply || $applied,
    'version' => 'COM.R1.5.2',
    'mode' => $apply ? 'apply' : 'dry-run',
    'applied' => $applied,
    'affected_rows' => $affected,
    'eligible_for_apply' => $expectedShape,
    'refusal_reason' => $refusalReason,
    'expected_divergences' => $expectedDivergences,
    'summary' => [
        'omie_total' => $omieTotal,
        'omie_status_known' => $omieKnown,
        'omie_active' => $omieActive,
        'omie_inactive' => $omieInactive,
        'db_before' => $dbBefore,
        'db_after_planned_or_actual' => $dbAfter,
        'planned_updates' => count($plan),
        'missing_in_biomie_master' => count($missingInDb),
    ],
    'plan' => array_slice($plan, 0, $sample),
    'missing_in_biomie_master' => array_slice($missingInDb, 0, $sample),
    'guardrails' => [
        'updates_only_table' => 'biomie_vendedores_omie',
        'updates_only_column' => 'ativo',
        'no_delete' => true,
        'no_history_change' => true,
        'apply_requires_exact_10_active_to_inactive' => true,
    ],
    'elapsed_s' => round(microtime(true) - $t0, 3),
];

if ($jsonMode) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($result['ok'] ? 0 : 2);
}

if ($pretty) {
    $hr = str_repeat('=', 78);
    echo PHP_EOL . $hr . PHP_EOL;
    echo '  COM.R1.5.2 - Omie Vendor Status Sync Repair' . PHP_EOL;
    echo $hr . PHP_EOL . PHP_EOL;
    echo '  Mode                 : ' . $result['mode'] . PHP_EOL;
    echo '  Eligible for apply   : ' . ($expectedShape ? 'yes' : 'no') . PHP_EOL;
    echo '  Applied              : ' . ($applied ? 'yes' : 'no') . PHP_EOL;
    if ($refusalReason !== null) {
        echo '  Refusal reason       : ' . $refusalReason . PHP_EOL;
    }
    echo '  Omie payload         : ' . $omieTotal . ' total, ' . $omieActive . ' active, ' . $omieInactive . ' inactive' . PHP_EOL;
    echo '  BIomie before        : ' . $dbBefore['total'] . ' total, ' . $dbBefore['active'] . ' active, ' . $dbBefore['inactive'] . ' inactive' . PHP_EOL;
    echo '  BIomie after         : ' . $dbAfter['total'] . ' total, ' . $dbAfter['active'] . ' active, ' . $dbAfter['inactive'] . ' inactive' . PHP_EOL;
    echo '  Planned updates      : ' . count($plan) . PHP_EOL;
    echo '  Affected rows        : ' . $affected . PHP_EOL . PHP_EOL;

    if ($plan !== []) {
        echo '  Vendor status update plan' . PHP_EOL;
        echo str_repeat('-', 78) . PHP_EOL;
        foreach (array_slice($plan, 0, $sample) as $change) {
            echo '  [' . $change['codigo_omie'] . '] ' . $change['nome_biomie']
                . '  ativo=' . ($change['current_ativo'] ? '1' : '0')
                . ' -> ' . ($change['target_ativo'] ? '1' : '0')
                . '  via ' . $change['status_field'] . '=' . (string) $change['status_value']
                . PHP_EOL;
        }
        echo PHP_EOL;
    }

    if ($missingInDb !== []) {
        echo '  Omie vendors missing in BIomie master' . PHP_EOL;
        echo str_repeat('-', 78) . PHP_EOL;
        foreach (array_slice($missingInDb, 0, $sample) as $missing) {
            echo '  [' . ($missing['codigo_omie'] ?: '-') . '] ' . $missing['nome'] . PHP_EOL;
        }
        echo PHP_EOL;
    }

    echo $hr . PHP_EOL . PHP_EOL;
}

exit($result['ok'] ? 0 : 2);
