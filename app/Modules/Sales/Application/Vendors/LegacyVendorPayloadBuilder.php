<?php

declare(strict_types=1);

namespace BIomie\Modules\Sales\Application\Vendors;

use BIomie\LegacyEndpointPayloadFactory;

final class LegacyVendorPayloadBuilder
{
    /**
     * @param array<string,mixed> $snapshot
     * @param array{start:string,end:string,label:string,competencia:?string} $range
     * @return array<string,mixed>
     */
    public function build(array $snapshot, array $range, string $source, ?string $canonicalError = null): array
    {
        return LegacyEndpointPayloadFactory::vendors($snapshot, $range, $source, $canonicalError);
    }
}
