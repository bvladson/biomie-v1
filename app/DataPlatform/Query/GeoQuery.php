<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Query;

use BIomie\Support\MapFilterSupport;

final readonly class GeoQuery
{
    public const DEFAULT_LAYER = 'mapa_composto_ceara';

    /**
     * @param array<string,mixed> $received
     * @param array<string,mixed> $normalized
     */
    public function __construct(
        public string $dateFrom,
        public string $dateTo,
        public string $startComp,
        public string $endComp,
        public ?string $city,
        public ?string $neighborhood,
        public ?string $cnae,
        public ?string $sellerId,
        public string $layer = self::DEFAULT_LAYER,
        public array $received = [],
        public array $normalized = [],
    ) {}

    /** @param array<string,mixed> $request */
    public static function fromRequest(array $request): self
    {
        $filters = MapFilterSupport::fromRequest($request);
        $layer = trim((string) ($request['layer'] ?? $request['focus'] ?? self::DEFAULT_LAYER));
        if ($layer === '') {
            $layer = self::DEFAULT_LAYER;
        }
        $city = $filters['city'];
        if ($city === null && $layer === 'fortaleza_bairros') {
            $city = 'fortaleza';
        }

        return new self(
            dateFrom: (string) $filters['date_from'],
            dateTo: (string) $filters['date_to'],
            startComp: (string) $filters['start_comp'],
            endComp: (string) $filters['end_comp'],
            city: $city,
            neighborhood: $filters['neighborhood'],
            cnae: $filters['cnae'],
            sellerId: $filters['seller_id'],
            layer: $layer,
            received: (array) $filters['filters_received'],
            normalized: (array) $filters['filters_normalized'],
        );
    }

    /** @return array<string,mixed> */
    public function periodMeta(): array
    {
        return [
            'date_from' => $this->dateFrom,
            'date_to' => $this->dateTo,
            'start_comp' => $this->startComp,
            'end_comp' => $this->endComp,
        ];
    }

    /** @return array<string,mixed> */
    public function filtersMeta(): array
    {
        return [
            'city' => $this->city,
            'neighborhood' => $this->neighborhood,
            'cnae' => $this->cnae,
            'seller_id' => $this->sellerId,
            'layer' => $this->layer,
            'received' => $this->received,
            'normalized' => $this->normalized,
        ];
    }

    public function isMetroNeighborhoodLayer(): bool
    {
        return $this->layer === 'grande_fortaleza_bairros';
    }

    public function isMultiCityLayer(): bool
    {
        return in_array($this->layer, [
            'ceara_municipios',
            'rmf_municipios',
            'metro_sobral_municipios',
            'metro_cariri_municipios',
            'metro_todas_municipios',
            self::DEFAULT_LAYER,
        ], true);
    }
}
