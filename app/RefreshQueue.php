<?php

declare(strict_types=1);

namespace BIomie;

use BIomie\Support\TenantContext;

final class RefreshQueue
{
    private string $jobsDir;

    public function __construct(private readonly string $baseDir, private readonly ?Logger $logger = null)
    {
        $this->jobsDir = rtrim($this->baseDir, '/\\') . '/jobs';
        if (!is_dir($this->jobsDir)) @mkdir($this->jobsDir, 0775, true);
    }

    public function enqueue(string $service, string $key, array $payload = []): array
    {
        $companyId = TenantContext::companyId();
        $canal = TenantContext::canal();
        $payload += [
            'company_id' => $companyId,
            'canal' => $canal,
        ];
        $jobId = sha1($service . '|company:' . $companyId . '|canal:' . $canal . '|' . $key);
        $queued = $this->jobPath('queued', $service, $jobId);
        $running = $this->jobPath('running', $service, $jobId);

        if (is_file($queued)) return ['queued' => false, 'job_id' => $jobId, 'status' => 'already_queued'];
        if (is_file($running)) return ['queued' => false, 'job_id' => $jobId, 'status' => 'already_running'];

        $job = [
            'job_id' => $jobId,
            'service' => $service,
            'snapshot_key' => $key,
            'payload' => $payload,
            'status' => 'queued',
            'requested_at' => date(DATE_ATOM),
            'attempts' => 0,
        ];
        $this->writeJson($queued, $job);
        return ['queued' => true, 'job_id' => $jobId, 'status' => 'queued'];
    }

    public function claimNext(array $services = []): ?array
    {
        $files = array_merge(
            glob($this->jobsDir . '/queued_sales_*.json') ?: [],
            glob($this->jobsDir . '/queued_finance_*.json') ?: []
        );
        sort($files);
        foreach ($files as $path) {
            $job = $this->readJson($path);
            if (!$job) continue;
            $svc = (string)($job['service'] ?? '');
            if ($services !== [] && !in_array($svc, $services, true)) continue;
            $id = (string)($job['job_id'] ?? '');
            if ($id === '') continue;
            $runPath = $this->jobPath('running', $svc, $id);
            if (!@rename($path, $runPath)) continue;
            $job['status'] = 'running';
            $job['attempts'] = ($job['attempts'] ?? 0) + 1;
            $job['started_at'] = date(DATE_ATOM);
            $job['_path'] = $runPath;
            $this->writeJson($runPath, $job);
            return $job;
        }
        return null;
    }

    public function complete(array $job, array $result = []): void
    {
        $path = (string)($job['_path'] ?? '');
        if (!$path || !is_file($path)) return;
        $job['status'] = 'done';
        $job['finished_at'] = date(DATE_ATOM);
        $job['result'] = $result;
        unset($job['_path']);
        $this->writeJson($path, $job);
        @rename($path, str_replace('/running_', '/done_', $path));
        $this->pruneOld();
    }

    public function fail(array $job, string $message): void
    {
        $path = (string)($job['_path'] ?? '');
        if (!$path || !is_file($path)) return;
        $job['status'] = 'failed';
        $job['finished_at'] = date(DATE_ATOM);
        $job['last_error'] = $message;
        unset($job['_path']);
        $this->writeJson($path, $job);
        @rename($path, str_replace('/running_', '/failed_', $path));
        $this->logger?->error('Job falhou', ['service' => $job['service'] ?? '', 'message' => $message]);
    }

    public function summary(): array
    {
        $counts = ['queued' => 0, 'running' => 0, 'done' => 0, 'failed' => 0];
        foreach (['queued', 'running', 'done', 'failed'] as $s) {
            $counts[$s] = count(array_merge(
                glob($this->jobsDir . "/{$s}_sales_*.json") ?: [],
                glob($this->jobsDir . "/{$s}_finance_*.json") ?: []
            ));
        }
        return $counts;
    }

    public function findBySnapshot(string $service, string $snapshotKey): ?array
    {
        $jobId = sha1(
            $service
            . '|company:' . TenantContext::companyId()
            . '|canal:' . TenantContext::canal()
            . '|' . $snapshotKey
        );
        foreach (['queued', 'running', 'done', 'failed'] as $status) {
            $path = $this->jobPath($status, $service, $jobId);
            if (!is_file($path)) {
                continue;
            }
            $job = $this->readJson($path);
            if (is_array($job)) {
                return $job;
            }
        }
        return null;
    }

    private function jobPath(string $status, string $service, string $jobId): string
    {
        return $this->jobsDir . "/{$status}_{$service}_{$jobId}.json";
    }

    private function readJson(string $path): ?array
    {
        $raw = @file_get_contents($path);
        if (!$raw) return null;
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    private function writeJson(string $path, array $payload): void
    {
        $temp = $path . '.tmp';
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }
        @file_put_contents($temp, $json, LOCK_EX);
        @rename($temp, $path);
    }

    private function pruneOld(int $maxDone = 20): void
    {
        foreach (['done', 'failed'] as $status) {
            $files = glob($this->jobsDir . "/{$status}_*.json") ?: [];
            if (count($files) > $maxDone) {
                sort($files);
                foreach (array_slice($files, 0, count($files) - $maxDone) as $f) @unlink($f);
            }
        }
    }
}
