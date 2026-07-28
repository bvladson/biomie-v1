<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Semantic;

/**
 * Maps KPI IDs to RBAC permissions and validates against the known permission matrix.
 *
 * This class ONLY maps and validates — it never grants access.
 * Authorization must go through Auth::requireCapability() as before.
 */
final class MetricPermissionMap
{
    /** Canonical RBAC permission matrix (mirrors app/Admin/RbacService.php). */
    public const KNOWN_PERMISSIONS = [
        'sales.view',
        'sales.sync',
        'sales.export',
        'geo.view',
        'geo.materialize',
        'finance.view',
        'finance.sync',
        'finance.export',
        'fiscal.view',
        'fiscal.sync',
        'fiscal.export',
        'omie.view',
        'omie.sync',
        'jobs.view',
        'jobs.run',
        'logs.view',
        'admin.view',
        'admin.manage',
        'admin.users',
        'admin.migrations',
        'admin.logs',
        'admin.console',
        'settings.view',
        'settings.manage',
        'documents.full',
    ];

    /** @var array<string,string>  metricId → permission */
    private array $map = [];

    public function register(string $metricId, string $permission): void
    {
        $this->map[$metricId] = $permission;
    }

    public function permissionFor(string $metricId): string
    {
        return $this->map[$metricId] ?? '';
    }

    public function isKnownPermission(string $permission): bool
    {
        return in_array($permission, self::KNOWN_PERMISSIONS, true);
    }

    public function hasPermission(string $metricId): bool
    {
        return isset($this->map[$metricId]) && $this->isKnownPermission($this->map[$metricId]);
    }

    /**
     * Returns all KPI IDs mapped to the given permission.
     * @return list<string>
     */
    public function metricsFor(string $permission): array
    {
        return array_keys(array_filter($this->map, fn (string $p): bool => $p === $permission));
    }

    /**
     * Returns KPIs whose permission is not in the known matrix.
     * @return array<string,string>  metricId → invalid permission
     */
    public function orphanedMetrics(): array
    {
        $orphaned = [];
        foreach ($this->map as $id => $perm) {
            if (!$this->isKnownPermission($perm)) {
                $orphaned[$id] = $perm;
            }
        }
        return $orphaned;
    }

    /**
     * Returns all registered mappings for audit purposes.
     * @return array<string,string>
     */
    public function all(): array
    {
        return $this->map;
    }
}
