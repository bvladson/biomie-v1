<?php

declare(strict_types=1);

namespace BIomie\SystemHealth\Checks;

use BIomie\Bootstrap;
use BIomie\SystemHealth\CheckInterface;
use BIomie\SystemHealth\SystemHealthCheck;

final class ApiCacheHealthCheck implements CheckInterface
{
    public function id(): string { return 'api_cache'; }
    public function label(): string { return 'Cache de APIs'; }

    public function run(): SystemHealthCheck
    {
        $start = microtime(true);
        $issues = [];

        $httpPhp = Bootstrap::rootPath('app/Http.php');
        $httpBody = is_file($httpPhp) ? (string) file_get_contents($httpPhp) : '';
        $httpSetsNoStore = str_contains($httpBody, 'no-store') && str_contains($httpBody, 'Cache-Control');

        $bootstrapPhp = Bootstrap::rootPath('api/bootstrap.php');
        $bootstrapBody = is_file($bootstrapPhp) ? (string) file_get_contents($bootstrapPhp) : '';
        $bootstrapSetsContentType = str_contains($bootstrapBody, 'Content-Type') && str_contains($bootstrapBody, 'application/json');

        $htaccess = Bootstrap::rootPath('.htaccess');
        $htaccessBody = is_file($htaccess) ? (string) file_get_contents($htaccess) : '';
        $htaccessHasPhpNoStore = str_contains($htaccessBody, 'no-store') && str_contains($htaccessBody, '.php');

        if (!$httpSetsNoStore) {
            $issues[] = 'Http::json() não encontrado ou não define Cache-Control: no-store.';
        }
        if (!$bootstrapSetsContentType) {
            $issues[] = 'api/bootstrap.php não define Content-Type: application/json.';
        }
        if (!$htaccessHasPhpNoStore) {
            $issues[] = '.htaccess não define no-store para .php — APIs podem ser cacheadas pelo servidor.';
        }

        $metrics = [
            'http_json_no_store' => $httpSetsNoStore ? 1 : 0,
            'bootstrap_content_type' => $bootstrapSetsContentType ? 1 : 0,
            'htaccess_php_no_store' => $htaccessHasPhpNoStore ? 1 : 0,
        ];
        $ms = (int) round((microtime(true) - $start) * 1000);

        if ($issues !== []) {
            return SystemHealthCheck::warning(
                $this->id(),
                $this->label(),
                'Configuração de cache de APIs incompleta.',
                $metrics,
                $issues,
                $ms
            );
        }

        return SystemHealthCheck::ok(
            $this->id(),
            $this->label(),
            'APIs dinâmicas com Cache-Control: no-store configurado em todas as camadas.',
            $metrics,
            $ms
        );
    }
}
