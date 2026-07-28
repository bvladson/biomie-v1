<?php

declare(strict_types=1);

/**
 * AGENDA DE ORÇAMENTOS — CLI do scanner do snapshot vivo (Fase 4).
 *
 * Adaptador fino: monta as dependências e chama QuoteLiveSnapshotScanner.
 * Toda a regra vive em app/QuoteAgenda — dry-run e produção rodam o MESMO código.
 *
 * Uso:
 *   php bin/quote_agenda_scan.php --company=MAG_FILIAL            # dry-run
 *   php bin/quote_agenda_scan.php --company=MAG_FILIAL --apply
 *   php bin/quote_agenda_scan.php --all --apply                   # sequencial
 */

use BIomie\Bootstrap;
use BIomie\BronzeRepository;
use BIomie\Cache;
use BIomie\CanonicalRepository;
use BIomie\Config;
use BIomie\Database;
use BIomie\Logger;
use BIomie\Omie\OmieClientFactory;
use BIomie\OmieRepository;
use BIomie\QuoteAgenda\QuoteLiveSnapshotScanner;
use BIomie\QuoteAgenda\QuoteLiveSnapshotService;
use BIomie\SharedDatabaseConnection;
use BIomie\Support\TenantCliContext;

require_once dirname(__DIR__) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require dirname(__DIR__) . '/app/Bootstrap.php';
Bootstrap::init();
set_time_limit(0);

$opts   = getopt('', ['company:', 'apply', 'all', 'etapa:', 'json']);
$apply  = isset($opts['apply']);
$etapa  = (string) ($opts['etapa'] ?? '10');
$jsonMode = isset($opts['json']);

$config = new Config();
$logger = new Logger(dirname(__DIR__) . '/storage/logs/biomie.log');
$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$shared = new SharedDatabaseConnection($config, $connection);
$pdo = $shared->current();
$reconnect = static fn () => $shared->recover();

// Empresas alvo — execução SEQUENCIAL, nunca concorrente (rate limit conservador).
$targets = [];
if (isset($opts['all'])) {
    $rows = $pdo->query(
        "SELECT c.slug FROM biomie_companies c
           JOIN biomie_company_integrations i ON i.company_id = c.id AND i.provider = 'omie' AND i.active = 1
          WHERE c.ativo = 1 ORDER BY c.id"
    )->fetchAll(PDO::FETCH_COLUMN);
    $targets = array_map('strval', $rows);
} elseif (($opts['company'] ?? '') !== '') {
    $targets = [(string) $opts['company']];
} else {
    fwrite(STDERR, "Uso: --company=SLUG [--apply] | --all [--apply]\n");
    exit(2);
}

// Empresas com cobrança ATIVADA. Só a falha destas derruba o batch: uma
// empresa não-ativada (ou sem orçamentos, como uma matriz recém-criada) pode
// falhar por anomalia de dado sem que isso mascare falhas das que operam.
$enabledIds = array_map('intval', $pdo->query(
    'SELECT company_id FROM biomie_quote_followup_policy WHERE enabled = 1'
)->fetchAll(PDO::FETCH_COLUMN));

$results = [];
$hasError = false;

foreach ($targets as $slug) {
    $tenant = TenantCliContext::boot($pdo, ['scan', '--company=' . $slug]);
    $cid = (int) $tenant['company_id'];

    $canonical = new CanonicalRepository($pdo, $logger, $reconnect, $cid, $tenant['canal']);
    $canonical->ensureFiscalSchema();
    $bronze = new BronzeRepository($pdo, $logger, $cid, $reconnect);
    $bronze->ensureSchema();
    $cache = new Cache(dirname(__DIR__) . '/storage/cache');
    $client = (new OmieClientFactory($pdo, $logger, $config, $cache, $reconnect))->forCompany($cid);
    $omie = new OmieRepository($client, $config, $bronze, 'quote-agenda-scan');

    $service = new QuoteLiveSnapshotService($pdo, $client, $omie, $config, $canonical, $cid);
    $scanner = new QuoteLiveSnapshotScanner($pdo, $service, $logger, $cid);

    try {
        $r = $scanner->scan($apply, $etapa);
    } catch (Throwable $e) {
        $r = ['ok' => false, 'company_id' => $cid, 'error' => $e->getMessage()];
    }
    $r['company_slug'] = $tenant['slug'];
    $r['policy_enabled'] = in_array($cid, $enabledIds, true);
    $results[] = $r;
    // Só falha o batch se uma empresa ATIVADA falhou. Falha de não-ativada é
    // visível no relatório/log, mas não trip o cron nem o monitoramento.
    if (!($r['ok'] ?? false) && !($r['skipped'] ?? false) && $r['policy_enabled']) {
        $hasError = true;
    }

    if (!$jsonMode) {
        printReport($r, $apply);
    }
}

if ($jsonMode) {
    echo json_encode(['ok' => !$hasError, 'apply' => $apply, 'results' => $results],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
}

exit($hasError ? 1 : 0);

/** @param array<string,mixed> $r */
function printReport(array $r, bool $apply): void
{
    echo str_repeat('═', 74), PHP_EOL;
    echo '  SCANNER · ' . ($r['company_slug'] ?? '?') . ' (company ' . ($r['company_id'] ?? '?') . ') · '
        . ($apply ? 'APPLY' : 'dry-run'), PHP_EOL;
    echo str_repeat('═', 74), PHP_EOL;

    if ($r['skipped'] ?? false) {
        echo '  ⏭  pulado: ' . ($r['reason'] ?? '?') . PHP_EOL;
        return;
    }
    if (isset($r['error'])) {
        echo '  ❌ ERRO: ' . $r['error'] . PHP_EOL;
        return;
    }
    if (!($r['ok'] ?? false)) {
        echo '  ❌ BLOQUEADO (nenhuma ausência marcada, last_successful_at intacto):' . PHP_EOL;
        foreach ((array) ($r['blocking'] ?? []) as $b) { echo '     · ' . $b . PHP_EOL; }
        return;
    }

    printf("  live_total         : %d%s", $r['live_total'] ?? 0, PHP_EOL);
    printf("  elegíveis          : %d%s", $r['eligible_total'] ?? 0, PHP_EOL);
    $exc = (array) ($r['excluded'] ?? []);
    printf("  excluídos (dura)   : %d%s%s", count($exc), $exc ? ' → ' . implode(',', $exc) : '', PHP_EOL);
    printf("  canonical_existing : %d%s", $r['canonical_existing'] ?? 0, PHP_EOL);
    $miss = (array) ($r['canonical_missing'] ?? []);
    printf("  canonical_missing  : %d%s%s", count($miss), $miss ? ' → ' . implode(',', array_slice($miss, 0, 10)) : '', PHP_EOL);
    printf("  snapshot_hash      : %s%s", substr((string) ($r['snapshot_hash'] ?? ''), 0, 16), PHP_EOL);

    $drifts = (array) ($r['drifts'] ?? []);
    printf("  com diferença      : %d%s", count($drifts), PHP_EOL);
    foreach ($drifts as $id => $ch) {
        echo '    · pedido ' . $id . ': ' . implode(', ', array_keys((array) $ch)) . PHP_EOL;
    }

    if ($r['applied'] ?? false) {
        $u = (array) ($r['upsert'] ?? []);
        $f = (array) ($r['followups'] ?? []);
        printf("%s  ✅ APLICADO · upsert: ins=%d upd=%d unch=%d skip=%d%s",
            PHP_EOL, $u['inserted'] ?? 0, $u['updated'] ?? 0, $u['unchanged'] ?? 0, $u['skipped'] ?? 0, PHP_EOL);
        printf("     followups: criados=%d atualizados=%d reapareceram=%d · ausências marcadas=%d%s",
            $f['created'] ?? 0, $f['refreshed'] ?? 0, $f['reappeared'] ?? 0, $r['missing_marked'] ?? 0, PHP_EOL);
    } else {
        echo PHP_EOL . '  (dry-run — nada escrito)' . PHP_EOL;
    }
}
