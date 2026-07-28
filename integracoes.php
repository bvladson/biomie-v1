<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Support\TenantContext;

require __DIR__ . '/app/page_bootstrap.php';

Auth::requireAnyCapability(['admin.view', 'admin.manage'], false);

// Preserva o ctx da aba no redirect: um Location server-side NÃO herda o ?ctx=
// que o link carrega. Sem ele, admin.php não resolve o contexto e joga o usuário
// de volta ao seletor de empresas (bug: Conexão Omie → select_empresa).
$ctx = TenantContext::isBooted() ? '&ctx=' . rawurlencode(TenantContext::contextId()) : '';
header('Location: admin.php?tab=integrations' . $ctx, true, 302);
exit;
