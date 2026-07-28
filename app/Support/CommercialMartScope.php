<?php

declare(strict_types=1);

namespace BIomie\Support;

use PDO;
use Throwable;

/**
 * Fail-closed tenant/channel scope for Gold marts shared by commercial screens.
 *
 * Gold keeps Varejo and Licitacao in the same physical tables. Every runtime
 * read must therefore apply both the tenant visibility set and the active
 * business channel. The consolidated company view intentionally follows the
 * Varejo contract, as defined by BiVisibilityScope.
 */
final class CommercialMartScope
{
    public static function condition(PDO $pdo, string $table, string $alias = ''): string
    {
        try {
            $tenant = trim(TenantContext::sqlFilter($alias));
            $channel = trim(BiVisibilityScope::getCondition($pdo, $table, $alias));
        } catch (Throwable) {
            return '1 = 0';
        }

        if ($tenant === '' || $channel === '') {
            return '1 = 0';
        }

        return '(' . $tenant . ') AND (' . $channel . ')';
    }

    public static function channelCondition(PDO $pdo, string $table, string $alias = ''): string
    {
        try {
            $channel = trim(BiVisibilityScope::getCondition($pdo, $table, $alias));
        } catch (Throwable) {
            return '1 = 0';
        }

        return $channel !== '' ? $channel : '1 = 0';
    }
}
