<?php

declare(strict_types=1);

namespace BIomie\SystemHealth\Checks;

use BIomie\Bootstrap;
use BIomie\Support\Asset;
use BIomie\SystemHealth\CheckInterface;
use BIomie\SystemHealth\SystemHealthCheck;

final class AssetVersionHealthCheck implements CheckInterface
{
    public function id(): string { return 'asset_version'; }
    public function label(): string { return 'Versão de assets'; }

    public function run(): SystemHealthCheck
    {
        $start = microtime(true);
        $assets = ['assets/js/app.js', 'assets/css/main.css', 'assets/js/cache-refresh.js', 'sw.js'];
        $missing = [];
        $versions = [];
        foreach ($assets as $asset) {
            if (!is_file(Bootstrap::rootPath($asset))) {
                $missing[] = $asset;
                continue;
            }
            $versions[$asset] = Asset::version($asset);
        }
        $metrics = [
            'build_version' => Asset::buildVersion(),
            'assets_checked' => count($assets),
            'assets_missing' => count($missing),
            'app_js_version' => $versions['assets/js/app.js'] ?? null,
            'main_css_version' => $versions['assets/css/main.css'] ?? null,
        ];
        $ms = (int) round((microtime(true) - $start) * 1000);
        if ($missing !== []) {
            return SystemHealthCheck::warning($this->id(), $this->label(), 'Há assets esperados ausentes.', $metrics, ['Verificar deploy: ' . implode(', ', $missing)], $ms);
        }
        return SystemHealthCheck::ok($this->id(), $this->label(), 'Assets críticos versionados por filemtime/build.', $metrics, $ms);
    }
}
