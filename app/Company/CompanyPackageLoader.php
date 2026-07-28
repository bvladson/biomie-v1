<?php

declare(strict_types=1);

namespace BIomie\Company;

use BIomie\Bootstrap;
use BIomie\Support\TenantContext;

/**
 * Loads company package manifests from companies/{slug}/manifest.json.
 * Uses a request-scoped in-memory cache to avoid repeated filesystem reads.
 */
final class CompanyPackageLoader
{
    /** @var array<string, CompanyManifest> */
    private static array $cache = [];

    public static function load(string $slug): CompanyManifest
    {
        $key = strtolower(trim($slug));
        if ($key === '') {
            return CompanyManifest::empty('');
        }

        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $path = Bootstrap::rootPath("companies/{$key}/manifest.json");
        if (!is_file($path)) {
            return self::$cache[$key] = CompanyManifest::empty($key);
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            return self::$cache[$key] = CompanyManifest::empty($key);
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return self::$cache[$key] = CompanyManifest::empty($key);
        }
        if (!is_array($data) || strtolower(trim((string) ($data['slug'] ?? ''))) !== $key) {
            return self::$cache[$key] = CompanyManifest::empty($key);
        }

        return self::$cache[$key] = new CompanyManifest($data);
    }

    /** Loads manifest for the currently authenticated tenant. */
    public static function forTenant(): CompanyManifest
    {
        $slug = '';
        try {
            if (TenantContext::isBooted()) {
                $slug = TenantContext::slug();
            }
        } catch (\Throwable) {}

        return $slug !== '' ? self::load($slug) : CompanyManifest::empty('');
    }

    /** Returns the filesystem path to the company's CSS theme file (if it exists). */
    public static function resolveThemeCssContent(string $slug): string
    {
        $key = strtolower(trim($slug));
        if ($key === '') {
            return '';
        }

        $path = Bootstrap::rootPath("assets/companies/{$key}/theme.css");
        if (!is_file($path)) {
            return '';
        }

        return (string) file_get_contents($path);
    }

    /** Clears request-scoped cache (used in tests or context switches). */
    public static function clearCache(): void
    {
        self::$cache = [];
    }
}
