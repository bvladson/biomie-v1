<?php

declare(strict_types=1);

namespace BIomie\Support\Filters\Presets;

use BIomie\Support\Filters\SortWhitelist;

final class ProductFilters
{
    public static function sortWhitelist(): SortWhitelist
    {
        return new SortWhitelist(
            map: [
                'produto'     => 'description',
                'sku'         => 'product_code',
                'quantidade'  => 'quantity',
                'revenue'     => 'revenue',
                'clientes'    => 'customers_count',
                'vendedores'  => 'vendors_count',
                'nfs'         => 'invoices_count',
                'ticket_medio' => 'avg_ticket',
            ],
            default: 'revenue'
        );
    }
}
