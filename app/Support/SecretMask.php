<?php

declare(strict_types=1);

namespace BIomie\Support;

/**
 * Mascaramento seguro de credenciais e segredos.
 *
 * Nunca expõe APP_SECRET, APP_KEY completa, DB_PASSWORD,
 * tokens ou cookies em HTML, JSON ou logs.
 */
final class SecretMask
{
    /** @var list<string> */
    private const REDACT_KEYS = [
        'app_secret',
        'app_key_full',
        'db_password',
        'password',
        'secret',
        'token',
        'cookie',
        'authorization',
        'gemini_api_key',
        'omie_app_secret',
    ];

    /**
     * Mascara um segredo mostrando apenas início e fim.
     *
     * Ex: "abc123456xyz" → "abc123...xyz"
     *     null           → null
     *     ""             → null
     */
    public static function maskSecret(?string $value, int $start = 6, int $end = 3): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $length = mb_strlen($value);
        if ($length <= ($start + $end + 3)) {
            return str_repeat('*', min($length, 8)) . '...';
        }

        return mb_substr($value, 0, $start) . '...' . mb_substr($value, -$end);
    }

    /**
     * Verifica se um valor de configuração está preenchido.
     */
    public static function isConfigured(?string $value): bool
    {
        return $value !== null && trim($value) !== '';
    }

    /**
     * Remove chaves sensíveis de um array (recursivo).
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function redactArray(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $normalizedKey = strtolower(str_replace(['-', ' '], '_', (string) $key));

            if (self::isSensitiveKey($normalizedKey)) {
                $result[$key] = '[REDACTED]';
                continue;
            }

            if (is_array($value)) {
                $result[$key] = self::redactArray($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Sanitiza uma mensagem de erro removendo segredos comuns.
     */
    public static function sanitizeErrorMessage(string $message, int $maxLength = 500): string
    {
        // Remove possíveis credenciais inline
        $sanitized = preg_replace(
            '/(?:app_key|app_secret|password|token|secret)\s*[=:]\s*["\']?[^\s"\']+["\']?/i',
            '[CREDENCIAL REMOVIDA]',
            $message
        ) ?? $message;

        // Remove stack traces
        $sanitized = preg_replace(
            '/(?:#\d+\s+|Stack trace:).*/s',
            '',
            $sanitized
        ) ?? $sanitized;

        // Remove SQLSTATE detalhes com parâmetros
        $sanitized = preg_replace(
            '/SQLSTATE\[\w+\]:\s*[^.]+/i',
            'Erro de banco de dados',
            $sanitized
        ) ?? $sanitized;

        $sanitized = trim($sanitized);
        if (mb_strlen($sanitized) > $maxLength) {
            $sanitized = mb_substr($sanitized, 0, $maxLength) . '...';
        }

        return $sanitized !== '' ? $sanitized : 'Erro interno do sistema.';
    }

    private static function isSensitiveKey(string $key): bool
    {
        foreach (self::REDACT_KEYS as $sensitive) {
            if ($key === $sensitive || str_contains($key, $sensitive)) {
                return true;
            }
        }

        return false;
    }
}
