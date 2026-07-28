<?php

declare(strict_types=1);

namespace BIomie\Admin;

final class Flash
{
    private const KEY = 'biomie_admin_flash';

    public static function set(string $type, string $message): void
    {
        $_SESSION[self::KEY] = [
            'type' => in_array($type, ['success', 'error', 'warning', 'info'], true) ? $type : 'info',
            'message' => $message,
        ];
    }

    /**
     * @return array{type:string,message:string}|null
     */
    public static function consume(): ?array
    {
        $flash = $_SESSION[self::KEY] ?? null;
        unset($_SESSION[self::KEY]);

        if (!is_array($flash) || !isset($flash['message'])) {
            return null;
        }

        return [
            'type' => (string) ($flash['type'] ?? 'info'),
            'message' => (string) $flash['message'],
        ];
    }
}
