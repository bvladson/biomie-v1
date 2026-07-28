<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Silver\Readers;

use BIomie\SchemaCapabilities;
use Closure;
use PDO;
use Throwable;

final class CanonicalDimensionEnricher
{
    public function __construct(
        private readonly Closure $pdoResolver,
    ) {
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public function enrichCustomerSellerIdentityRows(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        return $this->enrichSellerDimensionRows($this->enrichClientDimensionRows($rows));
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function enrichClientDimensionRows(array $rows): array
    {
        if (!$this->tableExists('biomie_clientes_dim')) {
            return $rows;
        }

        $codes = [];
        $documents = [];
        foreach ($rows as $row) {
            $code = trim((string) ($row['codigo_cliente_omie'] ?? $row['customer_code'] ?? ''));
            if ($code !== '' && $code !== '0') {
                $codes[$code] = $code;
            }

            $document = self::normalizeDocument((string) ($row['documento_cliente'] ?? $row['cliente_documento'] ?? $row['customer_document'] ?? ''));
            if ($document !== '') {
                $documents[$document] = $document;
            }
        }

        if ($codes === [] && $documents === []) {
            return $rows;
        }

        $dimensions = $this->fetchClientDimensions(array_values($codes), array_values($documents));
        if ($dimensions === []) {
            return $rows;
        }

        $byCode = [];
        $byDocument = [];
        foreach ($dimensions as $dimension) {
            $companyId = (int) ($dimension['company_id'] ?? \BIomie\Support\TenantContext::companyId());
            $code = trim((string) ($dimension['codigo_cliente_omie'] ?? ''));
            if ($code !== '' && $code !== '0') {
                $byCode[$companyId . '|' . $code] = $dimension;
            }

            $document = self::normalizeDocument((string) ($dimension['documento_normalizado'] ?? $dimension['cpf_cnpj'] ?? ''));
            if ($document !== '') {
                $byDocument[$companyId . '|' . $document] = $dimension;
            }
        }

        foreach ($rows as $index => $row) {
            $companyId = (int) ($row['company_id'] ?? \BIomie\Support\TenantContext::companyId());
            $code = trim((string) ($row['codigo_cliente_omie'] ?? $row['customer_code'] ?? ''));
            $document = self::normalizeDocument((string) ($row['documento_cliente'] ?? $row['cliente_documento'] ?? $row['customer_document'] ?? ''));
            $dimension = ($code !== '' && isset($byCode[$companyId . '|' . $code])) ? $byCode[$companyId . '|' . $code] : null;
            if ($dimension === null && $document !== '' && isset($byDocument[$companyId . '|' . $document])) {
                $dimension = $byDocument[$companyId . '|' . $document];
            }

            if ($dimension === null) {
                continue;
            }

            $rows[$index]['cliente_dim_razao_social'] = $dimension['nome_cliente'] ?? null;
            $rows[$index]['cliente_dim_nome_cliente'] = $dimension['nome_cliente'] ?? null;
            $rows[$index]['cliente_dim_nome_fantasia'] = $dimension['nome_fantasia'] ?? null;
            $rows[$index]['cliente_dim_cnpj_cpf'] = $dimension['cpf_cnpj'] ?? null;
            $rows[$index]['cliente_dim_sincronizado_em'] = $dimension['sincronizado_em'] ?? null;
        }

        return $rows;
    }

    /**
     * @param list<string> $codes
     * @param list<string> $documents
     * @return list<array<string,mixed>>
     */
    private function fetchClientDimensions(array $codes, array $documents): array
    {
        $hasDocumentNormalized = $this->columnExists('biomie_clientes_dim', 'documento_normalizado');
        $documentSelect = $hasDocumentNormalized ? 'documento_normalizado' : 'NULL AS documento_normalizado';
        $conditions = [];
        $params = [];

        if ($codes !== []) {
            $placeholders = [];
            foreach (array_values(array_unique($codes)) as $index => $code) {
                $key = 'code_' . $index;
                $placeholders[] = ':' . $key;
                $params[$key] = $code;
            }
            $conditions[] = 'codigo_cliente_omie IN (' . implode(', ', $placeholders) . ')';
        }

        if ($documents !== []) {
            $column = $hasDocumentNormalized ? 'documento_normalizado' : 'cpf_cnpj';
            $placeholders = [];
            foreach (array_values(array_unique($documents)) as $index => $document) {
                $key = 'doc_' . $index;
                $placeholders[] = ':' . $key;
                $params[$key] = $document;
            }
            $conditions[] = $column . ' IN (' . implode(', ', $placeholders) . ')';
        }

        if ($conditions === []) {
            return [];
        }

        try {
            $stmt = $this->pdo()->prepare(sprintf(
                'SELECT company_id, codigo_cliente_omie, nome_cliente, nome_fantasia, cpf_cnpj, %s, sincronizado_em
                 FROM biomie_clientes_dim
                 WHERE %s
                   AND (%s)',
                $documentSelect,
                \BIomie\Support\TenantContext::sqlFilter(),
                implode(' OR ', $conditions)
            ));
            $stmt->execute($params);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function enrichSellerDimensionRows(array $rows): array
    {
        $vendors = $this->getVendorIdentityMap();
        if ($vendors === []) {
            return $rows;
        }

        foreach ($rows as $index => $row) {
            $companyId = (int) ($row['company_id'] ?? \BIomie\Support\TenantContext::companyId());
            $code = trim((string) ($row['codigo_vendedor_omie'] ?? $row['vendor_id'] ?? $row['pedido_codVend'] ?? ''));
            $vendorKey = $companyId . '|' . $code;
            if ($code === '' || !isset($vendors[$vendorKey])) {
                continue;
            }

            $rows[$index]['vendedor_dim_nome'] = $vendors[$vendorKey]['nome'] ?? null;
            $rows[$index]['vendedor_dim_email'] = $vendors[$vendorKey]['email'] ?? null;
        }

        return $rows;
    }

    /**
     * @return array<string, array<string,mixed>>
     */
    private function getVendorIdentityMap(): array
    {
        if (!$this->tableExists('biomie_vendedores_omie')) {
            return [];
        }

        try {
            $tenantFilter = \BIomie\Support\TenantContext::sqlFilter();
            $rows = $this->pdo()
                ->query("SELECT company_id, codigo_omie, nome, email, ativo
                         FROM biomie_vendedores_omie
                         WHERE {$tenantFilter}")
                ->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }

        $map = [];
        foreach ($rows as $row) {
            $code = trim((string) ($row['codigo_omie'] ?? ''));
            if ($code === '') {
                continue;
            }
            $map[(int) ($row['company_id'] ?? 0) . '|' . $code] = $row;
        }

        return $map;
    }

    private function tableExists(string $table): bool
    {
        return SchemaCapabilities::tableExists($this->pdo(), $table);
    }

    private function columnExists(string $table, string $column): bool
    {
        return SchemaCapabilities::columnExists($this->pdo(), $table, $column);
    }

    private function pdo(): PDO
    {
        $pdo = ($this->pdoResolver)();
        if (!$pdo instanceof PDO) {
            throw new \RuntimeException('PDO resolver returned an invalid connection.');
        }

        return $pdo;
    }

    private static function normalizeDocument(string $document): string
    {
        return preg_replace('/\D+/', '', $document) ?? '';
    }
}
