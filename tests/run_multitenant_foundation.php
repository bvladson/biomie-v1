<?php

declare(strict_types=1);

require_once __DIR__ . '/TestSupport.php';

use BIomie\Cache;
use BIomie\Config;
use BIomie\Database;
use BIomie\Logger;
use BIomie\OmieClient;
use BIomie\Omie\OmieClientFactory;
use BIomie\OmieRateLimiter;
use BIomie\Sync\ChunkedWindowRunner;
use BIomie\Support\ChannelClassifierService;
use BIomie\Support\SecretCipher;
use BIomie\Support\TenantCliContext;
use BIomie\Support\TenantContext;

$config = new Config();
// Este arquivo é um PROBE DE ESTADO DE PRODUÇÃO: quase tudo aqui é LEITURA da
// config viva (integrações cifradas, webhooks, regras de canal). Leitura em
// produção é permitida — o que a barreira proíbe é ESCRITA. A única seção que
// escreve (o probe de isolamento no fim) usa test_pdo(), nunca esta conexão.
$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo = Database::connect($config, $connection);
$logger = new Logger(dirname(__DIR__) . '/storage/logs/tests.log');
$checks = [];

$check = static function (string $id, bool $ok, mixed $actual = null) use (&$checks): void {
    $checks[] = ['id' => $id, 'ok' => $ok, 'actual' => $actual];
};

$companies = $pdo->query(
    'SELECT id, slug, cnpj, ativo FROM biomie_companies ORDER BY id'
)->fetchAll(PDO::FETCH_ASSOC);
$check('five_active_companies', count(array_filter(
    $companies,
    static fn (array $row): bool => (bool) $row['ativo']
)) === 5, $companies);

$ergo = array_values(array_filter(
    $companies,
    static fn (array $row): bool => $row['slug'] === 'ERGOOFFICE'
))[0] ?? [];
$check(
    'ergo_active_with_cnpj',
    (bool) ($ergo['ativo'] ?? false) && (string) ($ergo['cnpj'] ?? '') === '49410635000156',
    $ergo
);
$check(
    'ergooffice_uses_canonical_package',
    is_file(dirname(__DIR__) . '/companies/ergooffice/manifest.json')
        && !is_file(dirname(__DIR__) . '/companies/ergo/manifest.json')
        && !is_file(dirname(__DIR__) . '/companies/ergo_office/manifest.json')
);

$integrationSummary = $pdo->query(
    "SELECT COUNT(*) total,
            SUM(active = 1) active_total,
            SUM(app_key <> '') keys_total,
            SUM(app_secret_encrypted LIKE 'v1:%') encrypted_total
       FROM biomie_company_integrations
      WHERE provider = 'omie'"
)->fetch(PDO::FETCH_ASSOC);
$check(
    'all_integrations_encrypted',
    (int) ($integrationSummary['total'] ?? 0) === 5
        && (int) ($integrationSummary['active_total'] ?? 0) === 5
        && (int) ($integrationSummary['keys_total'] ?? 0) === 5
        && (int) ($integrationSummary['encrypted_total'] ?? 0) === 5,
    $integrationSummary
);

$webhookColumns = $pdo->query(
    "SELECT COUNT(*)
       FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA=DATABASE()
        AND TABLE_NAME='biomie_company_integrations'
        AND COLUMN_NAME IN (
            'webhook_token_encrypted',
            'webhook_rotated_at',
            'webhook_last_received_at'
        )"
)->fetchColumn();
$check('webhook_management_schema_ready', (int) $webhookColumns === 3, $webhookColumns);

$webhookSummary = $pdo->query(
    "SELECT COUNT(*) total,
            SUM(webhook_token_hash <> '') hash_total,
            SUM(webhook_token_encrypted LIKE 'v1:%') encrypted_total
       FROM biomie_company_integrations
      WHERE provider='omie' AND active=1"
)->fetch(PDO::FETCH_ASSOC);
$check(
    'all_company_webhooks_configured',
    (int) ($webhookSummary['total'] ?? 0) === 5
        && (int) ($webhookSummary['hash_total'] ?? 0) === 5
        && (int) ($webhookSummary['encrypted_total'] ?? 0) === 5,
    $webhookSummary
);

$htaccess = file_get_contents(dirname(__DIR__) . '/.htaccess');
$check(
    'friendly_company_webhook_route',
    is_string($htaccess)
        && str_contains($htaccess, '^webhooks/omie/')
        && str_contains($htaccess, 'company_slug=$1&path_token=$2'),
    is_string($htaccess)
);

$legacyCredentials = $pdo->query(
    "SELECT COUNT(*) FROM biomie_companies
      WHERE omie_app_key <> '' OR omie_app_secret <> '' OR omie_webhook_token <> ''"
)->fetchColumn();
$check('legacy_credentials_cleared', (int) $legacyCredentials === 0, $legacyCredentials);

$unsafeColumns = $pdo->query(
    "SELECT COUNT(*)
       FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA=DATABASE()
        AND COLUMN_NAME='company_id'
        AND (IS_NULLABLE='YES' OR COLUMN_DEFAULT IS NOT NULL)"
)->fetchColumn();
$check('company_columns_fail_closed', (int) $unsafeColumns === 0, $unsafeColumns);

$expectedIndexes = [
    'biomie_sales_targets_monthly.uq_company_vendor_month',
    'biomie_eventos_canonicos.uq_company_event_idempotency',
    'biomie_omie_bronze.uq_company_bronze_idempotency',
    'biomie_jobs.uq_company_jobs_idempotency',
];
foreach ($expectedIndexes as $expected) {
    [$table, $index] = explode('.', $expected, 2);
    $stmt = $pdo->prepare(
        'SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX)
           FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table AND INDEX_NAME=:index_name'
    );
    $stmt->execute(['table' => $table, 'index_name' => $index]);
    $columns = (string) $stmt->fetchColumn();
    $check('tenant_unique_' . $table, str_starts_with($columns, 'company_id,'), $columns);
}

$adminAccess = $pdo->query(
    "SELECT COUNT(DISTINCT uc.company_id)
       FROM biomie_user_companies uc
       JOIN biomie_users u ON u.id=uc.user_id
      WHERE u.role='admin' AND u.is_active=1 AND uc.can_admin=1"
)->fetchColumn();
$check('administrator_can_manage_all_companies', (int) $adminAccess === 5, $adminAccess);

$stuckRuns = $pdo->query(
    "SELECT COUNT(*) FROM biomie_sync_runs
      WHERE status='running' AND started_at<DATE_SUB(NOW(), INTERVAL 1 HOUR)"
)->fetchColumn();
$check('no_stale_running_syncs', (int) $stuckRuns === 0, $stuckRuns);

$cipher = new SecretCipher((string) $config->get('app.secret_key', ''));
$probe = 'tenant-secret-probe';
$encrypted = $cipher->encrypt($probe);
$check('secret_cipher_roundtrip', $cipher->decrypt($encrypted) === $probe);

$factory = new OmieClientFactory($pdo, $logger, $config, new Cache(dirname(__DIR__) . '/storage/cache'));
$factoryResults = [];
$webhookFactoryResults = [];
$webhookTokens = [];
$cipher = new SecretCipher((string) $config->get('app.secret_key', ''));
foreach ($companies as $company) {
    if (!(bool) $company['ativo']) {
        continue;
    }
    try {
        $factory->forCompany((int) $company['id']);
        $factoryResults[(string) $company['slug']] = true;
    } catch (Throwable) {
        $factoryResults[(string) $company['slug']] = false;
    }

    $tokenStmt = $pdo->prepare(
        "SELECT webhook_token_encrypted
           FROM biomie_company_integrations
          WHERE company_id=:company_id AND provider='omie' AND active=1
          LIMIT 1"
    );
    $tokenStmt->execute(['company_id' => (int) $company['id']]);
    $encryptedToken = trim((string) $tokenStmt->fetchColumn());
    try {
        $token = $encryptedToken !== '' ? $cipher->decrypt($encryptedToken) : '';
        $webhookTokens[(string) $company['slug']] = $token;
        $friendlySlug = strtolower((string) $company['slug']);
        $resolvedCompanyId = $factory->resolveWebhookCompanyIdForSlug(
            $friendlySlug,
            $token
        );
        $webhookFactoryResults[(string) $company['slug']] = $resolvedCompanyId === (int) $company['id'];
    } catch (Throwable) {
        $webhookFactoryResults[(string) $company['slug']] = false;
    }
}
$check('factory_resolves_all_active_companies', !in_array(false, $factoryResults, true), $factoryResults);
$check(
    'factory_resolves_all_friendly_webhook_routes',
    count($webhookFactoryResults) === 5 && !in_array(false, $webhookFactoryResults, true),
    $webhookFactoryResults
);
$check(
    'webhook_slug_token_pair_fails_closed',
    $factory->resolveWebhookCompanyIdForSlug(
        'expert',
        (string) ($webhookTokens['ERGOOFFICE'] ?? '')
    ) === null
        && $factory->resolveWebhookCompanyIdForSlug('expert', str_repeat('0', 64)) === null
);

$omieClientProbe = (new ReflectionClass(OmieClient::class))->newInstanceWithoutConstructor();
$emptyPageMethod = new ReflectionMethod($omieClientProbe, 'emptyPageResponse');
$emptyPage = $emptyPageMethod->invoke(
    $omieClientProbe,
    'ListarPedidos',
    ['faultstring' => 'ERROR: NÃ£o existem registros para a pÃ¡gina [1]!'],
    ['pagina' => 1]
);
$check(
    'omie_empty_page_mojibake_is_zero_result',
    is_array($emptyPage)
        && ($emptyPage['pedido_venda_produto'] ?? null) === []
        && (int) ($emptyPage['total_de_paginas'] ?? -1) === 0,
    $emptyPage
);

$retryAfterMethod = new ReflectionMethod($omieClientProbe, 'retryAfterSecondsFromMessage');
$retryAfter = $retryAfterMethod->invoke(
    $omieClientProbe,
    'ERROR: Consumo redundante detectado. Aguarde 59 segundos para tentar novamente (REDUNDANT).'
);
$check('omie_redundant_wait_hint_is_parsed', $retryAfter === 59, $retryAfter);

$chunkAttempts = 0;
try {
    (new ChunkedWindowRunner($logger))->walk(
        '2026-06-20',
        '2026-06-20',
        static function () use (&$chunkAttempts): array {
            $chunkAttempts++;
            throw new RuntimeException('Consumo redundante detectado (REDUNDANT).');
        }
    );
    $check('chunked_window_does_not_repeat_redundant_call', false, $chunkAttempts);
} catch (RuntimeException) {
    $check('chunked_window_does_not_repeat_redundant_call', $chunkAttempts === 1, $chunkAttempts);
}

TenantContext::bootForCompany($pdo, 2, 'licitacao');
$check(
    'explicit_expert_context',
    TenantContext::companyId() === 2
        && TenantContext::canal() === 'licitacao'
        && TenantContext::sqlFilter('n') === 'n.company_id = 2'
);

try {
    TenantCliContext::boot($pdo, ['probe.php']);
    $check('cli_context_requires_company', false, 'context_booted_without_company');
} catch (InvalidArgumentException $exception) {
    $check(
        'cli_context_requires_company',
        str_contains($exception->getMessage(), '--company='),
        $exception->getMessage()
    );
}

$classifier = new ChannelClassifierService($pdo, 1);
$classified = $classifier->classify('Equipe Licitacao', 'pregao eletrônico', '');
$check('channel_classifier_uses_rules', $classified['channel'] === 'licitacao', $classified);

$tempDir = sys_get_temp_dir() . '/biomie-multitenant-' . bin2hex(random_bytes(4));
mkdir($tempDir, 0777, true);
try {
    TenantContext::bootForCompany($pdo, 1, 'varejo');
    $cache = new Cache($tempDir);
    $cache->put('probe', ['company' => 1]);
    TenantContext::bootForCompany($pdo, 2, 'licitacao');
    $cache->put('probe', ['company' => 2]);
    $company2 = $cache->read('probe');
    TenantContext::bootForCompany($pdo, 1, 'varejo');
    $company1 = $cache->read('probe');
    $check(
        'cache_isolated_by_company_and_channel',
        ($company1['company'] ?? null) === 1 && ($company2['company'] ?? null) === 2,
        ['company_1' => $company1, 'company_2' => $company2]
    );

    $limiter = new OmieRateLimiter($cache, $logger, 'test-' . bin2hex(random_bytes(8)), 'cli');
    $limiter->registerServerCooldown(2, 'test');
    $startedAt = microtime(true);
    try {
        $limiter->throttle('ListarVendedores', [
            'runtime' => ['max_sleep_ms' => 0],
        ]);
        $check('server_cooldown_blocks_without_network_wait', false, 'throttle_allowed');
    } catch (RuntimeException $exception) {
        $check(
            'server_cooldown_blocks_without_network_wait',
            str_contains($exception->getMessage(), 'Rate limit da Omie ativo')
                && (microtime(true) - $startedAt) < 1,
            $exception->getMessage()
        );
    }
} finally {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tempDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        if ($entry->isDir()) {
            @rmdir($entry->getPathname());
        } else {
            @unlink($entry->getPathname());
        }
    }
    @rmdir($tempDir);
}

// ── ÚNICA seção MUTÁVEL: valida que a UNIQUE inclui company_id (mesma chave de
// negócio permitida em empresas distintas). ESCRITA ⇒ vai para o test_db, jamais
// para a conexão de produção acima. O schema replicado prova a mesma propriedade.
$writePdo = test_pdo();
$writePdo->beginTransaction();
try {
    $insert = $writePdo->prepare(
        "INSERT INTO biomie_sales_targets_monthly
            (company_id, vendor_id, vendor_name, `year_month`, target_amount, source)
         VALUES (:company_id, 'tenant-probe-vendor', 'Probe', '2099-12', 1, 'manual')"
    );
    $insert->execute(['company_id' => 1]);
    $insert->execute(['company_id' => 2]);
    $count = $writePdo->query(
        "SELECT COUNT(*) FROM biomie_sales_targets_monthly
          WHERE vendor_id='tenant-probe-vendor' AND `year_month`='2099-12'"
    )->fetchColumn();
    $check('same_business_key_allowed_across_companies', (int) $count === 2, $count);
    $writePdo->rollBack();
} catch (Throwable $exception) {
    if ($writePdo->inTransaction()) {
        $writePdo->rollBack();
    }
    $check('same_business_key_allowed_across_companies', false, $exception->getMessage());
}

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
