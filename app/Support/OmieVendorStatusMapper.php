<?php

declare(strict_types=1);

namespace BIomie\Support;

final class OmieVendorStatusMapper
{
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

    public function __construct(
        private readonly VendorAliasResolver $aliasResolver = new VendorAliasResolver()
    ) {
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{known:bool,is_active:bool,field:?string,value:mixed}
     */
    public function resolve(array $payload): array
    {
        foreach ($this->flatten($payload) as $path => $value) {
            $field = $this->fieldName($path);
            if (!in_array($field, self::STATUS_FIELDS, true)) {
                continue;
            }

            $active = $this->statusValueToActive($field, $value);
            if ($active !== null) {
                return [
                    'known' => true,
                    'is_active' => $active,
                    'field' => $path,
                    'value' => $value,
                ];
            }
        }

        return [
            'known' => false,
            'is_active' => true,
            'field' => null,
            'value' => null,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function isActive(array $payload, bool $default = true): bool
    {
        $status = $this->resolve($payload);

        return (bool) ($status['known'] ? $status['is_active'] : $default);
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

    private function fieldName(string $path): string
    {
        $parts = explode('.', $path);

        return strtolower((string) preg_replace('/[^a-z0-9_]/i', '_', (string) end($parts)));
    }

    /**
     * @param array<string,mixed> $payload
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
}
