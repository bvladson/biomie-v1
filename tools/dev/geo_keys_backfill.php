<?php

declare(strict_types=1);

/**
 * Safe backfill for bairro_key/cidade_key in geo-derived tables.
 *
 * Usage:
 *   php tools/dev/geo_keys_backfill.php --dry-run --pretty --table=all
 *   php tools/dev/geo_keys_backfill.php --apply --pretty --table=mart_compradores_por_geo
 *   php tools/dev/geo_keys_backfill.php --dry-run --json --table=all
 *
 * Options:
 *   --dry-run          Show what would change (default)
 *   --apply            Execute deterministic UPDATEs
 *   --table=all|NAME   Target table(s)
 *   --limit=N          Max distinct city/neighborhood pairs per table (default 5000)
 *   --json             Pure JSON output
 *   --pretty           Human output; accepted for compatibility
 */

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\Support\Geo\GeoKeyNormalizer;
use BIomie\Support\SecretMask;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$opts = getopt('', ['dry-run', 'apply', 'table:', 'limit:', 'json', 'pretty']);
$dryRun = !isset($opts['apply']);
$tableFilter = (string) ($opts['table'] ?? 'all');
$limit = max(1, (int) ($opts['limit'] ?? 5000));
$json = isset($opts['json']);
$startedAt = date(DATE_ATOM);
$root = dirname(__DIR__, 2);

function geoPrintJson(array $payload): void
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
}

function geoFail(array $payload, bool $json, int $exitCode): never
{
    $payload['ok'] = false;
    if ($json) {
        geoPrintJson($payload);
    } else {
        echo "[ERRO] " . (string) ($payload['error'] ?? 'geo_keys_backfill_failed') . "\n";
        if (!empty($payload['message'])) {
            echo (string) $payload['message'] . "\n";
        }
        if (!empty($payload['log_path'])) {
            echo "Log: " . (string) $payload['log_path'] . "\n";
        }
    }
    exit($exitCode);
}

function quoteIdent(string $identifier): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
        throw new InvalidArgumentException('Invalid SQL identifier: ' . $identifier);
    }

    return '`' . $identifier . '`';
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

function colExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

/**
 * @param list<string> $candidates
 */
function detectColumn(PDO $pdo, string $table, array $candidates): string
{
    foreach ($candidates as $candidate) {
        if (colExists($pdo, $table, $candidate)) {
            return $candidate;
        }
    }

    return '';
}

function shouldNormalizeColumn(string $column): bool
{
    return str_contains($column, '_norm') || str_contains($column, '_normalizado');
}

function missingKeyWhere(): string
{
    return "(bairro_key IS NULL OR bairro_key = '')";
}

/**
 * @return array<string,mixed>
 */
function tableStats(PDO $pdo, string $table, string $cityCol, string $bairroCol): array
{
    $qt = quoteIdent($table);
    $qb = quoteIdent($bairroCol);
    $hasRevenue = colExists($pdo, $table, 'faturamento');
    $hasPeriod = colExists($pdo, $table, 'competencia');
    $missing = missingKeyWhere();

    $totalRows = (int) $pdo->query("SELECT COUNT(*) FROM {$qt}")->fetchColumn();
    $missingRows = (int) $pdo->query("SELECT COUNT(*) FROM {$qt} WHERE {$missing}")->fetchColumn();
    $missingWithNeighborhood = (int) $pdo->query(
        "SELECT COUNT(*) FROM {$qt}
         WHERE {$missing}
           AND {$qb} IS NOT NULL
           AND TRIM({$qb}) <> ''"
    )->fetchColumn();

    $missingWithRevenue = 0;
    $missingWithNeighborhoodAndRevenue = 0;
    $affectedRevenue = 0.0;
    $topAffected = [];
    $byPeriod = [];

    if ($hasRevenue) {
        $missingWithRevenue = (int) $pdo->query(
            "SELECT COUNT(*) FROM {$qt}
             WHERE {$missing}
               AND COALESCE(faturamento, 0) > 0"
        )->fetchColumn();
        $missingWithNeighborhoodAndRevenue = (int) $pdo->query(
            "SELECT COUNT(*) FROM {$qt}
             WHERE {$missing}
               AND {$qb} IS NOT NULL
               AND TRIM({$qb}) <> ''
               AND COALESCE(faturamento, 0) > 0"
        )->fetchColumn();
        $affectedRevenue = (float) $pdo->query(
            "SELECT COALESCE(SUM(faturamento), 0) FROM {$qt}
             WHERE {$missing}
               AND COALESCE(faturamento, 0) > 0"
        )->fetchColumn();

        $qc = quoteIdent($cityCol);
        $topSql = "SELECT {$qc} AS cidade, {$qb} AS bairro,
                          COUNT(*) AS rows_count,
                          COALESCE(SUM(faturamento), 0) AS revenue
                   FROM {$qt}
                   WHERE {$missing}
                     AND COALESCE(faturamento, 0) > 0
                   GROUP BY {$qc}, {$qb}
                   ORDER BY revenue DESC, rows_count DESC
                   LIMIT 10";
        $topAffected = $pdo->query($topSql)->fetchAll(PDO::FETCH_ASSOC);

        if ($hasPeriod) {
            $byPeriod = $pdo->query(
                "SELECT competencia,
                        COUNT(*) AS rows_count,
                        COALESCE(SUM(faturamento), 0) AS revenue
                 FROM {$qt}
                 WHERE {$missing}
                   AND COALESCE(faturamento, 0) > 0
                 GROUP BY competencia
                 ORDER BY competencia"
            )->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    return [
        'total_rows' => $totalRows,
        'missing_key_rows' => $missingRows,
        'missing_key_with_neighborhood_rows' => $missingWithNeighborhood,
        'missing_key_with_revenue_rows' => $missingWithRevenue,
        'missing_key_with_neighborhood_and_revenue_rows' => $missingWithNeighborhoodAndRevenue,
        'affected_revenue' => round($affectedRevenue, 2),
        'top_affected' => $topAffected,
        'impact_by_period' => $byPeriod,
    ];
}

/**
 * @return list<array<string,mixed>>
 */
function existingDisplayConflicts(PDO $pdo, string $table): array
{
    if (!colExists($pdo, $table, 'bairro_nome')) {
        return [];
    }

    $qt = quoteIdent($table);
    $hasRevenue = colExists($pdo, $table, 'faturamento');
    $revenueExpr = $hasRevenue ? 'COALESCE(SUM(faturamento), 0)' : '0';

    $rows = $pdo->query(
        "SELECT bairro_key,
                COALESCE(bairro_nome, bairro_norm, '') AS bairro_nome,
                COALESCE(bairro_norm, '') AS bairro_norm,
                COUNT(*) AS rows_count,
                {$revenueExpr} AS revenue
         FROM {$qt}
         WHERE bairro_key IS NOT NULL AND bairro_key <> ''
         GROUP BY bairro_key, COALESCE(bairro_nome, bairro_norm, ''), COALESCE(bairro_norm, '')
         ORDER BY bairro_key"
    )->fetchAll(PDO::FETCH_ASSOC);

    $grouped = [];
    foreach ($rows as $row) {
        $key = (string) ($row['bairro_key'] ?? '');
        if ($key === '') {
            continue;
        }
        $grouped[$key][] = $row;
    }

    $conflicts = [];
    foreach ($grouped as $key => $items) {
        $names = [];
        $norms = [];
        $totalRows = 0;
        $totalRevenue = 0.0;

        foreach ($items as $item) {
            $name = trim((string) ($item['bairro_nome'] ?? ''));
            $norm = trim((string) ($item['bairro_norm'] ?? ''));
            if ($name !== '' && !in_array($name, $names, true)) {
                $names[] = $name;
            }
            if ($norm !== '' && !in_array($norm, $norms, true)) {
                $norms[] = $norm;
            }
            $totalRows += (int) ($item['rows_count'] ?? 0);
            $totalRevenue += (float) ($item['revenue'] ?? 0);
        }

        if (count($names) <= 1) {
            continue;
        }

        $classification = count($norms) === 1 ? 'display_name_variant' : 'ambiguous_existing_names';
        $decision = $classification === 'display_name_variant' ? 'mapear' : 'exigir_correcao_manual';
        $canonical = $decision === 'mapear' ? chooseCanonicalDisplayName($names) : '';
        $rowsToUpdate = 0;

        if ($canonical !== '') {
            foreach ($items as $item) {
                if (trim((string) ($item['bairro_nome'] ?? '')) !== $canonical) {
                    $rowsToUpdate += (int) ($item['rows_count'] ?? 0);
                }
            }
        }

        $conflicts[] = [
            'bairro_key' => $key,
            'nomes_distintos' => $names,
            'bairro_norm_distintos' => $norms,
            'rows_count' => $totalRows,
            'rows_to_update' => $rowsToUpdate,
            'revenue' => round($totalRevenue, 2),
            'classification' => $classification,
            'reason' => $classification === 'display_name_variant'
                ? 'Mesmo bairro_key e mesmo bairro_norm em linhas ja preenchidas; diferenca apenas de nome de exibicao.'
                : 'Mesmo bairro_key aparece com bairro_norm distintos em linhas ja preenchidas.',
            'decision' => $decision,
            'canonical_bairro_nome' => $canonical,
        ];
    }

    return $conflicts;
}

/**
 * @param array<string,string|bool> $target
 * @return array<string,mixed>
 */
function analyzeTable(PDO $pdo, array $target, int $limit): array
{
    $table = (string) $target['table'];
    $cityCol = (string) $target['city_col'];
    $bairroCol = (string) $target['bairro_col'];

    if (!tableExists($pdo, $table)) {
        return ['table' => $table, 'skipped' => true, 'reason' => 'table_missing'];
    }

    foreach (['bairro_key', $cityCol, $bairroCol] as $requiredCol) {
        if ($requiredCol === '' || !colExists($pdo, $table, $requiredCol)) {
            return ['table' => $table, 'skipped' => true, 'reason' => 'required_column_missing', 'column' => $requiredCol];
        }
    }

    $qt = quoteIdent($table);
    $qc = quoteIdent($cityCol);
    $qb = quoteIdent($bairroCol);
    $hasRevenue = colExists($pdo, $table, 'faturamento');
    $hasPeriod = colExists($pdo, $table, 'competencia');
    $missing = missingKeyWhere();
    $revenueExpr = $hasRevenue ? 'COALESCE(SUM(faturamento), 0)' : '0';
    $minPeriodExpr = $hasPeriod ? 'MIN(competencia)' : 'NULL';
    $maxPeriodExpr = $hasPeriod ? 'MAX(competencia)' : 'NULL';

    $distinctPairs = (int) $pdo->query(
        "SELECT COUNT(*) FROM (
            SELECT 1 FROM {$qt}
            WHERE {$missing}
            GROUP BY {$qc}, {$qb}
         ) pairs"
    )->fetchColumn();

    $stmt = $pdo->query(
        "SELECT {$qc} AS city_raw,
                {$qb} AS bairro_raw,
                COUNT(*) AS rows_count,
                {$revenueExpr} AS revenue,
                {$minPeriodExpr} AS first_period,
                {$maxPeriodExpr} AS last_period
         FROM {$qt}
         WHERE {$missing}
         GROUP BY {$qc}, {$qb}
         ORDER BY rows_count DESC, revenue DESC
         LIMIT {$limit}"
    );
    $pairs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $keyToCandidates = [];
    $updates = [];
    $skippedEmpty = [];

    foreach ($pairs as $pair) {
        $cityRaw = $pair['city_raw'];
        $bairroRaw = $pair['bairro_raw'];
        $cityText = $cityRaw === null ? '' : (string) $cityRaw;
        $bairroText = $bairroRaw === null ? '' : (string) $bairroRaw;
        $bairroKey = GeoKeyNormalizer::makeNeighborhoodKey($bairroText);
        $cidadeKey = GeoKeyNormalizer::makeCityKey($cityText);
        $bairroNorm = GeoKeyNormalizer::normalizeNeighborhood($bairroText);
        $cidadeNorm = GeoKeyNormalizer::normalizeCity($cityText);
        $bairroDisplay = GeoKeyNormalizer::displayName($bairroText);
        $cidadeDisplay = GeoKeyNormalizer::displayName($cityText);
        $rowsCount = (int) ($pair['rows_count'] ?? 0);
        $revenue = round((float) ($pair['revenue'] ?? 0), 2);

        $mapping = [
            'city_raw' => $cityRaw,
            'bairro_raw' => $bairroRaw,
            'city_norm' => $cidadeNorm,
            'bairro_norm' => $bairroNorm,
            'cidade_key' => $cidadeKey,
            'bairro_key' => $bairroKey,
            'cidade_nome' => $cidadeDisplay,
            'bairro_nome' => $bairroDisplay,
            'rows_count' => $rowsCount,
            'revenue' => $revenue,
            'first_period' => $pair['first_period'] ?? null,
            'last_period' => $pair['last_period'] ?? null,
        ];

        if ($bairroKey === '') {
            $skippedEmpty[] = $mapping + ['reason' => 'empty_bairro_key'];
            continue;
        }

        $updates[] = $mapping;
        $keyToCandidates[$bairroKey][] = [
            'bairro_raw' => $bairroRaw,
            'bairro_norm' => $bairroNorm,
            'bairro_nome' => $bairroDisplay,
            'cidade_key' => $cidadeKey,
            'rows_count' => $rowsCount,
            'revenue' => $revenue,
        ];
    }

    $conflicts = [];
    $blockedKeys = [];
    foreach ($keyToCandidates as $bairroKey => $candidates) {
        $names = [];
        $norms = [];
        $cities = [];
        $rows = 0;
        $revenue = 0.0;

        foreach ($candidates as $candidate) {
            $name = (string) ($candidate['bairro_nome'] ?? '');
            $norm = (string) ($candidate['bairro_norm'] ?? '');
            $city = (string) ($candidate['cidade_key'] ?? '');
            if ($name !== '' && !in_array($name, $names, true)) {
                $names[] = $name;
            }
            if ($norm !== '' && !in_array($norm, $norms, true)) {
                $norms[] = $norm;
            }
            if ($city !== '' && !in_array($city, $cities, true)) {
                $cities[] = $city;
            }
            $rows += (int) ($candidate['rows_count'] ?? 0);
            $revenue += (float) ($candidate['revenue'] ?? 0);
        }

        if (count($names) <= 1) {
            continue;
        }

        $classification = count($norms) === 1 ? 'display_name_variant' : 'ambiguous_normalized_values';
        $decision = $classification === 'display_name_variant' ? 'mapear' : 'exigir_correcao_manual';
        if ($decision !== 'mapear') {
            $blockedKeys[] = $bairroKey;
        }

        $conflicts[] = [
            'bairro_key' => $bairroKey,
            'nomes_distintos' => $names,
            'bairro_norm_distintos' => $norms,
            'cidade_keys' => $cities,
            'rows_count' => $rows,
            'revenue' => round($revenue, 2),
            'classification' => $classification,
            'reason' => $classification === 'display_name_variant'
                ? 'Mesmo bairro_key e mesmo bairro_norm; diferenca apenas de nome de exibicao/case/acento/separador.'
                : 'Mesmo bairro_key agrupa valores normalizados distintos.',
            'decision' => $decision,
        ];
    }

    $blockedKeySet = array_fill_keys($blockedKeys, true);
    $eligible = [];
    $blocked = [];
    foreach ($updates as $update) {
        $bairroKey = (string) $update['bairro_key'];
        if (isset($blockedKeySet[$bairroKey])) {
            $blocked[] = $update + ['reason' => 'blocked_conflict'];
            continue;
        }
        $eligible[] = $update;
    }

    $eligibleRows = array_sum(array_map(static fn (array $row): int => (int) $row['rows_count'], $eligible));
    $blockedRows = array_sum(array_map(static fn (array $row): int => (int) $row['rows_count'], $blocked));
    $skippedRows = array_sum(array_map(static fn (array $row): int => (int) $row['rows_count'], $skippedEmpty));
    $eligibleRevenue = round(array_sum(array_map(static fn (array $row): float => (float) $row['revenue'], $eligible)), 2);
    $blockedRevenue = round(array_sum(array_map(static fn (array $row): float => (float) $row['revenue'], $blocked)), 2);
    $existingDisplayConflicts = existingDisplayConflicts($pdo, $table);
    $blockedExistingDisplayConflicts = count(array_filter(
        $existingDisplayConflicts,
        static fn (array $conflict): bool => ($conflict['decision'] ?? '') !== 'mapear'
    ));
    $displayNameRowsToUpdate = array_sum(array_map(
        static fn (array $conflict): int => (int) ($conflict['rows_to_update'] ?? 0),
        array_filter(
            $existingDisplayConflicts,
            static fn (array $conflict): bool => ($conflict['decision'] ?? '') === 'mapear'
        )
    ));

    return [
        'table' => $table,
        'dry_run' => true,
        'city_col' => $cityCol,
        'bairro_col' => $bairroCol,
        'has_cidade_key' => colExists($pdo, $table, 'cidade_key'),
        'has_bairro_nome' => colExists($pdo, $table, 'bairro_nome'),
        'has_cidade_nome' => colExists($pdo, $table, 'cidade_nome'),
        'normalize_bairro_col' => shouldNormalizeColumn($bairroCol),
        'stats_before' => tableStats($pdo, $table, $cityCol, $bairroCol),
        'pairs_found' => count($pairs),
        'pairs_total' => $distinctPairs,
        'limit_applied' => $distinctPairs > count($pairs),
        'eligible_pairs' => count($eligible),
        'eligible_rows' => $eligibleRows,
        'eligible_revenue' => $eligibleRevenue,
        'skipped_empty_pairs' => count($skippedEmpty),
        'skipped_empty_rows' => $skippedRows,
        'blocked_conflict_pairs' => count($blocked),
        'blocked_conflict_rows' => $blockedRows,
        'blocked_conflict_revenue' => $blockedRevenue,
        'conflicts' => $conflicts,
        'existing_display_conflicts' => $existingDisplayConflicts,
        'blocked_existing_display_conflicts' => $blockedExistingDisplayConflicts,
        'display_name_rows_to_update' => $displayNameRowsToUpdate,
        'updates' => $eligible,
        'blocked_updates' => $blocked,
        'skipped_empty' => $skippedEmpty,
        'sample_updates' => array_slice($eligible, 0, 20),
    ];
}

/**
 * @param array<string,mixed> $analysis
 * @return array<string,mixed>
 */
function applyTable(PDO $pdo, array $analysis): array
{
    if (!empty($analysis['skipped'])) {
        return $analysis;
    }

    $table = (string) $analysis['table'];
    $cityCol = (string) $analysis['city_col'];
    $bairroCol = (string) $analysis['bairro_col'];
    $qt = quoteIdent($table);
    $qc = quoteIdent($cityCol);
    $qb = quoteIdent($bairroCol);

    $setClauses = ['bairro_key = :bairro_key'];
    if (!empty($analysis['has_cidade_key'])) {
        $setClauses[] = 'cidade_key = :cidade_key';
    }
    if (!empty($analysis['normalize_bairro_col'])) {
        $setClauses[] = $qb . ' = :bairro_norm';
    }
    if (!empty($analysis['has_bairro_nome'])) {
        $setClauses[] = 'bairro_nome = :bairro_nome';
    }
    if (!empty($analysis['has_cidade_nome'])) {
        $setClauses[] = 'cidade_nome = :cidade_nome';
    }

    $sql = "UPDATE {$qt}
            SET " . implode(', ', $setClauses) . "
            WHERE {$qc} <=> :city_raw
              AND {$qb} <=> :bairro_raw
              AND " . missingKeyWhere();
    $stmt = $pdo->prepare($sql);

    $updatedRows = 0;
    $updatedMappings = [];

    foreach (($analysis['updates'] ?? []) as $mapping) {
        $params = [
            ':bairro_key' => (string) $mapping['bairro_key'],
            ':city_raw' => $mapping['city_raw'],
            ':bairro_raw' => $mapping['bairro_raw'],
        ];
        if (!empty($analysis['has_cidade_key'])) {
            $params[':cidade_key'] = (string) $mapping['cidade_key'];
        }
        if (!empty($analysis['normalize_bairro_col'])) {
            $params[':bairro_norm'] = (string) $mapping['bairro_norm'];
        }
        if (!empty($analysis['has_bairro_nome'])) {
            $params[':bairro_nome'] = (string) $mapping['bairro_nome'];
        }
        if (!empty($analysis['has_cidade_nome'])) {
            $params[':cidade_nome'] = (string) $mapping['cidade_nome'];
        }

        $stmt->execute($params);
        $rowCount = $stmt->rowCount();
        $updatedRows += $rowCount;
        $updatedMappings[] = $mapping + ['updated_rows' => $rowCount];
    }

    $displayNameRowsUpdated = 0;
    $displayNameFixes = [];
    if (!empty($analysis['has_bairro_nome'])) {
        $displayUpdate = $pdo->prepare(
            "UPDATE {$qt}
             SET bairro_nome = :canonical_name
             WHERE bairro_key = :bairro_key
               AND COALESCE(bairro_nome, '') <> :canonical_name_check"
        );

        foreach (($analysis['existing_display_conflicts'] ?? []) as $conflict) {
            if (($conflict['decision'] ?? '') !== 'mapear') {
                continue;
            }
            $canonical = trim((string) ($conflict['canonical_bairro_nome'] ?? ''));
            $bairroKey = trim((string) ($conflict['bairro_key'] ?? ''));
            if ($canonical === '' || $bairroKey === '') {
                continue;
            }

            $displayUpdate->execute([
                'canonical_name' => $canonical,
                'canonical_name_check' => $canonical,
                'bairro_key' => $bairroKey,
            ]);
            $rowCount = $displayUpdate->rowCount();
            $displayNameRowsUpdated += $rowCount;
            $displayNameFixes[] = $conflict + ['updated_rows' => $rowCount];
        }
    }

    $analysis['dry_run'] = false;
    $analysis['updated_rows'] = $updatedRows;
    $analysis['updated_mappings'] = $updatedMappings;
    $analysis['display_name_rows_updated'] = $displayNameRowsUpdated;
    $analysis['display_name_fixes'] = $displayNameFixes;
    $analysis['stats_after'] = tableStats($pdo, $table, $cityCol, $bairroCol);

    return $analysis;
}

/**
 * @param list<array<string,mixed>> $results
 * @return array<string,mixed>
 */
function summarizeResults(array $results, bool $dryRun): array
{
    $summary = [
        'tables' => count($results),
        'skipped_tables' => 0,
        'eligible_rows' => 0,
        'eligible_pairs' => 0,
        'updated_rows' => 0,
        'display_name_variant_conflicts' => 0,
        'blocked_conflicts' => 0,
        'blocked_conflict_rows' => 0,
        'display_name_rows_to_update' => 0,
        'display_name_rows_updated' => 0,
        'skipped_empty_rows' => 0,
        'missing_key_with_revenue_rows' => 0,
        'affected_revenue' => 0.0,
        'updates_executed' => 0,
    ];

    foreach ($results as $result) {
        if (!empty($result['skipped'])) {
            $summary['skipped_tables']++;
            continue;
        }
        $summary['eligible_rows'] += (int) ($result['eligible_rows'] ?? 0);
        $summary['eligible_pairs'] += (int) ($result['eligible_pairs'] ?? 0);
        $summary['updated_rows'] += (int) ($result['updated_rows'] ?? 0);
        $summary['blocked_conflicts'] += (int) ($result['blocked_existing_display_conflicts'] ?? 0);
        $summary['blocked_conflicts'] += count(array_filter(
            $result['conflicts'] ?? [],
            static fn (array $conflict): bool => ($conflict['decision'] ?? '') !== 'mapear'
        ));
        $summary['display_name_variant_conflicts'] += count(array_filter(
            $result['conflicts'] ?? [],
            static fn (array $conflict): bool => ($conflict['classification'] ?? '') === 'display_name_variant'
        ));
        $summary['display_name_variant_conflicts'] += count(array_filter(
            $result['existing_display_conflicts'] ?? [],
            static fn (array $conflict): bool => ($conflict['classification'] ?? '') === 'display_name_variant'
        ));
        $summary['blocked_conflict_rows'] += (int) ($result['blocked_conflict_rows'] ?? 0);
        $summary['display_name_rows_to_update'] += (int) ($result['display_name_rows_to_update'] ?? 0);
        $summary['display_name_rows_updated'] += (int) ($result['display_name_rows_updated'] ?? 0);
        $summary['skipped_empty_rows'] += (int) ($result['skipped_empty_rows'] ?? 0);
        $summary['missing_key_with_revenue_rows'] += (int) ($result['stats_before']['missing_key_with_revenue_rows'] ?? 0);
        $summary['affected_revenue'] += (float) ($result['stats_before']['affected_revenue'] ?? 0);
    }

    $summary['affected_revenue'] = round($summary['affected_revenue'], 2);
    $summary['updates_executed'] = $dryRun ? 0 : $summary['updated_rows'] + $summary['display_name_rows_updated'];

    return $summary;
}

function writeGeoLog(string $root, string $mode, array $payload): string
{
    $dir = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $path = $dir . DIRECTORY_SEPARATOR . 'geo_keys_backfill_' . $mode . '_' . date('Ymd_His') . '.json';
    file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

    return $path;
}

function moneyFmt(float $value): string
{
    return 'R$ ' . number_format($value, 2, ',', '.');
}

function displayNameScore(string $name): float
{
    $score = 0.0;
    if (preg_match('/[^\x00-\x7F]/', $name)) {
        $score += 50;
    }
    if (str_contains($name, '/')) {
        $score += 25;
    }
    if (preg_match('/[A-Z]/', $name)) {
        $score += 10;
    }
    if ($name !== '' && $name === strtolower($name)) {
        $score -= 5;
    }

    return $score + (strlen($name) / 1000);
}

/** @param list<string> $names */
function chooseCanonicalDisplayName(array $names): string
{
    $filtered = [];
    foreach ($names as $name) {
        $name = trim($name);
        if ($name !== '' && !in_array($name, $filtered, true)) {
            $filtered[] = $name;
        }
    }

    usort($filtered, static function (string $left, string $right): int {
        $score = displayNameScore($right) <=> displayNameScore($left);
        return $score !== 0 ? $score : strcmp($left, $right);
    });

    return $filtered[0] ?? '';
}

/**
 * @param list<array<string,mixed>> $rows
 */
function printTopRows(array $rows, int $limit = 5): void
{
    $shown = 0;
    foreach ($rows as $row) {
        if ($shown >= $limit) {
            break;
        }
        $bairro = trim((string) ($row['bairro'] ?? ''));
        $cidade = trim((string) ($row['cidade'] ?? ''));
        $count = (int) ($row['rows_count'] ?? 0);
        $revenue = (float) ($row['revenue'] ?? 0);
        echo "      - " . ($bairro !== '' ? $bairro : '__sem_bairro__')
            . ($cidade !== '' ? " / {$cidade}" : '')
            . ": {$count} linhas, " . moneyFmt($revenue) . "\n";
        $shown++;
    }
}

/**
 * @param array<string,mixed> $payload
 */
function printHuman(array $payload): void
{
    $dryRun = ($payload['mode'] ?? '') === 'dry_run';
    echo ($dryRun ? "[DRY RUN] " : "[APPLY] ") . "Backfill seguro de bairro_key/cidade_key\n";
    echo "Tabela alvo: " . (string) ($payload['table_filter'] ?? 'all') . "\n";
    echo "Log: " . (string) ($payload['log_path'] ?? '') . "\n\n";

    $summary = $payload['summary'] ?? [];
    echo "Resumo\n";
    echo "  eligible_rows              : " . (int) ($summary['eligible_rows'] ?? 0) . "\n";
    echo "  eligible_pairs             : " . (int) ($summary['eligible_pairs'] ?? 0) . "\n";
    echo "  updated_rows               : " . (int) ($summary['updated_rows'] ?? 0) . "\n";
    echo "  updates_executed           : " . (int) ($summary['updates_executed'] ?? 0) . "\n";
    echo "  display_name_conflicts     : " . (int) ($summary['display_name_variant_conflicts'] ?? 0) . "\n";
    echo "  display_names_to_update    : " . (int) ($summary['display_name_rows_to_update'] ?? 0) . "\n";
    echo "  display_names_updated      : " . (int) ($summary['display_name_rows_updated'] ?? 0) . "\n";
    echo "  blocked_conflicts          : " . (int) ($summary['blocked_conflicts'] ?? 0) . "\n";
    echo "  missing_key_with_revenue   : " . (int) ($summary['missing_key_with_revenue_rows'] ?? 0) . "\n";
    echo "  affected_revenue           : " . moneyFmt((float) ($summary['affected_revenue'] ?? 0)) . "\n\n";

    foreach (($payload['results'] ?? []) as $result) {
        echo "Tabela: " . (string) ($result['table'] ?? '') . "\n";
        if (!empty($result['skipped'])) {
            echo "  Pulada: " . (string) ($result['reason'] ?? '') . "\n\n";
            continue;
        }

        $stats = $result['stats_before'] ?? [];
        echo "  total_rows                         : " . (int) ($stats['total_rows'] ?? 0) . "\n";
        echo "  missing_key_rows                   : " . (int) ($stats['missing_key_rows'] ?? 0) . "\n";
        echo "  missing_key_with_revenue_rows      : " . (int) ($stats['missing_key_with_revenue_rows'] ?? 0) . "\n";
        echo "  affected_revenue                   : " . moneyFmt((float) ($stats['affected_revenue'] ?? 0)) . "\n";
        echo "  pairs_found/total                  : " . (int) ($result['pairs_found'] ?? 0) . "/" . (int) ($result['pairs_total'] ?? 0) . "\n";
        echo "  eligible_pairs                     : " . (int) ($result['eligible_pairs'] ?? 0) . "\n";
        echo "  " . ($dryRun ? "would_update_rows" : "updated_rows") . "                  : "
            . (int) ($dryRun ? ($result['eligible_rows'] ?? 0) : ($result['updated_rows'] ?? 0)) . "\n";
        echo "  skipped_empty_rows                 : " . (int) ($result['skipped_empty_rows'] ?? 0) . "\n";
        echo "  blocked_conflict_rows              : " . (int) ($result['blocked_conflict_rows'] ?? 0) . "\n";
        echo "  display_name_rows_to_update        : " . (int) ($result['display_name_rows_to_update'] ?? 0) . "\n";

        if (!empty($result['conflicts'])) {
            echo "  conflitos de nome:\n";
            foreach ($result['conflicts'] as $conflict) {
                $names = implode(' | ', array_map('strval', $conflict['nomes_distintos'] ?? []));
                echo "      - " . (string) ($conflict['bairro_key'] ?? '')
                    . " => {$names}"
                    . " [" . (string) ($conflict['classification'] ?? '') . "; decisao="
                    . (string) ($conflict['decision'] ?? '') . "]\n";
            }
        }

        if (!empty($result['existing_display_conflicts'])) {
            echo "  conflitos existentes de bairro_nome:\n";
            foreach ($result['existing_display_conflicts'] as $conflict) {
                $names = implode(' | ', array_map('strval', $conflict['nomes_distintos'] ?? []));
                echo "      - " . (string) ($conflict['bairro_key'] ?? '')
                    . " => {$names}"
                    . " [canonical=" . (string) ($conflict['canonical_bairro_nome'] ?? '')
                    . "; decisao=" . (string) ($conflict['decision'] ?? '') . "]\n";
            }
        }

        if (!empty($stats['top_affected'])) {
            echo "  top bairros afetados:\n";
            printTopRows($stats['top_affected']);
        }

        echo "\n";
    }

    echo $dryRun
        ? "Proximo passo: revisar conflitos/documento; depois usar --apply se o dry-run estiver limpo.\n"
        : "Proximo passo: rematerializar marts geo e rodar guard/health.\n";
}

$config = new Config();
$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';

if (!Database::isConfigured($config, $connection)) {
    geoFail(['error' => 'no_database', 'message' => 'Banco nao configurado.'], $json, 2);
}

try {
    $pdo = Database::connect($config, $connection);
} catch (Throwable $e) {
    geoFail([
        'error' => 'db_connect_failed',
        'message' => SecretMask::sanitizeErrorMessage($e->getMessage(), 220),
    ], $json, 2);
}

$targets = [
    'mart_compradores_por_geo' => [
        'table' => 'mart_compradores_por_geo',
        'city_col' => 'cidade_norm',
        'bairro_col' => 'bairro_norm',
    ],
    'mart_vendas_por_cnae_cnpj' => [
        'table' => 'mart_vendas_por_cnae_cnpj',
        'city_col' => 'cidade_norm',
        'bairro_col' => 'bairro_norm',
    ],
    'biomie_clientes_dim' => [
        'table' => 'biomie_clientes_dim',
        'city_col' => detectColumn($pdo, 'biomie_clientes_dim', ['cidade_normalizada', 'cidade_norm', 'cidade']),
        'bairro_col' => detectColumn($pdo, 'biomie_clientes_dim', ['bairro_normalizado', 'bairro_norm', 'bairro']),
    ],
    'biomie_clientes_geo_dim' => [
        'table' => 'biomie_clientes_geo_dim',
        'city_col' => 'cidade_norm',
        'bairro_col' => 'bairro_norm',
    ],
];

$selectedTargets = [];
foreach ($targets as $name => $target) {
    if ($tableFilter !== 'all' && $tableFilter !== $name) {
        continue;
    }
    $selectedTargets[] = $target;
}

if ($selectedTargets === []) {
    geoFail([
        'error' => 'invalid_table_filter',
        'message' => 'Tabela alvo nao reconhecida: ' . $tableFilter,
    ], $json, 2);
}

try {
    $analysisResults = [];
    foreach ($selectedTargets as $target) {
        $analysisResults[] = analyzeTable($pdo, $target, $limit);
    }

    $analysisSummary = summarizeResults($analysisResults, true);

    if (!$dryRun && (int) $analysisSummary['blocked_conflicts'] > 0) {
        $payload = [
            'ok' => false,
            'mode' => 'apply_blocked',
            'started_at' => $startedAt,
            'finished_at' => date(DATE_ATOM),
            'connection' => $connection,
            'table_filter' => $tableFilter,
            'summary' => $analysisSummary,
            'results' => $analysisResults,
            'error' => 'blocked_conflicts',
            'message' => 'Apply recusado: ha conflito nao deterministico. Revise blocked_updates antes de aplicar.',
        ];
        $payload['log_path'] = writeGeoLog($root, 'apply_blocked', $payload);
        geoFail($payload, $json, 3);
    }

    if ($dryRun) {
        $payload = [
            'ok' => true,
            'mode' => 'dry_run',
            'started_at' => $startedAt,
            'finished_at' => date(DATE_ATOM),
            'connection' => $connection,
            'table_filter' => $tableFilter,
            'limit' => $limit,
            'summary' => $analysisSummary,
            'results' => $analysisResults,
        ];
        $payload['log_path'] = writeGeoLog($root, 'dry_run', $payload);

        if ($json) {
            geoPrintJson($payload);
        } else {
            printHuman($payload);
        }
        exit(0);
    }

    $applyResults = [];
    $pdo->beginTransaction();
    try {
        foreach ($analysisResults as $result) {
            $applyResults[] = applyTable($pdo, $result);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $payload = [
        'ok' => true,
        'mode' => 'apply',
        'started_at' => $startedAt,
        'finished_at' => date(DATE_ATOM),
        'connection' => $connection,
        'table_filter' => $tableFilter,
        'limit' => $limit,
        'summary' => summarizeResults($applyResults, false),
        'results' => $applyResults,
        'preflight_summary' => $analysisSummary,
    ];
    $payload['log_path'] = writeGeoLog($root, 'apply', $payload);

    if ($json) {
        geoPrintJson($payload);
    } else {
        printHuman($payload);
    }
    exit(0);
} catch (Throwable $e) {
    geoFail([
        'error' => 'geo_keys_backfill_exception',
        'message' => SecretMask::sanitizeErrorMessage($e->getMessage(), 500),
    ], $json, 2);
}
