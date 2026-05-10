<?php

declare(strict_types=1);

use BIomie\Acl\DataMasker;
use BIomie\Auth;
use BIomie\Cache;
use BIomie\HomeBootstrapService;
use BIomie\Http;
use BIomie\PageRenderer;

require __DIR__ . '/app/page_bootstrap.php';
Auth::requireCapability('sales.view', false);

$bootPayloads = null;
try {
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
} catch (Throwable $exception) {
    $logger->warning('Index bootstrap indisponivel; seguindo com hidratacao client-side.', [
        'message' => $exception->getMessage(),
    ]);
}

echo PageRenderer::render('index.html', [
    'page_key' => 'dashboard',
    'user' => Auth::currentUser(),
    'is_admin' => Auth::isAdmin(),
    'boot_payloads' => $bootPayloads,
]);
