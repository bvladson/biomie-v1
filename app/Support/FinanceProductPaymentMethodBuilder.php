<?php

declare(strict_types=1);

namespace BIomie\Support;

use PDO;

/**
 * Read-only view of payment-method usage across invoiced products.
 *
 * Values are based on distinct title/NF/product links. Item revenue is
 * intentionally not summed because installments can associate one NF with
 * multiple financial titles and would duplicate the fiscal value.
 */
final class FinanceProductPaymentMethodBuilder
{
    private const METHOD_LABELS = [
        'boleto' => 'Boleto',
        'pix' => 'Pix',
        'cartao_credito' => 'Cartao de credito',
        'cartao_debito' => 'Cartao de debito',
        'cartao' => 'Cartao',
        'carteira_digital' => 'Carteira digital',
        'conta_bancaria' => 'Conta bancaria',
        'dinheiro' => 'Dinheiro',
        'transferencia' => 'Transferencia',
        'cheque' => 'Cheque',
        'deposito' => 'Deposito',
        'outro' => 'Outro',
        'nao_informado' => 'Nao informado',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string,mixed> */
    public function build(string $start, string $end, int $productLimit = 20): array
    {
        $required = [
            'biomie_titulos_financeiros_canonicos',
            'biomie_bridge_nf_titulo_items',
            'biomie_notas_fiscais_canonicas',
            'biomie_notas_fiscais_itens_canonicos',
        ];
        foreach ($required as $table) {
            if (!$this->tableExists($table)) {
                return $this->emptyPayload($start, $end, 'table_missing:' . $table);
            }
        }

        $visibility = BiVisibilityScope::getCondition($this->pdo, 'biomie_notas_fiscais_canonicas', 'nf');
        $sql = "SELECT DISTINCT
                    COALESCE(NULLIF(TRIM(i.codigo_produto), ''), '__sem_sku__') AS product_code,
                    COALESCE(NULLIF(TRIM(i.descricao_produto), ''), 'Produto sem descricao') AS product_name,
                    t.titulo_key,
                    b.nota_key,
                    COALESCE(t.forma_pagamento, '') AS forma_pagamento,
                    COALESCE(t.especie_documento, '') AS especie_documento,
                    COALESCE(t.codigo_conta_corrente_omie, '') AS codigo_conta_corrente_omie
                FROM biomie_titulos_financeiros_canonicos t
                INNER JOIN biomie_bridge_nf_titulo_items b
                  ON b.company_id = t.company_id AND b.titulo_key = t.titulo_key
                INNER JOIN biomie_notas_fiscais_canonicas nf
                  ON nf.company_id = b.company_id AND nf.nota_key = b.nota_key
                INNER JOIN biomie_notas_fiscais_itens_canonicos i
                  ON i.company_id = nf.company_id AND i.nota_key = nf.nota_key
                WHERE t.data_competencia BETWEEN :start AND :end
                  AND " . TenantContext::sqlFilter('t') . "
                  AND nf.status_fiscal = 'autorizada'
                  AND nf.operation_scope = 'commercial'
                  AND {$visibility}
                ORDER BY product_name ASC, t.titulo_key ASC
                LIMIT 50000";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['start' => $start, 'end' => $end]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $classifier = new FinanceReceivableTraceBuilder($this->pdo);
        $methods = [];
        $products = [];
        $identifiedLinks = 0;

        foreach ($rows as $row) {
            $methodKey = $classifier->normalizePaymentMethod($row);
            $methodLabel = self::METHOD_LABELS[$methodKey] ?? ucfirst(str_replace('_', ' ', $methodKey));
            $productKey = (string) $row['product_code'];
            $titleKey = (string) $row['titulo_key'];
            $nfKey = (string) $row['nota_key'];

            if ($methodKey !== 'nao_informado') {
                $identifiedLinks++;
            }

            if (!isset($methods[$methodKey])) {
                $methods[$methodKey] = [
                    'method_key' => $methodKey,
                    'method_label' => $methodLabel,
                    'titles' => [],
                    'nfs' => [],
                    'products' => [],
                ];
            }
            $methods[$methodKey]['titles'][$titleKey] = true;
            $methods[$methodKey]['nfs'][$nfKey] = true;
            $methods[$methodKey]['products'][$productKey] = true;

            if (!isset($products[$productKey])) {
                $products[$productKey] = [
                    'product_code' => $productKey === '__sem_sku__' ? '' : $productKey,
                    'product_name' => (string) $row['product_name'],
                    'titles' => [],
                    'nfs' => [],
                    'methods' => [],
                ];
            }
            $products[$productKey]['titles'][$titleKey] = true;
            $products[$productKey]['nfs'][$nfKey] = true;
            $products[$productKey]['methods'][$methodKey]['titles'][$titleKey] = true;
            $products[$productKey]['methods'][$methodKey]['nfs'][$nfKey] = true;
        }

        $methodRows = array_values(array_map(static fn (array $row): array => [
            'method_key' => $row['method_key'],
            'method_label' => $row['method_label'],
            'titles_count' => count($row['titles']),
            'nfs_count' => count($row['nfs']),
            'products_count' => count($row['products']),
        ], $methods));
        usort($methodRows, static function (array $a, array $b): int {
            if ($a['method_key'] === 'nao_informado') return 1;
            if ($b['method_key'] === 'nao_informado') return -1;
            return $b['titles_count'] <=> $a['titles_count'];
        });

        $productRows = [];
        foreach ($products as $product) {
            $methodUsage = [];
            foreach ($product['methods'] as $methodKey => $method) {
                $methodUsage[] = [
                    'method_key' => $methodKey,
                    'method_label' => self::METHOD_LABELS[$methodKey] ?? ucfirst(str_replace('_', ' ', $methodKey)),
                    'titles_count' => count($method['titles']),
                    'nfs_count' => count($method['nfs']),
                ];
            }
            usort($methodUsage, static function (array $a, array $b): int {
                if ($a['method_key'] === 'nao_informado') return 1;
                if ($b['method_key'] === 'nao_informado') return -1;
                return $b['titles_count'] <=> $a['titles_count'];
            });
            $titleCount = count($product['titles']);
            $top = $methodUsage[0] ?? ['method_key' => 'nao_informado', 'method_label' => 'Nao informado', 'titles_count' => 0, 'nfs_count' => 0];
            $productRows[] = [
                'product_code' => $product['product_code'],
                'product_name' => $product['product_name'],
                'titles_count' => $titleCount,
                'nfs_count' => count($product['nfs']),
                'methods_count' => count($methodUsage),
                'top_method_key' => $top['method_key'],
                'top_method_label' => $top['method_label'],
                'top_method_titles' => $top['titles_count'],
                'top_method_share_pct' => round($top['titles_count'] / $titleCount * 100, 1),
                'method_usage' => array_slice($methodUsage, 0, 5),
            ];
        }
        usort($productRows, static fn (array $a, array $b): int => $b['titles_count'] <=> $a['titles_count']);
        $productRows = array_slice($productRows, 0, max(1, min(100, $productLimit)));

        $totalLinks = count($rows);
        return [
            'ok' => true,
            'period' => ['start' => $start, 'end' => $end, 'basis' => 'data_competencia'],
            'summary' => [
                'links_count' => $totalLinks,
                'identified_links' => $identifiedLinks,
                'coverage_pct' => $totalLinks > 0 ? round($identifiedLinks / $totalLinks * 100, 1) : 0.0,
                'methods_count' => count($methodRows),
                'products_count' => count($products),
                'top_method' => $methodRows[0] ?? null,
            ],
            'methods' => $methodRows,
            'products' => $productRows,
            'diagnostics' => [
                'source' => 'canonical_titles_bridge_nf_items',
                'measure' => 'distinct_title_nf_product_links',
                'rows_loaded' => $totalLinks,
                'truncated' => $totalLinks >= 50000,
                'generated_at' => date(DATE_ATOM),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function emptyPayload(string $start, string $end, string $reason): array
    {
        return [
            'ok' => true,
            'period' => ['start' => $start, 'end' => $end, 'basis' => 'data_competencia'],
            'summary' => ['links_count' => 0, 'identified_links' => 0, 'coverage_pct' => 0.0, 'methods_count' => 0, 'products_count' => 0, 'top_method' => null],
            'methods' => [],
            'products' => [],
            'diagnostics' => ['source' => 'unavailable', 'reason' => $reason, 'generated_at' => date(DATE_ATOM)],
        ];
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
        );
        $stmt->execute(['table' => $table]);
        return (int) $stmt->fetchColumn() > 0;
    }
}
