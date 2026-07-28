<?php

declare(strict_types=1);

namespace BIomie;

use BIomie\Support\CaBundleResolver;
use BIomie\Omie\OmieExecutionContext;
use BIomie\Observability\ExternalCallGuard;
use BIomie\Support\SecretMask;
use RuntimeException;

final class OmieClient
{
    private readonly string $executionContext;
    private readonly ?OmieRateLimiter $rateLimiter;

    public function __construct(
        private readonly string $appKey,
        private readonly string $appSecret,
        private readonly int $timeoutSeconds,
        private readonly Logger $logger,
        private readonly ?OmieMethodPolicyRegistry $policyRegistry = null,
        private readonly ?Cache $cache = null,
        ?string $executionContext = null,
        ?OmieRateLimiter $rateLimiter = null,
    ) {
        $this->executionContext = OmieExecutionContext::normalize($executionContext);
        $this->rateLimiter = $rateLimiter ?? ($cache !== null ? new OmieRateLimiter($cache, $logger, $appKey, $this->executionContext) : null);
    }

    public function post(string $endpoint, string $call, array $params = []): array
    {
        $telemetry = Telemetry::global();
        $span = $telemetry?->startSpan('omie.post', [
            'call' => $call,
            'endpoint' => $endpoint,
            'source_name' => 'omie_client',
        ], 'omie', 'omie_client');
        $startedAt = microtime(true);
        $policy = $this->applyExecutionContextPolicy($this->policyRegistry?->forCall($call) ?? []);
        $cacheKey = $this->responseCacheKey($endpoint, $call, $params, $policy);
        $cacheTtl = (int) ($policy['redundant_cache_ttl_seconds'] ?? 0);
        if ($cacheKey !== null && $this->cache !== null && $cacheTtl > 0) {
            $cached = $this->cache->get($cacheKey, $cacheTtl);
            if (is_array($cached['response'] ?? null)) {
                $this->logger->info('Resposta Omie reutilizada em cache curto para evitar consulta redundante.', [
                    'call' => $call,
                    'cache_ttl_seconds' => $cacheTtl,
                    'cache_key' => $cacheKey,
                ]);
                $telemetry?->recordMetric('omie_cache_hit_total', 1, 'counter', [
                    'call' => $call,
                    'cached' => '1',
                ], 'omie', 'omie_client');
                $span?->finish('ok', [
                    'call' => $call,
                    'cached' => true,
                    'attempts' => 0,
                ]);

                return (array) $cached['response'];
            }
        }

        $payload = [
            'call' => $call,
            'app_key' => $this->appKey,
            'app_secret' => $this->appSecret,
            'param' => [$params],
        ];

        $circuitKey = 'omie_cb_' . $call;
        if ($this->cache !== null) {
            $circuit = $this->cache->get($circuitKey, 300);
            if (is_array($circuit) && ($circuit['failures'] ?? 0) >= 3) {
                $span?->finish('error', ['call' => $call, 'message' => 'Circuit Tripped']);
                $telemetry?->recordEvent('ERROR', 'omie.circuit_breaker', 'Circuit breaker is open.', ['call' => $call], 'omie', 'omie_client');
                throw new RuntimeException('Omie temporariamente indisponível devido a timeout excessivo (Circuit Breaker ativo).');
            }
        }

        $attempt = 0;
        $maxAttempts = max(1, (int) ($policy['retry']['max_attempts'] ?? 3));
        $timeoutSeconds = $this->timeoutSecondsForPolicy($policy);
        $connectTimeoutSeconds = $this->connectTimeoutSecondsForPolicy($policy);
        $lastException = null;
        while ($attempt < $maxAttempts) {
            $attempt++;
            if ($attempt === 1) {
                ExternalCallGuard::beforeNetworkCall(
                    'omie',
                    $call,
                    $this->executionContext,
                    $this->policyRegistry?->externalGetGuardMode() ?? 'observe',
                    $this->logger,
                );
            }
            $this->applyCentralRateLimit($call, $policy, 'request');
            $this->applyProcessRateLimit($call, $policy);

            $ch = curl_init($endpoint);
            if ($ch === false) {
                throw new RuntimeException('Nao foi possivel iniciar a conexao com a API do Omie.');
            }

            $curlOptions = [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                CURLOPT_TIMEOUT => $timeoutSeconds,
                CURLOPT_CONNECTTIMEOUT => $connectTimeoutSeconds,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ];
            $caBundle = $this->resolveCaBundle();
            if ($caBundle !== null) {
                $curlOptions[CURLOPT_CAINFO] = $caBundle;
            }
            curl_setopt_array($ch, $curlOptions);

            $response = curl_exec($ch);
            $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            if (PHP_VERSION_ID < 80000) {
                curl_close($ch);
            }

            try {
                if ($response === false || $curlError !== '') {
                    throw new RuntimeException('Falha na comunicacao com o Omie: ' . $curlError);
                }

                $decoded = json_decode($response, true);
                if (!is_array($decoded)) {
                    throw new RuntimeException('A resposta do Omie nao veio em JSON valido.');
                }

                if ($statusCode >= 400 && !isset($decoded['faultstring']) && !isset($decoded['faultcode'])) {
                    throw new RuntimeException('Omie respondeu HTTP ' . $statusCode . '.');
                }

                if (isset($decoded['faultstring']) || isset($decoded['faultcode'])) {
                    $emptyPageResponse = $this->emptyPageResponse($call, $decoded, $params);
                    if ($emptyPageResponse !== null) {
                        $durationMs = max(0, (int) round((microtime(true) - $startedAt) * 1000));
                        $telemetry?->recordMetric('omie_request_duration_ms', $durationMs, 'histogram', [
                            'call' => $call,
                            'outcome' => 'empty_page',
                            'attempt' => $attempt,
                        ], 'omie', 'omie_client');
                        $telemetry?->recordMetric('omie_request_total', 1, 'counter', [
                            'call' => $call,
                            'outcome' => 'empty_page',
                        ], 'omie', 'omie_client');
                        $this->rememberCachedResponse($cacheKey, $emptyPageResponse);
                        if ($this->cache !== null) {
                            $this->cache->delete($circuitKey);
                        }
                        $span?->finish('ok', [
                            'call' => $call,
                            'attempts' => $attempt,
                            'http_status' => $statusCode,
                            'outcome' => 'empty_page',
                        ]);
                        return $emptyPageResponse;
                    }

                    $message = (string) ($decoded['faultstring'] ?? $decoded['faultcode']);
                    throw new RuntimeException('Omie retornou erro: ' . $message);
                }

                $durationMs = max(0, (int) round((microtime(true) - $startedAt) * 1000));
                $telemetry?->recordMetric('omie_request_duration_ms', $durationMs, 'histogram', [
                    'call' => $call,
                    'outcome' => 'ok',
                    'attempt' => $attempt,
                ], 'omie', 'omie_client');
                $telemetry?->recordMetric('omie_request_total', 1, 'counter', [
                    'call' => $call,
                    'outcome' => 'ok',
                ], 'omie', 'omie_client');
                $this->rememberCachedResponse($cacheKey, $decoded);
                if ($this->cache !== null) {
                    $this->cache->delete($circuitKey);
                }
                $span?->finish('ok', [
                    'call' => $call,
                    'attempts' => $attempt,
                    'http_status' => $statusCode,
                ]);
                return $decoded;
            } catch (RuntimeException $exception) {
                $lastException = $exception;
                $safeMessage = SecretMask::sanitizeErrorMessage($exception->getMessage(), 500);
                $retryAfterSeconds = $this->retryAfterSecondsFromMessage($safeMessage);
                $serverConsumptionBlock = $this->isServerConsumptionBlock($safeMessage);
                if ($serverConsumptionBlock && $retryAfterSeconds !== null) {
                    $this->rateLimiter?->registerServerCooldown(
                        $retryAfterSeconds,
                        'omie_consumption_block'
                    );
                }
                $retryable = !$serverConsumptionBlock && $this->shouldRetry($safeMessage, $statusCode);
                $shouldRetry = $attempt < $maxAttempts && $retryable;

                $context = [
                    'endpoint' => $endpoint,
                    'call' => $call,
                    'attempt' => $attempt,
                    'max_attempts' => $maxAttempts,
                    'status' => $statusCode,
                    'message' => $safeMessage,
                    'retry' => $shouldRetry,
                    'retryable' => $retryable,
                    'retry_after_seconds' => $retryAfterSeconds,
                    'server_consumption_block' => $serverConsumptionBlock,
                    'runtime_context' => (string) ($policy['runtime']['context'] ?? $this->executionContext),
                    'duration_ms' => max(0, (int) round((microtime(true) - $startedAt) * 1000)),
                ];

                $this->recordOperationalSignal($safeMessage, $statusCode, $context);

                if ($shouldRetry) {
                    $sleepMs = $this->retryDelayMs($safeMessage, $statusCode, $attempt, $policy);
                    $maxSleepMs = max(0, (int) ($policy['runtime']['max_sleep_ms'] ?? 0));
                    if ($sleepMs > 0 && $maxSleepMs <= 0) {
                        $context['sleep_ms'] = $sleepMs;
                        $context['retry_suppressed'] = true;
                        $this->logger->warning('Retry Omie suprimido no contexto web; operacao deve ser enfileirada.', $context);
                        $telemetry?->recordEvent('WARNING', 'omie.retry_suppressed_web', 'Retry Omie suprimido no contexto web.', $context, 'omie', 'omie_client');
                        throw $exception;
                    }

                    $sleepMs = min($sleepMs, $maxSleepMs);
                    $context['sleep_ms'] = $sleepMs;
                    $this->logger->warning('Falha transitoria na API Omie; aplicando retry.', $context);
                    $telemetry?->recordEvent('WARNING', 'omie.retry', 'Falha transitoria na API Omie; aplicando retry.', $context, 'omie', 'omie_client');
                    usleep($sleepMs * 1000);
                    continue;
                }

                if ($retryable && OmieExecutionContext::isWeb((string) ($policy['runtime']['context'] ?? $this->executionContext))) {
                    $context['retry_suppressed'] = true;
                    $this->logger->warning('Retry Omie suprimido no contexto web; operacao deve ser enfileirada.', $context);
                    $telemetry?->recordEvent('WARNING', 'omie.retry_suppressed_web', 'Retry Omie suprimido no contexto web.', $context, 'omie', 'omie_client');
                }

                $this->logger->error('Falha definitiva na API Omie.', $context);
                $durationMs = max(0, (int) round((microtime(true) - $startedAt) * 1000));
                $telemetry?->recordMetric('omie_request_duration_ms', $durationMs, 'histogram', [
                    'call' => $call,
                    'outcome' => 'error',
                    'attempt' => $attempt,
                ], 'omie', 'omie_client');
                $telemetry?->recordMetric('omie_request_total', 1, 'counter', [
                    'call' => $call,
                    'outcome' => 'error',
                ], 'omie', 'omie_client');
                $telemetry?->recordEvent('ERROR', 'omie.error', 'Falha definitiva na API Omie.', $context, 'omie', 'omie_client');
                
                if ($this->cache !== null) {
                    $circuit = $this->cache->get($circuitKey, 300) ?? ['failures' => 0];
                    $circuit['failures']++;
                    $this->cache->put($circuitKey, $circuit);
                }
                
                $span?->finish('error', [
                    'call' => $call,
                    'attempts' => $attempt,
                    'http_status' => $statusCode,
                    'message' => $safeMessage,
                ]);
                throw $exception;
            }
        }

        $span?->finish('error', [
            'call' => $call,
            'message' => 'Falha desconhecida ao consultar a API do Omie.',
        ]);
        throw $lastException instanceof RuntimeException
            ? $lastException
            : new RuntimeException('Falha desconhecida ao consultar a API do Omie.');
    }

    /**
     * Structured wrapper for diagnostics and CLI tooling.
     *
     * Keeps post() backward compatible for production code while giving audits a
     * standard envelope and an explicit dry-run/no-network mode.
     *
     * @param array<string,mixed> $params
     * @param array{dry_run?:bool,no_network?:bool} $options
     * @return array<string,mixed>
     */
    public function postStructured(string $endpoint, string $call, array $params = [], array $options = []): array
    {
        $requestId = RequestContext::id();
        $startedAt = microtime(true);
        $dryRun = !empty($options['dry_run']) || !empty($options['no_network']);

        if ($dryRun) {
            return [
                'ok' => true,
                'endpoint' => $endpoint,
                'call' => $call,
                'http_status' => null,
                'duration_ms' => 0,
                'request_id' => $requestId,
                'data' => null,
                'error' => null,
                'dry_run' => true,
            ];
        }

        try {
            $data = $this->post($endpoint, $call, $params);
            return [
                'ok' => true,
                'endpoint' => $endpoint,
                'call' => $call,
                'http_status' => 200,
                'duration_ms' => max(0, (int) round((microtime(true) - $startedAt) * 1000)),
                'request_id' => $requestId,
                'data' => $data,
                'error' => null,
                'dry_run' => false,
            ];
        } catch (\Throwable $exception) {
            $message = SecretMask::sanitizeErrorMessage($exception->getMessage(), 300);
            return [
                'ok' => false,
                'endpoint' => $endpoint,
                'call' => $call,
                'http_status' => null,
                'duration_ms' => max(0, (int) round((microtime(true) - $startedAt) * 1000)),
                'request_id' => $requestId,
                'data' => null,
                'error' => [
                    'type' => $this->classifyStructuredError($message),
                    'message' => $message,
                    'retryable' => $this->shouldRetry($message, 0),
                ],
                'dry_run' => false,
            ];
        }
    }

    /**
     * Best-effort process-local throttle. Cross-process protection is handled by
     * cron/job design and Omie method policies; this prevents tight loops inside
     * one worker from exceeding the per-method budget.
     *
     * @param array<string,mixed> $policy
     */
    private function applyProcessRateLimit(string $call, array $policy): void
    {
        static $lastCallAt = [];

        $runtime = is_array($policy['runtime'] ?? null) ? $policy['runtime'] : [];
        $maxSleepMs = (int) ($runtime['max_sleep_ms'] ?? 0);
        if ($maxSleepMs <= 0) {
            return;
        }

        // Global bucket: shared across ALL methods. Prevents burst when many methods
        // fire in sequence (vendors → clients → orders each have their own per-method
        // counter but Omie counts them all towards a cumulative ~20-call window).
        $globalIntervalMs = (int) ($policy['global_rate_limit_min_interval_ms'] ?? 0);
        if ($globalIntervalMs > 0) {
            $now = microtime(true);
            $lastGlobal = (float) ($lastCallAt['__GLOBAL__'] ?? 0.0);
            if ($lastGlobal > 0) {
                $elapsedGlobal = (int) round(($now - $lastGlobal) * 1000);
                if ($elapsedGlobal < $globalIntervalMs) {
                    $sleepMs = min($globalIntervalMs - $elapsedGlobal, $maxSleepMs);
                    if ($sleepMs > 0) {
                        usleep($sleepMs * 1000);
                    }
                }
            }
            $lastCallAt['__GLOBAL__'] = microtime(true);
        }

        // Per-method bucket: prevents tight loops on a single high-volume method.
        $minIntervalMs = max(250, (int) ($policy['rate_limit_min_interval_ms'] ?? 250));
        $now = microtime(true);
        $last = (float) ($lastCallAt[$call] ?? 0.0);
        $elapsedMs = $last > 0 ? (int) round(($now - $last) * 1000) : $minIntervalMs;
        if ($elapsedMs < $minIntervalMs) {
            $sleepMs = min($minIntervalMs - $elapsedMs, $maxSleepMs);
            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }
        $lastCallAt[$call] = microtime(true);
    }

    /**
     * @param array<string,mixed> $policy
     */
    private function applyCentralRateLimit(string $call, array $policy, string $operationType): void
    {
        if ($this->rateLimiter === null) {
            return;
        }

        $signal = $this->rateLimiter->throttle($call, $policy, $operationType);
        if (!empty($signal['enforced']) && (int) ($signal['slept_ms'] ?? 0) > 0) {
            $this->logger->info('Rate limit local Omie aplicado antes da requisicao.', [
                'call' => $call,
                'context' => $signal['context'] ?? $this->executionContext,
                'slept_ms' => (int) ($signal['slept_ms'] ?? 0),
                'request_count_window' => (int) ($signal['request_count_window'] ?? 0),
            ]);
        }
    }

    private function classifyStructuredError(string $message): string
    {
        $normalized = $this->normalizeMessage($message);
        if (str_contains($normalized, 'timeout') || str_contains($normalized, 'timed out')) {
            return 'timeout';
        }
        if (str_contains($normalized, 'rate') || str_contains($normalized, 'too many') || str_contains($normalized, 'consumo indevido')) {
            return 'rate_limit';
        }
        if (str_contains($normalized, 'json')) {
            return 'json_error';
        }
        if (str_contains($normalized, 'omie retornou erro')) {
            return 'omie_error';
        }
        return 'http_error';
    }

    private function shouldRetry(string $message, int $statusCode): bool
    {
        $normalized = $this->normalizeMessage($message);
        if ($statusCode >= 500 || $statusCode === 429) {
            return true;
        }

        foreach ([
            'timed out',
            'timeout',
            'could not connect',
            'temporarily unavailable',
            'service unavailable',
            'redundant',
            'too many',
            'try again',
            'try again in',
            'tente novamente',
            'consumo indevido',
            'api bloqueada',
            'falha na comunicacao',
            'nao foi possivel iniciar a conexao',
        ] as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function isServerConsumptionBlock(string $message): bool
    {
        $normalized = $this->normalizeMessage($message);
        return str_contains($normalized, 'api bloqueada')
            || str_contains($normalized, 'consumo indevido')
            || str_contains($normalized, 'consumo redundante')
            || str_contains($normalized, 'redundant');
    }

    private function resolveCaBundle(): ?string
    {
        return CaBundleResolver::resolve();
    }

    /**
     * @param array<string,mixed> $policy
     * @return array<string,mixed>
     */
    private function applyExecutionContextPolicy(array $policy): array
    {
        $runtime = is_array($policy['runtime'] ?? null) ? $policy['runtime'] : [];
        $retry = is_array($policy['retry'] ?? null) ? $policy['retry'] : [];
        $context = $this->executionContext;

        if (OmieExecutionContext::isWeb($context)) {
            $retry['max_attempts'] = min(max(1, (int) ($retry['max_attempts'] ?? 1)), 1);
            $retry['base_delay_ms'] = 0;
            $retry['max_delay_ms'] = 0;
            $retry['hint_cap_ms'] = 0;
            $runtime['max_sleep_ms'] = 0;
            $runtime['timeout_seconds'] = min(
                (int) ($runtime['web_timeout_seconds'] ?? 8),
                max(1, $this->timeoutSeconds)
            );
            $runtime['connect_timeout_seconds'] = min((int) ($runtime['web_connect_timeout_seconds'] ?? 3), 5);
        } else {
            $retry['max_attempts'] = max(1, (int) ($retry['max_attempts'] ?? ($runtime['job_max_attempts'] ?? 4)));
            $retry['hint_cap_ms'] = max(1000, (int) ($retry['hint_cap_ms'] ?? 60000));
            $runtime['max_sleep_ms'] = max(0, (int) ($runtime['job_max_sleep_ms'] ?? ($runtime['max_sleep_ms'] ?? 60000)));
            $runtime['timeout_seconds'] = max(1, (int) ($runtime['job_timeout_seconds'] ?? $this->timeoutSeconds));
            $runtime['connect_timeout_seconds'] = max(1, (int) ($runtime['job_connect_timeout_seconds'] ?? 10));
        }

        $runtime['context'] = $context;
        $policy['retry'] = $retry;
        $policy['runtime'] = $runtime;

        return $policy;
    }

    /**
     * @param array<string,mixed> $policy
     */
    private function timeoutSecondsForPolicy(array $policy): int
    {
        $runtime = is_array($policy['runtime'] ?? null) ? $policy['runtime'] : [];
        return max(1, (int) ($runtime['timeout_seconds'] ?? $this->timeoutSeconds));
    }

    /**
     * @param array<string,mixed> $policy
     */
    private function connectTimeoutSecondsForPolicy(array $policy): int
    {
        $runtime = is_array($policy['runtime'] ?? null) ? $policy['runtime'] : [];
        return max(1, (int) ($runtime['connect_timeout_seconds'] ?? 10));
    }

    /**
     * @param array<string,mixed> $policy
     */
    private function retryDelayMs(string $message, int $statusCode, int $attempt, array $policy = []): int
    {
        $hintSeconds = $this->retryAfterSecondsFromMessage($message);
        if ($hintSeconds !== null) {
            $jitter = random_int(150, 750);
            $hintCap = (int) ($policy['retry']['hint_cap_ms'] ?? 300000);
            if ($hintCap <= 0) {
                return 0;
            }
            $hintCap = max(1000, $hintCap);
            return min(($hintSeconds * 1000) + $jitter, $hintCap);
        }

        return $this->backoffWithJitterMs(
            $attempt,
            $statusCode,
            (int) ($policy['retry']['base_delay_ms'] ?? 0),
            (int) ($policy['retry']['max_delay_ms'] ?? 0),
        );
    }

    private function backoffWithJitterMs(
        int $attempt,
        int $statusCode = 0,
        int $policyBaseDelayMs = 0,
        int $policyMaxDelayMs = 0,
    ): int
    {
        $base = $policyBaseDelayMs > 0 ? $policyBaseDelayMs : ($statusCode === 429 ? 2000 : 400);
        $base *= 2 ** max(0, $attempt - 1);
        $jitter = random_int(50, 250);
        $maxDelay = $policyMaxDelayMs > 0 ? $policyMaxDelayMs : 5000;

        return min($base + $jitter, $maxDelay);
    }

    private function retryAfterSecondsFromMessage(string $message): ?int
    {
        $normalized = $this->normalizeMessage($message);
        if (preg_match(
            '/(?:tente novamente em|try again in|aguarde)\s+(\d+)\s+segundos?/',
            $normalized,
            $matches
        ) === 1) {
            return max(1, (int) $matches[1]);
        }

        return null;
    }

    private function normalizeMessage(string $message): string
    {
        $source = $message;
        if (str_contains($source, 'Ã') || str_contains($source, 'Â')) {
            $repaired = @mb_convert_encoding($source, 'Windows-1252', 'UTF-8');
            if (is_string($repaired) && mb_check_encoding($repaired, 'UTF-8')) {
                $source = $repaired;
            }
        }

        $normalized = mb_strtolower($source);
        $normalized = strtr($normalized, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
        ]);
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? $normalized;

        return trim(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);
    }

    /**
     * @param array<string,mixed> $context
     */
    private function recordOperationalSignal(string $message, int $statusCode, array $context): void
    {
        $telemetry = Telemetry::global();
        $normalized = $this->normalizeMessage($message);

        if (
            $statusCode === 429
            || str_contains($normalized, 'too many')
            || str_contains($normalized, 'rate limit')
            || str_contains($normalized, 'api bloqueada')
            || str_contains($normalized, 'consumo indevido')
        ) {
            $this->logger->warning('Omie sinalizou rate limit.', $context);
            $telemetry?->recordEvent('WARNING', 'omie.rate_limited', 'Omie sinalizou rate limit.', $context, 'omie', 'omie_client');
        }

        if (str_contains($normalized, 'redundant') || str_contains($normalized, 'consumo indevido')) {
            $this->logger->warning('Omie sinalizou requisicao redundante.', $context);
            $telemetry?->recordEvent('WARNING', 'omie.redundant_request', 'Omie sinalizou requisicao redundante.', $context, 'omie', 'omie_client');
        }
    }

    private function emptyPageResponse(string $call, array $decoded, array $params): ?array
    {
        $message = (string) ($decoded['faultstring'] ?? $decoded['faultcode'] ?? '');
        if ($message === '') {
            return null;
        }

        $normalized = $this->normalizeMessage($message);

        if (!str_contains($normalized, 'nao existem registros para a pagina')) {
            return null;
        }

        $page = (int) ($params['pagina'] ?? 1);

        return match ($call) {
            'ListarPedidos' => [
                'pedido_venda_produto' => [],
                'total_de_paginas' => 0,
                'pagina' => $page,
            ],
            'ListarNF' => [
                'nfCadastro' => [],
                'total_de_paginas' => 0,
                'pagina' => $page,
                'registros' => 0,
                'total_de_registros' => 0,
            ],
            'ListarNotaEnt' => [
                'notas' => [],
                'nTotalPaginas' => 0,
                'nPagina' => (int) ($params['nPagina'] ?? $page),
                'nRegistros' => 0,
                'nTotalRegistros' => 0,
            ],
            'ListarEtapasPedido' => [
                'etapasPedido' => [],
                'nTotPaginas' => 0,
                'nPagina' => (int) ($params['nPagina'] ?? $page),
            ],
            'ListarVendedores' => [
                'cadastro' => [],
                'total_de_paginas' => 0,
                'pagina' => $page,
            ],
            'ListarClientesResumido' => [
                'clientes_cadastro_resumido' => [],
                'total_de_paginas' => 0,
                'pagina' => $page,
            ],
            'ListarClientes' => [
                'clientes_cadastro' => [],
                'total_de_paginas' => 0,
                'pagina' => $page,
            ],
            'ListarMovimentos' => [
                'movimentos' => [],
                'nTotPaginas' => 0,
                'nPagina' => (int) ($params['nPagina'] ?? $page),
            ],
            'PesquisarLancamentos', 'ListarLancamentos' => [
                'titulosEncontrados' => [],
                'nTotPaginas' => 0,
                'nPagina' => (int) ($params['nPagina'] ?? $page),
            ],
            'ListarContasCorrentes' => [
                'lista_contas_correntes' => [],
                'total_de_paginas' => 0,
                'pagina' => $page,
            ],
            default => null,
        };
    }

    /**
     * @param array<string,mixed> $policy
     */
    private function responseCacheKey(string $endpoint, string $call, array $params, array $policy): ?string
    {
        if ($this->cache === null) {
            return null;
        }

        $ttl = (int) ($policy['redundant_cache_ttl_seconds'] ?? 0);
        if ($ttl <= 0) {
            return null;
        }

        $fingerprint = [];
        foreach ((array) ($policy['redundant_id_keys'] ?? []) as $key) {
            if (!array_key_exists($key, $params)) {
                continue;
            }

            $value = trim((string) $params[$key]);
            if ($value === '') {
                continue;
            }

            $fingerprint[] = $key . '=' . $value;
        }

        if ($fingerprint === []) {
            return null;
        }

        return 'omie_response_' . hash('sha256', implode('|', [
            substr(hash('sha256', $this->appKey), 0, 16),
            $endpoint,
            $call,
            implode('|', $fingerprint),
        ]));
    }

    private function rememberCachedResponse(?string $cacheKey, array $response): void
    {
        if ($cacheKey === null || $this->cache === null) {
            return;
        }

        $this->cache->put($cacheKey, [
            'response' => $response,
            'cached_at' => date(DATE_ATOM),
        ]);
    }
}
