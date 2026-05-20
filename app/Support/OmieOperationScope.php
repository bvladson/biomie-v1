<?php

declare(strict_types=1);

namespace BIomie\Support;

use PDO;
use Throwable;

/**
 * Fase H — Contrato de escopo de operação Omie.
 *
 * Classifica operações Omie em três escopos:
 *   'commercial'  → Orçamento - PD     (entra no faturamento comercial)
 *   'remittance'  → Remessa de Produto (somente informativo)
 *   'unknown'     → não classificado    (excluído do comercial por padrão)
 *
 * Fontes de classificação (por prioridade):
 *   1. Regras configuráveis em biomie_operation_scope_rules (se tabela existir)
 *   2. Constantes PHP em COMMERCIAL_NAMES / REMITTANCE_NAMES
 *
 * REGRA ABSOLUTA: Nunca quebrar sync por falha na tabela de regras.
 * REGRA ABSOLUTA: Unknown não entra na base comercial.
 */
final class OmieOperationScope
{
    public const COMMERCIAL  = 'commercial';
    public const REMITTANCE  = 'remittance';
    public const UNKNOWN     = 'unknown';

    private const COMMERCIAL_NAMES = [
        'orçamento - pd',
        'orcamento - pd',
        'orcamento pd',
        'orçamento pd',
        'orcamento-pd',
        'orçamento-pd',
        'venda de mercadorias',
        'venda de produto',
        'venda produto',
        'pedido de venda',
        'venda',
    ];

    private const REMITTANCE_NAMES = [
        'remessa de produto',
        'remessa de produtos',
        'remessa produto',
        'simples remessa',
        'remessa',
        'transferência',
        'transferencia',
        'devolução',
        'devolucao',
        'bonificação',
        'bonificacao',
        'demonstração',
        'demonstracao',
    ];

    // ── DB rules cache ─────────────────────────────────────────────────────────

    /** @var array<string,string>|null map of normalized_pattern => scope */
    private static ?array $dbRules = null;
    private static string $dbRulesSource = 'php_constants';

    /**
     * Carrega regras da tabela biomie_operation_scope_rules.
     * Silencioso em caso de falha — cai para constantes PHP.
     */
    public static function loadRulesFromDb(PDO $pdo): void
    {
        try {
            $exists = (int) $pdo->query(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biomie_operation_scope_rules'"
            )->fetchColumn();

            if (!$exists) {
                return;
            }

            $rows = $pdo->query(
                "SELECT normalized_pattern, scope FROM biomie_operation_scope_rules
                 WHERE is_active = 1 ORDER BY priority ASC, id ASC"
            )->fetchAll(PDO::FETCH_ASSOC);

            $map = [];
            foreach ($rows as $row) {
                $pattern = mb_strtolower(trim((string) $row['normalized_pattern']), 'UTF-8');
                $scope   = (string) $row['scope'];
                if ($pattern !== '' && in_array($scope, [self::COMMERCIAL, self::REMITTANCE], true)) {
                    $map[$pattern] = $scope;
                }
            }

            if ($map !== []) {
                self::$dbRules       = $map;
                self::$dbRulesSource = 'database';
            }
        } catch (Throwable) {
            // Never interrupt sync on DB rules failure.
        }
    }

    public static function rulesSource(): string
    {
        return self::$dbRulesSource;
    }

    // ── Classification ─────────────────────────────────────────────────────────

    /**
     * Classifica a partir do nome da operação (natOp ou nome_tipo_atividade).
     * Retorna apenas o escopo string.
     */
    public static function scopeForName(string $name): string
    {
        return self::classifyName($name, '')->scope;
    }

    /**
     * Classifica e retorna lineage completo.
     *
     * @return array{scope:string,source:string,confidence:string,rule:string,raw_value:string}
     */
    public static function classifyName(string $name, string $source): array
    {
        $raw        = $name;
        $normalized = mb_strtolower(trim($name), 'UTF-8');

        if ($normalized === '') {
            return self::lineage(self::UNKNOWN, $source, 'unknown', 'unknown_empty_name', $raw);
        }

        // 1. DB rules (exact match on normalized_pattern)
        if (self::$dbRules !== null) {
            if (isset(self::$dbRules[$normalized])) {
                return self::lineage(
                    self::$dbRules[$normalized],
                    $source,
                    'high',
                    'db_exact_' . preg_replace('/[^a-z0-9]/', '_', $normalized),
                    $raw
                );
            }
            // DB substring match
            foreach (self::$dbRules as $pattern => $scope) {
                if (str_contains($normalized, $pattern)) {
                    return self::lineage($scope, $source, 'medium', 'db_contains_' . preg_replace('/[^a-z0-9]/', '_', $pattern), $raw);
                }
            }
        }

        // 2. PHP constants — exact match
        foreach (self::COMMERCIAL_NAMES as $pattern) {
            if ($normalized === $pattern) {
                return self::lineage(self::COMMERCIAL, $source, 'high', 'exact_' . preg_replace('/[^a-z0-9]/', '_', $pattern), $raw);
            }
        }
        foreach (self::REMITTANCE_NAMES as $pattern) {
            if ($normalized === $pattern) {
                return self::lineage(self::REMITTANCE, $source, 'high', 'exact_' . preg_replace('/[^a-z0-9]/', '_', $pattern), $raw);
            }
        }

        // 3. PHP constants — substring match
        foreach (self::COMMERCIAL_NAMES as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return self::lineage(self::COMMERCIAL, $source, 'medium', 'contains_' . preg_replace('/[^a-z0-9]/', '_', $pattern), $raw);
            }
        }
        foreach (self::REMITTANCE_NAMES as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return self::lineage(self::REMITTANCE, $source, 'medium', 'contains_' . preg_replace('/[^a-z0-9]/', '_', $pattern), $raw);
            }
        }

        return self::lineage(self::UNKNOWN, $source, 'unknown', 'unknown_no_match', $raw);
    }

    /**
     * Classifica NF-e. Retorna escopo string (compatibilidade).
     *
     * @param array<string,mixed> $rawInvoice
     */
    public static function scopeForInvoice(array $rawInvoice): string
    {
        return self::classifyInvoice($rawInvoice)['scope'];
    }

    /**
     * Classifica NF-e com lineage completo.
     *
     * @param array<string,mixed> $rawInvoice
     * @return array{scope:string,source:string,confidence:string,rule:string,raw_value:string}
     */
    public static function classifyInvoice(array $rawInvoice): array
    {
        $ide = $rawInvoice['ide'] ?? [];

        // Fonte primária: ide.natOp
        $natOp = trim((string) ($ide['natOp'] ?? ''));
        if ($natOp !== '') {
            return self::classifyName($natOp, 'ide.natOp');
        }

        // Fallback via finNFe (SEFAZ code — priority 2 when natOp absent)
        $finNFe = (string) ($ide['finNFe'] ?? '');
        if ($finNFe === '4') {
            return self::lineage(self::REMITTANCE, 'ide.finNFe', 'medium', 'finnfe_devolucao', $finNFe);
        }
        // finNFe=1 (NF-e Normal) = standard commercial sale; safe fallback when natOp absent.
        // Accounts with remittance will have natOp populated → takes priority above.
        if ($finNFe === '1') {
            return self::lineage(self::COMMERCIAL, 'ide.finNFe', 'low', 'finnfe_normal', $finNFe);
        }

        return self::lineage(self::UNKNOWN, 'ide.natOp', 'unknown', 'unknown_no_natop', '');
    }

    /**
     * Classifica pedido. Retorna escopo string (compatibilidade).
     *
     * @param array<string,mixed> $rawOrder
     */
    public static function scopeForOrder(array $rawOrder): string
    {
        return self::classifyOrder($rawOrder)['scope'];
    }

    /**
     * Classifica pedido com lineage completo.
     *
     * @param array<string,mixed> $rawOrder
     * @return array{scope:string,source:string,confidence:string,rule:string,raw_value:string}
     */
    public static function classifyOrder(array $rawOrder): array
    {
        $cab = $rawOrder['cabecalho'] ?? [];
        if (!is_array($cab)) {
            return self::lineage(self::UNKNOWN, 'cabecalho', 'unknown', 'unknown_no_cabecalho', '');
        }

        $name = trim((string) ($cab['nome_tipo_atividade'] ?? ''));
        if ($name !== '') {
            return self::classifyName($name, 'cabecalho.nome_tipo_atividade');
        }

        // Fallback: codigo_tipo_atividade
        $code = trim((string) ($cab['codigo_tipo_atividade'] ?? ''));
        if ($code !== '') {
            $mapped = self::scopeForActivityCode($code);
            if ($mapped !== self::UNKNOWN) {
                return self::lineage($mapped, 'cabecalho.codigo_tipo_atividade', 'low', 'activity_code_' . $code, $code);
            }
        }

        return self::lineage(self::UNKNOWN, 'cabecalho.nome_tipo_atividade', 'unknown', 'unknown_no_tipo_atividade', '');
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    public static function isCommercial(string $scope): bool  { return $scope === self::COMMERCIAL; }
    public static function isRemittance(string $scope): bool  { return $scope === self::REMITTANCE; }
    public static function isUnknown(string $scope): bool     { return $scope === self::UNKNOWN; }

    public static function label(string $scope): string
    {
        return match ($scope) {
            self::COMMERCIAL => 'Orçamento - PD',
            self::REMITTANCE => 'Remessa de Produto',
            default          => 'Operação desconhecida',
        };
    }

    /** @return list<string> */
    public static function validScopes(): array
    {
        return [self::COMMERCIAL, self::REMITTANCE, self::UNKNOWN];
    }

    // ── Private ────────────────────────────────────────────────────────────────

    /**
     * @return array{scope:string,source:string,confidence:string,rule:string,raw_value:string}
     */
    private static function lineage(string $scope, string $source, string $confidence, string $rule, string $rawValue): array
    {
        return [
            'scope'      => $scope,
            'source'     => $source,
            'confidence' => $confidence,
            'rule'       => $rule,
            'raw_value'  => $rawValue,
        ];
    }

    private static function scopeForActivityCode(string $code): string
    {
        // Populated after running operation_scope_debug.php and identifying codes for this Omie account.
        $map = [];
        return $map[$code] ?? self::UNKNOWN;
    }
}
