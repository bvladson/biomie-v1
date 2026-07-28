<?php

declare(strict_types=1);

/**
 * Digest periódico (6h) — resumo dos webhooks Omie→BIomie + saúde do sync.
 * Envia ao Telegram (AlertNotifier) e publica no sininho in-app (NotificationService).
 *
 * Uso:   php bin/notifications_digest.php [--hours=6] [--pretty] [--no-notify]
 * Cron:  a cada 6h (0,6,12,18h) — ver cron/hostinger/notifications_digest.sh
 */

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\Logger;
use BIomie\Notifications\NotificationService;
use BIomie\Ops\AlertNotifier;
use BIomie\Support\SecretMask;

require_once dirname(__DIR__) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__) . '/app/Bootstrap.php';
Bootstrap::init();

$opts   = getopt('', ['hours:', 'pretty', 'no-notify']);
$hours  = max(1, (int) ($opts['hours'] ?? 6));
$pretty = isset($opts['pretty']);
$notify = !isset($opts['no-notify']);

function dq(PDO $p, string $sql): array
{
    try {
        $s = $p->query($sql);
        return $s ? $s->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (\Throwable) {
        return [];
    }
}

try {
    $config = new Config();
    $logger = new Logger(dirname(__DIR__) . '/storage/logs/notifications_digest.log');
    $conn = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo = Database::connect($config, $conn);

    // Empresas + estado de integração webhook
    $companies = [];
    foreach (dq($pdo, "SELECT c.id, c.slug, COALESCE(c.nome_fantasia, c.slug) AS name,
                              i.webhook_last_received_at AS last_received
                         FROM biomie_companies c
                         LEFT JOIN biomie_company_integrations i
                           ON i.company_id = c.id AND i.provider = 'omie'
                        WHERE c.ativo = 1 ORDER BY c.id") as $r) {
        $companies[(int) $r['id']] = [
            'name' => (string) $r['name'],
            'last_received' => $r['last_received'] ?: null,
            'received' => 0, 'processed' => 0, 'failed' => 0,
        ];
    }

    // Atividade de webhook na janela
    foreach (dq($pdo, "SELECT company_id, status, COUNT(*) n
                         FROM biomie_webhook_inbox
                        WHERE received_at >= (NOW() - INTERVAL {$hours} HOUR)
                        GROUP BY company_id, status") as $r) {
        $cid = (int) $r['company_id'];
        if (!isset($companies[$cid])) {
            continue;
        }
        $n = (int) $r['n'];
        $st = strtolower((string) $r['status']);
        $companies[$cid]['received'] += $n;
        if (str_contains($st, 'process') && !str_contains($st, 'fail')) {
            $companies[$cid]['processed'] += $n;
        } elseif (str_contains($st, 'fail')) {
            $companies[$cid]['failed'] += $n;
        }
    }

    $totReceived = 0;
    $totFailed = 0;
    $silent = [];
    $lines = [];
    foreach ($companies as $cid => $c) {
        $totReceived += $c['received'];
        $totFailed += $c['failed'];
        if ($c['last_received'] === null) {
            $lines[] = sprintf('%s: — (webhook não configurado)', $c['name']);
            continue;
        }
        $lines[] = sprintf(
            '%s: %d recebidos · %d processados%s',
            $c['name'],
            $c['received'],
            $c['processed'],
            $c['failed'] > 0 ? ' · ' . $c['failed'] . ' falhas ⚠️' : ''
        );
        // Configurada mas sem evento na janela → silêncio
        if ($c['received'] === 0) {
            $silent[] = $c['name'];
        }
    }

    // Saúde do sync (domínios que completaram nas últimas 24h)
    $syncOk = 0;
    $syncDomains = ['commercial', 'finance', 'gold'];
    foreach (dq($pdo, "SELECT domain_name, MAX(CASE WHEN status IN ('completed','completed_with_warnings') THEN finished_at END) last_ok
                         FROM biomie_sync_runs WHERE started_at >= (NOW() - INTERVAL 24 HOUR) GROUP BY domain_name") as $r) {
        if (in_array(strtolower((string) $r['domain_name']), $syncDomains, true) && $r['last_ok']) {
            $syncOk++;
        }
    }

    $header = sprintf('📥 Webhooks BIomie — últimas %dh', $hours);
    $summaryLine = sprintf('Total: %d eventos · %d falhas', $totReceived, $totFailed);
    $silentLine = $silent === []
        ? '🔇 Silêncio: nenhuma empresa configurada ficou sem evento'
        : '🔇 Silêncio: ' . implode(', ', $silent);
    $syncLine = sprintf('🔄 Sync (24h): %d/3 domínios com execução OK', $syncOk);

    $allLines = array_merge($lines, ['', $summaryLine, $silentLine, $syncLine]);
    $severity = $totFailed > 0 ? 'warning' : 'info';

    $result = [
        'ok' => true,
        'hours' => $hours,
        'total_received' => $totReceived,
        'total_failed' => $totFailed,
        'silent' => $silent,
        'sync_ok_domains' => $syncOk,
    ];

    if ($notify) {
        (new AlertNotifier($config, $logger))->send($severity, $header, $allLines);
        (new NotificationService($pdo))->push([
            'category' => 'webhook',
            'severity' => $severity,
            'title' => sprintf('Resumo de webhooks (%dh): %d eventos%s', $hours, $totReceived, $totFailed > 0 ? ', ' . $totFailed . ' falhas' : ''),
            'body' => implode("\n", $lines),
            'company_id' => null,
            'source' => 'notifications_digest',
            'link' => 'notificacoes.php',
            'dedupe_key' => 'webhook_digest_' . $hours . 'h',
            'dedupe_window_seconds' => max(60, $hours * 3600 - 600),
        ]);
        $result['notified'] = true;
    }

    if ($pretty) {
        echo $header . PHP_EOL;
        foreach ($allLines as $l) {
            echo '  ' . $l . PHP_EOL;
        }
    } else {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, SecretMask::sanitizeErrorMessage($exception->getMessage(), 500) . PHP_EOL);
    exit(2);
}
