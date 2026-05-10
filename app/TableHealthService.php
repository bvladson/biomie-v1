<?php

declare(strict_types=1);

namespace BIomie;

use PDO;
use Throwable;

/**
 * Verifica saúde de tabelas SQL sem travar a página.
 *
 * Usa information_schema para existência e colunas,
 * COUNT seguro para contagem e heurística para última atualização.
 */
final class TableHealthService
{
    /** @var array<string,bool> */
    private array $existsCache = [];

    public function __construct(
        private readonly PDO $pdo,
        private readonly ?Logger $logger = null,
    ) {}

    public function tableExists(string $table): bool
    {
        if (isset($this->existsCache[$table])) {
            return $this->existsCache[$table];
        }

        try {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
            );
            $stmt->execute(['table' => $table]);
            $exists = (int) $stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            $this->logger?->warning('TableHealthService: falha ao verificar tabela.', [
                'table' => $table,
                'message' => $e->getMessage(),
            ]);
            $exists = false;
        }

        $this->existsCache[$table] = $exists;
        return $exists;
    }

    /**
     * Contagem segura: usa TABLE_ROWS da information_schema para tabelas grandes,
     * COUNT(*) para tabelas pequenas ou quando precisão é necessária.
     */
    public function safeCount(string $table, bool $exact = false): ?int
    {
        if (!$this->tableExists($table)) {
            return null;
        }

        try {
            if (!$exact) {
                // Estimativa rápida via information_schema
                $stmt = $this->pdo->prepare(
                    'SELECT TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
                );
                $stmt->execute(['table' => $table]);
                $estimate = $stmt->fetchColumn();
                if ($estimate !== false && (int) $estimate > 50000) {
                    return (int) $estimate;
                }
            }

            // COUNT exato para tabelas pequenas
            $result = $this->pdo->query(sprintf('SELECT COUNT(*) FROM `%s`', $table));
            return $result !== false ? (int) $result->fetchColumn() : null;
        } catch (Throwable $e) {
            $this->logger?->warning('TableHealthService: falha ao contar registros.', [
                'table' => $table,
                'message' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Tenta encontrar a última atualização usando colunas de data candidatas.
     */
    public function lastUpdatedAt(string $table, array $columnCandidates = []): ?string
    {
        if (!$this->tableExists($table)) {
            return null;
        }

        $defaults = [
            'updated_at', 'refreshed_at', 'sincronizado_em',
            'created_at', 'fetched_at', 'ingested_at', 'synced_at',
            'last_materialized_at', 'completed_at', 'data_emissao',
        ];
        $candidates = array_merge($columnCandidates, $defaults);

        try {
            // Descobre colunas reais
            $stmt = $this->pdo->prepare(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
            );
            $stmt->execute(['table' => $table]);
            $columns = array_flip(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []));

            foreach ($candidates as $col) {
                if (!isset($columns[$col])) {
                    continue;
                }

                $result = $this->pdo->query(sprintf('SELECT MAX(`%s`) FROM `%s`', $col, $table));
                $value = $result !== false ? $result->fetchColumn() : false;
                if ($value !== false && $value !== null && (string) $value !== '') {
                    return (string) $value;
                }
            }

            return null;
        } catch (Throwable $e) {
            $this->logger?->warning('TableHealthService: falha ao obter lastUpdatedAt.', [
                'table' => $table,
                'message' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Retorna status completo de uma tabela.
     *
     * @return array{name:string,exists:bool,rows:?int,last_updated:?string,status:string,message:string}
     */
    public function tableStatus(string $table, string $label = ''): array
    {
        $exists = $this->tableExists($table);
        if (!$exists) {
            return [
                'name' => $table,
                'label' => $label ?: $table,
                'exists' => false,
                'rows' => null,
                'last_updated' => null,
                'status' => 'danger',
                'message' => 'Tabela ausente.',
            ];
        }

        $rows = $this->safeCount($table);
        $lastUpdated = $this->lastUpdatedAt($table);

        if ($rows === 0 || $rows === null) {
            return [
                'name' => $table,
                'label' => $label ?: $table,
                'exists' => true,
                'rows' => $rows,
                'last_updated' => $lastUpdated,
                'status' => 'warning',
                'message' => 'Tabela existe, mas esta vazia.',
            ];
        }

        return [
            'name' => $table,
            'label' => $label ?: $table,
            'exists' => true,
            'rows' => $rows,
            'last_updated' => $lastUpdated,
            'status' => 'ok',
            'message' => 'Disponivel.',
        ];
    }
}
