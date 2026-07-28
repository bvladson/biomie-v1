<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Support\TenantContext;

require __DIR__ . '/app/page_bootstrap.php';

Auth::requireAnyCapability(['admin.view', 'admin.manage'], false);

// Preserva o ctx da aba (Location server-side não herda o ?ctx= do link).
$ctx = TenantContext::isBooted() ? '&ctx=' . rawurlencode(TenantContext::contextId()) : '';
header('Location: admin.php?tab=cockpit' . $ctx, true, 302);
exit;
