<?php

declare(strict_types=1);

namespace BIomie\Support;

final class DocumentMask
{
    public static function maskDocument(?string $document, ?string $invalidFallback = null): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) ($document ?? '')) ?? '';
        if ($digits === '') {
            return $invalidFallback;
        }

        $length = strlen($digits);
        if ($length === 11) {
            return '***.***.***-**';
        }

        if ($length === 14) {
            return substr($digits, 0, 2)
                . '.'
                . substr($digits, 2, 3)
                . '.'
                . substr($digits, 5, 3)
                . '/****-**';
        }

        return $invalidFallback;
    }
}
