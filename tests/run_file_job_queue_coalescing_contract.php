<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Queue\FileJobQueue;

$checks = [];
$skipped = [];
$check = static function (string $id, bool $ok, mixed $actual = null) use (&$checks): void {
    $checks[] = ['id' => $id, 'ok' => $ok, 'actual' => $actual];
};
$requireJob = static function (?array $job, string $scenario): array {
    if ($job === null) {
        throw new RuntimeException('Expected a claimable job: ' . $scenario);
    }

    return $job;
};
$jobCopies = static function (string $directory, string $jobId): int {
    return count(glob($directory . '/jobs/*_job_*_' . $jobId . '.json') ?: []);
};

$tempRoot = realpath(sys_get_temp_dir());
if ($tempRoot === false) {
    throw new RuntimeException('Cannot resolve temporary directory.');
}
$base = $tempRoot . DIRECTORY_SEPARATOR . 'biomie_queue_contract_' . bin2hex(random_bytes(8));
if (!mkdir($base, 0700)) {
    throw new RuntimeException('Cannot create isolated queue contract directory.');
}
$createdBase = realpath($base);

try {
    $deltaPayload = ['company_id' => 1, 'canal' => 'varejo', 'domains' => ['commercial']];
    $deltaOptions = [
        'idempotency_key' => 'omie.delta.sync|company:1|commercial',
        'coalesce_running' => true,
        'priority' => 4,
    ];

    // A pending duplicate is covered by the pending run, without an extra rerun.
    $pendingBase = $base . '/pending';
    $pendingQueue = new FileJobQueue($pendingBase);
    $pendingFirst = $pendingQueue->enqueue('high', 'omie.delta.sync', $deltaPayload, $deltaOptions);
    $pendingDuplicate = $pendingQueue->enqueue('high', 'omie.delta.sync', $deltaPayload, $deltaOptions);
    $pendingJob = $requireJob($pendingQueue->claimNext(['high']), 'pending duplicate');
    $check(
        'pending_duplicates_share_one_execution',
        $pendingFirst['queued'] === true
            && $pendingDuplicate['queued'] === false
            && $pendingFirst['job_id'] === $pendingDuplicate['job_id']
            && $pendingDuplicate['status'] === 'queued'
            && $jobCopies($pendingBase, $pendingFirst['job_id']) === 1
            && $pendingQueue->claimNext(['high']) === null,
    );
    $pendingQueue->complete($pendingJob, ['ok' => true]);
    $check(
        'pending_duplicate_does_not_request_redundant_rerun',
        ($pendingQueue->find($pendingFirst['job_id'])['status'] ?? null) === 'completed'
            && $pendingQueue->claimNext(['high']) === null,
    );

    // Complete receives the original claimed snapshot: it must read newer signals.
    $signalBase = $base . '/running_signal';
    $signalQueue = new FileJobQueue($signalBase);
    $signalFirst = $signalQueue->enqueue('high', 'omie.delta.sync', $deltaPayload, $deltaOptions);
    $originalClaim = $requireJob($signalQueue->claimNext(['high']), 'running signal');
    $runningDuplicate = $signalQueue->enqueue('high', 'omie.delta.sync', $deltaPayload, $deltaOptions);
    $signalQueue->enqueue('high', 'omie.delta.sync', $deltaPayload, $deltaOptions);
    $signalledJob = $signalQueue->find($signalFirst['job_id']);
    $check(
        'running_duplicate_records_a_durable_signal',
        $runningDuplicate['queued'] === false
            && $runningDuplicate['status'] === 'running'
            && !empty($signalledJob['rerun_requested_at'])
            && $signalQueue->claimNext(['high']) === null
            && $jobCopies($signalBase, $signalFirst['job_id']) === 1,
    );
    $signalQueue->complete($originalClaim, ['ok' => true]);
    $rerun = $signalQueue->find($signalFirst['job_id']);
    $check(
        'completion_preserves_signals_as_one_fresh_pending_run',
        is_array($rerun)
            && $rerun['status'] === 'queued'
            && (int) $rerun['attempts'] === 0
            && empty($rerun['rerun_requested_at'])
            && $jobCopies($signalBase, $signalFirst['job_id']) === 1,
        $rerun === null ? null : [
            'status' => $rerun['status'],
            'attempts' => $rerun['attempts'],
            'rerun_requested_at' => $rerun['rerun_requested_at'] ?? null,
        ],
    );
    $rerunClaim = $requireJob($signalQueue->claimNext(['high']), 'fresh run after signal');
    $check('rerun_starts_with_first_attempt', (int) $rerunClaim['attempts'] === 1);
    $signalQueue->complete($rerunClaim, ['ok' => true]);
    $check(
        'rerun_without_new_signal_finishes_permanently',
        ($signalQueue->find($signalFirst['job_id'])['status'] ?? null) === 'completed'
            && $signalQueue->claimNext(['high']) === null
            && $jobCopies($signalBase, $signalFirst['job_id']) === 1,
    );

    // A new event after completion can run; old completed state must not hide retries.
    $reenqueued = $signalQueue->enqueue('high', 'omie.delta.sync', $deltaPayload, $deltaOptions);
    $check(
        'completed_delta_can_be_enqueued_again_without_old_copy',
        $reenqueued['queued'] === true
            && $reenqueued['job_id'] === $signalFirst['job_id']
            && $jobCopies($signalBase, $signalFirst['job_id']) === 1,
    );
    $retryClaim = $requireJob($signalQueue->claimNext(['high']), 'reenqueued delta failure');
    $signalQueue->fail($retryClaim, 'Transient contract-test failure.', true);
    $retryBefore = $signalQueue->find($signalFirst['job_id']);
    $retryDuplicate = $signalQueue->enqueue('high', 'omie.delta.sync', $deltaPayload, $deltaOptions);
    $retryAfter = $signalQueue->find($signalFirst['job_id']);
    $check(
        'retryable_failure_is_visible_and_not_duplicated_by_enqueue',
        ($retryBefore['status'] ?? null) === 'failed_retryable'
            && $retryDuplicate['queued'] === false
            && $retryDuplicate['status'] === 'failed_retryable'
            && ($retryAfter['status'] ?? null) === 'failed_retryable'
            && ($retryBefore['attempts'] ?? null) === ($retryAfter['attempts'] ?? null)
            && ($retryBefore['available_at'] ?? null) === ($retryAfter['available_at'] ?? null)
            && $jobCopies($signalBase, $signalFirst['job_id']) === 1
            && count($signalQueue->listJobs(['queued'])) === 0,
    );

    $inboxQueue = new FileJobQueue($base . '/inbox');
    $inboxOptions = [
        'idempotency_key' => 'webhook.inbox.process|company:1|inbox:contract-1',
        'dedupe_completed' => true,
    ];
    $inboxPayload = ['company_id' => 1, 'canal' => 'varejo'];
    $inboxFirst = $inboxQueue->enqueue('high', 'webhook.inbox.process', $inboxPayload, $inboxOptions);
    $inboxClaim = $requireJob($inboxQueue->claimNext(['high']), 'inbox completion');
    $inboxQueue->complete($inboxClaim, ['ok' => true]);
    $inboxDuplicate = $inboxQueue->enqueue('high', 'webhook.inbox.process', $inboxPayload, $inboxOptions);
    $check(
        'same_completed_inbox_is_deduplicated',
        $inboxDuplicate['queued'] === false
            && $inboxDuplicate['job_id'] === $inboxFirst['job_id']
            && $inboxDuplicate['status'] === 'completed'
            && $inboxQueue->claimNext(['high']) === null,
    );
    $nextInbox = $inboxQueue->enqueue('high', 'webhook.inbox.process', $inboxPayload, [
        'idempotency_key' => 'webhook.inbox.process|company:1|inbox:contract-2',
        'dedupe_completed' => true,
    ]);
    $check(
        'different_inbox_still_enqueues',
        $nextInbox['queued'] === true && $nextInbox['job_id'] !== $inboxFirst['job_id'],
    );

    // Recent completions must still deduplicate after the queue exceeds 50 jobs.
    $retentionQueue = new FileJobQueue($base . '/recent_inbox_retention');
    $retentionOptions = [];
    for ($index = 0; $index < 64; $index++) {
        $options = [
            'idempotency_key' => 'webhook.inbox.process|company:1|inbox:retention-' . $index,
            'dedupe_completed' => true,
        ];
        $retentionOptions[] = $options;
        $retentionQueue->enqueue('high', 'webhook.inbox.process', $inboxPayload, $options);
        $retentionClaim = $requireJob($retentionQueue->claimNext(['high']), 'recent inbox retention ' . $index);
        $retentionQueue->complete($retentionClaim, ['ok' => true]);
    }
    $retainedCompletions = count($retentionQueue->listJobs(['completed'], 100));
    $retainedDuplicates = 0;
    foreach ($retentionOptions as $options) {
        $duplicate = $retentionQueue->enqueue('high', 'webhook.inbox.process', $inboxPayload, $options);
        if ($duplicate['queued'] === false && $duplicate['status'] === 'completed') {
            $retainedDuplicates++;
        }
    }
    $check(
        'more_than_fifty_recent_completions_preserve_inbox_deduplication',
        $retainedCompletions === 64
            && $retainedDuplicates === 64
            && $retentionQueue->claimNext(['high']) === null,
        ['completed' => $retainedCompletions, 'duplicates_deduplicated' => $retainedDuplicates],
    );

    // Defer must keep actual prior failures, but must not count lock contention.
    $signalQueue->retry($signalFirst['job_id']);
    $deferClaim = $requireJob($signalQueue->claimNext(['high']), 'defer retry');
    $attemptsBeforeDefer = (int) $deferClaim['attempts'];
    $deferStartedAt = time();
    $signalQueue->defer($deferClaim, 'Outro sync fiscal da empresa ja esta em execucao.', 60);
    $deferred = $signalQueue->find($signalFirst['job_id']);
    $check(
        'defer_preserves_prior_failures_without_consuming_an_attempt',
        $attemptsBeforeDefer === 2
            && ($deferred['status'] ?? null) === 'queued'
            && (int) ($deferred['attempts'] ?? -1) === 1,
    );
    $check(
        'defer_delays_work_and_releases_locks',
        strtotime((string) ($deferred['available_at'] ?? '')) >= $deferStartedAt + 60
            && ($deferred['available_at'] ?? null) === ($deferred['next_attempt_at'] ?? null)
            && empty($deferred['locked_by'])
            && empty($deferred['locked_at'])
            && empty($deferred['lock_expires_at'])
            && $signalQueue->claimNext(['high']) === null
            && $jobCopies($signalBase, $signalFirst['job_id']) === 1,
    );

    foreach (['queued', 'failed_retryable', 'running', 'completed', 'failed_terminal'] as $status) {
        $cancelBase = $base . '/cancel_' . $status;
        $cancelQueue = new FileJobQueue($cancelBase);
        $cancelFirst = $cancelQueue->enqueue('high', 'omie.delta.sync', $deltaPayload, [
            'idempotency_key' => 'cancel-contract-' . $status,
        ]);
        if ($status !== 'queued') {
            $cancelClaim = $requireJob($cancelQueue->claimNext(['high']), 'cancel ' . $status);
            if ($status === 'completed') {
                $cancelQueue->complete($cancelClaim);
            } elseif ($status === 'failed_retryable' || $status === 'failed_terminal') {
                $cancelQueue->fail($cancelClaim, 'Contract cancellation fixture.', $status === 'failed_retryable');
            }
        }
        $beforeCancel = $cancelQueue->find($cancelFirst['job_id']);
        $cancelled = $cancelQueue->cancel($cancelFirst['job_id'], 'Superseded by verified successful cursor.');
        $afterCancel = $cancelQueue->find($cancelFirst['job_id']);
        $canCancel = in_array($status, ['queued', 'failed_retryable'], true);
        $check(
            'cancel_' . $status . ($canCancel ? '_archives_job' : '_leaves_job_unchanged'),
            $canCancel
                ? (is_array($cancelled)
                    && $cancelled['status'] === 'cancelled'
                    && $cancelled['cancel_reason'] === 'Superseded by verified successful cursor.'
                    && !empty($cancelled['finished_at'])
                    && ($afterCancel['attempts'] ?? null) === ($beforeCancel['attempts'] ?? null)
                    && $jobCopies($cancelBase, $cancelFirst['job_id']) === 1)
                : ($cancelled === null && $afterCancel === $beforeCancel),
        );
    }

    $snapshotBase = $base . '/cancel_snapshot';
    $snapshotQueue = new FileJobQueue($snapshotBase);
    $snapshotEnqueue = $snapshotQueue->enqueue('high', 'omie.delta.sync', $deltaPayload, [
        'idempotency_key' => 'cancel-snapshot',
    ]);
    $observed = $snapshotQueue->find((string) $snapshotEnqueue['job_id']);
    $snapshotClaim = $requireJob($snapshotQueue->claimNext(['high']), 'cancel stale snapshot');
    $snapshotQueue->fail($snapshotClaim, 'Changed after inspection.', true);
    $staleCancel = $snapshotQueue->cancelIfUnchanged(
        (string) $snapshotEnqueue['job_id'],
        'Should not cancel a changed job.',
        is_array($observed) ? $observed : [],
    );
    $check(
        'cancel_if_unchanged_rejects_stale_snapshot',
        $staleCancel === null
            && ($snapshotQueue->find((string) $snapshotEnqueue['job_id'])['status'] ?? null) === 'failed_retryable',
    );

    if (function_exists('pcntl_fork') && function_exists('pcntl_waitpid')) {
        $raceBase = $base . '/concurrent';
        mkdir($raceBase, 0700);
        $gate = $raceBase . '/start';
        $children = [];
        for ($index = 0; $index < 8; $index++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                break;
            }
            if ($pid === 0) {
                $deadline = microtime(true) + 10;
                while (!is_file($gate) && microtime(true) < $deadline) {
                    usleep(1000);
                }
                if (!is_file($gate)) {
                    exit(2);
                }
                try {
                    $raceQueue = new FileJobQueue($raceBase);
                    $enqueued = $raceQueue->enqueue('high', 'omie.delta.sync', $deltaPayload, $deltaOptions);
                    $saved = file_put_contents($raceBase . '/result_' . $index . '.json', json_encode($enqueued, JSON_THROW_ON_ERROR));
                    exit($saved === false ? 3 : 0);
                } catch (Throwable $error) {
                    exit(4);
                }
            }
            $children[] = $pid;
        }
        touch($gate);
        $childrenOk = count($children) === 8;
        foreach ($children as $pid) {
            $waited = pcntl_waitpid($pid, $childStatus);
            $childrenOk = $childrenOk
                && $waited === $pid
                && pcntl_wifexited($childStatus)
                && pcntl_wexitstatus($childStatus) === 0;
        }
        $raceResults = [];
        foreach (glob($raceBase . '/result_*.json') ?: [] as $path) {
            $decoded = json_decode((string) file_get_contents($path), true);
            if (is_array($decoded)) {
                $raceResults[] = $decoded;
            }
        }
        $raceQueue = new FileJobQueue($raceBase);
        $queuedWinners = count(array_filter($raceResults, static fn (array $row): bool => ($row['queued'] ?? false) === true));
        $raceIds = array_values(array_unique(array_column($raceResults, 'job_id')));
        $check(
            'eight_concurrent_enqueues_have_one_winner_and_one_job',
            $childrenOk
                && count($raceResults) === 8
                && $queuedWinners === 1
                && count($raceIds) === 1
                && count($raceQueue->listJobs()) === 1
                && $jobCopies($raceBase, $raceIds[0]) === 1,
            ['children' => count($children), 'results' => count($raceResults), 'winners' => $queuedWinners],
        );
    } else {
        $skipped[] = 'Concurrent enqueue requires pcntl_fork and pcntl_waitpid.';
    }
} catch (Throwable $error) {
    $check('contract_execution', false, get_class($error) . ': ' . $error->getMessage());
} finally {
    // Remove only this invocation's resolved child directory, never a supplied path.
    $resolvedBase = realpath($base);
    if ($createdBase !== false
        && $resolvedBase === $createdBase
        && dirname($resolvedBase) === $tempRoot
        && preg_match('/^biomie_queue_contract_[a-f0-9]{16}$/D', basename($resolvedBase)) === 1) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($resolvedBase, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            ($entry->isDir() && !$entry->isLink()) ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($resolvedBase);
    }
}

$failed = array_values(array_filter($checks, static fn (array $row): bool => !$row['ok']));
$result = [
    'ok' => $failed === [],
    'passed' => count($checks) - count($failed),
    'total' => count($checks),
    'skipped' => $skipped,
    'checks' => $checks,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($result['ok'] ? 0 : 1);
