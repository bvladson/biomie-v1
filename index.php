<?php

declare(strict_types=1);

use BIomie\Acl\DataMasker;
use BIomie\Auth;
use BIomie\Cache;
use BIomie\Database;
use BIomie\HomeBootstrapService;
use BIomie\Http;
use BIomie\PageRenderer;
use BIomie\Support\SellerVisibilityScope;
use BIomie\Support\TenantContext;

require __DIR__ . '/app/page_bootstrap.php';
/** @var \BIomie\Config $config */
/** @var \BIomie\Logger $logger */
Auth::requireCapability('sales.view', false);

$bootPayloads = null;
// Arquitetura 10/10: o boot pesado é opt-in. A página carrega shell/skeleton rápido e hidrata via APIs.
if ((string) ($_GET['boot'] ?? '') === 'full') {
    try {
        // O bundle legado contém visão global e não aceita escopo por vendedor.
        // Portanto só pode ser embutido quando o usuário efetivamente vê todos.
        // Perfis self e modos own/selected seguem a hidratação segura pelas APIs.
        $selfOnly = !Auth::hasCapability('sales.quotes.followup.view')
            && Auth::hasCapability('sales.quotes.followup.self');
        $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
        $pdo = Database::connect($config, $connection);
        $userId = (int) (Auth::currentUser()['id'] ?? 0);
        $companyId = (int) TenantContext::companyId();
        $sellerScope = $selfOnly
            ? []
            : (new SellerVisibilityScope($pdo))->allowedSellerIds($companyId, $userId);

        if ($sellerScope === null) {
            $range = Http::resolveDateRange();
            Http::validateDateRange($range['start'], $range['end']);

            $bootstrap = new HomeBootstrapService(
                $config,
                $logger,
                new Cache(__DIR__ . '/storage/cache')
            );
            $bootPayloads = $bootstrap->build($range['start'], $range['end']);

            if (is_array($bootPayloads['dashboard']['overview'] ?? null)) {
                $bootPayloads['dashboard']['overview'] = DataMasker::applyToPayload(
                    $bootPayloads['dashboard']['overview'],
                    Auth::canViewFullDocuments()
                );
            }

            if (is_array($bootPayloads['dashboard']['health'] ?? null)) {
                $bootPayloads['dashboard']['health'] = DataMasker::applyToPayload(
                    $bootPayloads['dashboard']['health'],
                    Auth::canViewFullDocuments()
                );
            }
        }
    } catch (Throwable $exception) {
        $logger->warning('Index bootstrap indisponivel; seguindo com hidratacao client-side.', [
            'message' => $exception->getMessage(),
        ]);
    }
}

echo PageRenderer::render('index.html', [
    'page_key'      => 'dashboard',
    'user'          => Auth::currentUser(),
    'is_admin'      => Auth::isAdmin(),
    'boot_payloads' => $bootPayloads,
]);
