<?php

declare(strict_types=1);

namespace BIomie\Commercial;

use PDO;

/**
 * COM.R2.1 — Resolvedor de carteira de clientes.
 *
 * Determina o vendedor dono da carteira de um cliente usando a cadeia de prioridade:
 *   1. biomie_clientes_dim.vendedor_responsavel_nome (confidence 100) — se existir e confiável
 *   2. Vendedor da primeira NF autorizada do cliente (confidence 70)
 *   3. Sem dono (confidence 0)
 *
 * Read-only. Sem escrita. Sem override manual nesta fase (nenhuma migration criada).
 */
final class ClientCommercialOwnershipResolver
{
    private const CONFIDENCE_DIM        = 100;
    private const CONFIDENCE_FIRST_NF   = 70;
    private const CONFIDENCE_NO_OWNER   = 0;

    private const SOURCE_DIM_RESPONSAVEL = 'dim_responsavel';
    private const SOURCE_PRIMEIRA_NF     = 'primeira_nf';
    private const SOURCE_SEM_DONO        = 'sem_dono';

    private PDO  $pdo;
    private bool $dimTableAvailable;

    // Cache por codigo_cliente_omie
    /** @var array<int|string, array<string,mixed>> */
    private array $cache = [];

    public function __construct(PDO $pdo)
    {
        $this->pdo              = $pdo;
        $this->dimTableAvailable = $this->checkDimTable();
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Resolve ownership for a single client.
     *
     * @param int|null    $codigoClienteOmie
     * @param string|null $documentoCliente   CNPJ/CPF fallback
     * @param string|null $clienteNome        Name fallback for display
     * @param string      $saleVendorName     Vendor who made the sale
     * @param string      $saleDate           YYYY-MM-DD of the sale
     *
     * @return array{
     *   cliente_key: string,
     *   codigo_cliente_omie: int|null,
     *   documento_cliente: string,
     *   cliente_nome: string,
     *   owner_vendor_name: string,
     *   owner_vendor_channel: string,
     *   ownership_source: string,
     *   ownership_confidence: int,
     *   first_purchase_date: string,
     *   first_purchase_vendor: string,
     *   last_purchase_date: string,
     *   last_purchase_vendor: string,
     *   is_new_customer: bool,
     *   is_recurring_customer: bool,
     *   is_recovered_customer: bool,
     *   is_out_of_portfolio: bool,
     *   sale_vendor_name: string,
     *   reason: string,
     *   warnings: list<string>,
     * }
     */
    public function resolve(
        ?int    $codigoClienteOmie,
        ?string $documentoCliente,
        ?string $clienteNome,
        string  $saleVendorName,
        string  $saleDate,
        string  $periodStart,
        string  $periodEnd
    ): array {
        $cacheKey = (string) ($codigoClienteOmie ?? $documentoCliente ?? $clienteNome ?? '');
        if (isset($this->cache[$cacheKey])) {
            $base = $this->cache[$cacheKey];
            return $this->enrichWithSale($base, $saleVendorName, $saleDate, $periodStart);
        }

        $warnings = [];

        // ── 1. Try dim table ───────────────────────────────────────────────────
        $owner  = '';
        $source = self::SOURCE_SEM_DONO;
        $conf   = self::CONFIDENCE_NO_OWNER;

        if ($this->dimTableAvailable && $codigoClienteOmie !== null && $codigoClienteOmie > 0) {
            $row = $this->queryDim($codigoClienteOmie);
            if ($row !== null && (string) ($row['vendedor_responsavel_nome'] ?? '') !== '') {
                $owner  = (string) $row['vendedor_responsavel_nome'];
                $source = self::SOURCE_DIM_RESPONSAVEL;
                $conf   = self::CONFIDENCE_DIM;
            }
        }

        // ── 2. First NF vendor ────────────────────────────────────────────────
        $firstNf = $this->queryFirstNf($codigoClienteOmie, $documentoCliente);
        $firstPurchaseDate   = (string) ($firstNf['data'] ?? '');
        $firstPurchaseVendor = (string) ($firstNf['vendor'] ?? '');

        if ($owner === '' && $firstPurchaseVendor !== '') {
            $owner  = $firstPurchaseVendor;
            $source = self::SOURCE_PRIMEIRA_NF;
            $conf   = self::CONFIDENCE_FIRST_NF;
        }

        if ($owner === '') {
            $warnings[] = 'Nenhum dono detectado para este cliente';
        }

        // ── 3. Last purchase ──────────────────────────────────────────────────
        $lastNf = $this->queryLastNf($codigoClienteOmie, $documentoCliente, $periodEnd);
        $lastPurchaseDate   = (string) ($lastNf['data']   ?? '');
        $lastPurchaseVendor = (string) ($lastNf['vendor'] ?? '');

        $base = [
            'cliente_key'          => $cacheKey,
            'codigo_cliente_omie'  => $codigoClienteOmie,
            'documento_cliente'    => (string) ($documentoCliente ?? ''),
            'cliente_nome'         => (string) ($clienteNome ?? ''),
            'owner_vendor_name'    => $owner,
            'owner_vendor_channel' => $this->detectChannel($owner),
            'ownership_source'     => $source,
            'ownership_confidence' => $conf,
            'first_purchase_date'  => $firstPurchaseDate,
            'first_purchase_vendor' => $firstPurchaseVendor,
            'last_purchase_date'   => $lastPurchaseDate,
            'last_purchase_vendor' => $lastPurchaseVendor,
            'warnings'             => $warnings,
        ];

        $this->cache[$cacheKey] = $base;
        return $this->enrichWithSale($base, $saleVendorName, $saleDate, $periodStart);
    }

    /**
     * Resolve ownership for a list of clients from a period query.
     * More efficient: fetches first-NF data in bulk.
     *
     * @param list<array{codigo_cliente_omie:int|null,documento_cliente:string|null,nome_cliente:string|null,nome_vendedor:string,data:string}> $rows
     * @param string $periodStart
     * @param string $periodEnd
     * @return list<array<string,mixed>>
     */
    public function resolveBatch(array $rows, string $periodStart, string $periodEnd): array
    {
        return array_map(function (array $row) use ($periodStart, $periodEnd): array {
            return $this->resolve(
                isset($row['codigo_cliente_omie']) ? (int) $row['codigo_cliente_omie'] : null,
                $row['documento_cliente'] ?? null,
                $row['nome_cliente'] ?? null,
                (string) ($row['nome_vendedor'] ?? ''),
                (string) ($row['data'] ?? $periodEnd),
                $periodStart,
                $periodEnd
            );
        }, $rows);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function checkDimTable(): bool
    {
        try {
            $this->pdo->query("SELECT 1 FROM biomie_clientes_dim LIMIT 1");
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string,mixed>|null */
    private function queryDim(int $codigoClienteOmie): ?array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT vendedor_responsavel_nome, codigo_vendedor_responsavel
                 FROM biomie_clientes_dim
                 WHERE codigo_cliente_omie = :codigo
                 LIMIT 1'
            );
            $stmt->execute([':codigo' => $codigoClienteOmie]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{data:string,vendor:string}|null */
    private function queryFirstNf(?int $codigoClienteOmie, ?string $documentoCliente): ?array
    {
        try {
            $where = "status_fiscal = 'autorizada' AND operation_scope = 'commercial'";
            $params = [];

            if ($codigoClienteOmie !== null && $codigoClienteOmie > 0) {
                $where   .= ' AND codigo_cliente_omie = :codigo';
                $params[':codigo'] = $codigoClienteOmie;
            } elseif ($documentoCliente !== null && $documentoCliente !== '') {
                $where   .= ' AND documento_cliente = :doc';
                $params[':doc'] = $documentoCliente;
            } else {
                return null;
            }

            $stmt = $this->pdo->prepare(
                "SELECT COALESCE(data_competencia, data_emissao) AS data, nome_vendedor AS vendor
                 FROM biomie_notas_fiscais_canonicas
                 WHERE {$where}
                 ORDER BY COALESCE(data_competencia, data_emissao) ASC
                 LIMIT 1"
            );
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{data:string,vendor:string}|null */
    private function queryLastNf(?int $codigoClienteOmie, ?string $documentoCliente, string $upToDate): ?array
    {
        try {
            $where = "status_fiscal = 'autorizada' AND operation_scope = 'commercial'
                      AND COALESCE(data_competencia, data_emissao) <= :upto";
            $params = [':upto' => $upToDate];

            if ($codigoClienteOmie !== null && $codigoClienteOmie > 0) {
                $where   .= ' AND codigo_cliente_omie = :codigo';
                $params[':codigo'] = $codigoClienteOmie;
            } elseif ($documentoCliente !== null && $documentoCliente !== '') {
                $where   .= ' AND documento_cliente = :doc';
                $params[':doc'] = $documentoCliente;
            } else {
                return null;
            }

            $stmt = $this->pdo->prepare(
                "SELECT COALESCE(data_competencia, data_emissao) AS data, nome_vendedor AS vendor
                 FROM biomie_notas_fiscais_canonicas
                 WHERE {$where}
                 ORDER BY COALESCE(data_competencia, data_emissao) DESC
                 LIMIT 1"
            );
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string,mixed> $base */
    private function enrichWithSale(array $base, string $saleVendorName, string $saleDate, string $periodStart): array
    {
        $owner       = $base['owner_vendor_name'];
        $firstDate   = $base['first_purchase_date'];
        $isNew       = $firstDate === '' || $firstDate >= $periodStart;
        $isRecurring = !$isNew;
        $isRecovered = false; // TODO: requires gap logic (out-of-activity period)

        // Cross-channel is informational only (CAIO-REV vs CAIO-VAR)
        $isCrossChannel = $this->isCrossChannel($owner, $saleVendorName);
        $isOutOfPortfolio = $owner !== '' && $saleVendorName !== '' && $owner !== $saleVendorName;

        $reason = match (true) {
            $owner === ''           => 'Sem dono cadastrado',
            $isOutOfPortfolio && $isCrossChannel => 'Venda cross-channel (canal diferente do dono)',
            $isOutOfPortfolio       => 'Venda fora da carteira',
            default                 => 'Venda dentro da carteira',
        };

        return array_merge($base, [
            'sale_vendor_name'    => $saleVendorName,
            'sale_date'           => $saleDate,
            'is_new_customer'     => $isNew,
            'is_recurring_customer' => $isRecurring,
            'is_recovered_customer' => $isRecovered,
            'is_out_of_portfolio' => $isOutOfPortfolio,
            'is_cross_channel'    => $isCrossChannel,
            'reason'              => $reason,
        ]);
    }

    private function detectChannel(string $vendorName): string
    {
        $upper = strtoupper(trim($vendorName));
        if (str_ends_with($upper, '- VAR') || str_ends_with($upper, '- VAREJO')) return 'varejo';
        if (str_ends_with($upper, '- REV') || str_ends_with($upper, '- REVENDA')) return 'revenda';
        if (str_ends_with($upper, '- REP') || str_ends_with($upper, '- REPRESENTANTE')) return 'representante';
        if (str_contains($upper, 'LICIT')) return 'licitacao';
        return 'outro';
    }

    private function isCrossChannel(string $owner, string $saleVendor): bool
    {
        $ownerCh = $this->detectChannel($owner);
        $saleCh  = $this->detectChannel($saleVendor);
        if ($ownerCh === 'outro' || $saleCh === 'outro') return false;
        return $ownerCh !== $saleCh;
    }
}
