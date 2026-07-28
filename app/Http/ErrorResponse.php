<?php

declare(strict_types=1);

namespace BIomie\Http;

final class ErrorResponse
{
    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public static function json(string $requestId, int $status, string $error, string $message, array $context = []): array
    {
        return [
            'ok' => false,
            'error' => $error,
            'message' => $message,
            'request_id' => $requestId,
            'context' => self::safeContext($context),
        ];
    }

    public static function safeMessageForStatus(int $status): string
    {
        return match ($status) {
            401 => 'Sessao expirada. Faca login novamente.',
            403 => 'Seu perfil nao tem permissao para acessar este recurso.',
            404 => 'Recurso nao encontrado.',
            422 => 'Nao foi possivel processar os parametros enviados.',
            default => 'Nao foi possivel carregar este recurso.',
        };
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public static function safeContext(array $context): array
    {
        $safe = [];
        foreach ($context as $key => $value) {
            $keyString = strtolower((string) $key);
            if (self::isSensitiveKey($keyString)) {
                $safe[$key] = '[redacted]';
                continue;
            }

            if (is_array($value)) {
                $safe[$key] = self::safeContext($value);
                continue;
            }

            if (is_object($value)) {
                $safe[$key] = '[object ' . $value::class . ']';
                continue;
            }

            if (is_string($value) && strlen($value) > 500) {
                $safe[$key] = substr($value, 0, 500) . '...';
                continue;
            }

            $safe[$key] = $value;
        }

        return $safe;
    }

    private static function isSensitiveKey(string $key): bool
    {
        foreach (['pass', 'password', 'secret', 'token', 'cookie', 'session', 'authorization', 'app_secret', 'db_pass'] as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }
}
