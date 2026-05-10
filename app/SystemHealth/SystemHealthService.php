<?php

declare(strict_types=1);

namespace BIomie\SystemHealth;

use BIomie\SystemHealth\Checks\CustomerDataQualityCheck;
use BIomie\SystemHealth\Checks\DatabaseHealthCheck;
use BIomie\SystemHealth\Checks\FreshnessHealthCheck;
use BIomie\SystemHealth\Checks\GeoDataQualityCheck;
use BIomie\SystemHealth\Checks\JobsHealthCheck;
use BIomie\SystemHealth\Checks\SemanticHealthCheck;
use BIomie\SystemHealth\Checks\ShadowHealthCheck;
use BIomie\SystemHealth\Checks\WebhookInboxHealthCheck;
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
            new JobsHealthCheck($this->pdo),
            new FreshnessHealthCheck($this->pdo),
            new SemanticHealthCheck(),
            new ShadowHealthCheck($this->pdo),
            new CustomerDataQualityCheck($this->pdo),
            new GeoDataQualityCheck($this->pdo),
            new WebhookInboxHealthCheck($this->pdo),
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
