<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\Logger;
use BIomie\Queue\FileJobQueue;
use BIomie\Tools\SupersededDeltaJobPolicy;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
require_once __DIR__ . '/support/SupersededDeltaJobPolicy.php';
Bootstrap::init();

$arguments = array_slice($argv, 1);
if (array_diff($arguments, ['--apply']) !== []) {
    fwrite(STDERR, "Uso: php tools/dev/cancel_superseded_delta_jobs.php [--apply]\nSem --apply, apenas apresenta a simulacao.\n");
    exit(1);
}
$apply = in_array('--apply', $arguments, true);
$root = dirname(__DIR__, 2);
$config = new Config();
$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
if (!Database::isConfigured($config, $connection)) {
    fwrite(STDERR, "Banco de dados nao configurado.\n");
    exit(1);
}

// SQL DATETIME values were written in the application's timezone, not SQL NOW().
$timezone = new DateTimeZone(date_default_timezone_get());
$policy = new SupersededDeltaJobPolicy($timezone);
$logger = new Logger($root . '/storage/logs/queue_cleanup.log');
$queue = new FileJobQueue($root . '/storage/cache', $logger);
$jobs = $queue->listJobs(['queued', 'failed_retryable'], 500);
$pdo = Database::connect($config, $connection);
$coverageQuery = $pdo->prepare(<<<'SQL'
    SELECT r.company_id, r.domain_name, r.endpoint_group,
           r.run_type, r.status, r.started_at, r.finished_at,
           r.records_failed, r.items_failed,
           c.last_success_at AS cursor_last_success_at
    FROM biomie_sync_runs r
    INNER JOIN (
        SELECT company_id, domain_name, endpoint_group,
               MIN(last_success_at) AS last_success_at
        FROM biomie_sync_cursors
        WHERE endpoint_group = domain_name
        GROUP BY company_id, domain_name, endpoint_group
        HAVING SUM(CASE WHEN last_success_at IS NULL THEN 1 ELSE 0 END) = 0
    ) c ON c.company_id = r.company_id
       AND c.domain_name = r.domain_name
       AND c.endpoint_group = r.endpoint_group
    WHERE r.company_id = :company_id
      AND r.domain_name = :domain_name
      AND r.endpoint_group = r.domain_name
      AND r.run_type = 'delta'
      AND r.status = 'completed'
      AND r.records_failed = 0
      AND r.items_failed = 0
      AND r.started_at > :requested_at
      AND r.finished_at IS NOT NULL
      AND r.finished_at >= r.started_at
      AND c.last_success_at >= r.finished_at
    ORDER BY r.started_at DESC, r.finished_at DESC
    LIMIT 1
SQL);

$loadCoverage = static function (array $description) use ($coverageQuery): array {
    $coverageQuery->execute([
        'company_id' => $description['company_id'],
        'domain_name' => $description['domain'],
        'requested_at' => $description['latest_requested_at_local'],
    ]);
    return $coverageQuery->fetchAll(PDO::FETCH_ASSOC);
};

$eligible = [];
$notEligible = [];
foreach ($jobs as $job) {
    if ((string) ($job['type'] ?? $job['job_type'] ?? '') !== 'omie.delta.sync') {
        continue;
    }

    $description = $policy->describe($job);
    $coverage = $description['valid'] ? $loadCoverage($description) : [];
    $assessment = $policy->assess($job, $coverage);
    if ($assessment['eligible']) {
        $eligible[] = $assessment;
    } else {
        $notEligible[] = $assessment;
    }
}

$cancelled = [];
$raced = [];
if ($apply) {
    foreach ($eligible as $row) {
        $jobId = (string) $row['job_id'];
        // Legacy bucket jobs cannot receive new-key webhook signals. Still
        // reject any snapshot changed by a retry, reservation or manual edit.
        $current = $queue->find($jobId);
        if ($current === null) {
            $raced[] = ['job_id' => $jobId, 'reason' => 'job_changed_since_inspection'];
            continue;
        }
        $description = $policy->describe($current);
        $rechecked = $policy->assess($current, $description['valid'] ? $loadCoverage($description) : []);
        if (!$rechecked['eligible']) {
            $raced[] = ['job_id' => $jobId, 'reason' => $rechecked['reason']];
            continue;
        }

        $result = $queue->cancelIfUnchanged(
            $jobId,
            sprintf(
                'Legacy delta superseded: company=%d domain=%s; successful full-domain delta started=%s finished=%s; cursor=%s.',
                $rechecked['company_id'],
                $rechecked['domain'],
                $rechecked['covered_by']['started_at'],
                $rechecked['covered_by']['finished_at'],
                $rechecked['covered_by']['cursor_last_success_at'],
            ),
            $current,
        );
        if ($result === null) {
            $raced[] = ['job_id' => $jobId, 'reason' => 'job_reserved_or_changed_before_cancel'];
            continue;
        }
        $cancelled[] = $jobId;
    }
}

$result = [
    'ok' => true,
    'mode' => $apply ? 'apply' : 'dry-run',
    'timezone' => $timezone->getName(),
    'inspected_pending_jobs' => count($jobs),
    'inspection_limit' => 500,
    'eligible_delta_jobs' => count($eligible),
    'not_eligible_delta_jobs' => count($notEligible),
    'cancelled_jobs' => count($cancelled),
    'raced_or_already_changed_jobs' => count($raced),
    'eligible' => $eligible,
    'not_eligible' => $notEligible,
    'cancelled_job_ids' => $cancelled,
    'raced_jobs' => $raced,
    'queue_summary' => $queue->summary(),
    'executed_at' => date(DATE_ATOM),
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit(0);
