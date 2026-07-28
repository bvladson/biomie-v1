<?php

declare(strict_types=1);

namespace BIomie\SystemHealth;

use BIomie\SystemHealth\Checks\ApiCacheHealthCheck;
use BIomie\SystemHealth\Checks\MobileExperienceHealthCheck;
use BIomie\SystemHealth\Checks\ServiceWorkerCacheHealthCheck;
use BIomie\SystemHealth\Checks\ProductMixHealthCheck;
use BIomie\SystemHealth\Checks\OPcacheHealthCheck;
use BIomie\SystemHealth\Checks\GeoDetailConsistencyHealthCheck;
use BIomie\SystemHealth\Checks\ProhibitedManualImportHealthCheck;
use BIomie\SystemHealth\Checks\OperationScopeHealthCheck;
use BIomie\SystemHealth\Checks\AssetVersionHealthCheck;
use BIomie\SystemHealth\Checks\CustomerDataQualityCheck;
use BIomie\SystemHealth\Checks\DatabaseHealthCheck;
use BIomie\SystemHealth\Checks\FreshnessHealthCheck;
use BIomie\SystemHealth\Checks\GeoDataQualityCheck;
use BIomie\SystemHealth\Checks\GoldMaterializationHealthCheck;
use BIomie\SystemHealth\Checks\JobsHealthCheck;
use BIomie\SystemHealth\Checks\LoginWallpaperHealthCheck;
use BIomie\SystemHealth\Checks\OmieSyncFreshnessHealthCheck;
use BIomie\SystemHealth\Checks\SemanticHealthCheck;
use BIomie\SystemHealth\Checks\ShadowHealthCheck;
use BIomie\SystemHealth\Checks\WebhookInboxHealthCheck;
use BIomie\SystemHealth\Checks\WorkerQueueHealthCheck;
use PDO;

final class SystemHealthService
{
    public function __construct(
        private readonly ?PDO $pdo,
    ) {}

    public function run(): SystemHealthReport
    {
        /** @var list<CheckInterface> $checks */
        $checks = [
            new DatabaseHealthCheck($this->pdo),
            new OmieSyncFreshnessHealthCheck($this->pdo),
            new WorkerQueueHealthCheck($this->pdo),
            new GoldMaterializationHealthCheck($this->pdo),
            new JobsHealthCheck($this->pdo),
            new FreshnessHealthCheck($this->pdo),
            new SemanticHealthCheck(),
            new ShadowHealthCheck($this->pdo),
            new CustomerDataQualityCheck($this->pdo),
            new GeoDataQualityCheck($this->pdo),
            new WebhookInboxHealthCheck($this->pdo),
            new LoginWallpaperHealthCheck(),
            new OPcacheHealthCheck(),
            new AssetVersionHealthCheck(),
            new ServiceWorkerCacheHealthCheck(),
            new ApiCacheHealthCheck(),
            new ProductMixHealthCheck($this->pdo),
            new GeoDetailConsistencyHealthCheck($this->pdo),
            new ProhibitedManualImportHealthCheck($this->pdo),
            ...($this->pdo !== null ? [new OperationScopeHealthCheck($this->pdo)] : []),
            new MobileExperienceHealthCheck(),
        ];

        $results = [];
        foreach ($checks as $checker) {
            $start = microtime(true);
            try {
                $result = $checker->run();
            } catch (\Throwable $e) {
                $ms     = (int) round((microtime(true) - $start) * 1000);
                $result = SystemHealthCheck::unknown($checker->id(), $checker->label(), $e, $ms);
            }
            $results[] = $result;
        }

        return new SystemHealthReport($results, date('c'));
    }
}
