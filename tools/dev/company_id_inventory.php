<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$opts = getopt('', ['output:']);
$config = new Config();
$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo = Database::connect($config, $connection);

$sql = <<<'SQL'
SELECT
    c.TABLE_NAME,
    c.COLUMN_NAME,
    c.COLUMN_TYPE,
    c.IS_NULLABLE,
    c.COLUMN_KEY,
    c.EXTRA,
    COALESCE(t.TABLE_ROWS, 0) AS estimated_rows,
    CASE WHEN fk.TABLE_NAME IS NULL THEN 0 ELSE 1 END AS has_foreign_key,
    CASE WHEN ix.TABLE_NAME IS NULL THEN 0 ELSE 1 END AS is_indexed
FROM information_schema.COLUMNS c
JOIN information_schema.TABLES t
  ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
LEFT JOIN (
    SELECT DISTINCT TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME
      FROM information_schema.KEY_COLUMN_USAGE
     WHERE TABLE_SCHEMA = DATABASE()
       AND REFERENCED_TABLE_NAME IS NOT NULL
) fk ON fk.TABLE_SCHEMA = c.TABLE_SCHEMA
    AND fk.TABLE_NAME = c.TABLE_NAME
    AND fk.COLUMN_NAME = c.COLUMN_NAME
LEFT JOIN (
    SELECT DISTINCT TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME
      FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
) ix ON ix.TABLE_SCHEMA = c.TABLE_SCHEMA
    AND ix.TABLE_NAME = c.TABLE_NAME
    AND ix.COLUMN_NAME = c.COLUMN_NAME
WHERE c.TABLE_SCHEMA = DATABASE()
  AND c.COLUMN_NAME = 'company_id'
ORDER BY c.TABLE_NAME
SQL;

$rows = [];
foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $type = strtolower((string) $row['COLUMN_TYPE']);
    $rows[] = [
        'table' => (string) $row['TABLE_NAME'],
        'column' => (string) $row['COLUMN_NAME'],
        'type' => $type,
        'unsigned' => str_contains($type, 'unsigned'),
        'nullable' => $row['IS_NULLABLE'] === 'YES',
        'indexed' => (bool) $row['is_indexed'],
        'foreign_key' => (bool) $row['has_foreign_key'],
        'estimated_rows' => (int) $row['estimated_rows'],
        'family' => match (true) {
            str_contains((string) $row['TABLE_NAME'], 'user'),
            str_contains((string) $row['TABLE_NAME'], 'access'),
            str_contains((string) $row['TABLE_NAME'], 'context') => 'access_context',
            str_contains((string) $row['TABLE_NAME'], 'quote') => 'quote_agenda',
            str_starts_with((string) $row['TABLE_NAME'], 'mart_') => 'marts',
            str_contains((string) $row['TABLE_NAME'], 'job'),
            str_contains((string) $row['TABLE_NAME'], 'webhook'),
            str_contains((string) $row['TABLE_NAME'], 'operational') => 'operations',
            default => 'canonical_commercial',
        },
    ];
}

$narrow = array_values(array_filter(
    $rows,
    static fn (array $row): bool => str_starts_with($row['type'], 'tinyint')
        || str_starts_with($row['type'], 'smallint')
));
$payload = [
    'ok' => true,
    'mode' => 'read-only',
    'generated_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
    'canonical_future_type' => 'INT UNSIGNED',
    'decision' => 'Do not migrate in one release; migrate by family after FK and lock rehearsal.',
    'summary' => [
        'columns' => count($rows),
        'narrow_columns' => count($narrow),
        'indexed_columns' => count(array_filter($rows, static fn (array $row): bool => $row['indexed'])),
        'foreign_keys' => count(array_filter($rows, static fn (array $row): bool => $row['foreign_key'])),
    ],
    'columns' => $rows,
];

$output = isset($opts['output']) ? (string) $opts['output'] : '';
if ($output !== '') {
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || file_put_contents($output, $json . PHP_EOL) === false) {
        throw new RuntimeException('Unable to write company_id inventory.');
    }
}
echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
