<?php

declare(strict_types=1);

namespace BIomie\SystemHealth\Checks;

use BIomie\Bootstrap;
use BIomie\SystemHealth\CheckInterface;
use BIomie\SystemHealth\SystemHealthCheck;

final class ServiceWorkerCacheHealthCheck implements CheckInterface
{
    public function id(): string { return 'service_worker_cache'; }
    public function label(): string { return 'Service Worker / Cache'; }

    public function run(): SystemHealthCheck
    {
        $start = microtime(true);
        $path = Bootstrap::rootPath('sw.js');
        $body = is_file($path) ? (string) file_get_contents($path) : '';
        $cachesPhp = preg_match("~PAGE_ALLOWLIST|PAGE_CACHE|index\\.php|api/overview\\.php|api/products\\.php~", $body) === 1;
        $hasNoStoreFetch = str_contains($body, "cache: 'no-store'") || str_contains($body, 'cache: "no-store"');
        $metrics = [
            'sw_exists' => is_file($path) ? 1 : 0,
            'caches_php_or_api' => $cachesPhp ? 1 : 0,
            'network_no_store_for_dynamic' => $hasNoStoreFetch ? 1 : 0,
            'mtime' => is_file($path) ? date('c', (int) filemtime($path)) : null,
        ];
        $ms = (int) round((microtime(true) - $start) * 1000);
        if (!is_file($path)) {
            return SystemHealthCheck::unknown($this->id(), $this->label(), new \RuntimeException('sw.js não encontrado.'), $ms);
        }
        if ($cachesPhp || !$hasNoStoreFetch) {
            return SystemHealthCheck::critical($this->id(), $this->label(), 'Service Worker pode servir PHP/API antigos.', $metrics, ['Não cachear páginas PHP nem /api; cachear apenas assets versionados.'], $ms);
        }
        return SystemHealthCheck::ok($this->id(), $this->label(), 'Service Worker limitado a assets versionados.', $metrics, $ms);
    }
}
