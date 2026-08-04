<?php

declare(strict_types=1);

namespace BIomie;

use BIomie\Support\PerformanceTimer;
use BIomie\Support\TenantContext;
use BIomie\Observability\RequestPerformanceContext;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

final class Http
{
    public static function json(array $payload, int $status = 200, array $headers = [], bool $cacheable = false): never
    {
        [$payload, $headers] = self::contextualizePayload($payload, $headers);
        self::captureResponseContext($payload, $status);
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
        header('Vary: Accept, X-BIOMIE-Context');
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

        RequestPerformanceContext::setTag('response_bytes', strlen($json));
        echo $json;
        exit;
    }

    /**
     * @return array{name:string,total_ms:float,measures:array<string,float>,warnings:list<string>,runtime:array<string,mixed>}|null
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
     * @param array<string,mixed> $payload
     */
    private static function captureResponseContext(array $payload, int $status): void
    {
        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        $source = trim((string) ($payload['source'] ?? $meta['source_of_truth'] ?? $payload['mode'] ?? ''));
        $servedFrom = trim((string) ($meta['served_from'] ?? ''));
        $cacheStatus = trim((string) ($meta['cache_status'] ?? ''));

        RequestPerformanceContext::setTag('response_status', $status);
        if ($source !== '') {
            RequestPerformanceContext::setTag('source', $source);
        }
        if ($servedFrom !== '') {
            RequestPerformanceContext::setTag('served_from', $servedFrom);
        }
        if ($cacheStatus !== '') {
            RequestPerformanceContext::setTag('cache_status', $cacheStatus);
        }
    }

    /**
     * Responds with JSON and ETag-based conditional caching.
     *
     * The representation remains private and no-store. Callers that keep the
     * validator in memory may send If-None-Match and receive 304 without
     * persisting authenticated data in the browser cache.
     *
     * $maxAge is retained as a compatibility/revalidation hint and is recorded
     * in request telemetry. It never relaxes the authenticated no-store policy.
     *
     * @param int $maxAge Revalidation cadence hint in seconds (default 60)
     */
    public static function jsonWithEtag(array $payload, int $maxAge = 60, array $headers = []): never
    {
        [$payload, $headers] = self::contextualizePayload($payload, $headers);
        $json = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );

        if ($json === false) {
            $json = '{"ok":false,"message":"Falha ao serializar a resposta JSON."}';
        }

        $etag = '"' . md5($json) . '"';
        $clientEtag = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
        $requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $isConditionalRequest = $clientEtag !== '';
        $notModified = in_array($requestMethod, ['GET', 'HEAD'], true)
            && $isConditionalRequest
            && self::ifNoneMatchMatches($clientEtag, $etag);
        $status = $notModified ? 304 : 200;
        $responseBytes = $notModified || $requestMethod === 'HEAD' ? 0 : strlen($json);

        self::captureResponseContext($payload, $status);
        RequestPerformanceContext::setTag('response_bytes', $responseBytes);
        RequestPerformanceContext::setTag('etag_revalidation_hint_seconds', max(0, $maxAge));
        RequestPerformanceContext::setTag('etag_match', $notModified);
        if ($isConditionalRequest) {
            RequestPerformanceContext::increment('etag_conditional_requests');
        }
        if ($notModified) {
            RequestPerformanceContext::increment('etag_not_modified_responses');
        } else {
            RequestPerformanceContext::increment('etag_full_responses');
        }

        // Do not inject performance diagnostics into the payload: their changing
        // duration would change the ETag on every request and defeat revalidation.
        $performance = self::finishPerformanceTimer();
        if ($performance !== null) {
            $headers['X-BIomie-Response-Time-Ms'] = (string) $performance['total_ms'];
            $headers['Server-Timing'] = 'app;dur=' . $performance['total_ms'];
        }

        http_response_code($status);
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }
        if (!$notModified) {
            header('Content-Type: application/json; charset=utf-8');
        }
        header('X-Request-Id: ' . RequestContext::id());
        header('X-Correlation-Id: ' . RequestContext::correlationId());
        header('X-Trace-Id: ' . RequestContext::traceId());
        header('Vary: Accept, X-BIOMIE-Context');
        header('ETag: ' . $etag);
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');

        if ($notModified || $requestMethod === 'HEAD') {
            exit;
        }

        echo $json;
        exit;
    }

    private static function ifNoneMatchMatches(string $headerValue, string $etag): bool
    {
        if (trim($headerValue) === '*') {
            return true;
        }

        $normalizedEtag = self::normalizeWeakEtag($etag);
        foreach (explode(',', $headerValue) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '' || $candidate === '*') {
                if ($candidate === '*') {
                    return true;
                }
                continue;
            }

            if (self::normalizeWeakEtag($candidate) === $normalizedEtag) {
                return true;
            }
        }

        return false;
    }

    private static function normalizeWeakEtag(string $etag): string
    {
        $etag = trim($etag);
        if (str_starts_with(strtoupper($etag), 'W/')) {
            $etag = ltrim(substr($etag, 2));
        }

        return $etag;
    }

    /**
     * Acrescenta a identidade do contexto efetivamente usado pela requisição.
     * Campos de segurança são sempre definidos pelo servidor, nunca pelo endpoint.
     *
     * @param array<string,mixed> $payload
     * @param array<string,string> $headers
     * @return array{0:array<string,mixed>,1:array<string,string>}
     */
    public static function contextualizePayload(array $payload, array $headers = []): array
    {
        if (!TenantContext::isBooted()) {
            return [$payload, $headers];
        }

        $contextMeta = TenantContext::apiMeta();
        $existingMeta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];

        // array_merge nesta ordem impede que um endpoint suplante a
        // identidade calculada pelo TenantContext. data_as_of só é preservado
        // quando o produtor conhece a idade real do dado; nunca inventamos
        // freshness com o relógio da resposta.
        $payload['meta'] = array_merge($existingMeta, $contextMeta);
        $headers['X-BIOMIE-Context'] = TenantContext::contextId();

        return [$payload, $headers];
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

    /**
     * Erro genérico com código próprio — usado nos catch dos endpoints Geo.
     * A mensagem passa por sanitização para não vazar detalhes internos.
     */
    public static function jsonError(string $code, string $message, int $status = 500): never
    {
        self::json([
            'ok' => false,
            'error' => $code,
            'message' => \BIomie\Support\SecretMask::sanitizeErrorMessage($message, 300),
            'request_id' => RequestContext::id(),
        ], $status);
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
        return SchemaCapabilities::tableExists($pdo, $tableName);
    }

    public static function columnExists(PDO $pdo, string $tableName, string $columnName): bool
    {
        return SchemaCapabilities::columnExists($pdo, $tableName, $columnName);
    }

    public static function noStoreJson(): void
    {
        \BIomie\Support\HttpCache::noStoreJson();
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
        if ($token === null || $token === '') {
            $token = self::csrfTokenFromJsonBody();
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
     * Token CSRF vindo do corpo JSON da requisição.
     *
     * Um POST com `Content-Type: application/json` NÃO popula `$_POST` — o PHP
     * só preenche esse array para form-urlencoded e multipart. Quem mandava o
     * token apenas dentro do JSON (o padrão de vários fetch nossos) chegava
     * aqui sem token nenhum e levava 419 no bootstrap, ANTES de o endpoint
     * rodar uma linha. Era essa a causa de "marquei como lida e não apaga": a
     * requisição morria na porta, e o log só dizia "CSRF inválido" — o que
     * parecia token expirado, mas era token nunca lido.
     *
     * Não há afrouxamento de segurança: um POST cross-origin não consegue
     * mandar `Content-Type: application/json` sem preflight, então este caminho
     * é tão protegido quanto o do header.
     *
     * O gate por content-type é deliberado: em multipart o `php://input` NÃO é
     * relegível, e ler aqui quebraria uploads. Em JSON é relegível (PHP >= 5.6),
     * então os endpoints seguem lendo o corpo normalmente depois.
     */
    private static function csrfTokenFromJsonBody(): ?string
    {
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? self::headerValue('Content-Type', '') ?? ''));
        if (!str_contains($contentType, 'json')) {
            return null;
        }

        $raw = file_get_contents('php://input');
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        $token = is_array($decoded) ? ($decoded['csrf_token'] ?? null) : null;

        return is_string($token) && $token !== '' ? $token : null;
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
