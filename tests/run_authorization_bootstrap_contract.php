<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Admin\RbacService;
use BIomie\Bootstrap;

require_once dirname(__DIR__) . '/app/Bootstrap.php';
Bootstrap::init();

$checks = [];
$check = static function (string $id, bool $ok, mixed $detail = null) use (&$checks): void {
    $checks[] = ['id' => $id, 'ok' => $ok, 'detail' => $detail];
};

$root = dirname(__DIR__);
$apiBootstrap = (string) file_get_contents($root . '/api/bootstrap.php');
$financeBootstrap = (string) file_get_contents($root . '/api/bootstrap_finance.php');
$pageBootstrap = (string) file_get_contents($root . '/app/page_bootstrap.php');
$authSource = (string) file_get_contents($root . '/app/Auth.php');
$rbacSource = (string) file_get_contents($root . '/app/Admin/RbacService.php');

foreach ([
    'api/bootstrap.php' => $apiBootstrap,
    'api/bootstrap_finance.php' => $financeBootstrap,
] as $file => $source) {
    $contextPosition = strpos($source, 'TenantContext::boot(');
    $csrfPosition = strpos($source, 'Http::enforceCsrf($logger)');
    $routeLogicPosition = $file === 'api/bootstrap.php'
        ? strpos($source, '$contextMethod =')
        : strpos($source, '$financeBootstrapCapabilities =');

    $check(
        $file . ':csrf_after_context',
        $contextPosition !== false && $csrfPosition !== false && $contextPosition < $csrfPosition,
        compact('contextPosition', 'csrfPosition'),
    );
    $check(
        $file . ':csrf_before_route_logic',
        $csrfPosition !== false && $routeLogicPosition !== false && $csrfPosition < $routeLogicPosition,
        compact('csrfPosition', 'routeLogicPosition'),
    );
}

$pageLoginPosition = strpos($pageBootstrap, 'Auth::requireLogin(false);', strpos($pageBootstrap, "['login.php', 'logout.php']"));
$pageContextPosition = strpos($pageBootstrap, 'TenantContext::boot(');
$check(
    'page_bootstrap_authenticates_before_tenant_context',
    $pageLoginPosition !== false
        && $pageContextPosition !== false
        && $pageLoginPosition < $pageContextPosition,
    compact('pageLoginPosition', 'pageContextPosition'),
);

$rbacPermissions = new ReflectionMethod(Auth::class, 'rbacPermissions');
$originalMode = getenv('BIOMIE_AUTHORIZATION_MODE');

try {
    putenv('BIOMIE_AUTHORIZATION_MODE');
    $defaultPermissions = $rbacPermissions->invoke(null, []);
    $check(
        'rbac_unavailable_fails_closed_by_default',
        $defaultPermissions === [],
        $defaultPermissions,
    );

    putenv('BIOMIE_AUTHORIZATION_MODE=legacy');
    $legacyPermissions = $rbacPermissions->invoke(null, []);
    $check(
        'legacy_fallback_requires_explicit_env',
        $legacyPermissions === null,
        $legacyPermissions,
    );

    putenv('BIOMIE_AUTHORIZATION_MODE=legacy-ish');
    $nonLegacyPermissions = $rbacPermissions->invoke(null, []);
    $check(
        'non_exact_legacy_value_stays_fail_closed',
        $nonLegacyPermissions === [],
        $nonLegacyPermissions,
    );
} finally {
    if ($originalMode === false) {
        putenv('BIOMIE_AUTHORIZATION_MODE');
    } else {
        putenv('BIOMIE_AUTHORIZATION_MODE=' . $originalMode);
    }
}

$check(
    'rbac_schema_failure_uses_fail_closed_helper',
    preg_match(
        '/if\s*\(!self::\$rbacService->tablesReady\(\)\)\s*\{\s*return self::denyBecauseRbacUnavailable\(/s',
        $authSource,
    ) === 1,
);
$check(
    'rbac_exception_uses_fail_closed_helper',
    preg_match(
        '/catch\s*\(\\\\Throwable \$exception\)\s*\{\s*return self::denyBecauseRbacUnavailable\(/s',
        $authSource,
    ) === 1,
);
$check(
    'rbac_failure_log_does_not_claim_legacy_fallback',
    !str_contains($authSource, 'Falha ao consultar RBAC; usando matriz legada.'),
);

$rbacPdo = new PDO('sqlite::memory:');
$rbacPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$rbacPdo->exec(<<<'SQL'
CREATE TABLE biomie_roles (
    id INTEGER PRIMARY KEY,
    role_key TEXT NOT NULL,
    is_active INTEGER NOT NULL,
    sort_order INTEGER NOT NULL
);
CREATE TABLE biomie_permissions (
    id INTEGER PRIMARY KEY,
    permission_key TEXT NOT NULL,
    is_active INTEGER NOT NULL
);
CREATE TABLE biomie_role_permissions (
    role_id INTEGER NOT NULL,
    permission_id INTEGER NOT NULL
);
CREATE TABLE biomie_user_roles (
    user_id INTEGER NOT NULL,
    role_id INTEGER NOT NULL
);
INSERT INTO biomie_roles VALUES (1, 'admin', 1, 10);
SQL);
$rbac = new RbacService($rbacPdo);
$tablesReady = new ReflectionProperty(RbacService::class, 'tablesReadyCache');
$tablesReady->setValue($rbac, true);
$check(
    'empty_role_mapping_does_not_expand_to_default_matrix',
    $rbac->permissionsForRole('admin') === [],
);
$check(
    'user_without_rbac_mapping_does_not_expand_legacy_role',
    $rbac->userPermissions(42, 'admin') === [],
);
$check(
    'rbac_service_has_no_permission_fallback_path',
    !str_contains($rbacSource, 'fallbackPermissions('),
);

$passed = count(array_filter($checks, static fn (array $row): bool => $row['ok']));
$result = [
    'ok' => $passed === count($checks),
    'total' => count($checks),
    'passed' => $passed,
    'failed' => count($checks) - $passed,
    'checks' => $checks,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
exit($result['ok'] ? 0 : 1);
