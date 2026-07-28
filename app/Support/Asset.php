<?php

declare(strict_types=1);

namespace BIomie\Support;

use BIomie\Bootstrap;

final class Asset
{
    /** @return array{version:string,deployed_at:string,cache_strategy:string} */
    public static function build(): array
    {
        $path = Bootstrap::rootPath('config/build.php');
        $build = is_file($path) ? require $path : [];
        return [
            'version' => (string) ($build['version'] ?? getenv('BIOMIE_BUILD_VERSION') ?: 'dev'),
            'deployed_at' => (string) ($build['deployed_at'] ?? ''),
            'cache_strategy' => (string) ($build['cache_strategy'] ?? 'assets-filemtime'),
        ];
    }

    public static function buildVersion(): string
    {
        return self::build()['version'];
    }

    public static function version(string $relativePath): string
    {
        $path = self::normalize($relativePath);
        $absolute = Bootstrap::rootPath($path);
        $mtime = @filemtime($absolute);
        if ($mtime !== false) {
            return (string) $mtime;
        }
        return rawurlencode(self::buildVersion());
    }

    public static function url(string $relativePath): string
    {
        $path = self::preferMinified(self::normalize($relativePath));
        $separator = str_contains($path, '?') ? '&' : '?';
        return $path . $separator . 'v=' . self::version($path);
    }

    /**
     * Serve o irmão .min.js/.min.css quando ele existe E não está desatualizado
     * em relação ao fonte — em dev, editar o fonte volta a servi-lo cru até o
     * build regenerar o minificado (tools/dev/minify_assets.ps1).
     */
    private static function preferMinified(string $path): string
    {
        $suffix = null;
        foreach (['.js', '.css'] as $ext) {
            if (str_ends_with($path, $ext) && !str_ends_with($path, '.min' . $ext)) {
                $suffix = $ext;
                break;
            }
        }
        if ($suffix === null) {
            return $path;
        }

        $minPath = substr($path, 0, -strlen($suffix)) . '.min' . $suffix;
        $minMtime = @filemtime(Bootstrap::rootPath($minPath));
        if ($minMtime === false) {
            return $path;
        }

        $srcMtime = @filemtime(Bootstrap::rootPath($path));
        return ($srcMtime === false || $minMtime >= $srcMtime) ? $minPath : $path;
    }

    public static function tagsMeta(): string
    {
        $build = self::build();
        return sprintf(
            '<meta name="biomie-build" content="%s" />' . "\n" .
            '<meta name="biomie-deployed-at" content="%s" />' . "\n" .
            '<meta name="biomie-cache-strategy" content="%s" />',
            htmlspecialchars($build['version'], ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($build['deployed_at'], ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($build['cache_strategy'], ENT_QUOTES, 'UTF-8')
        );
    }

    private static function normalize(string $relativePath): string
    {
        $path = trim($relativePath);
        $path = ltrim($path, '/');
        $path = preg_replace('~(^|/)\.\.(?:/|$)~', '', $path) ?: $path;
        return $path;
    }
}
