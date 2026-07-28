<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\Support\SecretCipher;

require_once dirname(__DIR__) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require dirname(__DIR__) . '/app/Bootstrap.php';
Bootstrap::init();

$config = new Config();
$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo = Database::connect($config, $connection);
$args = $argv ?? [];
$selector = '';
$filtered = [];
foreach ($args as $index => $arg) {
    if ($index === 0) {
        $filtered[] = $arg;
        continue;
    }
    if (str_starts_with((string) $arg, '--company=')) {
        $selector = trim(substr((string) $arg, strlen('--company=')));
        continue;
    }
    $filtered[] = $arg;
}
$args = array_values($filtered);
if ($selector === '') {
    fwrite(STDERR, "Informe --company=SLUG|ID.\n");
    exit(2);
}
$companyStmt = $pdo->prepare(
    'SELECT id, slug, canal_padrao, ativo
       FROM biomie_companies
      WHERE id = :id OR slug = :slug
      LIMIT 1'
);
$companyStmt->execute([
    'id' => ctype_digit($selector) ? (int) $selector : 0,
    'slug' => strtoupper($selector),
]);
$company = $companyStmt->fetch(PDO::FETCH_ASSOC);
if (!is_array($company)) {
    fwrite(STDERR, "Empresa nao encontrada: {$selector}.\n");
    exit(2);
}
$tenant = [
    'company_id' => (int) $company['id'],
    'slug' => (string) $company['slug'],
    'canal' => (string) ($company['canal_padrao'] ?: 'varejo'),
    'active' => (bool) $company['ativo'],
];
$command = strtolower((string) ($args[1] ?? 'status'));

if ($command === 'status') {
    $stmt = $pdo->prepare(
        "SELECT c.id, c.slug, c.cnpj, c.ativo,
                i.provider, i.active AS integration_active,
                (i.app_key <> '') AS has_app_key,
                (i.app_secret_encrypted IS NOT NULL AND i.app_secret_encrypted <> '') AS has_encrypted_secret,
                (i.webhook_token_hash IS NOT NULL AND i.webhook_token_hash <> '') AS has_webhook_token,
                i.last_used_at, i.last_success_at, i.last_error_at
           FROM biomie_companies c
           LEFT JOIN biomie_company_integrations i
             ON i.company_id = c.id AND i.provider = 'omie'
          WHERE c.id = :id"
    );
    $stmt->execute(['id' => $tenant['company_id']]);
    echo json_encode($stmt->fetch(PDO::FETCH_ASSOC) ?: [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit(0);
}

// ── REGISTER: insere/atualiza credenciais Omie para uma empresa nova ─────────
if ($command === 'register') {
    $appKey    = '';
    $appSecret = '';
    foreach ($args as $arg) {
        if (str_starts_with((string) $arg, '--app-key=')) {
            $appKey = substr((string) $arg, strlen('--app-key='));
        }
        if (str_starts_with((string) $arg, '--app-secret=')) {
            $appSecret = substr((string) $arg, strlen('--app-secret='));
        }
        if (str_starts_with((string) $arg, '--app-secret-env=')) {
            $envName = trim(substr((string) $arg, strlen('--app-secret-env=')));
            $envValue = $envName !== '' ? getenv($envName) : false;
            $appSecret = is_string($envValue) ? $envValue : '';
        }
    }

    if ($appKey === '' || $appSecret === '') {
        fwrite(STDERR, "Uso: php bin/company_integration.php register --company=SLUG --app-key=KEY --app-secret=SECRET\n");
        exit(2);
    }

    $cipher = new SecretCipher((string) $config->get('app.secret_key', ''));
    if (!$cipher->isAvailable()) {
        fwrite(STDERR, "BIOMIE_SECRET_KEY nao configurada; registro recusado.\n");
        exit(2);
    }

    $encrypted = $cipher->encrypt($appSecret);

    $pdo->beginTransaction();
    try {
        $upsert = $pdo->prepare(<<<'SQL'
            INSERT INTO biomie_company_integrations
                (company_id, provider, app_key, app_secret_encrypted, active, secret_rotated_at)
            VALUES
                (:company_id, 'omie', :app_key, :secret, 1, NOW())
            ON DUPLICATE KEY UPDATE
                app_key              = VALUES(app_key),
                app_secret_encrypted = VALUES(app_secret_encrypted),
                active               = 1,
                secret_rotated_at    = NOW()
        SQL);
        $upsert->execute([
            'company_id' => $tenant['company_id'],
            'app_key'    => $appKey,
            'secret'     => $encrypted,
        ]);
        if (in_array('--activate', $args, true)) {
            $activate = $pdo->prepare('UPDATE biomie_companies SET ativo = 1 WHERE id = :id');
            $activate->execute(['id' => $tenant['company_id']]);
        }
        if (in_array('--clear-legacy', $args, true)) {
            $clear = $pdo->prepare(
                "UPDATE biomie_companies
                    SET omie_app_key = '', omie_app_secret = '', omie_webhook_token = ''
                  WHERE id = :id"
            );
            $clear->execute(['id' => $tenant['company_id']]);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    echo json_encode([
        'ok'             => true,
        'company_id'     => $tenant['company_id'],
        'company_slug'   => $tenant['slug'],
        'secret_stored'  => str_starts_with($encrypted, 'v1:'),
        'activated' => in_array('--activate', $args, true) || $tenant['active'],
        'legacy_cleared' => in_array('--clear-legacy', $args, true),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit(0);
}

if ($command !== 'migrate-legacy') {
    fwrite(STDERR, "Uso: php bin/company_integration.php status|register|migrate-legacy --company=SLUG [--app-key=KEY] [--app-secret=SECRET] [--clear-legacy]\n");
    exit(2);
}

$cipher = new SecretCipher((string) $config->get('app.secret_key', ''));
if (!$cipher->isAvailable()) {
    fwrite(STDERR, "BIOMIE_SECRET_KEY nao configurada; migracao recusada.\n");
    exit(2);
}

$stmt = $pdo->prepare(
    'SELECT omie_app_key, omie_app_secret, omie_webhook_token
       FROM biomie_companies
      WHERE id = :id'
);
$stmt->execute(['id' => $tenant['company_id']]);
$legacy = $stmt->fetch(PDO::FETCH_ASSOC);
if (!is_array($legacy) || trim((string) ($legacy['omie_app_key'] ?? '')) === '') {
    fwrite(STDERR, "Credenciais legadas nao encontradas para a empresa.\n");
    exit(2);
}

$encrypted = $cipher->encrypt((string) ($legacy['omie_app_secret'] ?? ''));
$webhookToken = trim((string) ($legacy['omie_webhook_token'] ?? ''));
$pdo->beginTransaction();
try {
    $upsert = $pdo->prepare(<<<'SQL'
        INSERT INTO biomie_company_integrations
            (company_id, provider, app_key, app_secret_encrypted, webhook_token_hash,
             active, secret_rotated_at)
        VALUES
            (:company_id, 'omie', :app_key, :secret, :webhook_hash, 1, NOW())
        ON DUPLICATE KEY UPDATE
            app_key = VALUES(app_key),
            app_secret_encrypted = VALUES(app_secret_encrypted),
            webhook_token_hash = VALUES(webhook_token_hash),
            active = 1,
            secret_rotated_at = NOW()
    SQL);
    $upsert->execute([
        'company_id' => $tenant['company_id'],
        'app_key' => (string) $legacy['omie_app_key'],
        'secret' => $encrypted,
        'webhook_hash' => $webhookToken !== '' ? hash('sha256', $webhookToken) : null,
    ]);

    if (in_array('--clear-legacy', $args, true)) {
        $clear = $pdo->prepare(
            "UPDATE biomie_companies
                SET omie_app_key = '', omie_app_secret = '', omie_webhook_token = ''
              WHERE id = :id"
        );
        $clear->execute(['id' => $tenant['company_id']]);
    }

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $exception;
}

echo json_encode([
    'ok' => true,
    'company_id' => $tenant['company_id'],
    'company_slug' => $tenant['slug'],
    'legacy_cleared' => in_array('--clear-legacy', $args, true),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
