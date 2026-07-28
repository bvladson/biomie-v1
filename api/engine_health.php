<?php

/**
 * api/engine_health.php — Saúde do Motor por empresa (read-only).
 *
 * Unifica num só lugar os sinais hoje espalhados: integração Omie, webhook,
 * backlog do inbox, frescor de sync por domínio e atividade recente.
 *
 * GET → { ok, generated_at, companies: [ ... ] }
 * Permissão: admin.view | jobs.view | operations.view
 */

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Database;
use BIomie\Http;
use BIomie\RequestContext;

require __DIR__ . '/bootstrap.php';

/** @var \BIomie\Config $config definido em api/bootstrap.php */

Auth::requireAnyCapability(['admin.view', 'jobs.view', 'operations.view'], true);

try {
    $conn = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo  = Database::connect($config, $conn);

    $dbNow = (string) $pdo->query('SELECT NOW()')->fetchColumn();
    $nowTs = strtotime($dbNow) ?: time();

    // Empresas + integração
    $rows = $pdo->query(
        'SELECT c.id, c.slug, COALESCE(c.nome_fantasia, c.slug) AS name,
                COALESCE(i.active, 0) AS integration_active,
                CASE WHEN COALESCE(i.app_key, "") <> "" AND COALESCE(i.app_secret_encrypted, "") <> ""
                     THEN 1 ELSE 0 END AS integration_configured,
                i.last_success_at, i.last_error_at, i.last_error_summary,
                i.last_used_at, i.webhook_last_received_at
           FROM biomie_companies c
      LEFT JOIN biomie_company_integrations i ON i.company_id = c.id AND i.provider = "omie"
          WHERE c.ativo = 1
       ORDER BY c.id'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Inbox de webhook por empresa/status (últimas 24h p/ processados; pendentes/falhas totais correntes)
    $inbox = [];
    foreach (safeRows($pdo, "SELECT company_id, status, COUNT(*) n,
                                     SUM(received_at >= (NOW() - INTERVAL 24 HOUR)) n24
                                FROM biomie_webhook_inbox GROUP BY company_id, status") as $r) {
        $cid = (int) $r['company_id'];
        $st  = strtolower((string) $r['status']);
        $n   = (int) $r['n'];
        $n24 = (int) $r['n24'];
        $inbox[$cid] ??= ['pending' => 0, 'failed' => 0, 'processed_24h' => 0];
        if ($st === 'pending' || $st === 'processing') {
            $inbox[$cid]['pending'] += $n;
        } elseif (str_contains($st, 'fail')) {
            $inbox[$cid]['failed'] += $n;
        } elseif (str_contains($st, 'processad') || str_contains($st, 'processed') || str_contains($st, 'completed')) {
            $inbox[$cid]['processed_24h'] += $n24;
        }
    }

    // Sync por domínio (último OK) + falhas recentes (24h)
    $sync = [];
    foreach (safeRows($pdo, "SELECT company_id, domain_name,
                                    MAX(CASE WHEN status IN ('completed','completed_with_warnings') THEN finished_at END) last_ok,
                                    SUM(CASE WHEN status LIKE 'failed%' AND started_at >= (NOW() - INTERVAL 24 HOUR) THEN 1 ELSE 0 END) fails24
                               FROM biomie_sync_runs
                              WHERE started_at >= (NOW() - INTERVAL 7 DAY)
                              GROUP BY company_id, domain_name") as $r) {
        $cid = (int) $r['company_id'];
        $dom = normalizeDomain((string) $r['domain_name']);
        $sync[$cid][$dom] = ['last_ok' => $r['last_ok'] ?: null, 'fails24' => (int) $r['fails24']];
    }

    $ageMin = static function (?string $dt) use ($nowTs): ?int {
        if (!$dt) {
            return null;
        }
        $ts = strtotime($dt);
        return $ts === false ? null : max(0, (int) floor(($nowTs - $ts) / 60));
    };

    $companies = [];
    foreach ($rows as $r) {
        $cid = (int) $r['id'];
        $configured = (bool) $r['integration_configured'] && (bool) $r['integration_active'];
        $ib = $inbox[$cid] ?? ['pending' => 0, 'failed' => 0, 'processed_24h' => 0];
        $sy = $sync[$cid] ?? [];

        $commAge = $ageMin($sy['commercial']['last_ok'] ?? null);
        $finAge  = $ageMin($sy['finance']['last_ok'] ?? null);
        $goldAge = $ageMin($sy['gold']['last_ok'] ?? null);
        $whAge   = $ageMin($r['webhook_last_received_at'] ?? null);

        // Status do motor por empresa
        $errAfterOk = !empty($r['last_error_at'])
            && (empty($r['last_success_at'])
                || strtotime((string) $r['last_error_at']) > strtotime((string) $r['last_success_at']));
        $status = match (true) {
            !$configured                              => 'not_configured',
            $commAge === null || $commAge > 24 * 60   => 'critical',  // comercial sem OK há >24h
            $ib['failed'] > 0                         => 'warning',
            $errAfterOk                               => 'warning',
            $ib['pending'] > 50                       => 'warning',
            default                                   => 'ok',
        };

        $companies[] = [
            'company_id' => $cid,
            'slug' => (string) $r['slug'],
            'name' => (string) $r['name'],
            'status' => $status,
            'integration' => [
                'configured' => $configured,
                'active' => (bool) $r['integration_active'],
                'last_success_at' => $r['last_success_at'] ?: null,
                'last_error_at' => $r['last_error_at'] ?: null,
                'last_error' => $r['last_error_summary'] ? mb_substr((string) $r['last_error_summary'], 0, 140) : null,
                'last_used_at' => $r['last_used_at'] ?: null,
            ],
            'webhook' => [
                'last_received_at' => $r['webhook_last_received_at'] ?: null,
                'last_received_age_min' => $whAge,
                'inbox_pending' => $ib['pending'],
                'inbox_failed' => $ib['failed'],
                'processed_24h' => $ib['processed_24h'],
            ],
            'sync' => [
                'commercial_age_min' => $commAge,
                'finance_age_min' => $finAge,
                'gold_age_min' => $goldAge,
                'fails_24h' => (int) (($sy['commercial']['fails24'] ?? 0) + ($sy['finance']['fails24'] ?? 0) + ($sy['gold']['fails24'] ?? 0)),
            ],
        ];
    }

    // Coordenador GLOBAL de quota Omie (entre todas as empresas — mesmo IP do host).
    $globalQuota = null;
    try {
        $globalQuota = \BIomie\OmieRateLimiter::globalStatus();
    } catch (\Throwable) {
        $globalQuota = null;
    }

    Http::json([
        'ok' => true,
        'generated_at' => date(DATE_ATOM),
        'database_now' => $dbNow,
        'global_quota' => $globalQuota,
        'companies' => $companies,
        'request_id' => RequestContext::id(),
    ], 200, [], true);
} catch (\Throwable $e) {
    Http::json(['ok' => false, 'message' => 'Falha ao carregar saúde do motor.', 'request_id' => RequestContext::id()], 500);
}

/**
 * @return list<array<string,mixed>>
 */
function safeRows(PDO $pdo, string $sql): array
{
    try {
        $s = $pdo->query($sql);
        return $s ? $s->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (\Throwable) {
        return [];
    }
}

function normalizeDomain(string $d): string
{
    return match (strtolower(trim($d))) {
        'comercial' => 'commercial',
        'financeiro' => 'finance',
        default => strtolower(trim($d)),
    };
}
