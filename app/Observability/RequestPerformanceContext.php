<?php

declare(strict_types=1);

namespace BIomie\Observability;

/**
 * Lightweight per-request performance context.
 *
 * It intentionally tracks operational counters rather than wrapping PDO. Exact
 * SQL query counts remain the responsibility of dedicated read-model probes.
 */
final class RequestPerformanceContext
{
    /** @var array<string,int|float> */
    private static array $counters = [];

    /** @var array<string,string|int|float|bool|null> */
    private static array $tags = [];

    /** @var list<array<string,mixed>> */
    private static array $events = [];

    public static function increment(string $name, int|float $amount = 1): int|float
    {
        self::$counters[$name] = (self::$counters[$name] ?? 0) + $amount;
        return self::$counters[$name];
    }

    public static function setTag(string $name, string|int|float|bool|null $value): void
    {
        if (is_string($value)) {
            $value = trim($value);
        }
        self::$tags[$name] = $value;
    }

    /**
     * @param array<string,mixed> $context
     */
    public static function addEvent(string $name, array $context = []): void
    {
        if (count(self::$events) >= 12) {
            return;
        }

        self::$events[] = [
            'name' => $name,
            'context' => $context,
        ];
    }

    /**
     * @return array{counters:array<string,int|float>,tags:array<string,string|int|float|bool|null>,events:list<array<string,mixed>>}
     */
    public static function snapshot(): array
    {
        ksort(self::$counters);
        ksort(self::$tags);

        return [
            'counters' => self::$counters,
            'tags' => self::$tags,
            'events' => self::$events,
        ];
    }

    public static function reset(): void
    {
        self::$counters = [];
        self::$tags = [];
        self::$events = [];
    }
}
