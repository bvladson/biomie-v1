<?php

declare(strict_types=1);

/**
 * AGENDA DE ORÇAMENTOS — emissor de notificações (Fase 8).
 *
 * SEGURO POR PADRÃO: sem --apply nada é emitido. Decide, explica e sai.
 *
 * NÃO consulta o Omie. Lê o que o scanner já gravou; se o scanner parou, o
 * frescor acusa e o digest é SUPRIMIDO (ver QuoteAgendaNotificationPolicy).
 *
 *   php bin/quote_agenda_notify.php --company=MAG_FILIAL
 *   php bin/quote_agenda_notify.php --all --apply
 */

require_once __DIR__ . '/../app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require __DIR__ . '/../app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Config;
use BIomie\Database;
use BIomie\Logger;
use BIomie\Notifications\NotificationService;
use BIomie\Ops\AlertNotifier;
use BIomie\QuoteAgenda\QuoteAgendaNotificationPolicy;
use BIomie\QuoteAgenda\QuoteAgendaNotifier;
use BIomie\QuoteAgenda\QuoteAgendaReadService;
use BIomie\Support\TenantCliContext;

$opts    = getopt('', ['company:', 'all', 'apply', 'json', 'only:']);
$apply   = isset($opts['apply']);
$jsonOut = isset($opts['json']);
$slugOpt = isset($opts['company']) ? strtoupper(trim((string) $opts['company'])) : null;

// --only=digest (cron diário matinal) · --only=stale (cron frequente) · ambos.
$only = isset($opts['only']) ? strtolower(trim((string) $opts['only'])) : null;
if ($only !== null && !in_array($only, ['digest', 'stale'], true)) {
    fwrite(STDERR, "--only aceita apenas 'digest' ou 'stale'.\n");
    exit(2);
}

if ($slugOpt === null && !isset($opts['all'])) {
    fwrite(STDERR, "Use --company=SLUG ou --all. Sem --apply nada é emitido.\n");
    exit(2);
}

$config = new Config();
$logger = new Logger(dirname(__DIR__) . '/storage/logs/quote_agenda_notify.log');
$conn   = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo    = Database::connect($config, $conn);

// Flags globais: valem para TODAS as empresas. A granularidade por empresa
// vive em biomie_quote_followup_policy.enabled.
$flagsGlobal = [
    'module_enabled'        => (bool) $config->get('features.quote_agenda_enabled', false),
    'notifications_enabled' => (bool) $config->get('features.quote_agenda_notifications_enabled', false),
];

// A coluna é `ativo` (pt-BR) nesta tabela — não `is_active` como em biomie_users.
$companies = $pdo->query(
    'SELECT id, slug FROM biomie_companies WHERE ativo = 1 ORDER BY id'
)->fetchAll(PDO::FETCH_ASSOC);

if ($slugOpt !== null) {
    $companies = array_values(array_filter(
        $companies,
        static fn (array $c): bool => strtoupper((string) $c['slug']) === $slugOpt
    ));
    if ($companies === []) {
        fwrite(STDERR, "Empresa {$slugOpt} não encontrada ou inativa.\n");
        exit(2);
    }
}

$notifier = new QuoteAgendaNotifier(
    $pdo,
    new NotificationService($pdo),
    // Telegram/webhook só no apply: dry-run jamais toca canal externo.
    $apply ? new AlertNotifier($config, $logger) : null
);

$results = [];
foreach ($companies as $c) {
    $cid  = (int) $c['id'];
    $slug = (string) $c['slug'];

    // Mesmo bootstrap do scanner irmão (bin/quote_agenda_scan.php).
    TenantCliContext::boot($pdo, ['notify', '--company=' . $slug]);

    $policyRow = $pdo->prepare('SELECT enabled FROM biomie_quote_followup_policy WHERE company_id = :cid');
    $policyRow->execute(['cid' => $cid]);
    $policyEnabled = (bool) ($policyRow->fetchColumn() ?: 0);

    $read = new QuoteAgendaReadService($pdo, $cid);

    // Itens e frescor lidos na MESMA visão consistente: um scanner concluindo
    // no meio da leitura não pode produzir "dado fresco + lista velha".
    $bundle = $read->readWithFreshness(static fn (): array => $read->openItems());

    $results[] = $notifier->runForCompany(
        $cid,
        $slug,
        $flagsGlobal + ['policy_enabled' => $policyEnabled],
        $bundle['freshness'],
        $bundle['data'],
        $apply,
        $only
    );
}

if ($jsonOut) {
    echo json_encode(['ok' => true, 'applied' => $apply, 'companies' => $results],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit(0);
}

echo PHP_EOL, '  AGENDA — NOTIFICAÇÕES · ', $apply ? 'APPLY' : 'DRY-RUN (nada emitido)',
    $only !== null ? ' · only=' . $only : '', PHP_EOL;
printf("  flags globais: modulo=%s notificacoes=%s%s%s",
    var_export($flagsGlobal['module_enabled'], true),
    var_export($flagsGlobal['notifications_enabled'], true), PHP_EOL, PHP_EOL);

foreach ($results as $r) {
    printf("  ── %s (id=%d) · destinatarios=%d%s", $r['company_slug'], $r['company_id'], $r['recipients'], PHP_EOL);
    foreach (['digest', 'stale'] as $k) {
        $e = $r[$k];
        $status = $e['decision'] === 'send'
            ? ($e['emitted'] ? 'EMITIDO' : ($e['dedup_hit'] ? 'deduplicado' : ($apply ? 'FALHOU' : 'emitiria')))
            : 'suprimido';
        printf("     %-7s %-12s motivo=%s%s", $k, $status, $e['reason'], PHP_EOL);
        if (isset($e['preview'])) {
            printf("             \"%s\"%s", $e['preview']['title'], PHP_EOL);
            foreach ($e['preview']['lines'] as $l) { printf("               · %s%s", $l, PHP_EOL); }
        }
        if (isset($e['error'])) { printf("             ERRO: %s%s", $e['error'], PHP_EOL); }
    }
    echo PHP_EOL;
}

// Motivo mais comum de silêncio, explicado uma vez só no rodapé.
$suprimidos = array_filter($results, static fn (array $r): bool => $r['digest']['decision'] === 'suppress');
if ($suprimidos !== []) {
    echo '  Digests suprimidos existem por desenho: ', PHP_EOL;
    echo '    ', QuoteAgendaNotificationPolicy::R_STALE_SOURCE,
         ' = dado vencido nao vira cobranca (evita cobrar vendedor por orcamento ja faturado).', PHP_EOL;
}

exit(0);
