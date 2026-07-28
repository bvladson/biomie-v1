<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\Support\BiVisibilityScope;
use BIomie\Support\TenantContext;
use BIomie\Support\TenantContextResolver;
use BIomie\Support\TenantContextUnavailableException;

require_once dirname(__DIR__) . '/app/Bootstrap.php';
Bootstrap::init();

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec(<<<'SQL'
CREATE TABLE biomie_users (
    id INTEGER PRIMARY KEY,
    role TEXT NOT NULL,
    is_active INTEGER NOT NULL,
    permissions_version INTEGER NOT NULL
);
CREATE TABLE biomie_companies (
    id INTEGER PRIMARY KEY,
    slug TEXT NOT NULL,
    nome_fantasia TEXT NOT NULL,
    cnpj TEXT NOT NULL DEFAULT '',
    canal_padrao TEXT NOT NULL,
    ativo INTEGER NOT NULL
);
CREATE TABLE biomie_user_companies (
    user_id INTEGER NOT NULL,
    company_id INTEGER NOT NULL,
    can_varejo INTEGER NOT NULL,
    can_licitacao INTEGER NOT NULL,
    is_default INTEGER NOT NULL,
    default_canal TEXT NOT NULL,
    role_override TEXT NULL,
    PRIMARY KEY (user_id, company_id)
);
CREATE TABLE biomie_company_integrations (
    company_id INTEGER NOT NULL,
    provider TEXT NOT NULL,
    active INTEGER NOT NULL DEFAULT 0,
    app_key TEXT NOT NULL DEFAULT '',
    app_secret_encrypted TEXT NOT NULL DEFAULT '',
    last_success_at TEXT NULL,
    last_error_at TEXT NULL
);
CREATE TABLE biomie_user_seller_scope (
    company_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    visibility_mode TEXT NOT NULL,
    PRIMARY KEY (company_id, user_id)
);
CREATE TABLE biomie_user_visible_sellers (
    company_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    seller_id TEXT NOT NULL
);
CREATE TABLE biomie_quote_seller_binding (
    company_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    seller_id TEXT NOT NULL
);
CREATE TABLE biomie_user_contexts (
    context_id TEXT PRIMARY KEY,
    user_id INTEGER NOT NULL,
    context_type TEXT NOT NULL,
    company_id INTEGER NULL,
    allowed_company_ids_json TEXT NOT NULL,
    company_scope_map_json TEXT NOT NULL,
    business_scope TEXT NOT NULL,
    role_name TEXT NOT NULL,
    seller_scope_hash TEXT NOT NULL,
    permissions_version INTEGER NOT NULL,
    created_at TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    last_used_at TEXT NOT NULL,
    revoked_at TEXT NULL
);
SQL);

$pdo->exec(<<<'SQL'
INSERT INTO biomie_users VALUES
    (10, 'admin', 1, 7),
    (20, 'viewer', 1, 3);
INSERT INTO biomie_companies VALUES
    (1, 'MAG_FILIAL', 'MAG Filial', '', 'varejo', 1),
    (2, 'EXPERT', 'Expert', '', 'licitacao', 1),
    (3, 'INATIVA', 'Inativa', '', 'licitacao', 0);
INSERT INTO biomie_user_companies VALUES
    (10, 1, 1, 0, 1, 'varejo', NULL),
    (10, 2, 0, 1, 0, 'licitacao', 'manager'),
    (20, 1, 1, 0, 1, 'varejo', NULL);
INSERT INTO biomie_user_seller_scope VALUES
    (1, 10, 'all'),
    (2, 10, 'selected');
INSERT INTO biomie_user_visible_sellers VALUES
    (2, 10, 'VEN-2');
SQL);

$_SESSION = ['biomie_auth' => ['user_id' => 10]];
$_GET = [];
$_POST = [];
$_SERVER = [];

$checks = [];
$check = static function (string $id, bool $ok, mixed $actual = null) use (&$checks): void {
    $checks[] = ['id' => $id, 'ok' => $ok, 'actual' => $actual];
};
$reason = static function (callable $operation): string {
    try {
        $operation();
        return '';
    } catch (TenantContextUnavailableException $exception) {
        return $exception->getReasonCode();
    }
};

$resolver = new TenantContextResolver($pdo);

$mag = $resolver->createCompanyContext(10, 1, 'varejo');
$expert = $resolver->createCompanyContext(10, 2, 'licitacao');
$check(
    'opaque_ids_are_new_and_server_generated',
    $mag->contextId !== $expert->contextId
        && preg_match('/^[a-f0-9]{32}$/', $mag->contextId) === 1
        && preg_match('/^[a-f0-9]{32}$/', $expert->contextId) === 1
);
$check(
    'company_snapshot_contains_effective_authorization',
    $expert->companyId === 2
        && $expert->role === 'manager'
        && $expert->businessScope === 'licitacao'
        && $expert->allowedCompanyIds === [2]
        && $expert->allowedBusinessScopes === [2 => ['licitacao']]
        && strlen($expert->sellerScopeHash) === 64
        && $expert->permissionsVersion === 7,
    contextSwitchContextForTest($expert),
);

$_SERVER['HTTP_X_BIOMIE_CONTEXT'] = $expert->contextId;
$resolved = $resolver->resolveFromRequest(10);
$check(
    'valid_context_resolves_for_owner',
    $resolved->contextId === $expert->contextId && $resolved->companySlug === 'EXPERT'
);

unset($_SERVER['HTTP_X_BIOMIE_CONTEXT']);
$_SESSION['biomie_tenant'] = ['company_id' => 1, 'canal' => 'varejo'];
$check(
    'missing_ctx_never_falls_back_to_session_or_default',
    $reason(static fn () => $resolver->resolveFromRequest(10)) === 'context_required'
);

$_SERVER['HTTP_X_BIOMIE_CONTEXT'] = 'not-a-context';
$check(
    'malformed_ctx_fails_closed',
    $reason(static fn () => $resolver->resolveFromRequest(10)) === 'context_invalid'
);

$_SERVER['HTTP_X_BIOMIE_CONTEXT'] = $expert->contextId;
$_GET['ctx'] = str_repeat('a', 32);
$check(
    'divergent_header_and_query_fail_closed',
    $reason(static fn () => $resolver->resolveFromRequest(10)) === 'context_ambiguous'
);
unset($_GET['ctx']);

$check(
    'ctx_owned_by_another_user_is_not_reusable',
    $reason(static fn () => $resolver->resolveFromRequest(20)) === 'context_not_found'
);

$pdo->exec("UPDATE biomie_users SET permissions_version = 8 WHERE id = 10");
$check(
    'permissions_version_change_invalidates_old_tab',
    $reason(static fn () => $resolver->resolveFromRequest(10)) === 'context_permissions_changed'
);
$revoked = $pdo->query(
    "SELECT revoked_at FROM biomie_user_contexts WHERE context_id = " . $pdo->quote($expert->contextId)
)->fetchColumn();
$check('permissions_mismatch_revokes_context', is_string($revoked) && $revoked !== '');

$newMag = $resolver->createCompanyContext(10, 1, 'varejo');
$_SERVER['HTTP_X_BIOMIE_CONTEXT'] = $newMag->contextId;
$pdo->exec("UPDATE biomie_user_seller_scope SET visibility_mode = 'own' WHERE company_id = 1 AND user_id = 10");
$check(
    'seller_scope_change_invalidates_snapshot_even_without_counter_trigger',
    $reason(static fn () => $resolver->resolveFromRequest(10)) === 'context_permissions_changed'
);

$pdo->exec("UPDATE biomie_user_seller_scope SET visibility_mode = 'all' WHERE company_id = 1 AND user_id = 10");
$group = $resolver->createGroupContext(10, [2, 1, 2]);
$check(
    'group_context_is_normalized_and_read_only_role',
    $group->type === 'group'
        && $group->companyId === null
        && $group->allowedCompanyIds === [1, 2]
        && $group->businessScope === 'mixed'
        && $group->role === 'viewer'
        && $group->allowedBusinessScopes === [
            1 => ['varejo'],
            2 => ['licitacao'],
        ]
);
$check(
    'group_rejects_unowned_company',
    $reason(static fn () => $resolver->createGroupContext(10, [1, 3])) === 'context_company_forbidden'
);

$expired = $resolver->createCompanyContext(10, 1, 'varejo');
$pdo->exec(
    "UPDATE biomie_user_contexts SET expires_at = '2000-01-01 00:00:00'
      WHERE context_id = " . $pdo->quote($expired->contextId)
);
$_SERVER['HTTP_X_BIOMIE_CONTEXT'] = $expired->contextId;
$check(
    'expired_context_is_rejected',
    $reason(static fn () => $resolver->resolveFromRequest(10)) === 'context_expired'
);

$_SERVER['HTTP_X_BIOMIE_CONTEXT'] = $group->contextId;
$check(
    'group_is_denied_by_default_until_route_is_scope_aware',
    $reason(static fn () => TenantContext::boot($pdo)) === 'context_group_route_unsupported'
);
TenantContext::boot($pdo, true);
$meta = TenantContext::apiMeta();
$check(
    'tenant_context_exposes_stable_api_meta',
    ($meta['context_id'] ?? null) === $group->contextId
        && ($meta['context_type'] ?? null) === 'group'
        && array_key_exists('company_id', $meta)
        && $meta['company_id'] === null
        && ($meta['business_scope'] ?? null) === 'mixed'
        && ($meta['permissions_version'] ?? null) === 8
        && ($meta['allowed_company_ids'] ?? null) === [1, 2]
);
$check('group_sql_scope_uses_only_authorized_ids', TenantContext::sqlFilter('nf') === 'nf.company_id IN (1,2)');
$check(
    'group_is_never_represented_as_company_zero',
    $reason(static fn () => TenantContext::companyId()) === 'context_company_required'
        && TenantContext::companyIdOrNull() === null
);
$groupBusinessFilter = BiVisibilityScope::commercialVisibleSql('nf');
$check(
    'group_business_scope_is_enforced_per_company',
    str_contains($groupBusinessFilter, "nf.company_id = 1")
        && str_contains($groupBusinessFilter, "nf.business_channel = 'varejo'")
        && str_contains($groupBusinessFilter, 'nf.is_biomie_commercial_visible')
        && str_contains($groupBusinessFilter, "nf.company_id = 2")
        && str_contains($groupBusinessFilter, "nf.business_channel = 'licitacao'"),
    $groupBusinessFilter,
);
$check(
    'group_channel_fallback_fails_closed',
    BiVisibilityScope::licitacaoFallbackSql('nf') === '1=0'
);

$endpoint = (string) file_get_contents(dirname(__DIR__) . '/api/context_switch.php');
$adapter = (string) file_get_contents(dirname(__DIR__) . '/api/set_context.php');
$migration = (string) file_get_contents(
    dirname(__DIR__) . '/database/migrations/Version20260724010000_TenantContextR1.php'
);
$forwardMigration = (string) file_get_contents(
    dirname(__DIR__) . '/database/migrations/Version20260724011000_TenantContextScopeMapForward.php'
);
$check(
    'single_switch_implementation_has_csrf_auth_and_audit',
    str_contains($endpoint, 'Auth::requireLogin(true)')
        && str_contains($endpoint, 'HTTP_X_CSRF_TOKEN')
        && str_contains($endpoint, 'ContextSwitchAuditLogger')
        && str_contains($endpoint, 'createCompanyContext')
        && str_contains($endpoint, 'createGroupContext')
        && str_contains($endpoint, 'context_group_not_available')
        && str_contains($endpoint, 'groupContextEnabled')
        && !str_contains($adapter, 'biomie_tenant')
        && str_contains($adapter, "require __DIR__ . '/context_switch.php'")
);
$check(
    'migration_contains_expiry_revocation_permission_version_and_switch_log',
    str_contains($migration, 'biomie_user_contexts')
        && str_contains($migration, 'expires_at')
        && str_contains($migration, 'revoked_at')
        && str_contains($migration, 'company_scope_map_json')
        && str_contains($migration, 'permissions_version')
        && str_contains($migration, 'biomie_context_switch_log')
);
$check(
    'scope_map_has_idempotent_forward_migration',
    str_contains($forwardMigration, 'company_scope_map_json')
        && str_contains($forwardMigration, "context_type = 'group'")
        && str_contains($forwardMigration, 'revoked_at')
        && str_contains($forwardMigration, 'columnExists')
);
$check(
    'permission_version_covers_company_seller_and_rbac_mutations',
    str_contains($migration, 'trg_user_companies_permissions_au')
        && str_contains($migration, 'trg_seller_scope_permissions_au')
        && str_contains($migration, 'trg_visible_sellers_permissions_au')
        && str_contains($migration, 'trg_quote_binding_permissions_au')
        && str_contains($migration, 'trg_user_roles_permissions_au')
        && str_contains($migration, 'trg_role_permissions_permissions_au')
        && str_contains($migration, 'trg_roles_permissions_au')
        && str_contains($migration, 'trg_permissions_permissions_au')
);

$passed = count(array_filter($checks, static fn (array $row): bool => $row['ok']));
$result = [
    'ok' => $passed === count($checks),
    'total' => count($checks),
    'passed' => $passed,
    'failed' => count($checks) - $passed,
    'checks' => $checks,
];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($result['ok'] ? 0 : 1);

/** @return array<string,mixed> */
function contextSwitchContextForTest(object $context): array
{
    return get_object_vars($context);
}
