<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\SchemaCapabilities;
use BIomie\Support\SecretCipher;

require_once dirname(__DIR__) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require dirname(__DIR__) . '/app/Bootstrap.php';
Bootstrap::init();

$config = new Config();
$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo = Database::connect($config, $connection);
$args = array_slice($_SERVER['argv'] ?? [], 1);
$command = strtolower((string) ($args[0] ?? 'list'));
$companySelector = optionValue($args, 'company') ?? 'all';
$baseUrl = rtrim(
    optionValue($args, 'base-url') ?? (string) $config->get('app.url', ''),
    '/'
);

if (!SchemaCapabilities::columnExists($pdo, 'biomie_company_integrations', 'webhook_token_encrypted')) {
    fwrite(STDERR, "Migration de webhooks pendente. Execute: php bin/schema_migrate.php migrate\n");
    exit(2);
}
if ($baseUrl === '') {
    fwrite(STDERR, "APP_URL nao configurada. Informe --base-url=https://dominio/caminho\n");
    exit(2);
}
if (!in_array($command, ['list', 'rotate'], true)) {
    fwrite(STDERR, "Uso: php bin/company_webhooks.php list|rotate [--company=all|SLUG|ID]\n");
    exit(2);
}

$sql = <<<'SQL'
    SELECT c.id, c.slug, c.nome_fantasia, c.cnpj,
           i.webhook_token_hash, i.webhook_token_encrypted,
           i.webhook_rotated_at, i.webhook_last_received_at
      FROM biomie_companies c
      JOIN biomie_company_integrations i
        ON i.company_id = c.id
       AND i.provider = 'omie'
       AND i.active = 1
     WHERE c.ativo = 1
SQL;
$params = [];
if (strtolower($companySelector) !== 'all') {
    $sql .= ' AND (c.id = :company_id OR c.slug = :company_slug)';
    $params = [
        'company_id' => ctype_digit($companySelector) ? (int) $companySelector : 0,
        'company_slug' => strtoupper(str_replace('-', '_', $companySelector)),
    ];
}
$sql .= ' ORDER BY c.id';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$companies = $stmt->fetchAll(PDO::FETCH_ASSOC);
if ($companies === []) {
    fwrite(STDERR, "Nenhuma empresa ativa com integracao Omie encontrada.\n");
    exit(2);
}

$cipher = new SecretCipher((string) $config->get('app.secret_key', ''));
if (!$cipher->isAvailable()) {
    fwrite(STDERR, "BIOMIE_SECRET_KEY nao configurada; operacao recusada.\n");
    exit(2);
}

if ($command === 'rotate') {
    $update = $pdo->prepare(<<<'SQL'
        UPDATE biomie_company_integrations
           SET webhook_token_hash = :token_hash,
               webhook_token_encrypted = :token_encrypted,
               webhook_rotated_at = NOW(),
               updated_at = NOW()
         WHERE company_id = :company_id
           AND provider = 'omie'
           AND active = 1
    SQL);

    $pdo->beginTransaction();
    try {
        foreach ($companies as &$company) {
            $token = bin2hex(random_bytes(32));
            $encryptedToken = $cipher->encrypt($token);
            $update->execute([
                'token_hash' => hash('sha256', $token),
                'token_encrypted' => $encryptedToken,
                'company_id' => (int) $company['id'],
            ]);
            $company['webhook_token_encrypted'] = $encryptedToken;
            $company['webhook_token_hash'] = hash('sha256', $token);
            $company['webhook_rotated_at'] = date('Y-m-d H:i:s');
        }
        unset($company);
        $pdo->commit();
    } catch (Throwable $exception) {
        unset($company);
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

$rows = [];
foreach ($companies as $company) {
    $token = '';
    $encrypted = trim((string) ($company['webhook_token_encrypted'] ?? ''));
    if ($encrypted !== '') {
        try {
            $candidate = $cipher->decrypt($encrypted);
            if (hash_equals((string) ($company['webhook_token_hash'] ?? ''), hash('sha256', $candidate))) {
                $token = $candidate;
            }
        } catch (Throwable) {
            $token = '';
        }
    }

    $slug = strtolower(trim((string) $company['slug']));
    $rows[] = [
        'company_id' => (int) $company['id'],
        'slug' => (string) $company['slug'],
        'name' => (string) ($company['nome_fantasia'] ?? $company['slug']),
        'cnpj' => $company['cnpj'],
        'configured' => $token !== '',
        'endpoint' => $token !== ''
            ? $baseUrl . '/webhooks/omie/' . rawurlencode($slug) . '/' . rawurlencode($token)
            : null,
        'rotated_at' => $company['webhook_rotated_at'],
        'last_received_at' => $company['webhook_last_received_at'],
    ];
}

echo json_encode([
    'ok' => true,
    'command' => $command,
    'base_url' => $baseUrl,
    'companies' => $rows,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;

/**
 * @param list<string> $args
 */
function optionValue(array $args, string $name): ?string
{
    $prefix = '--' . $name . '=';
    foreach ($args as $arg) {
        if (str_starts_with($arg, $prefix)) {
            $value = trim(substr($arg, strlen($prefix)));
            return $value !== '' ? $value : null;
        }
    }

    return null;
}
