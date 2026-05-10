<?php

declare(strict_types=1);

namespace BIomie\Modules\Geo\Domain;

final readonly class GeoLocation
{
    public function __construct(
        public string $uf,
        public string $cidade,
        public string $bairro,
        public string $bairroNorm,
        public string $bairroOficial,
    ) {}
}
