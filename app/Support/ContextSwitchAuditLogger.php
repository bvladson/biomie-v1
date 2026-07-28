<?php

declare(strict_types=1);

namespace BIomie\Support;

use BIomie\RequestContext;
use PDO;

final class ContextSwitchAuditLogger
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function record(
        int $userId,
        ?CompanyContext $from,
        ?CompanyContext $to,
        string $status,
        ?string $reason = null,
    ): void {
        if (!in_array($status, ['success', 'blocked', 'error'], true)) {
            throw new \InvalidArgumentException('Status de auditoria inválido.');
        }

        $this->insert([
            'user_id' => $userId > 0 ? $userId : null,
            'from_context_id' => $from?->contextId,
            'from_context_type' => $from?->type,
            'from_company_id' => $from?->companyId,
            'to_context_id' => $to?->contextId,
            'to_context_type' => $to?->type,
            'to_company_id' => $to?->companyId,
            'status' => $status,
            'reason' => $reason,
        ]);
    }

    public function recordBlocked(
        int $userId,
        ?CompanyContext $from,
        ?string $targetType,
        ?int $targetCompanyId,
        string $reason,
        string $status = 'blocked',
    ): void {
        if (!in_array($targetType, [null, 'company', 'group'], true)) {
            $targetType = null;
        }
        $this->insert([
            'user_id' => $userId > 0 ? $userId : null,
            'from_context_id' => $from?->contextId,
            'from_context_type' => $from?->type,
            'from_company_id' => $from?->companyId,
            'to_context_id' => null,
            'to_context_type' => $targetType,
            'to_company_id' => $targetType === 'company' ? $targetCompanyId : null,
            'status' => in_array($status, ['blocked', 'error'], true) ? $status : 'blocked',
            'reason' => $reason,
        ]);
    }

    /**
     * @param array<string,mixed> $row
     */
    private function insert(array $row): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO biomie_context_switch_log (
                user_id,
                from_context_id, from_context_type, from_company_id,
                to_context_id, to_context_type, to_company_id,
                request_id, ip_address, user_agent, status, reason, created_at
             ) VALUES (
                :user_id,
                :from_context_id, :from_context_type, :from_company_id,
                :to_context_id, :to_context_type, :to_company_id,
                :request_id, :ip_address, :user_agent, :status, :reason, :created_at
             )'
        );
        $stmt->execute([
            'user_id' => $row['user_id'] ?? null,
            'from_context_id' => $row['from_context_id'] ?? null,
            'from_context_type' => $row['from_context_type'] ?? null,
            'from_company_id' => $row['from_company_id'] ?? null,
            'to_context_id' => $row['to_context_id'] ?? null,
            'to_context_type' => $row['to_context_type'] ?? null,
            'to_company_id' => $row['to_company_id'] ?? null,
            'request_id' => RequestContext::id(),
            'ip_address' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512),
            'status' => $row['status'],
            'reason' => isset($row['reason']) ? substr((string) $row['reason'], 0, 255) : null,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }
}
