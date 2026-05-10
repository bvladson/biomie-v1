<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Semantic;

/**
 * Binding contract for a KPI: combines its definition with resolved validation
 * status and permission metadata. Read-only after construction.
 */
final class KpiContract
{
    /** @param list<string> $violations */
    public function __construct(
        public readonly KpiDefinition $definition,
        public readonly bool          $isValid,
        public readonly array         $violations = [],
        public readonly string        $resolvedPermission = '',
    ) {}

    public static function build(KpiDefinition $def, MetricPermissionMap $permMap): self
    {
        $violations = [];

        if (!DataSourceTruth::isValid($def->sourceOfTruth)) {
            $violations[] = "source_of_truth invalido: {$def->sourceOfTruth}";
        }

        if (DataSourceTruth::isDiscouraged($def->sourceOfTruth) && $def->isActive()) {
            $violations[] = "source_of_truth '{$def->sourceOfTruth}' nao recomendado para KPI active.";
        }

        if ($def->permission === '') {
            $violations[] = 'permission esta vazia.';
        } elseif (!$permMap->isKnownPermission($def->permission)) {
            $violations[] = "permission '{$def->permission}' nao existe na matriz RBAC.";
        }

        if ($def->isActive()) {
            if ($def->sourceTable === '') {
                $violations[] = 'KPI active sem source_table.';
            }
            if ($def->officialDate === '') {
                $violations[] = 'KPI active sem official_date.';
            }
        }

        $resolvedPermission = $permMap->isKnownPermission($def->permission) ? $def->permission : '';

        return new self(
            definition:         $def,
            isValid:            empty($violations),
            violations:         $violations,
            resolvedPermission: $resolvedPermission,
        );
    }

    public function id(): string { return $this->definition->id; }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id'                  => $this->definition->id,
            'is_valid'            => $this->isValid,
            'violations'          => $this->violations,
            'resolved_permission' => $this->resolvedPermission,
            'definition'          => $this->definition->toArray(),
        ];
    }
}
