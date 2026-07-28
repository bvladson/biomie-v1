<?php

declare(strict_types=1);

namespace BIomie\OmieStatus;

use BIomie\Bootstrap;
use BIomie\Cache;
use BIomie\Config;
use BIomie\Logger;
use BIomie\Omie\OmieClientFactory;
use BIomie\Support\SecretMask;
use BIomie\Support\TenantContext;
use BIomie\TableHealthService;
use PDO;
use Throwable;

/**
 * Credenciais + teste de conexão com a Omie (empresa ativa).
 *
 * Extraído de OmieStatusService (fachada) — motivo de mudança: tudo que toca
 * biomie_omie_status_checks e o POST de validação vive aqui.
 */
final class ConnectionProbe
{
    public function __construct(
        private readonly Config $config,
        private readonly PDO $pdo,
        private readonly Logger $logger,
        private readonly TableHealthService $tableHealth,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function getCredentialStatus(): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT app_key, app_secret_encrypted, active
               FROM biomie_company_integrations
              WHERE company_id = :company_id AND provider = 'omie'
              LIMIT 1"
        );
        $stmt->execute(['company_id' => TenantContext::companyId()]);
        $integration = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $appKey = (string) ($integration['app_key'] ?? '');
        $keyConfigured = SecretMask::isConfigured($appKey);
        $secretConfigured = trim((string) ($integration['app_secret_encrypted'] ?? '')) !== '';
        $active = (bool) ($integration['active'] ?? false);

        return [
            'app_key_configured' => $keyConfigured,
            'app_key_masked' => $keyConfigured ? SecretMask::maskSecret($appKey) : null,
            'app_secret_configured' => $secretConfigured,
            'credentials_valid' => ($active && $keyConfigured && $secretConfigured)
                ? $this->lastTestResult()
                : 'not_configured',
            'environment' => (string) $this->config->get('app.env', getenv('APP_ENV') ?: 'production'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function getApiStatus(): array
    {
        $lastTest = $this->getLastTestRow();

        return [
            'last_test_at' => $lastTest['created_at'] ?? null,
            'last_success_at' => ($lastTest !== null && ($lastTest['status'] ?? '') === 'ok') ? ($lastTest['created_at'] ?? null) : null,
            'last_failure_at' => ($lastTest !== null && ($lastTest['status'] ?? '') !== 'ok') ? ($lastTest['created_at'] ?? null) : null,
            'last_latency_ms' => isset($lastTest['latency_ms']) ? (int) $lastTest['latency_ms'] : null,
            'last_endpoint' => $lastTest['endpoint'] ?? null,
            'last_method' => $lastTest['method'] ?? null,
            'status' => $lastTest !== null ? (string) ($lastTest['status'] ?? 'unknown') : 'pending',
        ];
    }

    /**
     * Dispara uma chamada mínima à Omie e registra o resultado.
     *
     * @return array<string,mixed>
     */
    public function testConnection(?int $actorUserId = null): array
    {
        $startMs = microtime(true);
        $endpoint = (string) $this->config->get('omie.endpoints.clients', 'https://app.omie.com.br/api/v1/geral/clientes/');
        $method = 'ListarClientesResumido';

        try {
            $cache = new Cache(Bootstrap::rootPath('storage/cache'));
            $client = (new OmieClientFactory($this->pdo, $this->logger, $this->config, $cache))
                ->forCompany(TenantContext::companyId());

            $client->post($endpoint, $method, [
                'pagina' => 1,
                'registros_por_pagina' => 1,
            ]);

            $latencyMs = (int) round((microtime(true) - $startMs) * 1000);

            $this->recordTestResult('ok', $endpoint, $method, $latencyMs, 'Conexao validada com sucesso.', null, $actorUserId);

            return [
                'ok' => true,
                'status' => 'ok',
                'message' => 'Conexao Omie validada com sucesso.',
                'latency_ms' => $latencyMs,
                'tested_endpoint' => $endpoint,
                'tested_method' => $method,
                'tested_at' => date('Y-m-d H:i:s'),
            ];
        } catch (Throwable $e) {
            $latencyMs = (int) round((microtime(true) - $startMs) * 1000);
            $sanitizedError = SecretMask::sanitizeErrorMessage($e->getMessage(), 300);

            $this->recordTestResult('danger', $endpoint, $method, $latencyMs, $sanitizedError, $sanitizedError, $actorUserId);

            return [
                'ok' => false,
                'status' => 'danger',
                'error' => 'omie_connection_failed',
                'message' => 'Nao foi possivel validar a conexao com a Omie.',
                'detail' => $sanitizedError,
                'latency_ms' => $latencyMs,
                'recommended_action' => 'Verifique credenciais, internet do servidor e permissoes do aplicativo.',
            ];
        }
    }

    private function lastTestResult(): string
    {
        $row = $this->getLastTestRow();
        if ($row === null) {
            return 'not_tested';
        }

        return (string) ($row['status'] ?? 'unknown') === 'ok' ? 'valid' : 'failed';
    }

    /**
     * @return array<string,mixed>|null
     */
    private function getLastTestRow(): ?array
    {
        if (!$this->tableHealth->tableExists('biomie_omie_status_checks')) {
            return null;
        }

        try {
            $stmt = $this->pdo->prepare(
                "SELECT * FROM biomie_omie_status_checks
                  WHERE company_id = :company_id AND check_type='connection_test'
                  ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute(['company_id' => TenantContext::companyId()]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function recordTestResult(
        string $status,
        string $endpoint,
        string $method,
        int $latencyMs,
        string $message,
        ?string $errorMessage,
        ?int $actorUserId,
    ): void {
        if (!$this->tableHealth->tableExists('biomie_omie_status_checks')) {
            return;
        }

        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO biomie_omie_status_checks
                 (company_id, check_type, status, endpoint, method, latency_ms, message, error_code, error_message_sanitized, actor_user_id, created_at)
                 VALUES (:company_id, 'connection_test', :status, :endpoint, :method, :latency_ms, :message, :error_code, :error_msg, :actor, NOW())"
            );
            $stmt->execute([
                'company_id' => TenantContext::companyId(),
                'status' => $status,
                'endpoint' => $endpoint,
                'method' => $method,
                'latency_ms' => $latencyMs,
                'message' => $message,
                'error_code' => $errorMessage !== null ? 'omie_connection_failed' : null,
                'error_msg' => $errorMessage,
                'actor' => $actorUserId,
            ]);
        } catch (Throwable $e) {
            $this->logger->warning('ConnectionProbe: falha ao registrar teste.', [
                'message' => $e->getMessage(),
            ]);
        }
    }
}
