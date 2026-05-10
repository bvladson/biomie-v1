<?php

declare(strict_types=1);

namespace BIomie;

use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Rastreia execuções dos jobs de materialização da camada gold.
 *
 * Cada job registra: nome, período, status, contadores de linhas e tempo.
 * O status pode ser: running → completed | failed | partial.
 *
 * Status de pipeline derivados por consulta:
 *   pronto     — último job completed com < 25 h de age
 *   atrasado   — último job completed com >= 25 h de age (período ativo)
 *   pendente   — nenhum job registrado para o período
 *   incompleto — último job falhou ou ficou em partial
 */
final class PipelineStatusRepository
{
    private const TABLE = 'biomie_pipeline_jobs';

    public function __construct(
        private readonly PDO $pdo,
        private readonly Logger $logger,
    ) {
    }

    // ── Escrita ──────────────────────────────────────────────────────────────

    /**
     * Abre um job em execução e retorna seu ID.
     */
    public function startJob(string $jobName, string $start, string $end): int
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO ' . self::TABLE . '
                    (job_name, period_start, period_end, status, started_at)
                 VALUES (:name, :start, :end, \'running\', NOW())'
            );
            $stmt->execute(['name' => $jobName, 'start' => $start, 'end' => $end]);
            return (int) $this->pdo->lastInsertId();
        } catch (Throwable $e) {
            $this->logger->warning('PipelineStatusRepository::startJob falhou.', ['message' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * Marca o job como concluído com os contadores de linhas.
     *
     * @param array<string,int> $counts  ex.: ['bridge'=>12,'vendedor_dia'=>45,...]
     */
    public function completeJob(int $id, array $counts, float $durationSeconds): void
    {
        if ($id === 0) {
            return;
        }

        $total = array_sum($counts);

        try {
            $stmt = $this->pdo->prepare(
                'UPDATE ' . self::TABLE . '
                 SET status          = \'completed\',
                     rows_processed  = :total,
                     counts_json     = :json,
                     completed_at    = NOW(),
                     duration_ms     = :ms
                 WHERE id = :id'
            );
            $stmt->execute([
                'id'    => $id,
                'total' => $total,
                'json'  => json_encode($counts, JSON_UNESCAPED_UNICODE),
                'ms'    => (int) round($durationSeconds * 1000),
            ]);
        } catch (Throwable $e) {
            $this->logger->warning('PipelineStatusRepository::completeJob falhou.', ['message' => $e->getMessage()]);
        }
    }

    /**
     * Marca o job como falho, registrando a mensagem de erro.
     */
    public function failJob(int $id, string $error): void
    {
        if ($id === 0) {
            return;
        }

        try {
            $stmt = $this->pdo->prepare(
                'UPDATE ' . self::TABLE . '
                 SET status        = \'failed\',
                     error_message = :err,
                     completed_at  = NOW()
                 WHERE id = :id'
            );
            $stmt->execute(['id' => $id, 'err' => mb_substr($error, 0, 2000)]);
        } catch (Throwable $e) {
            $this->logger->warning('PipelineStatusRepository::failJob falhou.', ['message' => $e->getMessage()]);
        }
    }

    // ── Leitura ──────────────────────────────────────────────────────────────

    /**
     * Retorna o registro mais recente do job para o período (ou null).
     *
     * @return array<string,mixed>|null
     */
    public function latestJob(string $jobName, string $start, string $end): ?array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM ' . self::TABLE . '
                 WHERE job_name = :name AND period_start = :start AND period_end = :end
                 ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute(['name' => $jobName, 'start' => $start, 'end' => $end]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row !== false ? $row : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Status derivado do pipeline para um período:
     * pronto | atrasado | pendente | incompleto
     *
     * "Atrasado" só se aplica a períodos ativos (mês corrente ou anterior recente).
     */
    public function pipelineStatus(string $jobName, string $start, string $end): string
    {
        $row = $this->latestJob($jobName, $start, $end);

        if ($row === null) {
            return 'pendente';
        }

        if (in_array($row['status'], ['failed', 'partial'], true)) {
            return 'incompleto';
        }

        if ($row['status'] === 'running') {
            return 'rodando';
        }

        // completed: verifica age
        $completedAt = $row['completed_at'] ?? null;
        if ($completedAt !== null) {
            $age = time() - strtotime((string) $completedAt);
            return $age < 90_000 ? 'pronto' : 'atrasado'; // 25 h
        }

        return 'pronto';
    }

    /**
     * Retorna um array com o status de todos os jobs conhecidos para o período,
     * indexado por job_name. Útil para o bloco de observabilidade do dashboard.
     *
     * @return array<string,array{status:string,completed_at:string|null,duration_ms:int|null,rows_processed:int|null}>
     */
    public function allJobsStatus(string $start, string $end): array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT j.*
                 FROM ' . self::TABLE . ' j
                 INNER JOIN (
                     SELECT job_name, MAX(id) AS max_id
                     FROM ' . self::TABLE . '
                     WHERE period_start = :start AND period_end = :end
                     GROUP BY job_name
                 ) latest ON latest.max_id = j.id'
            );
            $stmt->execute(['start' => $start, 'end' => $end]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $result = [];
            foreach ($rows as $row) {
                $name = (string) ($row['job_name'] ?? '');
                $completedAt = $row['completed_at'] ?? null;
                $age = $completedAt !== null ? time() - strtotime((string) $completedAt) : null;

                $derivedStatus = match ((string) ($row['status'] ?? '')) {
                    'completed' => ($age !== null && $age < 90_000) ? 'pronto' : 'atrasado',
                    'running'   => 'rodando',
                    default     => 'incompleto',
                };

                $result[$name] = [
                    'status'        => $derivedStatus,
                    'raw_status'    => (string) ($row['status'] ?? ''),
                    'completed_at'  => $completedAt,
                    'duration_ms'   => isset($row['duration_ms']) ? (int) $row['duration_ms'] : null,
                    'rows_processed' => isset($row['rows_processed']) ? (int) $row['rows_processed'] : null,
                    'error_message' => $row['error_message'] ?? null,
                ];
            }

            return $result;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Retorna timestamp da última materialização bem-sucedida para o job,
     * em qualquer período. Usado para mostrar "atualizado em" no dashboard.
     */
    public function lastSuccessfulRun(string $jobName): ?string
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT completed_at FROM ' . self::TABLE . '
                 WHERE job_name = :name AND status = \'completed\'
                 ORDER BY completed_at DESC LIMIT 1'
            );
            $stmt->execute(['name' => $jobName]);
            $val = $stmt->fetchColumn();
            return $val !== false ? (string) $val : null;
        } catch (Throwable) {
            return null;
        }
    }
}
