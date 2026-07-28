<?php

declare(strict_types=1);

namespace BIomie;

use RuntimeException;

/**
 * File-backed, cross-process limiter for Omie calls.
 *
 * It keeps the actual API consumption below Omie's per-method budget without
 * relying on a single PHP process. Web contexts never sleep here; long waits
 * must happen in cron/worker jobs.
 */
final class OmieRateLimiter
{
    private string $stateDir;
    private string $appKeyHash;

    public function __construct(
        Cache $cache,
        private readonly Logger $logger,
        string $appKey,
        private readonly string $executionContext,
    ) {
        $this->stateDir = rtrim($cache->directory(), '/\\') . '/omie-rate-limit';
        $this->appKeyHash = substr(hash('sha256', $appKey), 0, 16);
    }

    /**
     * @param array<string,mixed> $policy
     * @return array<string,mixed>
     */
    public function throttle(string $call, array $policy, string $operationType = 'request'): array
    {
        $runtime = is_array($policy['runtime'] ?? null) ? $policy['runtime'] : [];
        $maxSleepMs = max(0, (int) ($runtime['max_sleep_ms'] ?? 0));
        if (!is_dir($this->stateDir)) {
            @mkdir($this->stateDir, 0775, true);
        }

        // Cooldown efetivo = max(cooldown desta app_key, cooldown GLOBAL entre todas
        // as empresas). Assim um 429/5xx da Omie em qualquer empresa faz todas as
        // outras (mesmo IP do host) recuarem juntas. Ver registerServerCooldown().
        $cooldown = $this->effectiveServerCooldown();
        if ($cooldown['remaining_ms'] > 0) {
            if ($maxSleepMs <= 0 || $cooldown['remaining_ms'] > $maxSleepMs) {
                throw new RuntimeException(sprintf(
                    'Rate limit da Omie ativo (%s); reagende em %d segundos.',
                    $cooldown['scope'],
                    (int) ceil($cooldown['remaining_ms'] / 1000)
                ));
            }
            usleep($cooldown['remaining_ms'] * 1000);
        }

        if ($maxSleepMs <= 0) {
            return [
                'enforced' => false,
                'reason' => 'no_sleep_context',
                'context' => $this->executionContext,
                'call' => $call,
                'operation_type' => $operationType,
            ];
        }

        // Coordenador GLOBAL de quota: teto de chamadas/min somando todas as
        // empresas (host compartilha o mesmo IP de saída). Só limita o conjunto
        // — nunca afrouxa o limite por-empresa abaixo. Registra 1 slot global.
        $this->throttleGlobalWindow($maxSleepMs, $call);

        $perMinute = max(1, (int) ($policy['rate_limit_per_minute'] ?? 240));
        $minIntervalMs = max(
            (int) ceil(60000 / $perMinute),
            (int) ($policy['rate_limit_min_interval_ms'] ?? 250)
        );
        $maxConcurrency = max(1, min(4, (int) ($policy['max_concurrency'] ?? 1)));
        $stateKey = $this->stateKey($call);
        $statePath = $this->pathFor($stateKey, 'json');
        $lockPath = $this->pathFor($stateKey, 'lock');
        $sleptMs = 0;

        while (true) {
            $lock = @fopen($lockPath, 'c');
            if ($lock === false) {
                return [
                    'enforced' => false,
                    'reason' => 'lock_unavailable',
                    'call' => $call,
                    'operation_type' => $operationType,
                ];
            }

            flock($lock, LOCK_EX);

            try {
                $nowMs = (int) floor(microtime(true) * 1000);
                $state = $this->readState($statePath);
                $requests = array_values(array_filter(
                    array_map('intval', (array) ($state['requests_ms'] ?? [])),
                    static fn (int $timestamp): bool => ($nowMs - $timestamp) < 60000
                ));
                $lastRequestMs = (int) ($state['last_request_ms'] ?? 0);
                $waitMs = 0;

                if ($requests !== [] && count($requests) >= $perMinute) {
                    $oldest = min($requests);
                    $waitMs = max($waitMs, 60000 - ($nowMs - $oldest) + 50);
                }

                if ($lastRequestMs > 0 && ($nowMs - $lastRequestMs) < $minIntervalMs) {
                    $waitMs = max($waitMs, $minIntervalMs - ($nowMs - $lastRequestMs));
                }

                if ($waitMs <= 0) {
                    $requests[] = $nowMs;
                    $state = [
                        'app_key_hash' => $this->appKeyHash,
                        'call' => $call,
                        'context' => $this->executionContext,
                        'operation_type' => $operationType,
                        'rate_limit_per_minute' => $perMinute,
                        'max_concurrency' => $maxConcurrency,
                        'min_interval_ms' => $minIntervalMs,
                        'last_request_ms' => $nowMs,
                        'last_request_at' => date(DATE_ATOM),
                        'requests_ms' => $requests,
                        'request_count_window' => count($requests),
                    ];
                    $this->writeState($statePath, $state);

                    return [
                        'enforced' => true,
                        'slept_ms' => $sleptMs,
                        'call' => $call,
                        'operation_type' => $operationType,
                        'context' => $this->executionContext,
                        'rate_limit_per_minute' => $perMinute,
                        'max_concurrency' => $maxConcurrency,
                        'request_count_window' => count($requests),
                    ];
                }
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }

            $remainingMs = $maxSleepMs - $sleptMs;
            if ($remainingMs <= 0) {
                $message = 'Rate limit local da Omie ativo; reagende a operacao em background.';
                $this->logger->warning($message, [
                    'call' => $call,
                    'context' => $this->executionContext,
                    'operation_type' => $operationType,
                    'wait_ms' => $waitMs,
                    'slept_ms' => $sleptMs,
                ]);
                throw new RuntimeException($message);
            }

            $sleepMs = min($waitMs, $remainingMs);
            usleep($sleepMs * 1000);
            $sleptMs += $sleepMs;
        }
    }

    public function registerServerCooldown(int $seconds, string $reason = 'server_rate_limit'): void
    {
        $seconds = max(1, min($seconds, 3600));
        if (!is_dir($this->stateDir)) {
            @mkdir($this->stateDir, 0775, true);
        }

        $this->writeCooldownFile(
            $this->serverCooldownPath(),
            $this->pathFor('omie_rl_' . $this->appKeyHash . '_server_cooldown', 'lock'),
            $seconds,
            $reason
        );

        // Propaga o cooldown para o nível GLOBAL: se a Omie barrou esta app_key,
        // as demais empresas (mesmo IP de saída) também devem recuar. Desativável
        // via BIOMIE_OMIE_GLOBAL_COOLDOWN=0.
        if (self::globalCooldownPropagates()) {
            $dir = self::globalDir();
            if (@is_dir($dir) || @mkdir($dir, 0775, true) || @is_dir($dir)) {
                $this->writeCooldownFile(
                    $dir . '/global_cooldown.json',
                    $dir . '/global_cooldown.lock',
                    $seconds,
                    'global:' . $reason
                );
            }
        }
    }

    /**
     * Estende (nunca encurta) um arquivo de cooldown sob lock exclusivo.
     */
    private function writeCooldownFile(string $statePath, string $lockPath, int $seconds, string $reason): void
    {
        $lock = @fopen($lockPath, 'c');
        if ($lock === false) {
            return;
        }

        flock($lock, LOCK_EX);
        try {
            $current = $this->readState($statePath);
            $untilMs = max(
                (int) ($current['until_ms'] ?? 0),
                (int) floor(microtime(true) * 1000) + ($seconds * 1000)
            );
            $this->writeState($statePath, [
                'app_key_hash' => $this->appKeyHash,
                'reason' => $reason,
                'until_ms' => $untilMs,
                'until_at' => date(DATE_ATOM, (int) ceil($untilMs / 1000)),
                'registered_at' => date(DATE_ATOM),
            ]);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @param array<string,array<string,mixed>> $policies
     * @return list<array<string,mixed>>
     */
    public static function summarizePolicies(array $policies): array
    {
        $summary = [];
        foreach ($policies as $call => $policy) {
            $perMinute = max(1, (int) ($policy['rate_limit_per_minute'] ?? 240));
            $summary[] = [
                'call' => (string) $call,
                'domain' => (string) ($policy['domain'] ?? 'generic'),
                'page_size_target' => max(1, min(100, (int) ($policy['page_size_target'] ?? 100))),
                'rate_limit_per_minute' => $perMinute,
                'min_interval_ms' => max((int) ceil(60000 / $perMinute), (int) ($policy['rate_limit_min_interval_ms'] ?? 250)),
                'max_concurrency' => max(1, min(4, (int) ($policy['max_concurrency'] ?? 1))),
                'cache_ttl_seconds' => max(0, (int) ($policy['redundant_cache_ttl_seconds'] ?? 0)),
                'cache_id_keys' => array_values(array_map('strval', (array) ($policy['redundant_id_keys'] ?? []))),
                'window_strategy' => (string) (($policy['window']['strategy'] ?? '') ?: 'on_demand'),
            ];
        }

        usort($summary, static fn (array $a, array $b): int => [$a['domain'], $a['call']] <=> [$b['domain'], $b['call']]);
        return $summary;
    }

    private function stateKey(string $call): string
    {
        return 'omie_rl_' . $this->appKeyHash . '_' . preg_replace('/[^a-zA-Z0-9_-]+/', '_', $call);
    }

    private function pathFor(string $key, string $extension): string
    {
        return $this->stateDir . '/' . preg_replace('/[^a-zA-Z0-9_-]+/', '_', $key) . '.' . $extension;
    }

    /** @return array{remaining_ms:int,until_ms:int} */
    private function serverCooldown(): array
    {
        $state = $this->readState($this->serverCooldownPath());
        $untilMs = max(0, (int) ($state['until_ms'] ?? 0));
        $nowMs = (int) floor(microtime(true) * 1000);

        return [
            'remaining_ms' => max(0, $untilMs - $nowMs),
            'until_ms' => $untilMs,
        ];
    }

    private function serverCooldownPath(): string
    {
        return $this->pathFor('omie_rl_' . $this->appKeyHash . '_server_cooldown', 'json');
    }

    // ── Coordenador GLOBAL de quota (entre todas as empresas) ─────────────────

    /**
     * Cooldown efetivo = o mais longo entre o desta app_key e o GLOBAL.
     *
     * @return array{remaining_ms:int,until_ms:int,scope:string}
     */
    private function effectiveServerCooldown(): array
    {
        $local = $this->serverCooldown();
        $globalUntil = max(0, (int) (self::readJson(self::globalDir() . '/global_cooldown.json')['until_ms'] ?? 0));
        $nowMs = (int) floor(microtime(true) * 1000);
        $globalRemaining = max(0, $globalUntil - $nowMs);

        if ($globalRemaining > $local['remaining_ms']) {
            return ['remaining_ms' => $globalRemaining, 'until_ms' => $globalUntil, 'scope' => 'global'];
        }

        return ['remaining_ms' => $local['remaining_ms'], 'until_ms' => $local['until_ms'], 'scope' => 'app'];
    }

    /**
     * Teto global de chamadas/min somando todas as empresas (host = mesmo IP).
     * Janela deslizante de 60s em arquivo compartilhado, com lock. Só atrasa
     * quando o CONJUNTO estoura o teto — nunca afrouxa o limite por-empresa.
     * Registra 1 slot global por chamada permitida. Fail-open se o lock falhar.
     */
    private function throttleGlobalWindow(int $maxSleepMs, string $call): void
    {
        $perMinute = self::globalPerMinute();
        if ($perMinute <= 0) {
            return; // coordenador global desativado
        }

        $dir = self::globalDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $statePath = $dir . '/global_window.json';
        $lockPath  = $dir . '/global_window.lock';
        $sleptMs = 0;

        while (true) {
            $lock = @fopen($lockPath, 'c');
            if ($lock === false) {
                return; // não bloqueia o pipeline se o FS não cooperar
            }
            flock($lock, LOCK_EX);

            try {
                $nowMs = (int) floor(microtime(true) * 1000);
                $state = $this->readState($statePath);
                $requests = array_values(array_filter(
                    array_map('intval', (array) ($state['requests_ms'] ?? [])),
                    static fn (int $ts): bool => ($nowMs - $ts) < 60000
                ));
                $waitMs = 0;
                if (count($requests) >= $perMinute) {
                    $oldest = min($requests);
                    $waitMs = 60000 - ($nowMs - $oldest) + 50;
                }

                if ($waitMs <= 0) {
                    $requests[] = $nowMs;
                    $this->writeState($statePath, [
                        'global_rate_limit_per_minute' => $perMinute,
                        'last_request_ms' => $nowMs,
                        'last_request_at' => date(DATE_ATOM),
                        'last_call' => $call,
                        'requests_ms' => $requests,
                        'request_count_window' => count($requests),
                    ]);
                    return;
                }
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }

            $remainingMs = $maxSleepMs - $sleptMs;
            if ($remainingMs <= 0) {
                $message = 'Teto global de chamadas Omie atingido; reagende a operacao em background.';
                $this->logger->warning($message, [
                    'call' => $call,
                    'global_rate_limit_per_minute' => $perMinute,
                    'wait_ms' => $waitMs,
                    'slept_ms' => $sleptMs,
                ]);
                throw new RuntimeException($message);
            }

            $sleepMs = min($waitMs, $remainingMs);
            usleep($sleepMs * 1000);
            $sleptMs += $sleepMs;
        }
    }

    /** Diretório compartilhado entre TODAS as empresas (fora do escopo de tenant). */
    private static function globalDir(): string
    {
        $override = getenv('BIOMIE_OMIE_GLOBAL_DIR');
        if (is_string($override) && trim($override) !== '') {
            return rtrim($override, '/\\');
        }

        if (class_exists(\BIomie\Bootstrap::class) && method_exists(\BIomie\Bootstrap::class, 'rootPath')) {
            return rtrim(\BIomie\Bootstrap::rootPath('storage/shared/omie-rate-limit'), '/\\');
        }

        return rtrim(sys_get_temp_dir(), '/\\') . '/biomie-omie-global-rate-limit';
    }

    private static function globalPerMinute(): int
    {
        $env = getenv('BIOMIE_OMIE_GLOBAL_RPM');
        if ($env !== false && is_numeric($env)) {
            return max(0, (int) $env); // 0 desativa
        }

        return 600; // padrão: ~10 req/s somando as 5 empresas (cada uma já limitada a 240/min)
    }

    private static function globalCooldownPropagates(): bool
    {
        $env = getenv('BIOMIE_OMIE_GLOBAL_COOLDOWN');
        if ($env === false) {
            return true;
        }

        return !in_array(strtolower(trim((string) $env)), ['0', 'false', 'off', 'no'], true);
    }

    /**
     * Leitura ESTÁTICA do estado do coordenador global (para painéis/diagnóstico),
     * sem precisar de instância/empresa.
     *
     * @return array{per_minute_ceiling:int,used_last_minute:int,utilization_pct:int,cooldown_remaining_ms:int,cooldown_until_at:?string,cooldown_reason:?string,propagates_cooldown:bool,last_request_at:?string}
     */
    public static function globalStatus(): array
    {
        $dir = self::globalDir();
        $nowMs = (int) floor(microtime(true) * 1000);

        $window = self::readJson($dir . '/global_window.json');
        $requests = array_filter(
            array_map('intval', (array) ($window['requests_ms'] ?? [])),
            static fn (int $ts): bool => ($nowMs - $ts) < 60000
        );
        $used = count($requests);
        $ceiling = self::globalPerMinute();

        $cooldown = self::readJson($dir . '/global_cooldown.json');
        $until = max(0, (int) ($cooldown['until_ms'] ?? 0));
        $remaining = max(0, $until - $nowMs);

        return [
            'per_minute_ceiling' => $ceiling,
            'used_last_minute' => $used,
            'utilization_pct' => $ceiling > 0 ? (int) round($used / $ceiling * 100) : 0,
            'cooldown_remaining_ms' => $remaining,
            'cooldown_until_at' => $until > 0 ? date(DATE_ATOM, (int) ceil($until / 1000)) : null,
            'cooldown_reason' => $remaining > 0 ? (($cooldown['reason'] ?? null) ? (string) $cooldown['reason'] : null) : null,
            'propagates_cooldown' => self::globalCooldownPropagates(),
            'last_request_at' => isset($window['last_request_at']) ? (string) $window['last_request_at'] : null,
        ];
    }

    /** @return array<string,mixed> */
    private static function readJson(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $json = @file_get_contents($path);
        if (!is_string($json) || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string,mixed>
     */
    private function readState(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $json = @file_get_contents($path);
        if (!is_string($json) || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string,mixed> $state
     */
    private function writeState(string $path, array $state): void
    {
        $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }

        @file_put_contents($path, $json, LOCK_EX);
    }
}
