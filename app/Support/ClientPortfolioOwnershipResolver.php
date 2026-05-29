<?php

declare(strict_types=1);

namespace BIomie\Support;

/**
 * COM.R1 FASE 6 — Resolver de Dono da Carteira de Clientes.
 *
 * Determina o dono comercial de um cliente e identifica vendas fora da carteira.
 * Delega a lógica de resolução ao CustomerOwnershipResolver e expõe os campos
 * de saída definidos no spec COM.R1 FASE 6.
 *
 * Prioridade de dono:
 *   1. biomie_clientes_dim.vendedor_responsavel_nome (confidence=100)
 *   2. Primeira NF autorizada do cliente             (confidence=70)
 *   3. Primeiro pedido autorizado                    (confidence=60)
 *   4. Sem evidência                                 (confidence=0)
 *
 * Read-only. Sem acesso ao banco — trabalha com dados já carregados.
 */
final class ClientPortfolioOwnershipResolver
{
    private CustomerOwnershipResolver $inner;

    public function __construct()
    {
        $this->inner = new CustomerOwnershipResolver();
    }

    /**
     * Resolve ownership and return the spec-defined output fields.
     *
     * @param array<string,mixed> $input Expected keys:
     *   cliente_id_omie         int|string
     *   documento_cliente       string
     *   nome_cliente            string
     *   dim_vendor_name         string   — biomie_clientes_dim.vendedor_responsavel_nome
     *   dim_vendor_confidence   int      — 0..100
     *   dim_vendor_source       string
     *   sale_vendor_name        string   — vendedor da NF/pedido atual
     *   sale_vendor_code        string   — código Omie do vendedor da venda (opcional)
     *   first_nf_vendor_name    string   — vendedor da primeira NF (opcional)
     *   first_order_vendor_name string   — vendedor do primeiro pedido (opcional)
     *
     * @return array{
     *   owner_vendor_code:      string,
     *   owner_vendor_name:      string,
     *   owner_source:           string,
     *   owner_confidence:       int,
     *   sale_vendor_code:       string,
     *   sale_vendor_name:       string,
     *   is_out_of_portfolio:    bool,
     *   reason:                 string,
     *   warnings:               list<string>,
     *   display_badge:          string,
     *   conflict_type:          string,
     *   severity:               string
     * }
     */
    public function resolve(array $input): array
    {
        $inner = $this->inner->resolve($input);

        // Map CustomerOwnershipResolver output → spec fields
        return [
            'owner_vendor_code'   => '',   // not stored in dim currently; reserved for future
            'owner_vendor_name'   => $inner['owner_vendor_name'],
            'owner_source'        => $inner['owner_source'],
            'owner_confidence'    => $inner['owner_confidence'],
            'sale_vendor_code'    => trim((string) ($input['sale_vendor_code'] ?? '')),
            'sale_vendor_name'    => $inner['sale_vendor_name'],
            'is_out_of_portfolio' => $inner['is_out_of_portfolio'],
            'reason'              => $inner['reason'],
            'warnings'            => $this->buildWarnings($inner),
            'display_badge'       => $inner['display_badge'],
            'conflict_type'       => $inner['conflict_type'],
            'severity'            => $inner['severity'],
        ];
    }

    /**
     * Resolve a batch of rows.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public function resolveBatch(array $rows): array
    {
        return array_map(fn(array $row): array => array_merge($row, $this->resolve($row)), $rows);
    }

    // ── Public helpers (delegates) ────────────────────────────────────────────

    public function isEmptyVendor(string $name): bool
    {
        return $this->inner->isEmptyVendor($name);
    }

    public function sameVendor(string $a, string $b): bool
    {
        return $this->inner->sameVendor($a, $b);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function buildWarnings(array $inner): array
    {
        $warnings = [];
        if ($inner['owner_confidence'] === 0) {
            $warnings[] = 'Cliente sem dono definido — sem dados históricos suficientes.';
        } elseif ($inner['owner_confidence'] < 70) {
            $warnings[] = 'Dono derivado de histórico; pode ser impreciso.';
        }
        if ($inner['is_out_of_portfolio']) {
            $warnings[] = 'Venda fora da carteira: ' . $inner['conflict_label'];
        }
        return $warnings;
    }
}
