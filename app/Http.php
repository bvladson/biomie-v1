<?php

declare(strict_types=1);

namespace BIomie;

use BIomie\Support\PerformanceTimer;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

final class Http
{
    public static function json(array $payload, int $status = 200, array $headers = [], bool $cacheable = false): never
    {
        $performance = self::finishPerformanceTimer();
        if ($performance !== null) {
            $headers['X-BIomie-Response-Time-Ms'] = (string) $performance['total_ms'];
            if (self::canExposeDiagnostics()) {
                $diagnostics = is_array($payload['diagnostics'] ?? null) ? $payload['diagnostics'] : [];
                $diagnostics['performance'] = $performance;
                $payload['diagnostics'] = $diagnostics;
            }
        }

        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Request-Id: ' . RequestContext::id());
        header('X-Correlation-Id: ' . RequestContext::correlationId());
        header('X-Trace-Id: ' . RequestContext::traceId());
        header('Vary: Accept');
        header($cacheable ? 'Cache-Control: private, no-cache, must-revalidate' : 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }
        $json = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );

        if ($json === false) {
            $json = '{"ok":false,"message":"Falha ao serializar a resposta JSON."}';
        }

        echo $json;
        exit;
    }

    /**
     * @return array{name:string,total_ms:float,measures:array<string,float>,warnings:list<string>}|null
     */
    private static function finishPerformanceTimer(): ?array
    {
        $timer = $GLOBALS['biomie_performance_timer'] ?? null;
        if (!$timer instanceof PerformanceTimer) {
            return null;
        }

        unset($GLOBALS['biomie_performance_timer']);
        return $timer->finish();
    }

    private static function canExposeDiagnostics(): bool
    {
        try {
            if (Auth::canAccessAdminArea()) {
                return true;
            }

            $environment = strtolower((string) (getenv('APP_ENV') ?: 'production'));
            return $environment !== 'production' && self::boolQuery('debug', false);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Responds with JSON and ETag-based conditional caching.
     *
     * On cache hit (If-None-Match matches), sends 304 Not Modified with no body.
     * Otherwise sends the payload with ETag + Cache-Control headers.
     *
     * @param int $maxAge Cache-Control max-age in seconds (default 60)
     */
    public static function jsonWithEtag(array $payload, int $maxAge = 60, array $headers = []): never
    {
        $json = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );

        if ($json === false) {
            $json = '{"ok":false,"message":"Falha ao serializar a resposta JSON."}';
        }

        $etag = '"' . md5($json) . '"';
        $clientEtag = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));

        if ($clientEtag !== '' && $clientEtag === $etag) {
            http_response_code(304);
            header('ETag: ' . $etag);
            header('Cache-Control: private, max-age=' . $maxAge);
            exit;
        }

        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Request-Id: ' . RequestContext::id());
        header('X-Correlation-Id: ' . RequestContext::correlationId());
        header('X-Trace-Id: ' . RequestContext::traceId());
        header('Vary: Accept');
        header('ETag: ' . $etag);
        header('Cache-Control: private, max-age=' . $maxAge);
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }

        echo $json;
        exit;
    }

    public static function accepted(array $payload, array $headers = []): never
    {
        self::json($payload, 202, $headers);
    }

    /**
     * @param list<string> $allowedMethods
     */
    public static function methodNotAllowed(array $allowedMethods, ?string $message = null): never
    {
        $allowedMethods = array_values(array_unique(array_map(
            static fn (string $method): string => strtoupper(trim($method)),
            $allowedMethods
        )));

        self::json([
            'ok' => false,
            'error' => 'method_not_allowed',
            'message' => $message ?: 'Metodo nao permitido.',
            'allowed_methods' => $allowedMethods,
            'request_id' => RequestContext::id(),
        ], 405, ['Allow' => implode(', ', $allowedMethods)]);
    }

    public static function validationError(string $message = 'Parametros invalidos.', array $details = []): never
    {
        self::json([
            'ok' => false,
            'error' => 'validation_error',
            'message' => $message,
            'details' => $details,
            'request_id' => RequestContext::id(),
        ], 422);
    }

    public static function internalError(string $message = 'Erro interno. Tente novamente em alguns segundos.'): never
    {
        self::json([
            'ok' => false,
            'error' => 'internal_error',
            'message' => $message,
            'request_id' => RequestContext::id(),
        ], 500);
    }

    public static function tableExists(PDO $pdo, string $tableName): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
        );
        $stmt->execute(['table' => $tableName]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public static function jsonConflictMissingTable(string $tableName, ?string $friendlyMessage = null): never
    {
        self::json([
            'ok' => false,
            'error' => 'missing_analytic_table',
            'message' => $friendlyMessage
                ?? 'Os dados analíticos ainda não foram preparados. Execute as migrations e a materialização dos marts.',
            'table' => $tableName,
            'recommended_action' => 'Rodar php bin/schema_migrate.php migrate e depois materialize_marts.php',
        ], 409);
    }

    public static function query(string $key, ?string $default = null): ?string
    {
        $value = $_GET[$key] ?? $default;
        if ($value === null) {
            return null;
        }
        return is_string($value) ? trim($value) : $default;
    }

    public static function headerValue(string $name, ?string $default = null): ?string
    {
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $_SERVER[$serverKey] ?? $default;
        if ($value === null) {
            return null;
        }
        return is_string($value) ? trim($value) : $default;
    }

    public static function boolQuery(string $key, bool $default = false): bool
    {
        $value = self::query($key);
        if ($value === null || $value === '') {
            return $default;
        }
        return in_array(strtolower($value), ['1', 'true', 'sim', 'yes', 'y'], true);
    }

    public static function isWriteMethod(): bool
    {
        return in_array(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')), ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    public static function wantsAsync(): bool
    {
        $prefer = strtolower((string) self::headerValue('Prefer', ''));
        if (str_contains($prefer, 'respond-async')) {
            return true;
        }

        $mode = strtolower((string) self::query('mode', 'queue'));
        return $mode !== 'immediate';
    }

    public static function enforceCsrf(?Logger $logger = null): void
    {
        if (!self::isWriteMethod()) {
            return;
        }

        $token = self::headerValue('X-CSRF-Token');
        if ($token === null || $token === '') {
            $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;
        }

        if (!Auth::verifyCsrf($token)) {
            $logger?->warning('CSRF inválido', ['request_id' => RequestContext::id()]);
            self::json([
                'ok' => false,
                'message' => 'Sessão do formulário expirada. Recarregue a página e tente novamente.',
                'request_id' => RequestContext::id(),
            ], 419);
        }
    }

    /**
     * @return array{start:string,end:string,label:string}
     */
    public static function resolveDateRange(): array
    {
        $timezone = date_default_timezone_get();
        $now = new DateTimeImmutable('now', new \DateTimeZone($timezone));

        $start = self::query('start');
        $end = self::query('end');

        if ($start && $end) {
            return [
                'start' => $start,
                'end' => $end,
                'label' => self::formatLabel($start, $end),
            ];
        }

        $firstDay = $now->modify('first day of this month')->format('Y-m-d');
        $lastDay = $now->modify('last day of this month')->format('Y-m-d');

        return [
            'start' => $firstDay,
            'end' => $lastDay,
            'label' => self::formatLabel($firstDay, $lastDay),
        ];
    }

    /**
     * @return array{start:string,end:string,label:string,competencia:?string}
     */
    public static function resolveDateRangeOrCompetencia(string $queryKey = 'competencia'): array
    {
        $competencia = self::query($queryKey);
        if ($competencia !== null && $competencia !== '') {
            if (!preg_match('/^\d{4}-\d{2}$/', $competencia)) {
                throw new InvalidArgumentException($queryKey . ' deve seguir o formato YYYY-MM.');
            }

            $month = DateTimeImmutable::createFromFormat('!Y-m', $competencia);
            if (!$month instanceof DateTimeImmutable || $month->format('Y-m') !== $competencia) {
                throw new InvalidArgumentException($queryKey . ' deve seguir o formato YYYY-MM.');
            }

            $start = $month->format('Y-m-01');
            $end = $month->modify('last day of this month')->format('Y-m-d');

            return [
                'start' => $start,
                'end' => $end,
                'label' => self::formatLabel($start, $end),
                'competencia' => $competencia,
            ];
        }

        return self::resolveDateRange() + ['competencia' => null];
    }

    public static function validateDateRange(string $start, string $end, int $maxDays = 370): void
    {
        $startDate = DateTimeImmutable::createFromFormat('Y-m-d', $start);
        $endDate   = DateTimeImmutable::createFromFormat('Y-m-d', $end);

        if (!$startDate || !$endDate || $startDate->format('Y-m-d') !== $start || $endDate->format('Y-m-d') !== $end) {
            throw new InvalidArgumentException('start/end devem seguir o formato YYYY-MM-DD.');
        }

        if ($startDate > $endDate) {
            throw new InvalidArgumentException('start deve ser menor ou igual a end.');
        }

        if ((int) $startDate->diff($endDate)->days > $maxDays) {
            throw new InvalidArgumentException("O intervalo máximo permitido é de {$maxDays} dias.");
        }
    }

    private static function formatLabel(string $start, string $end): string
    {
        $startDate = DateTimeImmutable::createFromFormat('Y-m-d', $start);
        $endDate = DateTimeImmutable::createFromFormat('Y-m-d', $end);

        if (!$startDate || !$endDate) {
            return $start . ' até ' . $end;
        }

        return $startDate->format('d/m/Y') . ' até ' . $endDate->format('d/m/Y');
    }
}
