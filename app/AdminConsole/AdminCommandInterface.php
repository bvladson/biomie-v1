<?php

declare(strict_types=1);

namespace BIomie\AdminConsole;

interface AdminCommandInterface
{
    public function name(): string;

    public function description(): string;

    public function group(): string;

    public function permission(): string;

    public function isDestructive(): bool;

    public function requiresConfirmation(): bool;

    /**
     * Validates user-supplied arguments and returns an error string, or null if valid.
     *
     * @param array<string,string> $args
     */
    public function validateArgs(array $args): ?string;

    /**
     * @param array<string,string> $args
     */
    public function run(AdminCommandContext $context, array $args = []): AdminCommandResult;
}
