<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Query;

use BIomie\DataPlatform\Support\CustomerIdentityResolver;
use BIomie\Support\MapFilterSupport;

final readonly class CustomerQuery
{
    /**
     * @param array<string,mixed> $received
     * @param array<string,mixed> $normalized
     */
    public function __construct(
        public string $dateFrom,
        public string $dateTo,
        public string $startComp,
        public string $endComp,
        public ?string $customer,
        public ?string $document,
        public ?string $q,
        public ?string $city,
        public ?string $neighborhood,
        public ?string $cnae,
        public ?string $sellerId,
        public string $documentType,
        public ?string $qualityStatus,
        public int $limit,
        public int $offset,
        public string $sort,
        public string $direction,
        public array $received = [],
        public array $normalized = [],
    ) {}

    /** @param array<string,mixed> $request */
    public static function fromRequest(array $request): self
    {
        $filters = MapFilterSupport::fromRequest($request);
        $identity = CustomerIdentityResolver::normalize(
            self::firstString($request, ['customer', 'cliente', 'cliente_id', 'codigo_cliente_omie', 'id']),
            self::firstString($request, ['document', 'documento', 'cpf_cnpj', 'cnpj', 'cpf']),
            self::firstString($request, ['q', 'search', 'nome'])
        );

        $city = $filters['city'];
        if ($city === null) {
            $cityKey = strtolower(trim((string) ($request['cidade_key'] ?? '')));
            $city = $cityKey !== '' && $cityKey !== 'sem_cidade' ? $cityKey : null;
        }

        $neighborhood = $filters['neighborhood'];
        if ($neighborhood === null) {
            $bairroKey = strtolower(trim((string) ($request['bairro_key'] ?? '')));
            $neighborhood = $bairroKey !== '' && $bairroKey !== '__sem_bairro__' ? $bairroKey : null;
        }

        $documentType = self::normalizeDocumentType((string) ($request['document_type'] ?? 'all'));
        $qualityStatus = self::normalizeQualityStatus((string) ($request['status'] ?? $request['quality_status'] ?? ''));
        $limit = max(1, min(100, (int) ($request['limit'] ?? 50)));
        $offset = max(0, (int) ($request['offset'] ?? 0));
        $sort = self::normalizeSort((string) ($request['sort'] ?? 'revenue'));
        $direction = strtolower((string) ($request['direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        return new self(
            dateFrom: (string) $filters['date_from'],
            dateTo: (string) $filters['date_to'],
            startComp: (string) $filters['start_comp'],
            endComp: (string) $filters['end_comp'],
            customer: $identity['customer'] !== '' ? $identity['customer'] : null,
            document: $identity['document'] !== '' ? $identity['document'] : null,
            q: $identity['name'] !== '' ? $identity['name'] : null,
            city: $city,
            neighborhood: $neighborhood,
            cnae: $filters['cnae'],
            sellerId: $filters['seller_id'],
            documentType: $documentType,
            qualityStatus: $qualityStatus,
            limit: $limit,
            offset: $offset,
            sort: $sort,
            direction: $direction,
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
            'customer' => $this->customer,
            'document_type' => $this->documentType,
            'q' => $this->q,
            'city' => $this->city,
            'neighborhood' => $this->neighborhood,
            'cnae' => $this->cnae,
            'seller_id' => $this->sellerId,
            'quality_status' => $this->qualityStatus,
            'limit' => $this->limit,
            'offset' => $this->offset,
            'sort' => $this->sort,
            'direction' => $this->direction,
            'received' => $this->received,
            'normalized' => $this->normalized,
        ];
    }

    /**
     * @param array<string,mixed> $request
     * @param list<string> $keys
     */
    private static function firstString(array $request, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($request[$key]) && trim((string) $request[$key]) !== '') {
                return trim((string) $request[$key]);
            }
        }

        return null;
    }

    private static function normalizeDocumentType(string $value): string
    {
        return match (strtolower(trim($value))) {
            'pj', 'cnpj', 'b2b' => 'cnpj',
            'pf', 'cpf' => 'cpf',
            'invalid', 'invalido' => 'invalid',
            'unknown', 'desconhecido' => 'unknown',
            default => 'all',
        };
    }

    private static function normalizeQualityStatus(string $value): ?string
    {
        $status = strtolower(trim($value));
        $allowed = [
            'complete',
            'missing_document',
            'invalid_document',
            'missing_city',
            'missing_neighborhood',
            'missing_geo',
            'missing_cnae_cnpj',
            'not_applicable_cpf',
            'missing_owner',
            'no_commercial_history',
            'orphan_commercial_history',
        ];

        return in_array($status, $allowed, true) ? $status : null;
    }

    private static function normalizeSort(string $value): string
    {
        return in_array($value, ['revenue', 'last_purchase', 'name', 'invoices', 'quality'], true)
            ? $value
            : 'revenue';
    }
}
