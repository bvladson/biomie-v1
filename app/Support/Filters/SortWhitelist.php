<?php

declare(strict_types=1);

namespace BIomie\Support\Filters;

/**
 * Maps client-facing sort field names to safe SQL column expressions.
 * Unknown fields fall back to the default expression — never raw injection.
 */
final class SortWhitelist
{
    private string $defaultExpr;

    /**
     * @param array<string,string> $map     field name => SQL column expression
     * @param string               $default SQL expression when field is unknown
     */
    public function __construct(
        private readonly array $map,
        string $default = '',
    ) {
        $first = reset($map);
        $this->defaultExpr = $default !== '' ? $default : ($first !== false ? $first : '1');
    }

    public function resolve(string $field): string
    {
        return $this->map[$field] ?? $this->defaultExpr;
    }

    public function contains(string $field): bool
    {
        return isset($this->map[$field]);
    }

    /** @return list<string> */
    public function allowedFields(): array
    {
        return array_keys($this->map);
    }
}
