<?php

declare(strict_types=1);

namespace BIomie;

use BIomie\Support\TenantContext;
use PDO;
use Throwable;

final class TargetsRepository
{
    private ?bool $tableExists = null;
    private ?bool $tiersColumnExists = null;
    private ?bool $levelsTableExists = null;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Logger $logger,
        private readonly string $targetsTable = 'biomie_sales_targets_monthly',
        private readonly string $auditTable = 'biomie_sales_targets_audit',
        private readonly ?int $tenantCompanyId = null,
        private readonly ?string $tenantChannel = null,
    ) {
    }

    public function isAvailable(): bool
    {
        if ($this->tableExists !== null) {
            return $this->tableExists;
        }

        try {
            $this->tableExists = SchemaCapabilities::tableExists($this->pdo, $this->targetsTable);
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
     * @return array<string,array{vendor_id:string,vendor_name:string,year_month:string,target_amount:float,tiers:list<float>,currency:string,updated_at:?string}>
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

        $tiersCol = $this->supportsTiers() ? ', tiers_json' : '';
        $sql = sprintf(
            'SELECT vendor_id, vendor_name, `year_month`, target_amount, currency, updated_at%s
             FROM %s
              WHERE company_id = :company_id
                AND `year_month` IN (%s)',
            $tiersCol,
            $this->targetsTable,
            implode(', ', $placeholders)
        );
        $params['company_id'] = $this->companyId();

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
                'tiers'         => self::parseTiers(isset($row['tiers_json']) ? (string) $row['tiers_json'] : null),
                'currency'      => (string) ($row['currency'] ?? 'BRL'),
                'updated_at'    => isset($row['updated_at']) ? (string) $row['updated_at'] : null,
            ];
        }

        return $this->applyNormalizedLevels($rows);
    }

    public function getLastUpdatedAt(): ?string
    {
        if (!$this->isAvailable()) {
            return null;
        }

        $sql = sprintf('SELECT MAX(updated_at) FROM %s WHERE company_id = :company_id', $this->targetsTable);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['company_id' => $this->companyId()]);
        $value = $stmt->fetchColumn();

        if (!$value) {
            return null;
        }

        return date(DATE_ATOM, strtotime((string) $value));
    }

    /**
     * Returns all rows with target_amount > 0 for the given month (Phase F).
     *
     * @return list<array{vendor_id:string,vendor_name:string,year_month:string,target_amount:float,tiers:list<float>,currency:string,updated_at:?string}>
     */
    public function findActiveTargetsByMonth(string $yearMonth): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        $tiersCol = $this->supportsTiers() ? ', tiers_json' : '';
        $sql  = sprintf(
            'SELECT vendor_id, vendor_name, `year_month`, target_amount, currency, updated_at%s
             FROM %s
              WHERE company_id = :company_id
                AND `year_month` = :year_month AND target_amount > 0
             ORDER BY vendor_name ASC',
            $tiersCol,
            $this->targetsTable
        );
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['company_id' => $this->companyId(), 'year_month' => $yearMonth]);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $vendor = trim((string) ($row['vendor_id'] ?? ''));
            if ($vendor === '') {
                continue;
            }
            $rows[] = [
                'vendor_id'     => $vendor,
                'vendor_name'   => (string) ($row['vendor_name'] ?? ''),
                'year_month'    => (string) ($row['year_month'] ?? ''),
                'target_amount' => Support::money((float) ($row['target_amount'] ?? 0)),
                'tiers'         => self::parseTiers(isset($row['tiers_json']) ? (string) $row['tiers_json'] : null),
                'currency'      => (string) ($row['currency'] ?? 'BRL'),
                'updated_at'    => isset($row['updated_at']) ? (string) $row['updated_at'] : null,
            ];
        }
        return array_values($this->applyNormalizedLevels($rows));
    }

    /**
     * Returns diagnostic info about targets for a given month (Phase F).
     *
     * @return array<string,mixed>
     */
    public function listTargetDiagnostics(string $yearMonth): array
    {
        if (!$this->isAvailable()) {
            return [
                'targets_count'    => 0,
                'active_count'     => 0,
                'inactive_count'   => 0,
                'duplicate_targets'=> [],
                'invalid_targets'  => [],
                'team_total'       => 0.0,
                'last_updated_at'  => null,
                'reason_code'      => 'targets_table_not_found',
            ];
        }

        $tiersDiagnosticColumn = $this->supportsTiers() ? ', tiers_json' : '';
        $sql  = sprintf(
            'SELECT vendor_id, vendor_name, target_amount, updated_at%s
             FROM %s
              WHERE company_id = :company_id
                AND `year_month` = :year_month
             ORDER BY vendor_name ASC',
            $tiersDiagnosticColumn,
            $this->targetsTable
        );
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['company_id' => $this->companyId(), 'year_month' => $yearMonth]);
        $allRows = $stmt->fetchAll();

        $seen            = [];
        $duplicates      = [];
        $invalid         = [];
        $activeCount     = 0;
        $inactiveCount   = 0;
        $teamTotal       = 0.0;

        foreach ($allRows as $row) {
            $vid    = trim((string) ($row['vendor_id'] ?? ''));
            $amount = (float) ($row['target_amount'] ?? 0);

            if ($amount <= 0.0) {
                $invalid[] = ['vendor_id' => $vid, 'vendor_name' => $row['vendor_name'] ?? '', 'target_amount' => $amount];
                $inactiveCount++;
                continue;
            }

            if (isset($seen[$vid])) {
                $duplicates[] = ['vendor_id' => $vid, 'vendor_name' => $row['vendor_name'] ?? '', 'target_amount' => $amount];
            } else {
                $seen[$vid] = true;
            }

            $activeCount++;
            $teamTotal += $amount;
        }

        $levelConsistency = $this->levelConsistencyForMonth($yearMonth, $allRows);
        return [
            'targets_count'    => count($allRows),
            'active_count'     => $activeCount,
            'inactive_count'   => $inactiveCount,
            'duplicate_targets'=> $duplicates,
            'invalid_targets'  => $invalid,
            'team_total'       => Support::money($teamTotal),
            'last_updated_at'  => $this->getLastUpdatedAt(),
            'levels_consistent' => $levelConsistency['ok'],
            'levels_mismatches' => $levelConsistency['mismatches'],
            'reason_code'      => $activeCount === 0 ? 'no_active_targets' : null,
        ];
    }

    /**
     * Insere ou atualiza uma meta mensal, gravando trilha de auditoria.
     *
     * @param list<float> $tiers Níveis opcionais de meta ALÉM da base (Meta 2..N).
     * @return array{vendor_id:string,year_month:string,target_amount:float,previous_amount:?float,tiers:list<float>}
     */
    public function upsert(
        string $vendorId,
        string $vendorName,
        string $yearMonth,
        float $targetAmount,
        string $changedBy = 'interface',
        ?string $changeReason = null,
        array $tiers = []
    ): array {
        // Normaliza tiers: positivos, acima da meta base, crescentes, sem duplicados.
        $cleanTiers = [];
        foreach ($tiers as $tier) {
            if (is_numeric($tier) && (float) $tier > $targetAmount) {
                $cleanTiers[] = round((float) $tier, 2);
            }
        }
        sort($cleanTiers);
        $cleanTiers = array_slice(array_values(array_unique($cleanTiers)), 0, 9);
        $tiersJson  = $cleanTiers !== [] ? json_encode($cleanTiers) : null;
        $withTiers  = $this->supportsTiers();

        // Busca meta anterior para auditoria
        $stmt = $this->pdo->prepare(
            'SELECT target_amount FROM ' . $this->targetsTable .
            ' WHERE company_id = :company_id
                AND vendor_id = :vendor_id AND `year_month` = :year_month LIMIT 1'
        );
        $stmt->execute([
            'company_id' => $this->companyId(),
            'vendor_id' => $vendorId,
            'year_month' => $yearMonth,
        ]);
        $existing     = $stmt->fetch();
        $previousAmount = $existing !== false ? (float) $existing['target_amount'] : null;

        // Upsert (chave única: vendor_id + year_month)
        $tiersColInsert = $withTiers ? ', tiers_json' : '';
        $tiersValInsert = $withTiers ? ', :tiers_json' : '';
        $tiersColUpdate = $withTiers ? ', tiers_json = VALUES(tiers_json)' : '';
        $upsertSql = '
            INSERT INTO ' . $this->targetsTable . '
                (company_id, vendor_id, vendor_name, `year_month`, target_amount, source, created_by, updated_by' . $tiersColInsert . ')
            VALUES
                (:company_id, :vendor_id, :vendor_name, :year_month, :target_amount, :source, :created_by, :updated_by' . $tiersValInsert . ')
            ON DUPLICATE KEY UPDATE
                vendor_name    = VALUES(vendor_name),
                target_amount  = VALUES(target_amount),
                updated_by     = VALUES(updated_by)' . $tiersColUpdate . '
        ';

        $upsertParams = [
            'company_id'   => $this->companyId(),
            'vendor_id'     => $vendorId,
            'vendor_name'   => $vendorName !== '' ? $vendorName : null,
            'year_month'    => $yearMonth,
            'target_amount' => Support::money($targetAmount),
            'source'        => 'manual',
            'created_by'    => $changedBy,
            'updated_by'    => $changedBy,
        ];
        if ($withTiers) {
            $upsertParams['tiers_json'] = $tiersJson;
        }

        $stmt = $this->pdo->prepare($upsertSql);
        $stmt->execute($upsertParams);

        if ($this->supportsNormalizedLevels()) {
            $this->syncNormalizedLevels(
                $vendorId,
                $yearMonth,
                array_merge([$targetAmount], $cleanTiers),
                $changedBy,
                $changeReason
            );
        }

        // Auditoria
        $auditSql = '
            INSERT INTO ' . $this->auditTable . '
                (company_id, vendor_id, `year_month`, old_target_amount, new_target_amount, changed_by, change_reason)
            VALUES
                (:company_id, :vendor_id, :year_month, :old_target_amount, :new_target_amount, :changed_by, :change_reason)
        ';

        // Registra os níveis na trilha de auditoria (a tabela não tem coluna própria).
        $auditReason = ($changeReason !== '' && $changeReason !== null) ? $changeReason : null;
        if ($cleanTiers !== []) {
            $tiersNote   = 'tiers: ' . implode(', ', array_map(static fn (float $t): string => number_format($t, 2, '.', ''), $cleanTiers));
            $auditReason = $auditReason !== null ? ($auditReason . ' | ' . $tiersNote) : $tiersNote;
            $auditReason = mb_substr($auditReason, 0, 255);
        }

        $stmt = $this->pdo->prepare($auditSql);
        $stmt->execute([
            'company_id'        => $this->companyId(),
            'vendor_id'          => $vendorId,
            'year_month'         => $yearMonth,
            'old_target_amount'  => $previousAmount,
            'new_target_amount'  => Support::money($targetAmount),
            'changed_by'         => $changedBy,
            'change_reason'      => $auditReason,
        ]);

        // Invalida cache de disponibilidade para forçar releitura se necessário
        $this->tableExists = true;

        return [
            'vendor_id'       => $vendorId,
            'year_month'      => $yearMonth,
            'target_amount'   => Support::money($targetAmount),
            'previous_amount' => $previousAmount,
            'tiers'           => $cleanTiers,
        ];
    }

    /**
     * @param list<string> $months
     * @return array<string,float>
     */
    public function getCompanyTargetsForMonths(array $months): array
    {
        if ($months === []) {
            return [];
        }
        $placeholders = [];
        $params = [
            'company_id' => $this->companyId(),
            'canal' => $this->channel(),
        ];
        foreach (array_values(array_unique($months)) as $index => $month) {
            $key = 'month_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $month;
        }
        $stmt = $this->pdo->prepare(
            'SELECT competencia, meta_valor
               FROM biomie_company_targets
              WHERE company_id = :company_id
                AND canal = :canal
                AND ativo = 1
                AND competencia IN (' . implode(', ', $placeholders) . ')'
        );
        $stmt->execute($params);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(string) $row['competencia']] = (float) $row['meta_valor'];
        }
        return $result;
    }

    /**
     * A coluna tiers_json existe? (migration 20260709000000 aplicada)
     * Fallback gracioso: sem a coluna, todo o fluxo segue com meta única.
     */
    public function supportsTiers(): bool
    {
        if ($this->tiersColumnExists !== null) {
            return $this->tiersColumnExists;
        }
        try {
            $this->tiersColumnExists = $this->isAvailable()
                && SchemaCapabilities::columnExists($this->pdo, $this->targetsTable, 'tiers_json');
        } catch (Throwable) {
            $this->tiersColumnExists = false;
        }
        return $this->tiersColumnExists;
    }

    /**
     * Normaliza tiers_json (string) em lista de valores válidos, ordem crescente.
     *
     * @return list<float>
     */
    public static function parseTiers(?string $tiersJson): array
    {
        if ($tiersJson === null || trim($tiersJson) === '') {
            return [];
        }
        $decoded = json_decode($tiersJson, true);
        if (!is_array($decoded)) {
            return [];
        }
        $tiers = [];
        foreach ($decoded as $value) {
            if (is_numeric($value) && (float) $value > 0) {
                $tiers[] = round((float) $value, 2);
            }
        }
        sort($tiers);
        return array_slice(array_values(array_unique($tiers)), 0, 9); // máx. 10 metas no total
    }

    private function supportsNormalizedLevels(): bool
    {
        if ($this->levelsTableExists !== null) {
            return $this->levelsTableExists;
        }
        try {
            return $this->levelsTableExists = SchemaCapabilities::tableExists($this->pdo, 'biomie_sales_target_levels');
        } catch (Throwable) {
            return $this->levelsTableExists = false;
        }
    }

    /**
     * @param list<array<string,mixed>> $legacyRows
     * @return array{ok:bool,mismatches:list<array<string,mixed>>}
     */
    private function levelConsistencyForMonth(string $yearMonth, array $legacyRows): array
    {
        if (!$this->supportsNormalizedLevels()) return ['ok' => true, 'mismatches' => []];
        $stmt = $this->pdo->prepare(
            'SELECT vendor_id, level_number, target_amount FROM biomie_sales_target_levels
              WHERE company_id = :company_id AND `year_month` = :year_month AND active = 1
              ORDER BY vendor_id, level_number'
        );
        $stmt->execute(['company_id' => $this->companyId(), 'year_month' => $yearMonth]);
        $normalized = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $normalized[(string) $row['vendor_id']][] = Support::money((float) $row['target_amount']);
        }
        $mismatches = [];
        foreach ($legacyRows as $row) {
            $vendorId = (string) ($row['vendor_id'] ?? '');
            $expected = [Support::money((float) ($row['target_amount'] ?? 0))];
            foreach (self::parseTiers(isset($row['tiers_json']) ? (string) $row['tiers_json'] : null) as $tier) {
                $expected[] = Support::money($tier);
            }
            sort($expected, SORT_NUMERIC);
            if (($normalized[$vendorId] ?? []) !== $expected) {
                $mismatches[] = [
                    'vendor_id' => $vendorId,
                    'legacy' => $expected,
                    'normalized' => $normalized[$vendorId] ?? [],
                ];
            }
        }
        return ['ok' => $mismatches === [], 'mismatches' => $mismatches];
    }

    /**
     * A tabela normalizada é a fonte preferida; tiers_json é fallback de deploy.
     * @param array<int|string,array<string,mixed>> $rows
     * @return array<int|string,array<string,mixed>>
     */
    private function applyNormalizedLevels(array $rows): array
    {
        if ($rows === [] || !$this->supportsNormalizedLevels()) return $rows;
        $months = array_values(array_unique(array_map(
            static fn (array $row): string => (string) ($row['year_month'] ?? ''),
            array_values($rows)
        )));
        $months = array_values(array_filter($months, static fn (string $month): bool => $month !== ''));
        if ($months === []) return $rows;
        $params = ['company_id' => $this->companyId()];
        $holders = [];
        foreach ($months as $index => $month) {
            $holders[] = ':month_' . $index;
            $params['month_' . $index] = $month;
        }
        $stmt = $this->pdo->prepare(
            'SELECT vendor_id, `year_month`, level_number, target_amount
               FROM biomie_sales_target_levels
              WHERE company_id = :company_id AND active = 1
                AND `year_month` IN (' . implode(',', $holders) . ')
              ORDER BY vendor_id, `year_month`, level_number'
        );
        $stmt->execute($params);
        $levels = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $level) {
            $levels[(string) $level['vendor_id'] . '|' . (string) $level['year_month']][] = (float) $level['target_amount'];
        }
        foreach ($rows as $key => $row) {
            $pair = (string) ($row['vendor_id'] ?? '') . '|' . (string) ($row['year_month'] ?? '');
            if (!isset($levels[$pair])) continue;
            $rows[$key]['target_amount'] = Support::money($levels[$pair][0]);
            $rows[$key]['tiers'] = array_map(
                static fn (float $amount): float => Support::money($amount),
                array_slice($levels[$pair], 1)
            );
        }
        return $rows;
    }

    /** @param list<float> $levels */
    private function syncNormalizedLevels(
        string $vendorId,
        string $yearMonth,
        array $levels,
        string $changedBy,
        ?string $changeReason
    ): void {
        $companyId = $this->companyId();
        $select = $this->pdo->prepare(
            'SELECT level_number, target_amount FROM biomie_sales_target_levels
              WHERE company_id = :company_id AND vendor_id = :vendor_id AND `year_month` = :year_month'
        );
        $select->execute(['company_id' => $companyId, 'vendor_id' => $vendorId, 'year_month' => $yearMonth]);
        $before = [];
        foreach ($select->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $before[(int) $row['level_number']] = (float) $row['target_amount'];
        }

        $this->pdo->prepare(
            'DELETE FROM biomie_sales_target_levels
              WHERE company_id = :company_id AND vendor_id = :vendor_id AND `year_month` = :year_month'
        )->execute(['company_id' => $companyId, 'vendor_id' => $vendorId, 'year_month' => $yearMonth]);

        $insert = $this->pdo->prepare(
            'INSERT INTO biomie_sales_target_levels
                (company_id, vendor_id, `year_month`, level_number, level_name, target_amount, created_by, updated_by)
             VALUES (:company_id, :vendor_id, :year_month, :level_number, :level_name, :amount, :created_by, :updated_by)'
        );
        $audit = $this->pdo->prepare(
            'INSERT INTO biomie_sales_target_level_audit
                (company_id, vendor_id, `year_month`, level_number, old_amount, new_amount, changed_by, change_reason)
             VALUES (:company_id, :vendor_id, :year_month, :level_number, :old_amount, :new_amount, :changed_by, :reason)'
        );
        $max = max(count($levels), count($before));
        for ($index = 0; $index < $max; $index++) {
            $number = $index + 1;
            $newAmount = isset($levels[$index]) ? Support::money((float) $levels[$index]) : null;
            $oldAmount = $before[$number] ?? null;
            if ($newAmount !== null) {
                $insert->execute([
                    'company_id' => $companyId, 'vendor_id' => $vendorId, 'year_month' => $yearMonth,
                    'level_number' => $number, 'level_name' => self::levelName($number),
                    'amount' => $newAmount, 'created_by' => $changedBy, 'updated_by' => $changedBy,
                ]);
            }
            if ($oldAmount === $newAmount) {
                continue;
            }
            $audit->execute([
                'company_id' => $companyId, 'vendor_id' => $vendorId, 'year_month' => $yearMonth,
                'level_number' => $number, 'old_amount' => $oldAmount, 'new_amount' => $newAmount,
                'changed_by' => $changedBy, 'reason' => $changeReason,
            ]);
        }
    }

    private static function levelName(int $number): string
    {
        return match ($number) {
            1 => 'Compromisso', 2 => 'Superação', 3 => 'Excelência',
            default => 'Nível ' . $number,
        };
    }

    private function companyId(): int
    {
        return $this->tenantCompanyId ?? TenantContext::companyId();
    }

    private function channel(): string
    {
        $channel = $this->tenantChannel ?? TenantContext::canal();
        return $channel === 'licitacao' ? 'licitacao' : 'varejo';
    }
}
