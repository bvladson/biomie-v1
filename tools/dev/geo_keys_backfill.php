<?php

declare(strict_types=1);

/**
 * Backfill seguro de bairro_key e cidade_key em marts geo.
 *
 * Uso:
 *   php tools/dev/geo_keys_backfill.php --dry-run --table=all
 *   php tools/dev/geo_keys_backfill.php --apply --table=mart_compradores_por_geo
 *   php tools/dev/geo_keys_backfill.php --dry-run --json --table=all
 *
 * Parametros:
 *   --dry-run          Mostra o que seria alterado (padrao)
 *   --apply            Executa os UPDATEs
 *   --table=all|NAME   Tabela(s) alvo
 *   --limit=N          Maximo de linhas por tabela (padrao 5000)
 *   --json             Saida JSON pura
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

$opts  = getopt('', ['dry-run', 'apply', 'table:', 'limit:', 'json']);
$dry   = !isset($opts['apply']);
$table = (string) ($opts['table'] ?? 'all');
$limit = max(1, (int) ($opts['limit'] ?? 5000));
$json  = isset($opts['json']);

function geoOutput(mixed $data, bool $json): void
{
    if ($json) {
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    } else {
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                echo "  {$k}:\n";
                foreach ($v as $kk => $vv) {
                    echo "    {$kk}: " . (is_bool($vv) ? ($vv ? 'true' : 'false') : $vv) . "\n";
                }
            } else {
                echo "  {$k}: " . (is_bool($v) ? ($v ? 'true' : 'false') : $v) . "\n";
            }
        }
    }
}

$root   = dirname(__DIR__, 2);
$config = new Config();
$conn   = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';

if (!Database::isConfigured($config, $conn)) {
    geoOutput(['ok' => false, 'error' => 'no_database'], $json);
    exit(2);
}

try {
    $pdo = Database::connect($config, $conn);
} catch (\Throwable $e) {
    geoOutput(['ok' => false, 'error' => SecretMask::sanitizeErrorMessage($e->getMessage(), 220)], $json);
    exit(2);
}

function colExists(\PDO $pdo, string $table, string $col): bool
{
    return (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' AND COLUMN_NAME = '{$col}'"
    )->fetchColumn() > 0;
}

function tableExists(\PDO $pdo, string $table): bool
{
    return (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = '{$table}'"
    )->fetchColumn() > 0;
}

/**
 * Processa uma tabela: lê pares (city_col, bairro_col), gera chaves, aplica UPDATEs.
 *
 * @return array<string,mixed>
 */
function processTable(
    \PDO $pdo,
    string $tableName,
    string $cityCol,
    string $bairroCol,
    bool   $hasNome,
    bool   $hasCidadeNome,
    bool   $dry,
    int    $limit,
): array {
    if (!tableExists($pdo, $tableName)) {
        return ['table' => $tableName, 'skipped' => true, 'reason' => 'tabela nao existe'];
    }
    if (!colExists($pdo, $tableName, 'bairro_key')) {
        return ['table' => $tableName, 'skipped' => true, 'reason' => 'coluna bairro_key nao existe — rode a migration 20260517000002 primeiro'];
    }

    // Distinct pairs that still need a key
    $stmt = $pdo->prepare(
        "SELECT DISTINCT `{$cityCol}` AS city_raw, `{$bairroCol}` AS bairro_raw
         FROM `{$tableName}`
         WHERE bairro_key IS NULL OR bairro_key = ''
         LIMIT {$limit}"
    );
    $stmt->execute();
    $pairs = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    $updated       = 0;
    $skipped       = 0;
    $conflicts     = [];
    $keyToNames    = [];
    $emptyKeyRevRows = 0;

    foreach ($pairs as $pair) {
        $cityRaw   = (string) ($pair['city_raw'] ?? '');
        $bairroRaw = (string) ($pair['bairro_raw'] ?? '');

        $bairroKey = GeoKeyNormalizer::makeNeighborhoodKey($bairroRaw);
        $cidadeKey = GeoKeyNormalizer::makeCityKey($cityRaw);
        $bairroNorm = GeoKeyNormalizer::normalizeNeighborhood($bairroRaw);
        $displayName = GeoKeyNormalizer::displayName($bairroRaw);
        $cityDisplay = GeoKeyNormalizer::displayName($cityRaw);

        if ($bairroKey === '') {
            $skipped++;
            continue;
        }

        // Track name conflicts: same key, different display names
        if (!isset($keyToNames[$bairroKey])) {
            $keyToNames[$bairroKey] = [];
        }
        if ($displayName !== '' && !in_array($displayName, $keyToNames[$bairroKey], true)) {
            $keyToNames[$bairroKey][] = $displayName;
        }

        if (!$dry) {
            $setClauses = ['bairro_key = :bk', 'bairro_norm = :bn', 'cidade_key = :ck'];
            $params     = [':bk' => $bairroKey, ':bn' => $bairroNorm, ':ck' => $cidadeKey];

            if ($hasNome) {
                $setClauses[] = 'bairro_nome = :nome';
                $params[':nome'] = $displayName;
            }
            if ($hasCidadeNome) {
                $setClauses[] = 'cidade_nome = :cnome';
                $params[':cnome'] = $cityDisplay;
            }

            $setClauses[] = "bairro_norm = :bn";

            $sql = "UPDATE `{$tableName}`
                    SET " . implode(', ', array_unique($setClauses)) . "
                    WHERE (`{$cityCol}` <=> :city OR (`{$cityCol}` IS NULL AND :city2 IS NULL))
                      AND (`{$bairroCol}` <=> :bairro OR (`{$bairroCol}` IS NULL AND :bairro2 IS NULL))
                      AND (bairro_key IS NULL OR bairro_key = '')";

            $params[':city']   = $cityRaw !== '' ? $cityRaw : null;
            $params[':city2']  = $cityRaw !== '' ? $cityRaw : null;
            $params[':bairro'] = $bairroRaw !== '' ? $bairroRaw : null;
            $params[':bairro2'] = $bairroRaw !== '' ? $bairroRaw : null;

            $up = $pdo->prepare($sql);
            $up->execute($params);
            $updated += $up->rowCount();
        } else {
            $updated++;
        }
    }

    // Detect conflicts
    foreach ($keyToNames as $key => $names) {
        if (count($names) > 1) {
            $conflicts[] = ['bairro_key' => $key, 'nomes_distintos' => $names];
        }
    }

    // Check for rows with revenue but empty bairro_key (only in geo mart)
    if (colExists($pdo, $tableName, 'faturamento')) {
        try {
            $emptyKeyRevRows = (int) $pdo->query(
                "SELECT COUNT(*) FROM `{$tableName}`
                 WHERE (bairro_key IS NULL OR bairro_key = '') AND COALESCE(faturamento, 0) > 0"
            )->fetchColumn();
        } catch (\Throwable) {}
    }

    return [
        'table'          => $tableName,
        'dry_run'        => $dry,
        'pairs_found'    => count($pairs),
        'updated'        => $updated,
        'skipped_empty'  => $skipped,
        'conflicts'      => $conflicts,
        'empty_key_with_revenue' => $emptyKeyRevRows,
        'sample_mappings' => array_slice(
            array_map(
                fn ($k, $v) => ['bairro_key' => $k, 'nomes' => $v],
                array_keys($keyToNames),
                array_values($keyToNames)
            ),
            0, 10
        ),
    ];
}

// ── Tables to process ─────────────────────────────────────────────────────────

$targets = [
    'mart_compradores_por_geo'  => [
        'city_col'      => 'cidade_norm',
        'bairro_col'    => 'bairro_norm',
        'has_nome'      => true,
        'has_city_nome' => true,
    ],
    'mart_vendas_por_cnae_cnpj' => [
        'city_col'      => 'cidade_norm',
        'bairro_col'    => 'bairro_norm',
        'has_nome'      => false,
        'has_city_nome' => false,
    ],
    'biomie_clientes_dim'       => [
        'city_col'      => colExists($pdo, 'biomie_clientes_dim', 'cidade_normalizada') ? 'cidade_normalizada' : (colExists($pdo, 'biomie_clientes_dim', 'cidade') ? 'cidade' : ''),
        'bairro_col'    => colExists($pdo, 'biomie_clientes_dim', 'bairro_normalizado') ? 'bairro_normalizado' : (colExists($pdo, 'biomie_clientes_dim', 'bairro') ? 'bairro' : ''),
        'has_nome'      => false,
        'has_city_nome' => false,
    ],
    'biomie_clientes_geo_dim'   => [
        'city_col'      => 'cidade_norm',
        'bairro_col'    => 'bairro_norm',
        'has_nome'      => false,
        'has_city_nome' => false,
    ],
];

$results = [];
foreach ($targets as $tName => $cfg) {
    if ($table !== 'all' && $table !== $tName) {
        continue;
    }
    if ($cfg['city_col'] === '' || $cfg['bairro_col'] === '') {
        $results[] = ['table' => $tName, 'skipped' => true, 'reason' => 'coluna city/bairro nao encontrada'];
        continue;
    }
    $results[] = processTable(
        $pdo,
        $tName,
        $cfg['city_col'],
        $cfg['bairro_col'],
        $cfg['has_nome'],
        $cfg['has_city_nome'],
        $dry,
        $limit,
    );
}

$summary = [
    'ok'      => true,
    'mode'    => $dry ? 'dry_run' : 'apply',
    'table'   => $table,
    'results' => $results,
    'action'  => $dry
        ? 'Adicione --apply para executar as alteracoes'
        : 'Alteracoes aplicadas. Rematerialize: php bin/materialize_marts.php',
];

if (!$json) {
    echo ($dry ? "[DRY RUN] " : "[APPLY] ") . "Backfill de bairro_key/cidade_key\n\n";
    foreach ($results as $r) {
        echo "Tabela: {$r['table']}\n";
        if (!empty($r['skipped'])) {
            echo "  -> Pulado: " . ($r['reason'] ?? '') . "\n\n";
            continue;
        }
        echo "  Pares encontrados   : " . ($r['pairs_found'] ?? 0) . "\n";
        echo "  " . ($dry ? "Seriam atualizados" : "Atualizados") . "    : " . ($r['updated'] ?? 0) . "\n";
        echo "  Pulados (key vazia) : " . ($r['skipped_empty'] ?? 0) . "\n";
        if (!empty($r['conflicts'])) {
            echo "  CONFLITOS (" . count($r['conflicts']) . "):\n";
            foreach ($r['conflicts'] as $c) {
                echo "    " . $c['bairro_key'] . " -> " . implode(', ', $c['nomes_distintos']) . "\n";
            }
        }
        if (($r['empty_key_with_revenue'] ?? 0) > 0) {
            echo "  ATENCAO: " . $r['empty_key_with_revenue'] . " linhas com faturamento sem bairro_key\n";
        }
        echo "\n";
    }
    echo "Proximo passo: php bin/materialize_marts.php\n";
}

geoOutput($summary, $json);
exit(0);
