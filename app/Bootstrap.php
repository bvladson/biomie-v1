<?php

declare(strict_types=1);

namespace BIomie;

use RuntimeException;

final class Bootstrap
{
    private static bool $initialized = false;

    private static function registerAutoloader(): void
    {
        spl_autoload_register(static function (string $class): void {
            $prefix = 'BIomie\\';
            if (!str_starts_with($class, $prefix)) {
                return;
            }

            $relative = substr($class, strlen($prefix));
            $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
            if (is_file($path)) {
                require_once $path;
            }
        });
    }

    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;

        self::registerAutoloader();

        $timezone = getenv('APP_TIMEZONE');
        if ($timezone === false || trim($timezone) === '') {
            $timezone = (string) ($_ENV['APP_TIMEZONE'] ?? $_SERVER['APP_TIMEZONE'] ?? 'America/Fortaleza');
        }
        date_default_timezone_set(trim($timezone) !== '' ? trim($timezone) : 'America/Fortaleza');
        RequestContext::boot();

        // Preflight: extensoes obrigatorias.
        $missing = [];
        foreach (['pdo_mysql', 'curl', 'mbstring', 'json'] as $ext) {
            if (!extension_loaded($ext)) {
                $missing[] = $ext;
            }
        }
        if ($missing !== []) {
            throw new RuntimeException(
                'Extensoes PHP faltando: ' . implode(', ', $missing)
                . '. Ative-as no painel da Hostinger > PHP > Extensoes.'
            );
        }

        // Preflight: diretorios gravaveis.
        $storageDir = dirname(__DIR__) . '/storage';
        foreach (["$storageDir/cache", "$storageDir/cache/jobs", "$storageDir/logs", "$storageDir/releases"] as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            if (!is_writable($dir)) {
                throw new RuntimeException("Diretorio sem permissao de escrita: $dir");
            }
        }
    }

    public static function rootPath(string $path = ''): string
    {
        $root = dirname(__DIR__);
        return $path === '' ? $root : $root . '/' . ltrim($path, '/');
    }
}
