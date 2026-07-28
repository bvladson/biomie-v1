<?php

declare(strict_types=1);

namespace BIomie;

use Closure;
use PDO;

/**
 * Mantem uma unica conexao atual para processos longos.
 */
final class SharedDatabaseConnection
{
    private PDO $pdo;
    private int $lastProbeAt;
    /** @var null|Closure(PDO):void */
    private ?Closure $afterReconnect = null;

    public function __construct(
        private readonly Config $config,
        private readonly string $connection,
        ?PDO $pdo = null,
    ) {
        $this->pdo = $pdo ?? Database::connect($config, $connection);
        $this->lastProbeAt = time();
    }

    public function current(): PDO
    {
        return time() - $this->lastProbeAt >= 15 ? $this->recover() : $this->pdo;
    }

    /**
     * Registra uma acao executada somente quando uma nova sessao PDO foi
     * criada. Processos longos usam este gancho para renovar locks de sessao
     * (GET_LOCK), que o MySQL libera automaticamente quando a conexao cai.
     *
     * @param null|callable(PDO):void $callback
     */
    public function afterReconnect(?callable $callback): void
    {
        $this->afterReconnect = $callback === null
            ? null
            : Closure::fromCallable($callback);
    }

    public function recover(): PDO
    {
        try {
            @$this->pdo->query('SELECT 1');
            $this->lastProbeAt = time();
            return $this->pdo;
        } catch (\Throwable $exception) {
            if (!$this->isConnectionLoss($exception)) {
                throw $exception;
            }
        }

        $this->pdo = Database::reconnect($this->config, $this->connection);
        $this->lastProbeAt = time();
        if ($this->afterReconnect !== null) {
            ($this->afterReconnect)($this->pdo);
        }

        return $this->pdo;
    }

    private function isConnectionLoss(\Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'server has gone away')
            || str_contains($message, 'lost connection')
            || str_contains($message, 'error while sending query')
            || str_contains($message, 'ssl operation failed')
            || str_contains($message, 'cancelamento de uma conexao')
            || str_contains($message, 'sqlstate[hy000]')
            || str_contains($message, 'general error: 2006')
            || str_contains($message, 'general error: 2013');
    }
}
