<?php

declare(strict_types=1);

/**
 * Read-only audit for biomie_shadow_validations.
 *
 * Usage:
 *   php tools/dev/shadow_audit.php --hours=24 --pretty
 *   php tools/dev/shadow_audit.php --hours=168 --json
 */

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\Support\SecretMask;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$opts = getopt('', ['hours:', 'json', 'pretty']);
$hours = max(1, min(24 * 30, (int) ($opts['hours'] ?? 24)));
$json = isset($opts['json']);

function shadowJson(array $payload): void
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
}

function shadowFail(string $message, bool $json, int $exitCode): never
{
    $payload = ['ok' => false, 'error' => $message];
    if ($json) {
        shadowJson($payload);
    } else {
        echo "[ERRO] {$message}\n";
    }
    exit($exitCode);
}

function shadowTableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function classifyShadowRow(array $row): array
{
    $endpoint = (string) ($row['endpoint_name'] ?? '');
    $legacySource = (string) ($row['legacy_source'] ?? '');
    $candidateSource = (string) ($row['candidate_source'] ?? '');
    $context = json_decode((string) ($row['query_context_json'] ?? ''), true);
    $diff = json_decode((string) ($row['diff_json'] ?? ''), true);
    $details = is_array($diff['details'] ?? null) ? $diff['details'] : [];
    $summaryDiffs = is_array($details['summary']['mismatches'] ?? null) ? $details['summary']['mismatches'] : [];
    $freshnessDiffs = is_array($details['freshness']['mismatches'] ?? null) ? $details['freshness']['mismatches'] : [];

    $classification = 'unknown';
    $rootCause = 'Divergencia requer investigacao manual.';
    $recommendation = 'Investigar antes de corrigir.';
    $depends = [
        'commercial_marts' => false,
        'geo' => false,
        'base_comercial_nf' => false,
        'licitacao_visibility' => false,
        'finance' => str_starts_with($endpoint, 'finance_'),
    ];

    if ($endpoint === 'finance_overview'
        && $legacySource === 'historical-snapshot'
        && $candidateSource === 'canonical_shadow') {
        $classification = 'regra_antiga_vs_regra_nova';
        $rootCause = 'Snapshot financeiro legado/Omie comparado com tabela canonica financeira local; amostra recente nao depende de Geo nem Base Comercial NF.';
        $recommendation = 'Nao alterar FIN.R1/R2 nesta fase; manter como divergencia documentada e monitorar nova amostra apos sync/cache.';

        $receivedRows = is_array($details['received_rows'] ?? null) ? $details['received_rows'] : [];
        if (($receivedRows['legacy_count'] ?? null) === ($receivedRows['candidate_count'] ?? null)
            && ((int) ($receivedRows['missing_count'] ?? 0)) > 0
            && ((int) ($receivedRows['extra_count'] ?? 0)) > 0) {
            $classification = 'legacy_snapshot_vs_canonical_ordering_or_freshness';
            $rootCause = 'A lista recebida e limitada ao top 80; legacy e canonical tem mesma contagem exibida, mas ordenacao/conjunto divergem enquanto o resumo canonico tem +1 titulo.';
        }
    } elseif (($row['diff_type'] ?? '') === 'candidate_unavailable') {
        $classification = 'candidate_unavailable';
        $rootCause = 'Payload candidato nao foi gerado.';
        $recommendation = 'Verificar disponibilidade canonical para o endpoint.';
    } elseif (($row['diff_type'] ?? '') === 'match') {
        $classification = 'match';
        $rootCause = 'Sem divergencia material.';
        $recommendation = 'Nenhuma acao.';
    }

    return [
        'id' => (int) ($row['id'] ?? 0),
        'endpoint' => $endpoint,
        'executed_at' => (string) ($row['executed_at'] ?? ''),
        'diff_type' => (string) ($row['diff_type'] ?? ''),
        'severity' => (string) ($row['severity'] ?? ''),
        'summary' => (string) ($row['summary'] ?? ''),
        'query_signature' => (string) ($row['query_signature'] ?? ''),
        'legacy_source' => $legacySource,
        'candidate_source' => $candidateSource,
        'context' => is_array($context) ? $context : [],
        'summary_mismatches' => $summaryDiffs,
        'freshness_mismatches' => $freshnessDiffs,
        'classification' => $classification,
        'root_cause' => $rootCause,
        'recommendation' => $recommendation,
        'depends_on' => $depends,
    ];
}

function printShadowHuman(array $payload): void
{
    $summary = $payload['summary'];
    echo "Shadow audit ({$payload['hours']}h)\n";
    echo "  total              : {$summary['total']}\n";
    echo "  diverged           : {$summary['diverged']}\n";
    echo "  divergence_pct     : {$summary['divergence_pct']}\n";
    echo "  query_signatures   : {$summary['query_signatures']}\n";
    echo "  endpoints          : {$summary['endpoints']}\n\n";

    foreach ($payload['records'] as $record) {
        echo "#{$record['id']} {$record['endpoint']} {$record['executed_at']}\n";
        echo "  diff/severity      : {$record['diff_type']} / {$record['severity']}\n";
        echo "  sources            : {$record['legacy_source']} -> {$record['candidate_source']}\n";
        echo "  classification     : {$record['classification']}\n";
        echo "  root_cause         : {$record['root_cause']}\n";
        echo "  recommendation     : {$record['recommendation']}\n";
        if ($record['summary_mismatches'] !== []) {
            echo "  summary_mismatches :\n";
            foreach ($record['summary_mismatches'] as $path => $diff) {
                $legacy = $diff['legacy'] ?? null;
                $candidate = $diff['candidate'] ?? null;
                $delta = $diff['delta'] ?? null;
                echo "    - {$path}: legacy={$legacy} candidate={$candidate} delta={$delta}\n";
            }
        }
        echo "\n";
    }
}

$config = new Config();

try {
    [$pdo, $connectionName] = Database::getGeoConnection($config);
} catch (Throwable $exception) {
    shadowFail('db_connect_failed: ' . SecretMask::sanitizeErrorMessage($exception->getMessage(), 220), $json, 2);
}

if (!shadowTableExists($pdo, 'biomie_shadow_validations')) {
    shadowFail('biomie_shadow_validations_missing', $json, 2);
}

$stmt = $pdo->prepare(
    'SELECT id, endpoint_name, query_signature, query_context_json,
            legacy_payload_hash, candidate_payload_hash, diff_type, severity,
            summary, diff_json, sample_rate, sample_bucket, request_id,
            legacy_source, candidate_source, executed_at
     FROM biomie_shadow_validations
     WHERE executed_at >= DATE_SUB(NOW(), INTERVAL :hours HOUR)
     ORDER BY executed_at DESC, id DESC'
);
$stmt->bindValue(':hours', $hours, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$records = array_map('classifyShadowRow', $rows);
$total = count($records);
$diverged = count(array_filter($records, static fn (array $row): bool => $row['diff_type'] !== 'match'));
$signatures = count(array_unique(array_map(static fn (array $row): string => (string) $row['query_signature'], $records)));
$endpoints = array_values(array_unique(array_map(static fn (array $row): string => (string) $row['endpoint'], $records)));

$payload = [
    'ok' => true,
    'generated_at' => date(DATE_ATOM),
    'connection' => $connectionName,
    'hours' => $hours,
    'summary' => [
        'total' => $total,
        'diverged' => $diverged,
        'divergence_pct' => $total > 0 ? round(($diverged / $total) * 100, 2) : null,
        'query_signatures' => $signatures,
        'endpoints' => implode(',', $endpoints),
    ],
    'records' => $records,
];

if ($json) {
    shadowJson($payload);
} else {
    printShadowHuman($payload);
}
