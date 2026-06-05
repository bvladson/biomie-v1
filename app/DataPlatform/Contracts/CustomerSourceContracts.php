<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Contracts;

final class CustomerSourceContracts
{
    public const PROFILE = 'customer.profile.v1';
    public const IDENTITY = 'customer.identity.v1';
    public const COMMERCIAL_HISTORY = 'customer.commercial_history.v1';
    public const GEO_STATUS = 'customer.geo_status.v1';
    public const CNAE_STATUS = 'customer.cnae_status.v1';
    public const QUALITY = 'customer.quality.v1';
    public const SEARCH = 'customer.search.v1';
    public const OPPORTUNITIES = 'customer.opportunities.v1';
}
