<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\Cache;
use BIomie\Company\CompanyStorage;
use BIomie\Http;
use BIomie\Support\CompanyContext;
use BIomie\Support\TenantContext;
use BIomie\Support\TenantContextUnavailableException;

require_once dirname(__DIR__) . '/app/Bootstrap.php';
Bootstrap::init();

$checks = [];
$check = static function (string $id, bool $ok) use (&$checks): void {
    $checks[] = ['id' => $id, 'ok' => $ok];
};

$instance = new ReflectionProperty(TenantContext::class, 'instance');
$fingerprint = new ReflectionMethod(Cache::class, 'tenantScopeFingerprint');
$cache = new Cache(sys_get_temp_dir() . '/biomie-context-cache-contract');
$cacheTestSlug = 'CONTRACT_' . bin2hex(random_bytes(5));
$cacheTestDir = Bootstrap::rootPath(
    'storage/companies/' . strtolower($cacheTestSlug),
);

$companyA = new CompanyContext(
    str_repeat('a', 32),
    'company',
    7,
    1,
    'MAG_FILIAL',
    'MAG Filial',
    'varejo',
    'manager',
    str_repeat('1', 64),
    4,
    [1],
    [1 => ['varejo']],
);
$companyDifferentSeller = new CompanyContext(
    str_repeat('b', 32),
    'company',
    7,
    1,
    'MAG_FILIAL',
    'MAG Filial',
    'varejo',
    'manager',
    str_repeat('2', 64),
    4,
    [1],
    [1 => ['varejo']],
);
$companyEquivalentContext = new CompanyContext(
    str_repeat('e', 32),
    'company',
    7,
    1,
    'MAG_FILIAL',
    'MAG Filial',
    'varejo',
    'manager',
    str_repeat('1', 64),
    4,
    [1],
    [1 => ['varejo']],
);
$companyNewPermissions = new CompanyContext(
    str_repeat('f', 32),
    'company',
    7,
    1,
    'MAG_FILIAL',
    'MAG Filial',
    'varejo',
    'manager',
    str_repeat('1', 64),
    5,
    [1],
    [1 => ['varejo']],
);
$groupA = new CompanyContext(
    str_repeat('c', 32),
    'group',
    7,
    null,
    'CONSOLIDADO',
    'Consolidado Grupo',
    'mixed',
    'viewer',
    str_repeat('3', 64),
    4,
    [1, 2],
    [1 => ['varejo'], 2 => ['licitacao']],
);
$groupB = new CompanyContext(
    str_repeat('d', 32),
    'group',
    7,
    null,
    'CONSOLIDADO',
    'Consolidado Grupo',
    'mixed',
    'viewer',
    str_repeat('3', 64),
    4,
    [1, 3],
    [1 => ['varejo'], 3 => ['licitacao']],
);

try {
    $instance->setValue(null, $companyA);
    [$payload, $headers] = Http::contextualizePayload([
        'ok' => true,
        'meta' => [
            'context_id' => str_repeat('f', 32),
            'company_id' => 999,
            'data_as_of' => '2026-07-23T10:00:00-03:00',
        ],
    ]);
    $check(
        'server_context_overrides_endpoint_spoof',
        ($payload['meta']['context_id'] ?? null) === $companyA->contextId
            && ($payload['meta']['company_id'] ?? null) === 1,
    );
    $check(
        'real_data_as_of_is_preserved',
        ($payload['meta']['data_as_of'] ?? null) === '2026-07-23T10:00:00-03:00',
    );
    $check(
        'response_context_header_matches_meta',
        ($headers['X-BIOMIE-Context'] ?? null) === ($payload['meta']['context_id'] ?? null),
    );

    [$withoutFreshness] = Http::contextualizePayload(['ok' => true]);
    $check(
        'freshness_is_not_invented',
        !array_key_exists('data_as_of', (array) ($withoutFreshness['meta'] ?? [])),
    );

    $companyFingerprint = $fingerprint->invoke($cache);
    $instance->setValue(null, $companyEquivalentContext);
    $equivalentFingerprint = $fingerprint->invoke($cache);
    $check(
        'equivalent_context_ids_reuse_cache_partition',
        $companyFingerprint === $equivalentFingerprint,
    );

    $instance->setValue(null, $companyDifferentSeller);
    $sellerFingerprint = $fingerprint->invoke($cache);
    $check(
        'seller_scope_partitions_cache',
        is_string($companyFingerprint)
            && is_string($sellerFingerprint)
            && $companyFingerprint !== $sellerFingerprint,
    );

    $instance->setValue(null, $companyNewPermissions);
    $permissionsFingerprint = $fingerprint->invoke($cache);
    $check(
        'permissions_version_partitions_cache',
        is_string($permissionsFingerprint)
            && $companyFingerprint !== $permissionsFingerprint,
    );

    $instance->setValue(null, $groupA);
    $groupFingerprintA = $fingerprint->invoke($cache);
    $instance->setValue(null, $groupB);
    $groupFingerprintB = $fingerprint->invoke($cache);
    $check(
        'group_company_set_partitions_cache',
        is_string($groupFingerprintA)
            && is_string($groupFingerprintB)
            && $groupFingerprintA !== $groupFingerprintB,
    );

    $cacheCompanyA = new CompanyContext(
        str_repeat('1', 32),
        'company',
        7,
        901,
        $cacheTestSlug,
        'Cache Contract',
        'varejo',
        'manager',
        str_repeat('4', 64),
        2,
        [901],
        [901 => ['varejo']],
    );
    $cacheCompanyB = new CompanyContext(
        str_repeat('2', 32),
        'company',
        8,
        901,
        $cacheTestSlug,
        'Cache Contract',
        'varejo',
        'seller',
        str_repeat('5', 64),
        2,
        [901],
        [901 => ['varejo']],
    );
    $cacheInvalidator = new CompanyContext(
        str_repeat('3', 32),
        'company',
        0,
        901,
        $cacheTestSlug,
        'Cache Contract',
        'varejo',
        'system',
        hash('sha256', 'cache-invalidator'),
        0,
        [901],
        [901 => ['varejo']],
    );
    $partitionedCache = new Cache(sys_get_temp_dir() . '/unused-cache-root');
    $instance->setValue(null, $cacheCompanyA);
    $partitionedCache->get('domain:item', 60);
    $partitionedCache->put('domain:item', ['scope' => 'a']);
    $instance->setValue(null, $cacheCompanyB);
    $partitionedCache->get('domain:item', 60);
    $partitionedCache->put('domain:item', ['scope' => 'b']);
    $instance->setValue(null, $cacheInvalidator);
    $invalidated = $partitionedCache->invalidatePrefix('domain');
    $instance->setValue(null, $cacheCompanyA);
    $scopeAAfter = $partitionedCache->read('domain:item');
    $instance->setValue(null, $cacheCompanyB);
    $scopeBAfter = $partitionedCache->read('domain:item');
    $check(
        'logical_prefix_invalidates_all_authorization_variants',
        $invalidated['matched'] === 2
            && $invalidated['deleted'] === 2
            && $scopeAAfter === null
            && $scopeBAfter === null,
    );

    $instance->setValue(null, null);
    try {
        $cache->read('must_fail_without_context');
        $check('cache_without_context_is_blocked', false);
    } catch (TenantContextUnavailableException $exception) {
        $check(
            'cache_without_context_is_blocked',
            $exception->getReasonCode() === 'context_required_for_cache',
        );
    }

    try {
        CompanyStorage::path('', 'cache');
        $check('company_storage_without_slug_is_blocked', false);
    } catch (InvalidArgumentException) {
        $check('company_storage_without_slug_is_blocked', true);
    }
} finally {
    $instance->setValue(null, null);
    if (is_dir($cacheTestDir)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($cacheTestDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry->isDir()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }
        @rmdir($cacheTestDir);
    }
}

$failed = array_values(array_filter(
    $checks,
    static fn (array $row): bool => $row['ok'] !== true,
));
foreach ($checks as $row) {
    echo ($row['ok'] ? '[OK] ' : '[FAIL] ') . $row['id'] . PHP_EOL;
}
echo sprintf('%d/%d checks passed.%s', count($checks) - count($failed), count($checks), PHP_EOL);

exit($failed === [] ? 0 : 1);
