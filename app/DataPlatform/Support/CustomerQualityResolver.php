<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Support;

use BIomie\DataPlatform\Support\CnaeStatusResolver;

final class CustomerQualityResolver
{
    public const COMPLETE = 'complete';
    public const MISSING_DOCUMENT = 'missing_document';
    public const INVALID_DOCUMENT = 'invalid_document';
    public const MISSING_CITY = 'missing_city';
    public const MISSING_NEIGHBORHOOD = 'missing_neighborhood';
    public const MISSING_GEO = 'missing_geo';
    public const MISSING_CNAE_CNPJ = 'missing_cnae_cnpj';
    public const NOT_APPLICABLE_CPF = 'not_applicable_cpf';
    public const MISSING_OWNER = 'missing_owner';
    public const NO_COMMERCIAL_HISTORY = 'no_commercial_history';
    public const ORPHAN_COMMERCIAL_HISTORY = 'orphan_commercial_history';

    /**
     * @param array<string,mixed> $identity
     * @param array<string,mixed> $geo
     * @param array<string,mixed> $cnae
     * @param array<string,mixed> $commercial
     * @param array<string,mixed> $ownership
     * @return array{status:string,missing:list<string>,score:int}
     */
    public static function resolve(
        array $identity,
        array $geo,
        array $cnae,
        array $commercial,
        array $ownership,
        bool $hasDimProfile = true,
    ): array {
        $missing = [];
        $documentStatus = (string) ($identity['document_status'] ?? '');
        $documentType = (string) ($identity['document_type'] ?? 'unknown');

        if ($documentStatus === 'missing' || $documentType === 'unknown') {
            $missing[] = self::MISSING_DOCUMENT;
        } elseif ($documentStatus === 'invalid_format' || $documentType === 'invalid') {
            $missing[] = self::INVALID_DOCUMENT;
        }

        $city = trim((string) ($geo['cidade_key'] ?? ''));
        $neighborhood = trim((string) ($geo['bairro_key'] ?? ''));
        if ($city === '') {
            $missing[] = self::MISSING_CITY;
        }
        if ($neighborhood === '') {
            $missing[] = self::MISSING_NEIGHBORHOOD;
        }
        if ($city === '' && $neighborhood === '') {
            $missing[] = self::MISSING_GEO;
        }

        $cnaeStatus = (string) ($cnae['status'] ?? CnaeStatusResolver::UNKNOWN);
        if ($cnaeStatus === CnaeStatusResolver::MISSING_CNPJ) {
            $missing[] = self::MISSING_CNAE_CNPJ;
        }
        if ($cnaeStatus === CnaeStatusResolver::NOT_APPLICABLE_CPF) {
            $missing[] = self::NOT_APPLICABLE_CPF;
        }

        if (trim((string) ($ownership['seller_name'] ?? '')) === '') {
            $missing[] = self::MISSING_OWNER;
        }

        if ((float) ($commercial['revenue_period'] ?? 0) <= 0 && (int) ($commercial['invoices_period'] ?? 0) <= 0) {
            $missing[] = self::NO_COMMERCIAL_HISTORY;
        }
        if (!$hasDimProfile && ((float) ($commercial['revenue_period'] ?? 0) > 0 || (int) ($commercial['invoices_period'] ?? 0) > 0)) {
            $missing[] = self::ORPHAN_COMMERCIAL_HISTORY;
        }

        $missing = array_values(array_unique($missing));
        $blocking = array_values(array_diff($missing, [self::NOT_APPLICABLE_CPF]));
        $score = max(0, 100 - count($blocking) * 15);

        return [
            'status' => $blocking === [] ? self::COMPLETE : $blocking[0],
            'missing' => $missing,
            'score' => $score,
        ];
    }
}
