<?php

declare(strict_types=1);

use BIomie\Admin\AdminLogService;
use BIomie\Auth;
use BIomie\Database;
use BIomie\Http;
use BIomie\RequestContext;
use BIomie\SchemaCapabilities;
use BIomie\Support\SecretCipher;

require __DIR__ . '/bootstrap.php';

/** @var BIomie\Config $config */
/** @var BIomie\Logger $logger */
Auth::requireCapability('settings.manage', true);

$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo = Database::connect($config, $connection);

if (!SchemaCapabilities::columnExists($pdo, 'biomie_company_integrations', 'webhook_token_encrypted')) {
    Http::json([
        'ok' => false,
        'error' => 'migration_required',
        'message' => 'Execute a migration de gerenciamento de webhooks.',
        'recommended_command' => 'php bin/schema_migrate.php migrate',
        'request_id' => RequestContext::id(),
    ], 409);
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'GET') {
    Http::json([
        'ok' => true,
        'base_url' => webhookBaseUrl($config),
        'companies' => listWebhookCompanies($pdo, $config),
        'request_id' => RequestContext::id(),
    ]);
}

if ($method !== 'POST') {
    Http::methodNotAllowed(['GET', 'POST']);
}

Http::enforceCsrf($logger);

$raw = file_get_contents('php://input');
$body = is_string($raw) ? json_decode($raw, true) : null;
$body = is_array($body) ? $body : $_POST;
$action = strtolower(trim((string) ($body['action'] ?? 'rotate')));
$companyId = (int) ($body['company_id'] ?? 0);

if (!in_array($action, ['rotate', 'rotate_all'], true)) {
    Http::validationError('Acao de webhook invalida.');
}
if ($action === 'rotate' && $companyId <= 0) {
    Http::validationError('Selecione uma empresa para rotacionar o webhook.');
}

$sql = <<<'SQL'
    SELECT c.id, c.slug
      FROM biomie_companies c
      JOIN biomie_company_integrations i
        ON i.company_id = c.id
       AND i.provider = 'omie'
       AND i.active = 1
     WHERE c.ativo = 1
SQL;
$params = [];
if ($action === 'rotate') {
    $sql .= ' AND c.id = :company_id';
    $params['company_id'] = $companyId;
}
$sql .= ' ORDER BY c.id';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$companies = $stmt->fetchAll(PDO::FETCH_ASSOC);
if ($companies === []) {
    Http::validationError('Empresa ativa com integracao Omie nao encontrada.');
}

$cipher = new SecretCipher((string) $config->get('app.secret_key', ''));
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

$rotated = [];
$pdo->beginTransaction();
try {
    foreach ($companies as $company) {
        $token = bin2hex(random_bytes(32));
        $update->execute([
            'token_hash' => hash('sha256', $token),
            'token_encrypted' => $cipher->encrypt($token),
            'company_id' => (int) $company['id'],
        ]);
        $rotated[] = [
            'company_id' => (int) $company['id'],
            'slug' => (string) $company['slug'],
            'endpoint' => webhookEndpoint($config, (string) $company['slug'], $token),
        ];
    }
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $exception;
}

(new AdminLogService($pdo, $logger))->log('omie_webhook_rotated', null, [
    'company_ids' => array_column($rotated, 'company_id'),
    'count' => count($rotated),
]);

Http::json([
    'ok' => true,
    'message' => count($rotated) === 1
        ? 'Endpoint de webhook rotacionado.'
        : 'Endpoints de webhook rotacionados para todas as empresas.',
    'companies' => $rotated,
    'request_id' => RequestContext::id(),
]);

/**
 * @return list<array<string,mixed>>
 */
function listWebhookCompanies(PDO $pdo, BIomie\Config $config): array
{
    $stmt = $pdo->query(<<<'SQL'
        SELECT c.id, c.slug, c.nome_fantasia, c.cnpj,
               i.webhook_token_hash, i.webhook_token_encrypted,
               i.webhook_rotated_at, i.webhook_last_received_at,
               i.last_success_at, i.last_error_at
          FROM biomie_companies c
          JOIN biomie_company_integrations i
            ON i.company_id = c.id
           AND i.provider = 'omie'
         WHERE c.ativo = 1
           AND i.active = 1
         ORDER BY c.id
    SQL);

    $cipher = new SecretCipher((string) $config->get('app.secret_key', ''));
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $token = '';
        $encrypted = trim((string) ($row['webhook_token_encrypted'] ?? ''));
        if ($encrypted !== '') {
            try {
                $candidate = $cipher->decrypt($encrypted);
                if (hash_equals((string) ($row['webhook_token_hash'] ?? ''), hash('sha256', $candidate))) {
                    $token = $candidate;
                }
            } catch (Throwable) {
                $token = '';
            }
        }

        $rows[] = [
            'company_id' => (int) $row['id'],
            'slug' => (string) $row['slug'],
            'name' => (string) ($row['nome_fantasia'] ?? $row['slug']),
            'cnpj' => $row['cnpj'],
            'configured' => trim((string) ($row['webhook_token_hash'] ?? '')) !== '',
            'endpoint_available' => $token !== '',
            'endpoint' => $token !== '' ? webhookEndpoint($config, (string) $row['slug'], $token) : null,
            'rotated_at' => $row['webhook_rotated_at'],
            'last_received_at' => $row['webhook_last_received_at'],
            'last_success_at' => $row['last_success_at'],
            'last_error_at' => $row['last_error_at'],
        ];
    }

    return $rows;
}

function webhookBaseUrl(BIomie\Config $config): string
{
    $configured = rtrim(trim((string) $config->get('app.url', '')), '/');
    if ($configured !== '') {
        return $configured;
    }

    $https = strtolower((string) ($_SERVER['HTTPS'] ?? '')) === 'on';
    $scheme = $https ? 'https' : 'http';
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));

    return $scheme . '://' . $host;
}

function webhookEndpoint(BIomie\Config $config, string $slug, string $token): string
{
    $friendlySlug = strtolower(trim($slug));

    return webhookBaseUrl($config)
        . '/webhooks/omie/'
        . rawurlencode($friendlySlug)
        . '/'
        . rawurlencode($token);
}
