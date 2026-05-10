<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Gold\Builders;

use BIomie\Logger;
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
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
            );
            $stmt->execute(['table' => $table]);
            return (int) $stmt->fetchColumn() > 0;
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
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
            );
            $stmt->execute(['table' => $table, 'column' => $column]);
            return (int) $stmt->fetchColumn() > 0;
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
