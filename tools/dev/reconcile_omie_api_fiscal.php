<?php

declare(strict_types=1);

/**
 * Reconciliacao Omie API x Canonico
 *
 * Compara as NFs retornadas pela API Omie (ListarNF por data de emissao)
 * com as NFs canonicas armazenadas no banco local. Nenhum CSV ou planilha.
 *
 * Uso:
 *   php tools/dev/reconcile_omie_api_fiscal.php [opcoes]
 *
 * Opcoes:
 *   --start=YYYY-MM-DD     Data inicial (padrao: 1o dia do mes corrente)
 *   --end=YYYY-MM-DD       Data final   (padrao: ultimo dia do mes corrente)
 *   --json                 Saida JSON pura (para integracao com outros scripts)
 *   --explain              Explica cada diferenca encontrada
 *   --sample               Exibe amostra das primeiras 10 NFs de cada lado
 *   --api-only             Busca apenas na API Omie, sem comparar com o banco
 *   --canonical-only       Busca apenas no banco canonico, sem chamar a API Omie
 *   --no-api               Alias de --canonical-only
 */

use BIomie\Bootstrap;
use BIomie\BronzeRepository;
use BIomie\Cache;
use BIomie\CanonicalRepository;
use BIomie\Config;
use BIomie\Database;
use BIomie\Logger;
use BIomie\OmieApiReconciliationService;
use BIomie\OmieClient;
use BIomie\OmieMethodPolicyRegistry;
use BIomie\OmieRepository;
use BIomie\Support\SecretMask;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

// ── Argumentos ────────────────────────────────────────────────────────────────

$opts = getopt('', ['start:', 'end:', 'json', 'explain', 'sample', 'api-only', 'canonical-only', 'no-api']);
$start       = (string) ($opts['start'] ?? date('Y-m-01'));
$end         = (string) ($opts['end'] ?? date('Y-m-t'));
$isJson      = isset($opts['json']);
$explain     = isset($opts['explain']);
$sample      = isset($opts['sample']);
$apiOnly     = isset($opts['api-only']);
$canonOnly   = isset($opts['canonical-only']) || isset($opts['no-api']);

function output(mixed $data, bool $json): void
{
    if ($json) {
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    } else {
        printHuman($data);
    }
}

function printHuman(mixed $data, int $indent = 0): void
{
    $pad = str_repeat('  ', $indent);
    if (!is_array($data)) {
        echo $pad . (is_bool($data) ? ($data ? 'true' : 'false') : (string) $data) . "\n";
        return;
    }

    foreach ($data as $k => $v) {
        if (is_array($v)) {
            echo $pad . $k . ":\n";
            printHuman($v, $indent + 1);
        } else {
            echo $pad . $k . ': ' . (is_bool($v) ? ($v ? 'true' : 'false') : (string) $v) . "\n";
        }
    }
}

// ── Infraestrutura ────────────────────────────────────────────────────────────

$root   = dirname(__DIR__, 2);
$config = new Config();
$logger = new Logger($root . '/storage/logs/biomie.log');

$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
if (!Database::isConfigured($config, $connection)) {
    output(['ok' => false, 'error' => 'no_database', 'message' => 'Banco de dados nao configurado.'], $isJson);
    exit(2);
}

try {
    $pdo = Database::connect($config, $connection);
} catch (Throwable $exception) {
    output([
        'ok' => false,
        'error' => 'database_connection_failed',
        'message' => SecretMask::sanitizeErrorMessage($exception->getMessage(), 220),
    ], $isJson);
    exit(2);
}

$canonical = new CanonicalRepository($pdo, $logger);

// ── Busca canonico local ──────────────────────────────────────────────────────

$systemRows = [];
if (!$apiOnly) {
    try {
        $stmt = $pdo->prepare(
            'SELECT numero_nf, serie_nf, chave_nfe, status_fiscal,
                    data_emissao, nome_vendedor, nome_cliente,
                    valor_total, valor_mercadorias, valor_descontos
             FROM biomie_notas_fiscais_canonicas
             WHERE data_emissao BETWEEN :start AND :end
             ORDER BY data_emissao, numero_nf'
        );
        $stmt->execute([':start' => $start, ':end' => $end]);
        $systemRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $exception) {
        output([
            'ok' => false,
            'error' => 'canonical_query_failed',
            'message' => SecretMask::sanitizeErrorMessage($exception->getMessage(), 220),
        ], $isJson);
        exit(2);
    }
}

if ($canonOnly) {
    $authorized = array_filter($systemRows, fn ($r) => ($r['status_fiscal'] ?? '') === 'autorizada');
    $cancelled  = array_filter($systemRows, fn ($r) => ($r['status_fiscal'] ?? '') === 'cancelada');
    $result = [
        'ok'     => true,
        'mode'   => 'canonical_only',
        'period' => ['start' => $start, 'end' => $end],
        'canonical' => [
            'total_rows'        => count($systemRows),
            'authorized'        => count($authorized),
            'cancelled'         => count($cancelled),
            'invoice_total'     => round(array_sum(array_column(iterator_to_array($authorized, false), 'valor_total')), 2),
            'merchandise_total' => round(array_sum(array_column(iterator_to_array($authorized, false), 'valor_mercadorias')), 2),
        ],
    ];
    if ($sample) {
        $result['sample'] = array_values(array_slice($systemRows, 0, 10));
    }
    output($result, $isJson);
    exit(0);
}

// ── Omie API ──────────────────────────────────────────────────────────────────

if (!$config->hasOmieCredentials()) {
    output([
        'ok'      => false,
        'error'   => 'omie_not_configured',
        'message' => 'Credenciais Omie nao configuradas (omie.app_key / omie.app_secret).',
        'hint'    => 'Use --canonical-only para inspecionar apenas o banco local.',
    ], $isJson);
    exit(2);
}

try {
    $cache  = new Cache($root . '/storage/cache');
    $bronze = BronzeRepository::boot($config, $logger);
    $client = new OmieClient(
        (string) $config->get('omie.app_key'),
        (string) $config->get('omie.app_secret'),
        (int) $config->get('omie.request_timeout', 20),
        $logger,
        new OmieMethodPolicyRegistry($config),
        $cache,
    );
    $omieRepo = new OmieRepository($client, $config, $bronze, 'reconcile-cli');
} catch (Throwable $exception) {
    output([
        'ok'      => false,
        'error'   => 'omie_init_failed',
        'message' => SecretMask::sanitizeErrorMessage($exception->getMessage(), 220),
    ], $isJson);
    exit(2);
}

// ── Reconciliacao ─────────────────────────────────────────────────────────────

if (!$isJson) {
    echo "Reconciliando periodo {$start} ate {$end} via API Omie...\n";
    echo "NFs canonicas no banco (por data de emissao): " . count($systemRows) . "\n\n";
}

try {
    $svc    = new OmieApiReconciliationService($omieRepo, $logger);
    $report = $svc->buildPeriodReport($start, $end, $systemRows);
} catch (Throwable $exception) {
    output([
        'ok'      => false,
        'error'   => 'reconciliation_failed',
        'message' => SecretMask::sanitizeErrorMessage($exception->getMessage(), 220),
    ], $isJson);
    exit(2);
}

// ── Formatacao da saida ───────────────────────────────────────────────────────

$metrics     = $report['metrics'] ?? [];
$differences = $report['differences'] ?? [];
$warnings    = $report['warnings'] ?? [];

$result = [
    'ok'      => true,
    'mode'    => $apiOnly ? 'api_only' : 'api_vs_canonical',
    'period'  => ['start' => $start, 'end' => $end],
    'source'  => [
        'type'         => 'omie_api',
        'method'       => 'ListarNF por data de emissao',
        'generated_at' => $report['source']['generated_at'] ?? date(DATE_ATOM),
    ],
    'summary' => [
        'omie_api'       => [
            'authorized'        => (int) ($report['source']['summary']['authorized_notes'] ?? $metrics['notes_omie'] ?? 0),
            'cancelled'         => (int) ($report['source']['summary']['cancelled_notes'] ?? 0),
            'invoice_total'     => (float) ($report['source']['summary']['authorized_invoice_total'] ?? 0),
            'merchandise_total' => (float) ($report['source']['summary']['authorized_merchandise_total'] ?? 0),
        ],
        'canonical_db'   => [
            'total_by_issue_date' => count($systemRows),
            'authorized'          => count(array_filter($systemRows, fn ($r) => ($r['status_fiscal'] ?? '') === 'autorizada')),
        ],
        'reconciliation' => [
            'matches'           => (int) ($metrics['matches'] ?? 0),
            'missing_in_system' => (int) ($metrics['missing_in_system'] ?? 0),
            'extra_in_system'   => (int) ($metrics['extra_in_system'] ?? 0),
            'value_mismatches'  => (int) ($metrics['value_mismatches'] ?? 0),
            'status_mismatches' => (int) ($metrics['status_mismatches'] ?? 0),
            'vendor_mismatches' => (int) ($metrics['vendor_mismatches'] ?? 0),
        ],
    ],
    'warnings' => $warnings,
];

if ($explain && $differences !== []) {
    $explained = [];
    foreach ($differences as $diff) {
        if (($diff['type'] ?? 'match') === 'match') {
            continue;
        }
        $explained[] = [
            'note_ref' => $diff['note_ref'] ?? $diff['note_key'] ?? '?',
            'type'     => $diff['type'] ?? '?',
            'issues'   => $diff['issues'] ?? [],
            'omie'     => array_intersect_key(
                $diff['csv'] ?? [],
                array_flip(['status', 'issue_date', 'vendor_name', 'invoice_total', 'merchandise_total'])
            ),
            'canonical' => array_intersect_key(
                $diff['system'] ?? [],
                array_flip(['status', 'issue_date', 'vendor_name', 'invoice_total', 'merchandise_total'])
            ),
        ];
    }
    $result['differences'] = $explained;
}

if ($sample) {
    $notes = $report['source']['notes'] ?? [];
    $result['sample_omie']      = array_values(array_slice($notes, 0, 10));
    $result['sample_canonical'] = array_values(array_slice($systemRows, 0, 10));
}

if (!$isJson) {
    $s = $result['summary'];
    echo "=== Omie API ===\n";
    echo "  Autorizadas : " . $s['omie_api']['authorized'] . "\n";
    echo "  Canceladas  : " . $s['omie_api']['cancelled'] . "\n";
    echo sprintf("  Faturamento : R$ %s\n", number_format($s['omie_api']['invoice_total'], 2, ',', '.'));
    echo sprintf("  Mercadorias : R$ %s\n\n", number_format($s['omie_api']['merchandise_total'], 2, ',', '.'));

    echo "=== Canonico (banco local, por data de emissao) ===\n";
    echo "  Total       : " . $s['canonical_db']['total_by_issue_date'] . "\n";
    echo "  Autorizadas : " . $s['canonical_db']['authorized'] . "\n\n";

    $r = $s['reconciliation'];
    echo "=== Reconciliacao ===\n";
    echo "  Matches           : " . $r['matches'] . "\n";
    echo "  Faltam no banco   : " . $r['missing_in_system'] . "\n";
    echo "  Extras no banco   : " . $r['extra_in_system'] . "\n";
    echo "  Valores diferentes: " . $r['value_mismatches'] . "\n";
    echo "  Status diferentes : " . $r['status_mismatches'] . "\n";

    if ($warnings !== []) {
        echo "\nAvisos:\n";
        foreach ($warnings as $w) {
            echo "  ! {$w}\n";
        }
    }

    if ($explain && isset($result['differences']) && $result['differences'] !== []) {
        echo "\nDiferencas detalhadas:\n";
        foreach ($result['differences'] as $d) {
            echo "  [{$d['type']}] NF {$d['note_ref']}:\n";
            foreach ($d['issues'] as $issue) {
                echo "    - {$issue}\n";
            }
        }
    }

    if ($r['missing_in_system'] > 0) {
        echo "\nACIO RECOMENDADA: Execute o sync Omie para importar NFs faltantes:\n";
        echo "  php cron/sync_realtime_pipeline.php --domains=fiscal --mode=reconcile --reconcile=30 --json\n";
        echo "  php bin/materialize_marts.php --json\n";
    }

    echo "\nPara saida JSON completa: --json\n";
    echo "Para detalhar diferencas : --explain\n";
}

output($result, $isJson);
exit(0);
