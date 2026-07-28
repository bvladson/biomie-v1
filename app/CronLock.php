<?php

declare(strict_types=1);

namespace BIomie;

use PDO;

/**
 * Trava de concorrência para jobs CLI usando GET_LOCK do MariaDB.
 *
 * Uso:
 *   $lock = CronLock::acquire($pdo, 'biomie:sync_canonical', $logger);
 *   if ($lock === null) exit(0); // outro job já rodando
 *   try { ... } finally { $lock->release(); }
 */
final class CronLock
{
    private function __construct(
        private readonly PDO $pdo,
        private readonly string $name,
        private readonly ?Logger $logger = null,
    ) {
    }

    /**
     * Tenta adquirir o lock. Retorna null se outro processo já segura.
     * Timeout 0 = não-bloqueante (tenta uma vez e retorna).
     */
    public static function acquire(PDO $pdo, string $name, ?Logger $logger = null, int $timeout = 0): ?self
    {
        $stmt = $pdo->prepare('SELECT GET_LOCK(:name, :timeout) AS acquired');
        $stmt->execute(['name' => $name, 'timeout' => $timeout]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (((int) ($row['acquired'] ?? 0)) !== 1) {
            $logger?->info('Lock não adquirido, skip por execução concorrente.', ['lock' => $name]);
            fwrite(STDERR, "[SKIP] Lock '{$name}' já está em uso por outro processo.\n");
            return null;
        }

        $logger?->info('Lock adquirido.', ['lock' => $name]);
        return new self($pdo, $name, $logger);
    }

    public function release(): void
    {
        try {
            $stmt = $this->pdo->prepare('SELECT RELEASE_LOCK(:name)');
            $stmt->execute(['name' => $this->name]);
            $this->logger?->info('Lock liberado.', ['lock' => $this->name]);
        } catch (\Throwable $e) {
            $this->logger?->warning('Falha ao liberar lock.', ['lock' => $this->name, 'error' => $e->getMessage()]);
        }
    }
}
