<?php

declare(strict_types=1);

namespace BIomie\Support;

use BIomie\CronLock;
use BIomie\Logger;
use PDO;

/**
 * Serializa escritores Canonical/fiscais e materializadores Gold da empresa.
 *
 * A ordem e sempre fiscal -> Gold. Locks ja pertencentes a mesma conexao sao
 * herdados, sem uma aquisicao recursiva que exigiria outro RELEASE_LOCK.
 */
final class CommercialDataLockSet
{
    /** @param list<CronLock> $acquired */
    private function __construct(private array $acquired)
    {
    }

    public static function acquire(PDO $pdo, int $companyId, Logger $logger): ?self
    {
        $names = [
            'biomie:fiscal_write:company:' . $companyId,
            'biomie:materialize_marts:company:' . $companyId,
        ];
        $acquired = [];

        try {
            foreach ($names as $name) {
                if (self::isOwnedByCurrentConnection($pdo, $name)) {
                    continue;
                }

                $lock = CronLock::acquire($pdo, $name, $logger);
                if ($lock === null) {
                    self::releaseAll($acquired);
                    return null;
                }
                $acquired[] = $lock;
            }
        } catch (\Throwable $exception) {
            self::releaseAll($acquired);
            throw $exception;
        }

        return new self($acquired);
    }

    public function release(): void
    {
        self::releaseAll($this->acquired);
        $this->acquired = [];
    }

    private static function isOwnedByCurrentConnection(PDO $pdo, string $name): bool
    {
        $stmt = $pdo->prepare(
            'SELECT IS_USED_LOCK(:name) AS owner_id, CONNECTION_ID() AS connection_id'
        );
        $stmt->execute(['name' => $name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || $row['owner_id'] === null) {
            return false;
        }

        return (string) $row['owner_id'] === (string) ($row['connection_id'] ?? '');
    }

    /** @param list<CronLock> $locks */
    private static function releaseAll(array $locks): void
    {
        foreach (array_reverse($locks) as $lock) {
            $lock->release();
        }
    }
}
