<?php

declare(strict_types=1);

namespace BIomie\Omie;

final class OmieExecutionContext
{
    public const WEB = 'web';
    public const WEBHOOK = 'webhook';
    public const CLI = 'cli';
    public const JOB = 'job';

    public static function detect(): string
    {
        return PHP_SAPI === 'cli' ? self::CLI : self::WEB;
    }

    public static function normalize(?string $context): string
    {
        $context = strtolower(trim((string) $context));

        return match ($context) {
            self::WEBHOOK => self::WEBHOOK,
            self::CLI => self::CLI,
            self::JOB => self::JOB,
            self::WEB => self::WEB,
            default => self::detect(),
        };
    }

    public static function isWeb(string $context): bool
    {
        return in_array($context, [self::WEB, self::WEBHOOK], true);
    }

    public static function isWorker(string $context): bool
    {
        return in_array($context, [self::CLI, self::JOB], true);
    }
}
