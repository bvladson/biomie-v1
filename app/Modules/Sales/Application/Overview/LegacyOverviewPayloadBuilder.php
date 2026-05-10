<?php

declare(strict_types=1);

namespace BIomie\Modules\Sales\Application\Overview;

use BIomie\DatasetFreshnessService;
use BIomie\LegacyEndpointPayloadFactory;

final class LegacyOverviewPayloadBuilder
{
    /**
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    public function build(array $snapshot, DatasetFreshnessService $freshness): array
    {
        return LegacyEndpointPayloadFactory::overview($snapshot, $freshness);
    }
}
