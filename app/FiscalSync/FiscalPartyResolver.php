<?php

declare(strict_types=1);

namespace BIomie\FiscalSync;

use BIomie\Support;

final class FiscalPartyResolver
{
    public function extractVendorId(array $raw): string
    {
        $vendorId = Support::getByPaths($raw, [
            'informacoes_adicionais.codVend',
            'cabecalho.codVend',
            'vendedor.codigo',
            'vendedor.codVend',
            'codVend',
            'nVendedor',
        ], null);

        if ($vendorId === null || $vendorId === '') {
            $vendorId = Support::findFirstByKeys($raw, ['codVend', 'nVendedor', 'codigo_vendedor', 'codigo_vendedor_omie'], '');
        }

        return trim((string) $vendorId);
    }

    /**
     * @param array<string,string> $vendorMap
     */
    public function resolveVendorName(string $vendorId, array $raw, array $vendorMap): string
    {
        if ($vendorId !== '' && isset($vendorMap[$vendorId])) {
            return $vendorMap[$vendorId];
        }

        $name = trim((string) Support::getByPaths($raw, [
            'informacoes_adicionais.nome_vendedor',
            'vendedor.nome',
            'nome_vendedor',
            'Vendedor',
        ], ''));

        if ($name === '') {
            $name = trim((string) Support::findFirstByKeys($raw, ['Vendedor', 'nome_vendedor'], ''));
        }

        return $name !== '' ? $name : 'Sem vendedor';
    }

    public function extractClientCode(array $raw): int
    {
        $clientCode = Support::getByPaths($raw, [
            'cabecalho.codigo_cliente',
            'cliente.codigo_cliente_omie',
            'cliente.codigo_cliente',
            'codigo_cliente',
            'codigo_cliente_omie',
        ], null);

        if ($clientCode === null || $clientCode === '') {
            $clientCode = Support::findFirstByKeys($raw, ['codigo_cliente', 'codigo_cliente_omie'], 0);
        }

        return (int) $clientCode;
    }

    /**
     * @param array<string,mixed> $clientData
     */
    public function resolveClientName(array $raw, array $clientData): string
    {
        $name = trim((string) Support::getByPaths($clientData, ['razao_social', 'nome_fantasia'], ''));
        if ($name !== '') {
            return $name;
        }

        $name = trim((string) Support::getByPaths($raw, [
            'cliente.razao_social',
            'cliente.nome_fantasia',
            'cabecalho.nome_cliente',
            'nome_fantasia',
            'razao_social',
        ], ''));

        return $name !== '' ? $name : 'Cliente nao informado';
    }

    /**
     * @param array<string,mixed> $clientData
     */
    public function resolveClientDocument(array $raw, array $clientData): string
    {
        $document = trim((string) Support::getByPaths($clientData, ['cnpj_cpf'], ''));
        if ($document !== '') {
            return $document;
        }

        return trim((string) Support::getByPaths($raw, [
            'cliente.cnpj_cpf',
            'cliente.cpf_cnpj',
            'cnpj_cpf',
        ], ''));
    }

    /**
     * @param list<array<string,mixed>> $orders
     * @return list<int>
     */
    public function collectClientCodes(array $orders): array
    {
        $codes = [];

        foreach ($orders as $order) {
            if (!is_array($order)) {
                continue;
            }

            $clientCode = $this->extractClientCode($order);
            if ($clientCode > 0) {
                $codes[] = $clientCode;
            }
        }

        return array_values(array_unique($codes));
    }
}
