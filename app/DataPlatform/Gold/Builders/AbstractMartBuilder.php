<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Gold\Builders;

use BIomie\Logger;
use BIomie\SchemaCapabilities;
use BIomie\Support\TenantContext;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Helpers compartilhados pelos mart builders individuais.
 */
abstract class AbstractMartBuilder
{
    public function __construct(
        protected readonly PDO $pdo,
        protected readonly Logger $logger,
    ) {
    }

    protected function tableExists(string $table): bool
    {
        try {
            return SchemaCapabilities::tableExists($this->pdo, $table);
        } catch (Throwable $e) {
            $this->logger->warning('MartBuilder: falha ao verificar tabela.', [
                'table'   => $table,
                'message' => $e->getMessage(),
            ]);
            return false;
        }
    }

    protected function columnExists(string $table, string $column): bool
    {
        try {
            return SchemaCapabilities::columnExists($this->pdo, $table, $column);
        } catch (Throwable $e) {
            $this->logger->warning('MartBuilder: falha ao verificar coluna.', [
                'table'   => $table,
                'column'  => $column,
                'message' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Fase H — retorna cláusula SQL para filtrar operation_scope = 'commercial'.
     * Retorna '' se a coluna não existir (pré-migration H3).
     *
     * @param string $table Nome da tabela a verificar (ex: 'biomie_notas_fiscais_canonicas')
     * @param string $alias Alias SQL sem ponto (ex: 'n', 'p', 'nf', ou '' para sem alias)
     */
    protected function commercialScopeFragment(string $table, string $alias): string
    {
        if ($this->columnExists($table, 'operation_scope')) {
            $prefix = $alias !== '' ? $alias . '.' : '';
            return "\n              AND {$prefix}operation_scope = 'commercial'";
        }

        return '';
    }

    /**
     * Fase L — retorna cláusula SQL para excluir unidades não-comerciais (ex: Licitação).
     * Quando is_biomie_commercial_visible existe, usa a coluna; caso contrário cai para
     * exclusão por nome (fallback pré-migration).
     *
     * @param string $alias Alias SQL sem ponto; '' para tabela sem alias.
     */
    protected function visibilityFragment(string $table, string $alias): string
    {
        $prefix = $alias !== '' ? $alias . '.' : '';
        // Os marts preservam Varejo e Licitacao na mesma materializacao. Ainda
        // assim, linhas de Varejo explicitamente invisiveis (remessa, uso
        // interno etc.) nao podem entrar nas somas. Licitacao e mantida para o
        // contexto proprio, mesmo quando a flag comercial geral e zero.
        if ($this->columnExists($table, 'business_channel')) {
            if ($this->columnExists($table, 'is_biomie_commercial_visible')) {
                return "\n              AND ({$prefix}business_channel = 'licitacao'"
                    . " OR COALESCE({$prefix}is_biomie_commercial_visible, 1) = 1)";
            }
            return '';
        }
        if ($this->columnExists($table, 'is_biomie_commercial_visible')) {
            return "\n              AND COALESCE({$prefix}is_biomie_commercial_visible, 1) = 1";
        }
        if ($this->columnExists($table, 'nome_vendedor')) {
            return "\n              AND NOT UPPER({$prefix}nome_vendedor) LIKE '%LICITAC%'";
        }
        return '';
    }

    protected function companyId(): int
    {
        return TenantContext::companyId();
    }

    /**
     * @param array<string,mixed> $params
     */
    protected function exec(string $sql, array $params, string $stage, string $table, string $label): int
    {
        preg_match_all('/:[a-zA-Z_][a-zA-Z0-9_]*/', $sql, $matches);
        $sqlKeys = array_unique(array_map(
            static fn (string $p): string => ltrim($p, ':'),
            $matches[0] ?? []
        ));

        $normalized = [];
        foreach ($params as $k => $v) {
            $normalized[ltrim((string) $k, ':')] = $v;
        }

        $missing = array_values(array_diff($sqlKeys, array_keys($normalized)));

        if ($missing !== []) {
            $this->logger->error("MartBuilder: parametros ausentes em {$stage}.", [
                'stage'   => $stage, 'table' => $table, 'label' => $label,
                'missing' => $missing,
            ]);
            throw new RuntimeException(
                "Falha em {$stage} / {$table} / {$label}: parametros ausentes: " . implode(', ', $missing)
            );
        }

        $filtered = array_intersect_key($normalized, array_flip($sqlKeys));

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($filtered);
            return (int) $stmt->rowCount();
        } catch (Throwable $e) {
            $this->logger->error("MartBuilder: falha PDO em {$stage} / {$table}.", [
                'stage'   => $stage, 'table' => $table, 'label' => $label,
                'sqlstate' => is_scalar($e->getCode()) ? (string) $e->getCode() : 'unknown',
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
