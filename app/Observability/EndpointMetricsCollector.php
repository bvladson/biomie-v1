<?php

declare(strict_types=1);

namespace BIomie\Observability;

use Throwable;

/**
 * Coleta e agrega métricas de latência de endpoints.
 *
 * Gravação — zero overhead no request: a função de shutdown escreve uma linha
 * JSON no arquivo endpoint_requests.jsonl antes do processo encerrar.
 *
 * Leitura — summarize() lê as últimas 50 000 linhas do arquivo, filtra pelo
 * janela de horas solicitada e calcula p50/p95/avg/max por endpoint+method.
 *
 * Rotação — rotate() mantém o arquivo abaixo de $maxBytes truncando para as
 * últimas 10 000 linhas. Chame-o via bin/evaluate_health.php (cron horário).
 *
 * Uso em api/bootstrap.php:
 *   \BIomie\Observability\EndpointMetricsCollector::registerShutdownHook(
 *       dirname(__DIR__) . '/storage/logs/endpoint_requests.jsonl'
 *   );
 */
final class EndpointMetricsCollector
{
    public function __construct(private readonly string $logPath) {}

    /**
     * Registra um shutdown hook que grava uma linha JSONL ao final do request.
     * Deve ser chamado o mais cedo possível no bootstrap para capturar o
     * tempo completo de execução a partir de REQUEST_TIME_FLOAT.
     */
    public static function registerShutdownHook(string $logPath): void
    {
        $startTime = (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
        $endpoint  = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'unknown.php'));
        $method    = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        register_shutdown_function(static function () use ($logPath, $startTime, $endpoint, $method): void {
            try {
                $durationMs = (int) round((microtime(true) - $startTime) * 1000);
                $statusCode = (int) (http_response_code() ?: 200);

                $line = json_encode([
                    'ep' => $endpoint,
                    'm'  => $method,
                    'sc' => $statusCode,
                    'ms' => $durationMs,
                    'ts' => date('c'),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

                @file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX);
            } catch (Throwable) {}
        });
    }

    /**
     * Agrega as linhas do log da janela solicitada e retorna p50/p95/avg/max
     * por endpoint+method, ordenados por p95 decrescente.
     *
     * @return list<array{endpoint:string,method:string,total:int,errors:int,error_rate:float,avg_ms:int,p50_ms:int,p95_ms:int,max_ms:int}>
     */
    public function summarize(int $hours = 24): array
    {
        if (!is_file($this->logPath)) {
            return [];
        }

        $cutoff  = time() - ($hours * 3600);
        $grouped = [];

        try {
            // Evita OOM em arquivos grandes: lê apenas as últimas 50 000 linhas
            $lines = @file($this->logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $lines = array_slice($lines, -50_000);

            foreach ($lines as $line) {
                $row = json_decode($line, true);
                if (!is_array($row)) {
                    continue;
                }

                $ts = isset($row['ts']) ? (int) strtotime((string) $row['ts']) : 0;
                if ($ts < $cutoff) {
                    continue;
                }

                $ep  = (string) ($row['ep'] ?? 'unknown');
                $m   = (string) ($row['m']  ?? 'GET');
                $key = $ep . ':' . $m;
                $ms  = max(0, (int) ($row['ms'] ?? 0));
                $sc  = (int) ($row['sc'] ?? 200);

                $grouped[$key]['endpoint']    = $ep;
                $grouped[$key]['method']      = $m;
                $grouped[$key]['durations'][] = $ms;
                $grouped[$key]['total']       = (int) (($grouped[$key]['total'] ?? 0) + 1);
                $grouped[$key]['errors']      = (int) (($grouped[$key]['errors'] ?? 0) + ($sc >= 500 ? 1 : 0));
            }
        } catch (Throwable) {
            return [];
        }

        $result = [];
        foreach ($grouped as $data) {
            $dur = $data['durations'];
            sort($dur, SORT_NUMERIC);
            $n = count($dur);

            $result[] = [
                'endpoint'   => $data['endpoint'],
                'method'     => $data['method'],
                'total'      => $data['total'],
                'errors'     => $data['errors'],
                'error_rate' => $data['total'] > 0 ? round($data['errors'] / $data['total'] * 100, 1) : 0.0,
                'avg_ms'     => $n > 0 ? (int) round(array_sum($dur) / $n) : 0,
                'p50_ms'     => $n > 0 ? $dur[(int) floor($n * 0.50)] : 0,
                'p95_ms'     => $n > 0 ? $dur[(int) min(floor($n * 0.95), $n - 1)] : 0,
                'max_ms'     => $n > 0 ? max($dur) : 0,
            ];
        }

        usort($result, static fn (array $a, array $b): int => $b['p95_ms'] <=> $a['p95_ms']);
        return $result;
    }

    /**
     * Mantém o arquivo abaixo de $maxBytes retendo as últimas 10 000 linhas.
     * Chame via bin/evaluate_health.php para evitar crescimento ilimitado.
     */
    public function rotate(int $maxBytes = 10_485_760): void
    {
        if (!is_file($this->logPath)) {
            return;
        }

        $size = @filesize($this->logPath);
        if ($size === false || $size < $maxBytes) {
            return;
        }

        try {
            $lines = @file($this->logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $kept  = array_slice($lines, -10_000);
            @file_put_contents($this->logPath, implode("\n", $kept) . "\n", LOCK_EX);
        } catch (Throwable) {}
    }
}
