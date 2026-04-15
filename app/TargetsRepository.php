<?php

declare(strict_types=1);

namespace BIomie;

use PDO;
use Throwable;

final class TargetsRepository
{
    private ?bool $tableExists = null;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Logger $logger,
        private readonly string $targetsTable = 'biomie_sales_targets_monthly',
        private readonly string $auditTable = 'biomie_sales_targets_audit',
    ) {
    }

    public function isAvailable(): bool
    {
        if ($this->tableExists !== null) {
            return $this->tableExists;
        }

        try {
            $sql = 'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name LIMIT 1';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['table_name' => $this->targetsTable]);
            $this->tableExists = (bool) $stmt->fetchColumn();
        } catch (Throwable $exception) {
            $this->tableExists = false;
            $this->logger->warning('Plano de Voo sem tabela de metas disponível.', [
                'message' => $exception->getMessage(),
                'table' => $this->targetsTable,
            ]);
        }

        return $this->tableExists;
    }

    /**
     * @param list<string> $months
     * @return array<string,array{vendor_id:string,vendor_name:string,year_month:string,target_amount:float,currency:string,updated_at:?string}>
     *
     * NOTA: `year_month` precisa de backticks porque é palavra reservada no MariaDB
     * (usada na sintaxe INTERVAL ... YEAR_MONTH).
     */
    public function getTargetsForMonths(array $months, ?string $vendorId = null): array
    {
        if (!$this->isAvailable() || $months === []) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach (array_values(array_unique($months)) as $index => $month) {
            $key = ':m' . $index;
            $placeholders[] = $key;
            $params[ltrim($key, ':')] = $month;
        }

        $sql = sprintf(
            'SELECT vendor_id, vendor_name, `year_month`, target_amount, currency, updated_at
             FROM %s
             WHERE `year_month` IN (%s)',
            $this->targetsTable,
            implode(', ', $placeholders)
        );

        if ($vendorId !== null && $vendorId !== '') {
            $sql .= ' AND vendor_id = :vendor_id';
            $params['vendor_id'] = $vendorId;
        }

        $sql .= ' ORDER BY `year_month` ASC, vendor_name ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $vendor = trim((string) ($row['vendor_id'] ?? ''));
            $month  = trim((string) ($row['year_month'] ?? ''));
            if ($vendor === '' || $month === '') {
                continue;
            }

            $rows[$vendor . '|' . $month] = [
                'vendor_id'     => $vendor,
                'vendor_name'   => (string) ($row['vendor_name'] ?? ''),
                'year_month'    => $month,
                'target_amount' => Support::money((float) ($row['target_amount'] ?? 0)),
                'currency'      => (string) ($row['currency'] ?? 'BRL'),
                'updated_at'    => isset($row['updated_at']) ? (string) $row['updated_at'] : null,
            ];
        }

        return $rows;
    }

    public function getLastUpdatedAt(): ?string
    {
        if (!$this->isAvailable()) {
            return null;
        }

        $sql   = sprintf('SELECT MAX(updated_at) FROM %s', $this->targetsTable);
        $value = $this->pdo->query($sql)->fetchColumn();

        if (!$value) {
            return null;
        }

        return date(DATE_ATOM, strtotime((string) $value));
    }

    /**
     * Insere ou atualiza uma meta mensal, gravando trilha de auditoria.
     *
     * @return array{vendor_id:string,year_month:string,target_amount:float,previous_amount:?float}
     */
    public function upsert(
        string $vendorId,
        string $vendorName,
        string $yearMonth,
        float $targetAmount,
        string $changedBy = 'interface',
        ?string $changeReason = null
    ): array {
        // Busca meta anterior para auditoria
        $stmt = $this->pdo->prepare(
            'SELECT target_amount FROM ' . $this->targetsTable .
            ' WHERE vendor_id = :vendor_id AND `year_month` = :year_month LIMIT 1'
        );
        $stmt->execute(['vendor_id' => $vendorId, 'year_month' => $yearMonth]);
        $existing     = $stmt->fetch();
        $previousAmount = $existing !== false ? (float) $existing['target_amount'] : null;

        // Upsert (chave única: vendor_id + year_month)
        $upsertSql = '
            INSERT INTO ' . $this->targetsTable . '
                (vendor_id, vendor_name, `year_month`, target_amount, source, created_by, updated_by)
            VALUES
                (:vendor_id, :vendor_name, :year_month, :target_amount, :source, :created_by, :updated_by)
            ON DUPLICATE KEY UPDATE
                vendor_name    = VALUES(vendor_name),
                target_amount  = VALUES(target_amount),
                updated_by     = VALUES(updated_by)
        ';

        $stmt = $this->pdo->prepare($upsertSql);
        $stmt->execute([
            'vendor_id'     => $vendorId,
            'vendor_name'   => $vendorName !== '' ? $vendorName : null,
            'year_month'    => $yearMonth,
            'target_amount' => Support::money($targetAmount),
            'source'        => 'manual',
            'created_by'    => $changedBy,
            'updated_by'    => $changedBy,
        ]);

        // Auditoria
        $auditSql = '
            INSERT INTO ' . $this->auditTable . '
                (vendor_id, `year_month`, old_target_amount, new_target_amount, changed_by, change_reason)
            VALUES
                (:vendor_id, :year_month, :old_target_amount, :new_target_amount, :changed_by, :change_reason)
        ';

        $stmt = $this->pdo->prepare($auditSql);
        $stmt->execute([
            'vendor_id'          => $vendorId,
            'year_month'         => $yearMonth,
            'old_target_amount'  => $previousAmount,
            'new_target_amount'  => Support::money($targetAmount),
            'changed_by'         => $changedBy,
            'change_reason'      => ($changeReason !== '' && $changeReason !== null) ? $changeReason : null,
        ]);

        // Invalida cache de disponibilidade para forçar releitura se necessário
        $this->tableExists = true;

        return [
            'vendor_id'       => $vendorId,
            'year_month'      => $yearMonth,
            'target_amount'   => Support::money($targetAmount),
            'previous_amount' => $previousAmount,
        ];
    }
}
