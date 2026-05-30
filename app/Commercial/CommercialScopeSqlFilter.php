<?php

declare(strict_types=1);

namespace BIomie\Commercial;

use BIomie\Support\BiVisibilityScope;
use PDO;

/**
 * COM.R2.1 — Gerador de filtros SQL seguros para escopos comerciais.
 *
 * Reutiliza CommercialPipelineClassifier e BiVisibilityScope.
 * Nenhum endpoint deve reimplementar regra de escopo manualmente.
 * Read-only. Sem escrita.
 */
final class CommercialScopeSqlFilter
{
    // Alias padrão da tabela de NFs canônicas
    private const DEFAULT_NF_ALIAS = 'nf';
    private const NF_TABLE         = 'biomie_notas_fiscais_canonicas';

    private CommercialPeriodScopeContext $ctx;

    public function __construct(CommercialPeriodScopeContext $ctx)
    {
        $this->ctx = $ctx;
    }

    // ── WHERE conditions ──────────────────────────────────────────────────────

    /**
     * Returns the complete WHERE clause array for the given scope.
     * Uses COALESCE(data_competencia, data_emissao) for period filtering
     * to ensure consistency across all endpoints.
     *
     * @return array{conditions: list<string>, params: array<string,mixed>}
     */
    public function buildNfWhereConditions(string $alias = self::DEFAULT_NF_ALIAS): array
    {
        $conditions = [];
        $params     = [':start' => $this->ctx->dateStart(), ':end' => $this->ctx->dateEnd()];

        // ── Period (consistent across ALL endpoints) ──────────────────────────
        // Always use COALESCE to include NFs without data_competencia
        $conditions[] = "COALESCE({$alias}.data_competencia, {$alias}.data_emissao) BETWEEN :start AND :end";

        // ── Scope ─────────────────────────────────────────────────────────────
        $scope = $this->ctx->scope();

        match ($scope) {
            CommercialPeriodScopeContext::SCOPE_ORC_PD => (function () use (&$conditions, $alias): void {
                $conditions[] = "{$alias}.status_fiscal = 'autorizada'";
                $conditions[] = "{$alias}.operation_scope = 'commercial'";
                // Licitação excluded: BiVisibilityScope handles it
            })(),

            CommercialPeriodScopeContext::SCOPE_FATURADO => (function () use (&$conditions, $alias): void {
                $conditions[] = "{$alias}.status_fiscal = 'autorizada'";
                $conditions[] = "{$alias}.operation_scope = 'commercial'";
            })(),

            CommercialPeriodScopeContext::SCOPE_REMESSA => (function () use (&$conditions, $alias): void {
                $conditions[] = "{$alias}.status_fiscal = 'autorizada'";
                $conditions[] = "{$alias}.operation_scope = 'remittance'";
            })(),

            CommercialPeriodScopeContext::SCOPE_LICITACAO => (function () use (&$conditions, $alias): void {
                $conditions[] = "{$alias}.status_fiscal = 'autorizada'";
                // Licitação: operation_scope = 'commercial' BUT is_biomie_commercial_visible = 0
                $conditions[] = "({$alias}.operation_scope = 'commercial' AND COALESCE({$alias}.is_biomie_commercial_visible, 1) = 0)";
            })(),

            CommercialPeriodScopeContext::SCOPE_CANCELADO => (function () use (&$conditions, $alias): void {
                $conditions[] = "{$alias}.status_fiscal = 'cancelada'";
                $conditions[] = "{$alias}.operation_scope = 'commercial'";
            })(),

            CommercialPeriodScopeContext::SCOPE_TODOS => (function () use (&$conditions, $alias): void {
                // No status_fiscal filter — include all
                $conditions[] = "1=1";
            })(),

            // ORCAMENTO, CARTEIRA_ACEITA, ENTREGA_FINALIZADA, DEVOLVIDO → handled via pedidos, not NFs
            default => (function () use (&$conditions, $alias): void {
                $conditions[] = "{$alias}.status_fiscal = 'autorizada'";
                $conditions[] = "{$alias}.operation_scope = 'commercial'";
            })(),
        };

        return ['conditions' => $conditions, 'params' => $params];
    }

    /**
     * Same as buildNfWhereConditions but includes BiVisibilityScope.
     * Use this for all Orç-PD commercial endpoints.
     */
    public function buildNfWhereWithVisibility(PDO $pdo, string $alias = self::DEFAULT_NF_ALIAS): array
    {
        $result = $this->buildNfWhereConditions($alias);

        if ($this->ctx->scope() === CommercialPeriodScopeContext::SCOPE_ORC_PD
            || $this->ctx->scope() === CommercialPeriodScopeContext::SCOPE_FATURADO
        ) {
            $visCond = BiVisibilityScope::getCondition($pdo, self::NF_TABLE, $alias);
            $result['conditions'][] = $visCond;
        }

        return $result;
    }

    /**
     * Compile conditions into a WHERE clause string.
     *
     * @param list<string> $extra Additional conditions to AND-merge
     */
    public function compileWhere(array $conditions, array $extra = []): string
    {
        return implode(' AND ', array_filter(array_merge($conditions, $extra)));
    }

    /**
     * Returns SQL params for PDO binding.
     *
     * @return array<string,mixed>
     */
    public function baseParams(): array
    {
        return [':start' => $this->ctx->dateStart(), ':end' => $this->ctx->dateEnd()];
    }

    // ── Diagnóstico ───────────────────────────────────────────────────────────

    /**
     * @return array<string,mixed>
     */
    public function diagnostics(): array
    {
        return [
            'scope'      => $this->ctx->scope(),
            'channel'    => $this->ctx->channel(),
            'date_start' => $this->ctx->dateStart(),
            'date_end'   => $this->ctx->dateEnd(),
            'days'       => $this->ctx->daysPeriod(),
            'months'     => $this->ctx->monthsCovered(),
            'note'       => 'COALESCE(data_competencia, data_emissao) usado em todos os endpoints',
        ];
    }
}
