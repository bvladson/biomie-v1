<?php

declare(strict_types=1);

namespace BIomie\Support;

final class HttpCache
{
    public static function noStoreHtml(): void
    {
        self::sendNoStore('text/html; charset=utf-8');
    }

    public static function noStoreJson(): void
    {
        self::sendNoStore('application/json; charset=utf-8');
    }

    public static function privateShortLived(int $seconds = 30): void
    {
        if (headers_sent()) {
            return;
        }
        header('Cache-Control: private, max-age=' . max(0, $seconds) . ', must-revalidate');
        header('Vary: Cookie, Authorization, Accept');
    }

    private static function sendNoStore(string $contentType): void
    {
        if (headers_sent()) {
            return;
        }
        header('Content-Type: ' . $contentType);
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Vary: Cookie, Authorization, Accept');
    }
}
