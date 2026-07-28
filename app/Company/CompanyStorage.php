<?php

declare(strict_types=1);

namespace BIomie\Company;

use BIomie\Bootstrap;
use BIomie\Support\TenantContext;

/**
 * Resolves isolated storage paths for company-specific files.
 * All files go to storage/companies/{slug}/{relative}.
 * Nunca cai em storage global: empresa/contexto precisam ser explícitos.
 */
final class CompanyStorage
{
    public static function path(string $slug, string $relative): string
    {
        $slug = self::sanitizeSlug($slug);
        if ($slug === '') {
            throw new \InvalidArgumentException('Slug da empresa é obrigatório para storage isolado.');
        }

        return Bootstrap::rootPath("storage/companies/{$slug}/{$relative}");
    }

    /**
     * Returns the path and ensures the directory exists.
     * Use for log files, export files, or any path that needs to be written.
     */
    public static function ensure(string $slug, string $relative): string
    {
        $fullPath = self::path($slug, $relative);
        $dir = is_file($fullPath) ? dirname($fullPath) : $fullPath;

        // Check if $relative looks like a file (has extension)
        if (str_contains(basename($relative), '.')) {
            $dir = dirname($fullPath);
        }

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            throw new \RuntimeException('Storage isolado da empresa não está disponível para escrita.');
        }

        return $fullPath;
    }

    /** Resolves a storage path for the currently active tenant. */
    public static function forTenant(string $relative): string
    {
        return self::path(TenantContext::current()->companySlug, $relative);
    }

    /** Ensures directory exists and returns path for the currently active tenant. */
    public static function ensureForTenant(string $relative): string
    {
        return self::ensure(TenantContext::current()->companySlug, $relative);
    }

    /** Returns the base storage directory for a company (no trailing slash). */
    public static function baseDir(string $slug): string
    {
        $slug = self::sanitizeSlug($slug);
        if ($slug === '') {
            throw new \InvalidArgumentException('Slug da empresa é obrigatório para storage isolado.');
        }

        return Bootstrap::rootPath("storage/companies/{$slug}");
    }

    private static function sanitizeSlug(string $slug): string
    {
        return strtolower(preg_replace('/[^a-z0-9_\-]/i', '_', trim($slug)));
    }
}
