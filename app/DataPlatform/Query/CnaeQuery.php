<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Query;

use BIomie\DataPlatform\Support\CnaeStatusResolver;
use BIomie\Support\MapFilterSupport;

final readonly class CnaeQuery
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
        public ?string $city,
        public ?string $neighborhood,
        public ?string $cnae,
        public ?string $sellerId,
        public string $documentType,
        public ?string $status,
        public int $limit,
        public string $sort,
        public string $direction,
        public string $mode,
        public array $received = [],
        public array $normalized = [],
    ) {}

    /** @param array<string,mixed> $request */
    public static function fromRequest(array $request): self
    {
        $filters = MapFilterSupport::fromRequest($request);
        $documentType = self::normalizeDocumentType((string) ($request['document_type'] ?? 'all'));
        $status = self::normalizeStatus((string) ($request['status'] ?? ''));
        $limit = max(1, min(100, (int) ($request['limit'] ?? 50)));
        $sort = self::normalizeSort((string) ($request['sort'] ?? 'revenue'));
        $direction = strtolower((string) ($request['direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $mode = in_array((string) ($request['mode'] ?? ''), ['by_cnae', 'by_company', 'top_cnaes'], true)
            ? (string) $request['mode']
            : 'by_cnae';

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

        return new self(
            dateFrom: (string) $filters['date_from'],
            dateTo: (string) $filters['date_to'],
            startComp: (string) $filters['start_comp'],
            endComp: (string) $filters['end_comp'],
            city: $city,
            neighborhood: $neighborhood,
            cnae: $filters['cnae'],
            sellerId: $filters['seller_id'],
            documentType: $documentType,
            status: $status,
            limit: $limit,
            sort: $sort,
            direction: $direction,
            mode: $mode,
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
            'document_type' => $this->documentType,
            'status' => $this->status,
            'limit' => $this->limit,
            'sort' => $this->sort,
            'direction' => $this->direction,
            'mode' => $this->mode,
            'received' => $this->received,
            'normalized' => $this->normalized,
        ];
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

    private static function normalizeStatus(string $value): ?string
    {
        $status = strtolower(trim($value));
        return in_array($status, CnaeStatusResolver::statuses(), true) ? $status : null;
    }

    private static function normalizeSort(string $value): string
    {
        return in_array($value, ['revenue', 'customers', 'invoices', 'last_purchase'], true)
            ? $value
            : 'revenue';
    }
}
