<?php

declare(strict_types=1);

namespace BIomie\Tools;

use DateTimeImmutable;
use DateTimeZone;

/** Read-only eligibility rules shared by cleanup and its isolated contract. */
final class SupersededDeltaJobPolicy
{
    public function __construct(private readonly DateTimeZone $timezone)
    {
    }

    public function describe(array $job): array
    {
        $result = [
            'valid' => false,
            'job_id' => (string) ($job['job_id'] ?? ''),
            'status' => (string) ($job['status'] ?? ''),
            'created_at' => (string) ($job['created_at'] ?? ''),
        ];
        $reject = static fn (string $reason): array => $result + ['reason' => $reason];
        if (!in_array($result['status'], ['queued', 'failed_retryable'], true)) {
            return $reject('job_is_not_pending');
        }
        if ((string) ($job['type'] ?? $job['job_type'] ?? '') !== 'omie.delta.sync') {
            return $reject('job_is_not_delta');
        }
        $key = (string) ($job['idempotency_key'] ?? '');
        if (preg_match('/^omie\.delta\.sync\|company:([1-9][0-9]*)\|(commercial|finance)\|([0-9]+)$/D', $key, $match) !== 1) {
            return $reject('not_a_legacy_bucket_key');
        }
        if ($result['job_id'] !== substr(hash('sha256', $key), 0, 40)) {
            return $reject('job_id_does_not_match_key');
        }
        $payload = $job['payload'] ?? null;
        if (!is_array($payload) || array_diff(array_keys($payload), ['company_id', 'canal', 'domains']) !== []) {
            return $reject('payload_has_unsupported_filters');
        }
        if ((string) ($payload['company_id'] ?? '') !== $match[1]
            || ($payload['domains'] ?? null) !== [$match[2]]) {
            return $reject('payload_does_not_match_legacy_key');
        }
        $requestedAt = $this->timestamp($result['created_at']);
        if ($requestedAt === null) {
            return $reject('invalid_created_at');
        }
        foreach (['last_requested_at', 'rerun_requested_at'] as $field) {
            if (isset($job[$field]) && $job[$field] !== '') {
                $timestamp = $this->timestamp((string) $job[$field]);
                if ($timestamp === null) {
                    return $reject('invalid_' . $field);
                }
                if ($timestamp > $requestedAt) {
                    $requestedAt = $timestamp;
                }
            }
        }

        return array_replace($result, [
            'valid' => true,
            'reason' => null,
            'company_id' => (int) $match[1],
            'domain' => $match[2],
            'latest_requested_at_local' => $requestedAt->setTimezone($this->timezone)->format('Y-m-d H:i:s'),
            'latest_requested_at' => $requestedAt->format(DATE_ATOM),
        ]);
    }

    public function assess(array $job, array $coverage): array
    {
        $result = $this->describe($job);
        $result['eligible'] = false;
        if (!$result['valid']) {
            return $result;
        }
        $requestedAt = $this->timestamp($result['latest_requested_at']);
        foreach ($coverage as $run) {
            if ((int) ($run['company_id'] ?? 0) !== $result['company_id']
                || ($run['domain_name'] ?? null) !== $result['domain']
                || ($run['endpoint_group'] ?? null) !== $result['domain']
                || ($run['run_type'] ?? null) !== 'delta'
                || ($run['status'] ?? null) !== 'completed'
                || !in_array($run['records_failed'] ?? null, [0, '0'], true)
                || !in_array($run['items_failed'] ?? null, [0, '0'], true)) {
                continue;
            }
            $startedAt = $this->timestamp((string) ($run['started_at'] ?? ''));
            $finishedAt = $this->timestamp((string) ($run['finished_at'] ?? ''));
            $cursorAt = $this->timestamp((string) ($run['cursor_last_success_at'] ?? ''));
            if ($startedAt === null || $finishedAt === null || $cursorAt === null
                || $startedAt <= $requestedAt || $finishedAt < $startedAt || $cursorAt < $finishedAt) {
                continue;
            }
            $result['eligible'] = true;
            $result['reason'] = 'covered_by_successful_later_full_domain_delta';
            $result['covered_by'] = [
                'started_at' => (string) $run['started_at'],
                'finished_at' => (string) $run['finished_at'],
                'cursor_last_success_at' => (string) $run['cursor_last_success_at'],
            ];
            return $result;
        }

        $result['reason'] = 'no_proven_later_successful_full_domain_delta';
        return $result;
    }

    private function timestamp(string $value): ?DateTimeImmutable
    {
        // Reject relative dates and silent overflow (e.g. February 31).
        if (preg_match('/^\d{4}-\d{2}-\d{2}(?: \d{2}:\d{2}:\d{2}|T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2}))$/D', $value) !== 1) {
            return null;
        }
        try {
            $date = new DateTimeImmutable($value, $this->timezone);
            $errors = DateTimeImmutable::getLastErrors();
            return is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0) ? null : $date;
        } catch (\Throwable) {
            return null;
        }
    }
}
