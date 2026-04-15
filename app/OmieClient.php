<?php

declare(strict_types=1);

namespace BIomie;

use RuntimeException;

final class OmieClient
{
    public function __construct(
        private readonly string $appKey,
        private readonly string $appSecret,
        private readonly int $timeoutSeconds,
        private readonly Logger $logger,
        private readonly ?OmieMethodPolicyRegistry $policyRegistry = null,
        private readonly ?Cache $cache = null,
    ) {
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
        $policy = $this->policyRegistry?->forCall($call) ?? [];
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
        $lastException = null;

        while ($attempt < $maxAttempts) {
            $attempt++;
            $ch = curl_init($endpoint);
            if ($ch === false) {
                throw new RuntimeException('Nao foi possivel iniciar a conexao com a API do Omie.');
            }

            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                CURLOPT_TIMEOUT => $this->timeoutSeconds,
                CURLOPT_CONNECTTIMEOUT => 10,
            ]);

            $response = curl_exec($ch);
            $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            try {
                if ($response === false || $curlError !== '') {
                    throw new RuntimeException('Falha na comunicacao com o Omie: ' . $curlError);
                }

                $decoded = json_decode($response, true);
                if (!is_array($decoded)) {
                    throw new RuntimeException('A resposta do Omie nao veio em JSON valido.');
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
                $shouldRetry = $attempt < $maxAttempts && $this->shouldRetry($exception->getMessage(), $statusCode);

                $context = [
                    'endpoint' => $endpoint,
                    'call' => $call,
                    'attempt' => $attempt,
                    'status' => $statusCode,
                    'message' => $exception->getMessage(),
                    'retry' => $shouldRetry,
                ];

                if ($shouldRetry) {
                    $sleepMs = $this->retryDelayMs($exception->getMessage(), $statusCode, $attempt, $policy);
                    $context['sleep_ms'] = $sleepMs;
                    $this->logger->warning('Falha transitoria na API Omie; aplicando retry.', $context);
                    $telemetry?->recordEvent('WARNING', 'omie.retry', 'Falha transitoria na API Omie; aplicando retry.', $context, 'omie', 'omie_client');
                    usleep($sleepMs * 1000);
                    continue;
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
                    'message' => $exception->getMessage(),
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

    /**
     * @param array<string,mixed> $policy
     */
    private function retryDelayMs(string $message, int $statusCode, int $attempt, array $policy = []): int
    {
        $hintSeconds = $this->retryAfterSecondsFromMessage($message);
        if ($hintSeconds !== null) {
            $jitter = random_int(150, 750);
            $hintCap = max(1000, (int) ($policy['retry']['hint_cap_ms'] ?? 300000));
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
        if (preg_match('/(?:tente novamente em|try again in)\s+(\d+)\s+segundos?/', $normalized, $matches) === 1) {
            return max(1, (int) ($matches[1] ?? 0));
        }

        return null;
    }

    private function normalizeMessage(string $message): string
    {
        $normalized = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $message);
        if (!is_string($normalized) || $normalized === '') {
            $normalized = $message;
        }

        return mb_strtolower($normalized);
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

        return 'omie_response_' . hash('sha256', implode('|', [$endpoint, $call, implode('|', $fingerprint)]));
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
