<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/tools/dev/support/SupersededDeltaJobPolicy.php';

use BIomie\Tools\SupersededDeltaJobPolicy;

$policy = new SupersededDeltaJobPolicy(new DateTimeZone('America/Fortaleza'));
$key = 'omie.delta.sync|company:1|commercial|202609081230';
$job = [
    'job_id' => substr(hash('sha256', $key), 0, 40),
    'idempotency_key' => $key,
    'type' => 'omie.delta.sync',
    'status' => 'queued',
    'created_at' => '2026-09-08T12:30:00-03:00',
    'payload' => ['company_id' => 1, 'canal' => null, 'domains' => ['commercial']],
];
$run = [
    'company_id' => '1',
    'domain_name' => 'commercial',
    'endpoint_group' => 'commercial',
    'run_type' => 'delta',
    'status' => 'completed',
    'started_at' => '2026-09-08 12:31:00',
    'finished_at' => '2026-09-08 12:32:00',
    'cursor_last_success_at' => '2026-09-08 12:32:00',
    'records_failed' => '0',
    'items_failed' => '0',
];
$checks = [];
$check = static function (string $id, array $candidate, array $coverage, bool $expected) use (&$checks, $policy): void {
    $result = $policy->assess($candidate, $coverage);
    $checks[] = ['id' => $id, 'ok' => $result['eligible'] === $expected, 'reason' => $result['reason']];
};
$check('later_successful_domain_delta_covers_legacy_job', $job, [$run], true);
$financeKey = 'omie.delta.sync|company:1|finance|202609081230';
$check('finance_uses_its_own_full_domain_coverage', array_replace($job, [
    'job_id' => substr(hash('sha256', $financeKey), 0, 40),
    'idempotency_key' => $financeKey,
    'payload' => ['company_id' => 1, 'canal' => null, 'domains' => ['finance']],
]), [array_replace($run, ['domain_name' => 'finance', 'endpoint_group' => 'finance'])], true);
$check('retryable_legacy_job_can_be_covered', array_replace($job, ['status' => 'failed_retryable']), [$run], true);
$check('running_job_is_never_cancelled', array_replace($job, ['status' => 'running']), [$run], false);
$check('singleton_key_is_excluded', array_replace($job, ['idempotency_key' => 'omie.delta.sync|company:1|commercial']), [$run], false);
$check('unknown_bucket_key_is_excluded', array_replace($job, ['idempotency_key' => 'omie.delta.sync|company:1|commercial|latest']), [$run], false);
$check('extra_payload_filter_is_excluded', array_replace($job, ['payload' => $job['payload'] + ['nf_key' => 'filtered']]), [$run], false);
$check('multi_domain_payload_is_excluded', array_replace($job, ['payload' => array_replace($job['payload'], ['domains' => ['commercial', 'finance']])]), [$run], false);
$check('other_tenant_is_not_coverage', $job, [array_replace($run, ['company_id' => '2'])], false);
$check('endpoint_subset_is_not_full_domain_coverage', $job, [array_replace($run, ['endpoint_group' => 'invoices'])], false);
$check('reconcile_is_not_delta_coverage', $job, [array_replace($run, ['run_type' => 'reconcile'])], false);
$check('failed_run_is_not_coverage', $job, [array_replace($run, ['status' => 'failed'])], false);
$check('failed_records_are_not_coverage', $job, [array_replace($run, ['records_failed' => '1'])], false);
$check('failed_items_are_not_coverage', $job, [array_replace($run, ['items_failed' => '1'])], false);
$check('unknown_failure_count_is_not_coverage', $job, [array_replace($run, ['items_failed' => null])], false);
$check('run_started_before_request_does_not_cover_it', $job, [array_replace($run, ['started_at' => '2026-09-08 12:29:00'])], false);
$check('equal_second_does_not_prove_later_start', $job, [array_replace($run, ['started_at' => '2026-09-08 12:30:00'])], false);
$check('newer_coalesced_request_is_respected', array_replace($job, ['last_requested_at' => '2026-09-08T12:31:30-03:00']), [$run], false);
$check('cursor_behind_successful_run_is_not_coverage', $job, [array_replace($run, ['cursor_last_success_at' => '2026-09-08 12:31:59'])], false);
$check('invalid_finish_is_not_coverage', $job, [array_replace($run, ['finished_at' => '2026-09-08 12:30:59'])], false);
$check('invalid_date_is_not_coverage', array_replace($job, ['created_at' => '2026-02-31T12:30:00-03:00']), [$run], false);
$check('utc_request_compares_with_local_sql_clock', array_replace($job, ['created_at' => '2026-09-08T15:30:00Z']), [$run], true);
$check('missing_evidence_does_not_cancel', $job, [], false);

$failures = array_filter($checks, static fn (array $check): bool => !$check['ok']);
echo json_encode([
    'ok' => $failures === [],
    'passed' => count($checks) - count($failures),
    'total' => count($checks),
    'checks' => $checks,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($failures === [] ? 0 : 1);
