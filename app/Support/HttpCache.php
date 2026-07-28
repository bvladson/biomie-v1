<?php

declare(strict_types=1);

namespace BIomie\Support;

final class HttpCache
{
    public static function noStoreHtml(): void
    {
        self::sendNoStore('text/html; charset=utf-8');
    }

    /**
     * HTML de paginas de view autenticadas com bfcache habilitado.
     *
     * Usa "no-cache" (revalida sempre antes de reutilizar) em vez de "no-store".
     * Sem validadores, uma navegacao normal sempre refaz o request (nunca serve
     * pagina obsoleta), mas o back/forward passa a usar o bfcache do navegador
     * (voltar/avancar instantaneo). "private" impede caches compartilhados.
     * O logout envia Clear-Site-Data para expulsar o bfcache apos sair.
     */
    public static function bfcacheableHtml(): void
    {
        if (headers_sent()) {
            return;
        }
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: private, no-cache, must-revalidate, max-age=0');
        header('Vary: Cookie, Authorization, Accept');
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
