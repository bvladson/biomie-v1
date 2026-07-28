<?php

declare(strict_types=1);

namespace BIomie\Support;

final class CliGuard
{
    public static function requireCli(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        if (!headers_sent()) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            header('X-Robots-Tag: noindex, nofollow', true);
        }

        echo "Not found.\n";
        exit;
    }
}
