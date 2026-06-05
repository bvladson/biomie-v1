<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Support;

use BIomie\DataPlatform\Contracts\CustomerSourceContracts;
use BIomie\DataPlatform\ReadModel\CustomerReadModel;

final class CustomerLegacyAdapter
{
    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public static function enrichRows(CustomerReadModel $model, array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $profiles = $model->profilesByCustomerCodes(self::customerCodes($rows), false);
        $out = [];
        foreach ($rows as $row) {
            $out[] = self::enrichRow($row, $profiles);
        }

        return $out;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<string|int|null>
     */
    private static function customerCodes(array $rows): array
    {
        $codes = [];
        foreach ($rows as $row) {
            $code = trim((string) ($row['codigo_cliente_omie'] ?? ''));
            if ($code !== '') {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,array<string,mixed>> $profiles
     * @return array<string,mixed>
     */
    private static function enrichRow(array $row, array $profiles): array
    {
        $code = trim((string) ($row['codigo_cliente_omie'] ?? ''));
        $customer = $code !== '' ? ($profiles[$code] ?? null) : null;
        if ($customer === null) {
            return self::safeFallbackRow($row);
        }

        $document = (array) ($customer['document'] ?? []);
        $geo = (array) ($customer['geo'] ?? []);
        $cnae = (array) ($customer['cnae'] ?? []);
        $ownership = (array) ($customer['ownership'] ?? []);
        $quality = (array) ($customer['quality'] ?? []);
        $masked = (string) ($document['masked'] ?? '');
        $masked = $masked !== '' ? $masked : 'Nao informado';
        $name = (string) (($customer['nome'] ?? '') ?: ($row['customer_name'] ?? $row['cliente_nome'] ?? $row['nome_cliente'] ?? ''));

        $row['customer_name'] = $name;
        $row['cliente_nome'] = $name;
        $row['nome_cliente'] = $name;
        $row['document_raw'] = $masked;
        $row['document_masked'] = $masked;
        $row['documento_mascarado'] = $masked;
        $row['cliente_documento'] = $masked;
        $row['documento_cliente'] = $masked;
        $row['documento_norm'] = '';
        $row['document_type'] = (string) ($document['type'] ?? 'unknown');
        $row['document_label'] = (string) ($document['label'] ?? 'Documento');
        $row['buyer_kind'] = $row['document_type'] === 'cnpj' ? 'b2b' : ($row['document_type'] === 'cpf' ? 'cpf' : 'unknown');
        $row['is_b2b'] = $row['document_type'] === 'cnpj';
        $row['cidade'] = (string) (($geo['cidade_label'] ?? '') ?: ($geo['cidade_key'] ?? '') ?: ($row['cidade'] ?? ''));
        $row['cidade_norm'] = (string) (($geo['cidade_key'] ?? '') ?: ($row['cidade_norm'] ?? ''));
        $row['bairro'] = (string) (($geo['bairro_label'] ?? '') ?: ($geo['bairro_key'] ?? '') ?: ($row['bairro'] ?? ''));
        $row['bairro_norm'] = (string) (($geo['bairro_key'] ?? '') ?: ($row['bairro_norm'] ?? ''));
        $row['geo_quality'] = (string) ($geo['quality'] ?? 'geo_desconhecido');
        $row['cnae'] = $cnae['codigo'] ?? ($row['cnae'] ?? null);
        $row['cnae_codigo'] = $cnae['codigo'] ?? ($row['cnae_codigo'] ?? null);
        $row['cnae_descricao'] = $cnae['descricao'] ?? ($row['cnae_descricao'] ?? null);
        $row['cnae_status'] = (string) ($cnae['status'] ?? CnaeStatusResolver::UNKNOWN);
        $row['cnae_status_label'] = (string) ($cnae['status_label'] ?? CnaeStatusResolver::label($row['cnae_status']));
        $row['vendedor_principal'] = (string) (($row['vendedor_principal'] ?? '') ?: ($ownership['seller_name'] ?? ''));
        $row['seller_owner'] = [
            'seller_id' => (string) ($ownership['seller_id'] ?? ''),
            'seller_name' => (string) ($ownership['seller_name'] ?? ''),
            'owner_source' => (string) ($ownership['owner_source'] ?? 'missing'),
        ];
        $row['customer_quality_status'] = (string) ($quality['status'] ?? 'unknown');
        $row['customer_quality_missing'] = (array) ($quality['missing'] ?? []);
        $row['customer_source_contract'] = CustomerSourceContracts::PROFILE;
        $row['customer_profile'] = self::publicCustomerSummary($customer);

        return $row;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function safeFallbackRow(array $row): array
    {
        $documentRaw = (string) (($row['document_raw'] ?? '') ?: ($row['documento_cliente'] ?? '') ?: ($row['cliente_documento'] ?? ''));
        $document = CustomerDocumentClassifier::classify($documentRaw, false);
        $cnaeStatus = CnaeStatusResolver::resolve($document['digits'], (string) (($row['cnae'] ?? '') ?: ($row['cnae_codigo'] ?? '')));
        $masked = (string) $document['masked'];
        $masked = $masked !== '' ? $masked : 'Nao informado';

        $row['document_raw'] = $masked;
        $row['document_masked'] = $masked;
        $row['documento_mascarado'] = $masked;
        $row['cliente_documento'] = $masked;
        $row['documento_cliente'] = $masked;
        $row['documento_norm'] = '';
        $row['document_type'] = (string) $document['type'];
        $row['document_label'] = (string) $document['label'];
        $row['buyer_kind'] = $row['document_type'] === 'cnpj' ? 'b2b' : ($row['document_type'] === 'cpf' ? 'cpf' : 'unknown');
        $row['is_b2b'] = $row['document_type'] === 'cnpj';
        $row['cnae_status'] = $cnaeStatus;
        $row['cnae_status_label'] = CnaeStatusResolver::label($cnaeStatus);
        $row['customer_quality_status'] = 'pending_customer_readmodel';
        $row['customer_quality_missing'] = ['pending_customer_readmodel'];
        $row['customer_source_contract'] = CustomerSourceContracts::PROFILE;
        $row['customer_profile'] = null;

        return $row;
    }

    /**
     * @param array<string,mixed> $customer
     * @return array<string,mixed>
     */
    private static function publicCustomerSummary(array $customer): array
    {
        return [
            'customer_key' => (string) ($customer['customer_key'] ?? ''),
            'codigo_cliente_omie' => (string) ($customer['codigo_cliente_omie'] ?? ''),
            'nome' => (string) ($customer['nome'] ?? ''),
            'document' => (array) ($customer['document'] ?? []),
            'geo' => (array) ($customer['geo'] ?? []),
            'cnae' => (array) ($customer['cnae'] ?? []),
            'ownership' => (array) ($customer['ownership'] ?? []),
            'quality' => (array) ($customer['quality'] ?? []),
            'source' => (string) ($customer['source'] ?? 'unknown'),
        ];
    }
}
