<?php

declare(strict_types=1);

namespace BIomie\Support;

use PDO;

final class ActiveVendorResolver
{
    public const WARN_VENDOR_STATUS_SOURCE_UNTRUSTED = 'vendor_status_source_untrusted';
    public const WARN_ACTIVE_STATUS_MAPPING_GAP = 'active_status_mapping_gap';
    public const WARN_GHOST_VENDOR = 'ghost_vendor';
    public const WARN_STATUS_UNKNOWN = 'vendor_status_unknown';

    private const STATUS_FIELDS = [
        'ativo',
        'inativo',
        'bloqueado',
        'situacao',
        'status',
        'cancelado',
        'disabled',
        'inactive',
        'inativo_sn',
        'ativo_sn',
    ];

    /** @var array<string,array<string,mixed>> */
    private array $dbByCode = [];
    /** @var array<string,array<string,mixed>> */
    private array $dbByName = [];
    /** @var array<string,array<string,mixed>> */
    private array $omieByCode = [];
    /** @var array<string,array<string,mixed>> */
    private array $omieByName = [];

    private bool $loaded = false;
    private bool $vendorTableExists = false;
    private bool $bronzeTableExists = false;
    private bool $dbStatusTrusted = false;
    private bool $omieStatusTrusted = false;
    private bool $statusReliable = false;
    private bool $activeStatusMappingGap = false;
    private string $sourceUsed = 'none';

    /** @var array<string,mixed> */
    private array $summary = [
        'total' => 0,
        'active' => 0,
        'inactive' => 0,
        'source' => 'none',
        'table_exists' => false,
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly VendorAliasResolver $aliasResolver = new VendorAliasResolver()
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function resolve(string $vendorRef, string $vendorName = ''): array
    {
        $this->ensureLoaded();

        $rawName = trim($vendorName !== '' ? $vendorName : $vendorRef);
        $alias = $this->aliasResolver->resolve($rawName);
        $channel = (string) $alias['vendor_channel'];
        $warnings = array_values(array_unique(array_map('strval', $alias['warnings'] ?? [])));

        $dbRow = $this->findDbRow($vendorRef, $rawName);
        $omieRow = $this->findOmieRow($vendorRef, $rawName);
        $nameExistsInMaster = $this->nameExistsInMaster($rawName);
        $ghostVendor = $rawName !== '' && !$nameExistsInMaster && !$this->isGenericName($rawName);

        $isActive = true;
        $statusSource = 'assumed_active';
        $confidence = 35;
        $canonicalName = $rawName;
        $inactiveReason = '';
        $dbIsActive = $dbRow !== null ? (bool) ($dbRow['is_active'] ?? true) : null;
        $omieIsActive = null;
        $statusField = null;
        $statusValue = null;

        if ($omieRow !== null && ($omieRow['status_known'] ?? false)) {
            $omieIsActive = (bool) ($omieRow['is_active'] ?? true);
            $isActive = $omieIsActive;
            $statusSource = 'omie_bronze_payload';
            $confidence = 95;
            $canonicalName = (string) ($omieRow['name'] ?? $canonicalName);
            $statusField = isset($omieRow['status_field']) ? (string) $omieRow['status_field'] : null;
            $statusValue = $omieRow['status_value'] ?? null;

            if ($dbIsActive !== null && $dbIsActive !== $omieIsActive) {
                $warnings[] = self::WARN_ACTIVE_STATUS_MAPPING_GAP;
            }
        } elseif ($dbRow !== null && $this->dbStatusTrusted) {
            $isActive = (bool) ($dbRow['is_active'] ?? true);
            $statusSource = 'biomie_vendedores_omie';
            $confidence = 100;
            $canonicalName = (string) ($dbRow['name'] ?? $canonicalName);
        } elseif ($dbRow !== null) {
            $isActive = (bool) ($dbRow['is_active'] ?? true);
            $statusSource = 'biomie_vendedores_omie_untrusted';
            $confidence = 45;
            $canonicalName = (string) ($dbRow['name'] ?? $canonicalName);
            $warnings[] = self::WARN_VENDOR_STATUS_SOURCE_UNTRUSTED;
        } elseif ($omieRow !== null) {
            $isActive = true;
            $statusSource = 'omie_bronze_payload_status_unknown';
            $confidence = 55;
            $canonicalName = (string) ($omieRow['name'] ?? $canonicalName);
            $warnings[] = self::WARN_STATUS_UNKNOWN;
        } elseif (!$this->vendorTableExists && !$this->bronzeTableExists) {
            $statusSource = 'no_vendor_master:assumed_active';
            $confidence = 30;
            $warnings[] = self::WARN_VENDOR_STATUS_SOURCE_UNTRUSTED;
        } else {
            $statusSource = 'not_in_vendor_master:assumed_active';
            $confidence = 40;
            $warnings[] = self::WARN_GHOST_VENDOR;
        }

        if ($ghostVendor) {
            $warnings[] = self::WARN_GHOST_VENDOR;
        }
        if ($this->activeStatusMappingGap) {
            $warnings[] = self::WARN_ACTIVE_STATUS_MAPPING_GAP;
            $warnings[] = self::WARN_VENDOR_STATUS_SOURCE_UNTRUSTED;
        }
        if (!$this->statusReliable) {
            $warnings[] = self::WARN_VENDOR_STATUS_SOURCE_UNTRUSTED;
            $confidence = min($confidence, 55);
        }
        if (in_array(VendorAliasResolver::WARN_AMBIGUOUS_CHANNEL, $warnings, true)) {
            $confidence = min($confidence, 60);
        }

        $warnings = array_values(array_unique($warnings));
        if (!$isActive) {
            $inactiveReason = $statusSource === 'omie_bronze_payload'
                ? 'Vendedor marcado como inativo no payload ListarVendedores da Omie'
                : 'Vendedor marcado como inativo em biomie_vendedores_omie';
        }

        return $this->buildResolved(
            $rawName,
            $isActive,
            $channel,
            $canonicalName,
            $statusSource,
            $confidence,
            $warnings,
            $inactiveReason,
            $ghostVendor,
            $dbIsActive,
            $omieIsActive,
            $statusField,
            $statusValue,
            $alias
        );
    }

    public function ensureLoaded(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;
        $this->loadDbRoster();
        $this->loadOmieRosterFromBronze();
        $this->calculateTrust();
    }

    /**
     * @return list<string>
     */
    public function getActiveVendorNames(): array
    {
        $this->ensureLoaded();
        $names = [];
        $source = $this->omieStatusTrusted ? $this->omieByCode : $this->dbByCode;

        foreach ($source as $row) {
            if (($row['is_active'] ?? true) === true && trim((string) ($row['name'] ?? '')) !== '') {
                $names[] = (string) $row['name'];
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @return array<string,mixed>
     */
    public function getSummary(): array
    {
        $this->ensureLoaded();
        return $this->summary;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function getDbRoster(): array
    {
        $this->ensureLoaded();
        return array_values($this->dbByCode);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function getOmieRoster(): array
    {
        $this->ensureLoaded();
        return array_values($this->omieByCode);
    }

    private function loadDbRoster(): void
    {
        try {
            $this->vendorTableExists = $this->tableExists('biomie_vendedores_omie');
            if (!$this->vendorTableExists) {
                return;
            }

            $stmt = $this->pdo->query('SELECT codigo_omie, nome, email, comissao, ativo FROM biomie_vendedores_omie ORDER BY nome');
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $code = trim((string) ($row['codigo_omie'] ?? ''));
                $name = trim((string) ($row['nome'] ?? ''));
                if ($code === '' && $name === '') {
                    continue;
                }

                $record = [
                    'code' => $code,
                    'name' => $name,
                    'email' => (string) ($row['email'] ?? ''),
                    'commission' => (float) ($row['comissao'] ?? 0),
                    'is_active' => (bool) ($row['ativo'] ?? true),
                    'raw' => $row,
                ];
                if ($code !== '') {
                    $this->dbByCode[$code] = $record;
                }
                if ($name !== '') {
                    $this->dbByName[$this->aliasResolver->normalize($name)] = $record;
                }
            }
        } catch (\Throwable) {
            $this->vendorTableExists = false;
        }
    }

    private function loadOmieRosterFromBronze(): void
    {
        try {
            $this->bronzeTableExists = $this->tableExists('biomie_omie_bronze');
            if (!$this->bronzeTableExists) {
                return;
            }

            $sql = <<<'SQL'
                SELECT b.provider_key, b.natural_key, b.payload_json, b.ingested_at, b.id
                FROM biomie_omie_bronze b
                INNER JOIN (
                    SELECT
                        COALESCE(NULLIF(provider_key, ''), NULLIF(natural_key, ''), payload_hash) AS roster_key,
                        MAX(id) AS max_id
                    FROM biomie_omie_bronze
                    WHERE domain_name = 'vendors'
                       OR entity_name = 'vendor'
                       OR omie_method LIKE '%Vendedor%'
                       OR endpoint LIKE '%vendedor%'
                    GROUP BY COALESCE(NULLIF(provider_key, ''), NULLIF(natural_key, ''), payload_hash)
                ) latest ON latest.max_id = b.id
                ORDER BY b.id
            SQL;

            $stmt = $this->pdo->query($sql);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
                if (!is_array($payload)) {
                    continue;
                }

                $code = $this->firstString($payload, ['codigo', 'codigo_omie', 'codigo_vendedor', 'codVend']);
                if ($code === '') {
                    $code = trim((string) ($row['provider_key'] ?? $row['natural_key'] ?? ''));
                }
                $name = $this->firstString($payload, ['nome', 'nome_vendedor', 'vendedor_nome']);
                if ($code === '' && $name === '') {
                    continue;
                }

                $status = $this->resolveStatusFromPayload($payload);
                $record = [
                    'code' => $code,
                    'name' => $name,
                    'email' => $this->firstString($payload, ['email', 'e_mail', 'email_vendedor']),
                    'commission' => \BIomie\Support::toFloat($payload['comissao'] ?? $payload['percentual_comissao'] ?? 0),
                    'is_active' => $status['is_active'],
                    'status_known' => $status['known'],
                    'status_field' => $status['field'],
                    'status_value' => $status['value'],
                    'ingested_at' => (string) ($row['ingested_at'] ?? ''),
                    'raw' => $payload,
                ];
                if ($code !== '') {
                    $this->omieByCode[$code] = $record;
                }
                if ($name !== '') {
                    $this->omieByName[$this->aliasResolver->normalize($name)] = $record;
                }
            }
        } catch (\Throwable) {
            $this->bronzeTableExists = false;
        }
    }

    private function calculateTrust(): void
    {
        $dbActive = 0;
        $dbInactive = 0;
        foreach ($this->dbByCode as $row) {
            ((bool) ($row['is_active'] ?? true)) ? $dbActive++ : $dbInactive++;
        }

        $omieActive = 0;
        $omieInactive = 0;
        $omieKnown = 0;
        foreach ($this->omieByCode as $row) {
            if (!($row['status_known'] ?? false)) {
                continue;
            }
            $omieKnown++;
            ((bool) ($row['is_active'] ?? true)) ? $omieActive++ : $omieInactive++;
        }

        $this->omieStatusTrusted = $omieKnown > 0;
        $this->activeStatusMappingGap = $this->omieStatusTrusted && $omieInactive > 0 && $dbInactive === 0;
        $this->dbStatusTrusted = $this->vendorTableExists
            && !$this->activeStatusMappingGap
            && count($this->dbByCode) > 0
            && ($dbInactive > 0 || !$this->omieStatusTrusted);
        $this->statusReliable = $this->omieStatusTrusted || $this->dbStatusTrusted;
        $this->sourceUsed = $this->omieStatusTrusted
            ? 'omie_bronze_payload'
            : ($this->dbStatusTrusted ? 'biomie_vendedores_omie' : 'untrusted_vendor_status');

        $active = $this->omieStatusTrusted ? $omieActive : $dbActive;
        $inactive = $this->omieStatusTrusted ? $omieInactive : $dbInactive;
        $total = $this->omieStatusTrusted ? count($this->omieByCode) : count($this->dbByCode);

        $this->summary = [
            'total' => $total,
            'active' => $active,
            'inactive' => $inactive,
            'source' => $this->sourceUsed,
            'table_exists' => $this->vendorTableExists,
            'bronze_table_exists' => $this->bronzeTableExists,
            'db_total' => count($this->dbByCode),
            'db_active' => $dbActive,
            'db_inactive' => $dbInactive,
            'omie_total' => count($this->omieByCode),
            'omie_active' => $omieActive,
            'omie_inactive' => $omieInactive,
            'omie_status_known' => $omieKnown,
            'db_status_trusted' => $this->dbStatusTrusted,
            'omie_status_trusted' => $this->omieStatusTrusted,
            'status_reliable' => $this->statusReliable,
            'active_status_mapping_gap' => $this->activeStatusMappingGap,
            'warnings' => $this->activeStatusMappingGap ? [self::WARN_ACTIVE_STATUS_MAPPING_GAP] : [],
        ];
    }

    /**
     * @return array{known:bool,is_active:bool,field:?string,value:mixed}
     */
    private function resolveStatusFromPayload(array $payload): array
    {
        $flat = $this->flatten($payload);
        foreach ($flat as $path => $value) {
            $field = strtolower((string) preg_replace('/[^a-z0-9_]/i', '_', basename(str_replace('.', '/', $path))));
            if (!in_array($field, self::STATUS_FIELDS, true)) {
                continue;
            }

            $active = $this->statusValueToActive($field, $value);
            if ($active !== null) {
                return ['known' => true, 'is_active' => $active, 'field' => $path, 'value' => $value];
            }
        }

        return ['known' => false, 'is_active' => true, 'field' => null, 'value' => null];
    }

    private function statusValueToActive(string $field, mixed $value): ?bool
    {
        $v = $this->aliasResolver->normalize((string) $value);
        if ($v === '') {
            return null;
        }

        $negativeField = in_array($field, ['inativo', 'bloqueado', 'cancelado', 'disabled', 'inactive', 'inativo_sn'], true);
        $positiveField = in_array($field, ['ativo', 'ativo_sn'], true);

        if ($negativeField) {
            if (in_array($v, ['s', 'sim', 'y', 'yes', 'true', '1', 'inativo', 'inactive', 'disabled', 'bloqueado', 'cancelado'], true)) {
                return false;
            }
            if (in_array($v, ['n', 'nao', 'no', 'false', '0', 'ativo', 'active'], true)) {
                return true;
            }
        }

        if ($positiveField) {
            if (in_array($v, ['s', 'sim', 'y', 'yes', 'true', '1', 'ativo', 'active'], true)) {
                return true;
            }
            if (in_array($v, ['n', 'nao', 'no', 'false', '0', 'inativo', 'inactive', 'disabled'], true)) {
                return false;
            }
        }

        if (in_array($field, ['situacao', 'status'], true)) {
            if (str_contains($v, 'inativo') || str_contains($v, 'inactive') || str_contains($v, 'bloque')) {
                return false;
            }
            if (str_contains($v, 'ativo') || str_contains($v, 'active')) {
                return true;
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private function findDbRow(string $vendorRef, string $vendorName): ?array
    {
        $ref = trim($vendorRef);
        if ($ref !== '' && isset($this->dbByCode[$ref])) {
            return $this->dbByCode[$ref];
        }

        $nameKey = $this->aliasResolver->normalize($vendorName !== '' ? $vendorName : $vendorRef);
        return $this->dbByName[$nameKey] ?? null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findOmieRow(string $vendorRef, string $vendorName): ?array
    {
        $ref = trim($vendorRef);
        if ($ref !== '' && isset($this->omieByCode[$ref])) {
            return $this->omieByCode[$ref];
        }

        $nameKey = $this->aliasResolver->normalize($vendorName !== '' ? $vendorName : $vendorRef);
        return $this->omieByName[$nameKey] ?? null;
    }

    private function nameExistsInMaster(string $name): bool
    {
        $key = $this->aliasResolver->normalize($name);
        if ($key === '') {
            return true;
        }

        return isset($this->omieByName[$key]) || isset($this->dbByName[$key]);
    }

    private function isGenericName(string $name): bool
    {
        $r = $this->aliasResolver->resolve($name);
        return (string) ($r['resolved_by'] ?? '') === 'generic';
    }

    /**
     * @param list<string> $paths
     */
    private function firstString(array $payload, array $paths): string
    {
        foreach ($paths as $path) {
            $parts = explode('.', $path);
            $current = $payload;
            foreach ($parts as $part) {
                if (!is_array($current) || !array_key_exists($part, $current)) {
                    $current = null;
                    break;
                }
                $current = $current[$part];
            }
            if ($current !== null && trim((string) $current) !== '') {
                return trim((string) $current);
            }
        }

        return '';
    }

    /**
     * @return array<string,mixed>
     */
    private function flatten(array $payload, string $prefix = ''): array
    {
        $out = [];
        foreach ($payload as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . (string) $key;
            if (is_array($value)) {
                $out += $this->flatten($value, $path);
            } else {
                $out[$path] = $value;
            }
        }

        return $out;
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table LIMIT 1'
        );
        $stmt->execute(['table' => $table]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * @param list<string> $warnings
     * @param array<string,mixed> $alias
     * @return array<string,mixed>
     */
    private function buildResolved(
        string $rawName,
        bool $isActive,
        string $channel,
        string $canonicalName,
        string $source,
        int $confidence,
        array $warnings,
        string $inactiveReason,
        bool $ghostVendor,
        ?bool $dbIsActive,
        ?bool $omieIsActive,
        ?string $statusField,
        mixed $statusValue,
        array $alias
    ): array {
        $inactiveBadge = $isActive ? '' : '<span class="mag-badge mag-badge--inactive" title="Vendedor inativo - historico">Inativo</span>';
        $channelBadge = match ($channel) {
            VendorAliasResolver::CHANNEL_REV => '<span class="mag-badge mag-badge--channel-rev">Revenda</span>',
            VendorAliasResolver::CHANNEL_VAR => '<span class="mag-badge mag-badge--channel-var">Varejo</span>',
            VendorAliasResolver::CHANNEL_REPRESENTANTE => '<span class="mag-badge mag-badge--channel-rep">Representante</span>',
            VendorAliasResolver::CHANNEL_LICITACAO => '<span class="mag-badge mag-badge--channel-lic">Licitacao</span>',
            default => '',
        };

        return [
            'raw_vendor_name' => $rawName,
            'is_active' => $isActive,
            'inactive_reason' => $inactiveReason,
            'vendor_channel' => $channel,
            'canonical_name' => $canonicalName,
            'source' => $source,
            'confidence' => $confidence,
            'active_status_confidence' => $confidence,
            'active_status_warning' => $warnings !== [] ? implode(',', $warnings) : '',
            'warnings' => $warnings,
            'display_badge' => $isActive ? '' : 'Vendedor inativo - historico',
            'inactive_badge' => $inactiveBadge,
            'channel_badge' => $channelBadge,
            'ghost_vendor' => $ghostVendor,
            'db_is_active' => $dbIsActive,
            'omie_is_active' => $omieIsActive,
            'omie_status_field' => $statusField,
            'omie_status_value' => $statusValue,
            'active_status_mapping_gap' => in_array(self::WARN_ACTIVE_STATUS_MAPPING_GAP, $warnings, true),
            'alias_candidate' => (bool) ($alias['alias_candidate'] ?? false),
            'requires_manual_approval' => (bool) ($alias['requires_manual_approval'] ?? false),
            'channel_match' => (bool) ($alias['channel_match'] ?? false),
            'suggested_alias' => $alias['suggested_alias'] ?? null,
            'alias' => $alias,
        ];
    }
}
