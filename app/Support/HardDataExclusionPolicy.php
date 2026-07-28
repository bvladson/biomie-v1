<?php

declare(strict_types=1);

namespace BIomie\Support;

use PDO;

final class HardDataExclusionPolicy
{
    private const TABLE = 'biomie_data_exclusion_rules';

    /** @var array<string,array<string,true>|null> */
    private static array $rulesByConnection = [];

    /**
     * @param array<string,scalar|null> $identifiers
     */
    public static function matches(PDO $pdo, array $identifiers, ?int $companyId = null): bool
    {
        $companyId ??= TenantContext::companyId();
        $rules = self::rules($pdo, $companyId);
        if ($rules === []) {
            return false;
        }

        foreach ($identifiers as $type => $value) {
            $normalized = trim((string) ($value ?? ''));
            if ($normalized !== '' && isset($rules[$type . ':' . $normalized])) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string,true> */
    private static function rules(PDO $pdo, int $companyId): array
    {
        $cacheKey = spl_object_id($pdo) . ':' . $companyId;
        if (array_key_exists($cacheKey, self::$rulesByConnection)) {
            return self::$rulesByConnection[$cacheKey] ?? [];
        }

        try {
            $exists = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
            );
            $exists->execute([self::TABLE]);
            if ((int) $exists->fetchColumn() === 0) {
                self::$rulesByConnection[$cacheKey] = [];
                return [];
            }

            $stmt = $pdo->prepare(
                'SELECT identifier_type, identifier_value
                 FROM ' . self::TABLE . '
                 WHERE company_id = :company_id AND active = 1'
            );
            $stmt->execute(['company_id' => $companyId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $rules = [];
            foreach ($rows as $row) {
                $type = trim((string) ($row['identifier_type'] ?? ''));
                $value = trim((string) ($row['identifier_value'] ?? ''));
                if ($type !== '' && $value !== '') {
                    $rules[$type . ':' . $value] = true;
                }
            }

            self::$rulesByConnection[$cacheKey] = $rules;
            return $rules;
        } catch (\Throwable) {
            self::$rulesByConnection[$cacheKey] = [];
            return [];
        }
    }
}
