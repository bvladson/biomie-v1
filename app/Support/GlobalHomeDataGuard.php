<?php

declare(strict_types=1);

namespace BIomie\Support;

use BIomie\Auth;
use BIomie\Config;
use BIomie\Database;
use BIomie\Http;
use BIomie\RequestContext;

/**
 * Protege fontes agregadas da home que ainda nao aceitam recorte por vendedor.
 *
 * Esses endpoints so podem responder quando o usuario pode ver a empresa
 * inteira. Leitores com escopo own/selected usam commercial_dashboard.php,
 * que aplica o recorte de vendedor em cada consulta.
 */
final class GlobalHomeDataGuard
{
    public const ERROR_CODE = 'global_home_scope_restricted';

    public static function requireUnrestricted(?Config $config = null): void
    {
        if (Auth::isAdmin()) {
            return;
        }

        $sellerSelfOnly = !Auth::hasCapability('sales.quotes.followup.view')
            && Auth::hasCapability('sales.quotes.followup.self');
        if ($sellerSelfOnly) {
            self::deny();
        }

        $sharedConfig = $GLOBALS['config'] ?? null;
        $config ??= $sharedConfig instanceof Config ? $sharedConfig : new Config();

        $companyId = TenantContext::companyId();
        $userId = (int) (Auth::currentUser()['id'] ?? 0);
        $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
        $allowedSellerIds = (new SellerVisibilityScope(Database::connect($config, $connection)))
            ->allowedSellerIds($companyId, $userId);

        if ($allowedSellerIds !== null) {
            self::deny();
        }
    }

    private static function deny(): never
    {
        Http::json([
            'ok' => false,
            'error' => [
                'code' => self::ERROR_CODE,
                'message' => 'Esta fonte consolidada exige acesso a todos os vendedores.',
                'details' => null,
            ],
            'message' => 'Use a visao comercial filtrada para consultar os vendedores permitidos.',
            'request_id' => RequestContext::id(),
        ], 403);
    }
}
