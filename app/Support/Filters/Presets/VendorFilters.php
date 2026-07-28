<?php

declare(strict_types=1);

namespace BIomie\Support\Filters\Presets;

use BIomie\Support\Filters\SortWhitelist;

final class VendorFilters
{
    public static function sortWhitelist(): SortWhitelist
    {
        return new SortWhitelist(
            map: [
                'vendedor'       => 'vendor_name',
                'base_comercial' => 'base_comercial',
                'nfs'            => 'invoices_count',
                'clientes'       => 'customers_count',
                'ticket_medio'   => 'avg_ticket',
            ],
            default: 'base_comercial'
        );
    }
}
