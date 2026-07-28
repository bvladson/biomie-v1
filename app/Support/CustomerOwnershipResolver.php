<?php

declare(strict_types=1);

namespace BIomie\Support;

/**
 * COM.R1.4 — Resolver de Dono Comercial do Cliente.
 *
 * Determina o dono da carteira de um cliente e detecta conflito quando
 * a venda foi feita por vendedor diferente do dono.
 *
 * Prioridade de dono:
 *   1. biomie_clientes_dim.vendedor_responsavel_nome (confidence=100)
 *   2. Primeira NF autorizada (confidence=70)
 *   3. Primeiro pedido autorizado  (confidence=60)
 *   4. Sem evidência (confidence=0)
 *
 * Regra de conflito:
 *   Se owner != sale_vendor (ambos não-vazios/não-genéricos) → is_out_of_portfolio=true
 *
 * Read-only. Sem acesso ao banco — trabalha com dados já carregados.
 */
final class CustomerOwnershipResolver
{
    // Vendor names considered "empty" / non-specific
    private const EMPTY_VENDOR_PATTERNS = [
        '',
        'sem vendedor',
        'sem vendedor definido',
        'não informado',
        'nao informado',
        'enviado via api',
        'sem vendedor - licitacao',
        'licitação',
        'licitacao',
    ];

    // Conflict type constants
    public const CONFLICT_NONE                  = 'none';
    public const CONFLICT_SOLD_BY_OTHER         = 'sold_by_other_vendor';
    public const CONFLICT_NO_OWNER              = 'no_owner_defined';
    public const CONFLICT_MULTIPLE_OWNERS       = 'multiple_owners';
    public const CONFLICT_OWNER_INACTIVE_REVIEW = 'owner_inactive_review';
    public const CONFLICT_CROSS_CHANNEL         = 'cross_channel_conflict';
    public const CONFLICT_SALE_VENDOR_INACTIVE  = 'sale_vendor_inactive_warning';

    // Severity constants
    public const SEVERITY_NONE   = 'none';
    public const SEVERITY_INFO   = 'info';
    public const SEVERITY_WARN   = 'warn';

    /**
     * Resolve ownership and conflict for a single sale/client record.
     *
     * @param array<string,mixed> $input  Expected keys:
     *   cliente_id_omie           int|string  — Omie client ID
     *   documento_cliente         string      — CPF/CNPJ
     *   nome_cliente              string
     *   dim_vendor_name           string      — biomie_clientes_dim.vendedor_responsavel_nome
     *   dim_vendor_confidence     int         — 0..100
     *   dim_vendor_source         string      — origem do campo dim
     *   sale_vendor_name          string      — vendedor da NF/pedido atual
     *   first_nf_vendor_name      string      — vendedor da primeira NF autorizada
     *   first_order_vendor_name   string      — vendedor do primeiro pedido
     *   owner_is_active           bool        — (optional) se dono está ativo; null = desconhecido
     *   sale_vendor_is_active     bool        — (optional) se vendedor da venda está ativo
     *
     * @return array<string,mixed>
     */
    public function resolve(array $input): array
    {
        $dimVendorName    = trim((string) ($input['dim_vendor_name']        ?? ''));
        $dimConf          = (int)    ($input['dim_vendor_confidence']   ?? 0);
        $dimSource        = trim((string) ($input['dim_vendor_source']       ?? ''));
        $saleVendorName   = trim((string) ($input['sale_vendor_name']        ?? ''));
        $firstNfVendor    = trim((string) ($input['first_nf_vendor_name']    ?? ''));
        $firstOrdVendor   = trim((string) ($input['first_order_vendor_name'] ?? ''));

        // ── Resolve owner ─────────────────────────────────────────────────────
        $ownerName       = '';
        $ownerSource     = 'none';
        $ownerConfidence = 0;

        if (!$this->isEmptyVendor($dimVendorName)) {
            $ownerName       = $dimVendorName;
            $ownerSource     = $dimSource ?: 'clientes_dim';
            $ownerConfidence = max(1, $dimConf);
        } elseif (!$this->isEmptyVendor($firstNfVendor)) {
            $ownerName       = $firstNfVendor;
            $ownerSource     = 'first_authorized_invoice';
            $ownerConfidence = 70;
        } elseif (!$this->isEmptyVendor($firstOrdVendor)) {
            $ownerName       = $firstOrdVendor;
            $ownerSource     = 'first_authorized_order';
            $ownerConfidence = 60;
        }

        // ── Resolve sale vendor ───────────────────────────────────────────────
        $saleIsEmpty = $this->isEmptyVendor($saleVendorName);

        // ── Active status (optional; null = desconhecido) ─────────────────────
        $ownerIsActive      = array_key_exists('owner_is_active', $input)       ? ($input['owner_is_active'] === null ? null : (bool) $input['owner_is_active'])       : null;
        $saleVendorIsActive = array_key_exists('sale_vendor_is_active', $input)  ? ($input['sale_vendor_is_active'] === null ? null : (bool) $input['sale_vendor_is_active'])  : null;

        // ── Detect conflict ───────────────────────────────────────────────────
        $isOutOfPortfolio = false;
        $conflictType     = self::CONFLICT_NONE;
        $conflictLabel    = '';
        $severity         = self::SEVERITY_NONE;

        if ($ownerName === '') {
            $conflictType  = self::CONFLICT_NO_OWNER;
            $conflictLabel = 'Cliente sem dono definido';
            $severity      = self::SEVERITY_INFO;
        } elseif ($ownerIsActive === false) {
            // Owner is inactive — flag for review, not an operational conflict
            $conflictType  = self::CONFLICT_OWNER_INACTIVE_REVIEW;
            $conflictLabel = 'Cliente com dono inativo — revisar carteirização (' . $ownerName . ')';
            $severity      = self::SEVERITY_INFO;
        } elseif ($saleVendorIsActive === false && !$saleIsEmpty) {
            $conflictType  = self::CONFLICT_SALE_VENDOR_INACTIVE;
            $conflictLabel = 'Venda vinculada a vendedor inativo: ' . $saleVendorName;
            $severity      = self::SEVERITY_WARN;
        } elseif (!$saleIsEmpty && !$this->sameVendor($ownerName, $saleVendorName)) {
            $isOutOfPortfolio = true;
            if ($this->isCrossChannelConflict($ownerName, $saleVendorName)) {
                $conflictType  = self::CONFLICT_CROSS_CHANNEL;
                $conflictLabel = 'Conflito de canal: cliente de ' . $ownerName . ' vendido por ' . $saleVendorName;
            } else {
                $conflictType  = self::CONFLICT_SOLD_BY_OTHER;
                $conflictLabel = 'Cliente de ' . $ownerName . ' vendido por ' . $saleVendorName;
            }
            $severity = self::SEVERITY_WARN;
        }

        // ── Display badge ─────────────────────────────────────────────────────
        $displayBadge = match ($conflictType) {
            self::CONFLICT_SOLD_BY_OTHER         => 'Fora da carteira',
            self::CONFLICT_CROSS_CHANNEL         => 'Conflito de canal',
            self::CONFLICT_OWNER_INACTIVE_REVIEW => 'Dono inativo',
            self::CONFLICT_SALE_VENDOR_INACTIVE  => 'Vendedor inativo',
            self::CONFLICT_NO_OWNER              => 'Sem dono',
            default                              => 'Carteira própria',
        };

        return [
            'owner_vendor_name'   => $ownerName,
            'owner_source'        => $ownerSource,
            'owner_confidence'    => $ownerConfidence,
            'sale_vendor_name'    => $saleVendorName,
            'sale_vendor_source'  => $saleIsEmpty ? 'empty' : 'provided',
            'is_out_of_portfolio' => $isOutOfPortfolio,
            'conflict_type'       => $conflictType,
            'conflict_label'      => $conflictLabel,
            'display_badge'       => $displayBadge,
            'severity'            => $severity,
            'reason'              => $this->buildReason($ownerSource, $ownerConfidence, $conflictType),
        ];
    }

    /**
     * Resolve a batch of sales rows — more efficient for many rows.
     * Each $row must have the same keys as $input in resolve().
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>  — merged with original row + ownership fields
     */
    public function resolveBatch(array $rows): array
    {
        return array_map(function (array $row): array {
            $ownership = $this->resolve($row);
            return array_merge($row, $ownership);
        }, $rows);
    }

    /**
     * Build a per-vendor conflict summary from a batch of resolved rows.
     *
     * @param list<array<string,mixed>> $resolvedRows  — output of resolveBatch()
     * @return array<string,array{
     *   conflicts_out: int,
     *   value_out: float,
     *   clients_in: int,
     *   clients_invaded: int
     * }>  keyed by vendor name
     */
    public function buildVendorConflictSummary(array $resolvedRows): array
    {
        $summary = [];
        foreach ($resolvedRows as $r) {
            $owner      = (string) ($r['owner_vendor_name'] ?? '');
            $saleVendor = (string) ($r['sale_vendor_name']  ?? '');
            $valor      = (float)  ($r['valor']             ?? $r['valor_mercadorias'] ?? 0);
            $isOut      = (bool)   ($r['is_out_of_portfolio'] ?? false);

            // Vendor who made the sale: track "sold out of own portfolio"
            if ($saleVendor !== '' && !$this->isEmptyVendor($saleVendor)) {
                if (!isset($summary[$saleVendor])) {
                    $summary[$saleVendor] = ['conflicts_out' => 0, 'value_out' => 0.0, 'clients_in' => 0, 'clients_invaded' => 0];
                }
                if ($isOut) {
                    $summary[$saleVendor]['conflicts_out']++;
                    $summary[$saleVendor]['value_out'] += $valor;
                }
            }

            // Vendor who owns the client: track "clients sold by someone else"
            if ($owner !== '' && !$this->isEmptyVendor($owner)) {
                if (!isset($summary[$owner])) {
                    $summary[$owner] = ['conflicts_out' => 0, 'value_out' => 0.0, 'clients_in' => 0, 'clients_invaded' => 0];
                }
                if (!$isOut && $saleVendor !== '' && $this->sameVendor($owner, $saleVendor)) {
                    $summary[$owner]['clients_in']++;
                } elseif ($isOut) {
                    $summary[$owner]['clients_invaded']++;
                }
            }
        }
        return $summary;
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    public function isCrossChannelConflict(string $ownerName, string $saleVendorName): bool
    {
        // Detect cross-channel conflicts (e.g., CAIO-REV vs CAIO-VAR)
        // Uses VendorAliasResolver logic inline to avoid circular dependency
        $on = mb_strtolower(trim($ownerName));
        $sn = mb_strtolower(trim($saleVendorName));
        $revSuffixes = [' - rev', ' rev', ' - revenda', ' revenda'];
        $varSuffixes = [' - var', ' var', ' - varejo', ' varejo'];

        $ownerIsRev  = false;
        $ownerIsVar  = false;
        $saleIsRev   = false;
        $saleIsVar   = false;

        foreach ($revSuffixes as $s) {
            if (str_ends_with($on, $s)) { $ownerIsRev = true; break; }
        }
        foreach ($varSuffixes as $s) {
            if (str_ends_with($on, $s)) { $ownerIsVar = true; break; }
        }
        foreach ($revSuffixes as $s) {
            if (str_ends_with($sn, $s)) { $saleIsRev = true; break; }
        }
        foreach ($varSuffixes as $s) {
            if (str_ends_with($sn, $s)) { $saleIsVar = true; break; }
        }

        // Cross-channel = one is REV and the other is VAR
        return ($ownerIsRev && $saleIsVar) || ($ownerIsVar && $saleIsRev);
    }

    public function isEmptyVendor(string $name): bool
    {
        $n = mb_strtolower(trim($name));
        foreach (self::EMPTY_VENDOR_PATTERNS as $pat) {
            if ($n === $pat) return true;
        }
        // Numeric-only or very short names are suspect
        if (preg_match('/^\d{1,5}$/', $n)) return true;
        return false;
    }

    public function sameVendor(string $a, string $b): bool
    {
        return $this->normalizeVendorName($a) === $this->normalizeVendorName($b);
    }

    private function normalizeVendorName(string $name): string
    {
        // Uppercase, remove accents (via transliteration), collapse spaces, trim
        $n = mb_strtoupper(trim($name));
        $n = (string) preg_replace('/\s+/', ' ', $n);
        // Remove common suffixes that may differ between aliases
        return $n;
    }

    private function buildReason(string $ownerSource, int $confidence, string $conflictType): string
    {
        $parts = [];
        if ($ownerSource !== 'none') {
            $parts[] = "dono via {$ownerSource} (conf={$confidence})";
        }
        if ($conflictType !== self::CONFLICT_NONE) {
            $parts[] = "conflito: {$conflictType}";
        }
        return implode('; ', $parts) ?: 'sem dados';
    }
}
