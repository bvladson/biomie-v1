<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\Cache;

require_once dirname(__DIR__) . '/app/Bootstrap.php';
Bootstrap::init();

$dir = rtrim(sys_get_temp_dir(), '/\\') . '/biomie-cache-contract-' . bin2hex(random_bytes(6));
if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
    throw new RuntimeException('Nao foi possivel criar o diretorio temporario do teste.');
}

$checks = [];
$check = static function (string $id, bool $ok) use (&$checks): void {
    $checks[] = ['id' => $id, 'ok' => $ok];
};

try {
    $cache = new Cache($dir, isolated: true);
    $key = 'snapshot_fiscal_contract';

    // A miss captures generation 0. Invalidation must advance it even when
    // there is no JSON yet, rejecting the stale write that follows.
    $check('initial_miss', $cache->get($key, 60) === null);
    $emptyInvalidation = $cache->invalidatePrefix('snapshot_fiscal');
    $check('empty_prefix_generation_advanced', $emptyInvalidation['failed'] === 0);
    $cache->put($key, ['version' => 'stale']);
    $check('stale_after_empty_invalidation_rejected', $cache->read($key) === null);

    // A new read observes the current generation and allows a fresh write.
    $cache->get($key, 60);
    $cache->put($key, ['version' => 'fresh-1']);
    $check('fresh_write_allowed', ($cache->read($key)['version'] ?? null) === 'fresh-1');

    // Existing JSON is deleted and a value computed from the previous
    // generation cannot recreate it afterwards.
    $cache->get($key, 60);
    $existingInvalidation = $cache->invalidatePrefix('snapshot_fiscal');
    $check(
        'existing_prefix_deleted',
        $existingInvalidation['matched'] === 1
            && $existingInvalidation['deleted'] === 1
            && $cache->read($key) === null,
    );
    $cache->put($key, ['version' => 'stale-2']);
    $check('stale_after_delete_rejected', $cache->read($key) === null);

    $computed = $cache->getOrCompute($key, 60, static fn (): array => ['version' => 'fresh-2']);
    $check(
        'get_or_compute_persists_current_generation',
        ($computed['version'] ?? null) === 'fresh-2'
            && ($cache->read($key)['version'] ?? null) === 'fresh-2',
    );

    // Prefix generations are targeted: a nested circuit-breaker delete must
    // neither deadlock getOrCompute nor invalidate its unrelated outer key.
    $nestedKey = 'home_bootstrap_contract';
    $nested = $cache->getOrCompute($nestedKey, 60, static function () use ($cache): array {
        $cache->delete('omie_circuit_contract');
        return ['version' => 'nested-fresh'];
    });
    $check(
        'nested_unrelated_delete_does_not_deadlock_or_reject',
        ($nested['version'] ?? null) === 'nested-fresh'
            && ($cache->read($nestedKey)['version'] ?? null) === 'nested-fresh',
    );

    // delete() is exact-key invalidation. It must reject a stale write for
    // that key without invalidating another key that merely shares its prefix.
    $exactKey = 'dashboard_boot';
    $siblingKey = 'dashboard_bootstrap_other';
    $cache->get($exactKey, 60);
    $cache->get($siblingKey, 60);
    $cache->delete($exactKey);
    $cache->put($exactKey, ['version' => 'stale-exact']);
    $check('exact_delete_rejects_same_key_stale_write', $cache->read($exactKey) === null);
    $cache->put($siblingKey, ['version' => 'sibling-fresh']);
    $check(
        'exact_delete_does_not_invalidate_prefix_sibling',
        ($cache->read($siblingKey)['version'] ?? null) === 'sibling-fresh',
    );

    $failed = array_values(array_filter($checks, static fn (array $row): bool => !$row['ok']));
    echo json_encode([
        'ok' => $failed === [],
        'total' => count($checks),
        'passed' => count($checks) - count($failed),
        'failed' => count($failed),
        'checks' => $checks,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($failed === [] ? 0 : 1);
} finally {
    $files = glob($dir . '/*') ?: [];
    $hidden = glob($dir . '/.*') ?: [];
    foreach (array_unique(array_merge($files, $hidden)) as $file) {
        $name = basename($file);
        if ($name === '.' || $name === '..') {
            continue;
        }
        if (is_file($file)) {
            @unlink($file);
        }
    }
    @rmdir($dir);
}
