<?php

declare(strict_types=1);

namespace BIomie\Canonical;

use BIomie\Canonical\Support\CanonicalJsonCodec;
use BIomie\Support;
use Closure;
use PDO;

final class CanonicalDivergenceRepository
{
    public function __construct(
        private readonly Closure $pdoResolver,
        private readonly Closure $ensureSchema,
        private readonly CanonicalJsonCodec $jsonCodec,
        private readonly int $companyId,
    ) {
    }

    /**
     * @param array<string,mixed>|null $contexto
     * @param array<string,mixed> $meta
     */
    public function log(
        string $tipo,
        string $referencia,
        string $descricao,
        ?array $contexto = null,
        array $meta = [],
    ): void {
        ($this->ensureSchema)();
        $pdo = $this->pdo();

        $ruleCode = trim((string) ($meta['rule_code'] ?? $tipo));
        if ($ruleCode === '') {
            $ruleCode = $tipo;
        }

        $existingStmt = $pdo->prepare(
            'SELECT id, occurrence_count, operational_status
             FROM biomie_divergencias
             WHERE company_id = :company_id
               AND rule_code = :rule_code
               AND referencia = :ref
             LIMIT 1'
        );
        $existingStmt->execute([
            'company_id' => $this->companyId,
            'rule_code' => $ruleCode,
            'ref' => $referencia,
        ]);
        $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

        $contextJson = $contexto !== null ? $this->jsonCodec->encode($contexto) : null;
        $severity = $this->normalizeSeverity((string) ($meta['severity'] ?? 'medium'));
        $entityType = trim((string) ($meta['entity_type'] ?? 'generic')) ?: 'generic';
        $sourceDomain = trim((string) ($meta['source_domain'] ?? 'reconciliation')) ?: 'reconciliation';
        $sourceOrigin = $this->normalizeNullableString($meta['source_origin'] ?? null);
        $suggestedAction = $this->normalizeNullableString($meta['suggested_action'] ?? null);
        $periodStart = $this->normalizeNullableDate($meta['period_start'] ?? null);
        $periodEnd = $this->normalizeNullableDate($meta['period_end'] ?? null);

        if (is_array($existing)) {
            $occurrenceCount = ((int) ($existing['occurrence_count'] ?? 0)) + 1;
            $previousStatus = (string) ($existing['operational_status'] ?? 'aberta');
            $status = in_array($previousStatus, ['resolvida', 'ignorada_regra'], true)
                ? 'reincidente'
                : ($occurrenceCount > 1 ? 'reincidente' : 'aberta');

            $stmt = $pdo->prepare(<<<'SQL'
                UPDATE biomie_divergencias
                SET tipo = :tipo,
                    severity = :severity,
                    entity_type = :entity_type,
                    source_domain = :source_domain,
                    source_origin = :source_origin,
                    descricao = :descricao,
                    contexto_json = :contexto_json,
                    suggested_action = :suggested_action,
                    operational_status = :operational_status,
                    last_detected_at = NOW(),
                    last_seen_period_start = :last_seen_period_start,
                    last_seen_period_end = :last_seen_period_end,
                    occurrence_count = :occurrence_count,
                    resolvido_em = CASE WHEN :clear_resolution_1 = 1 THEN NULL ELSE resolvido_em END,
                    resolvido_por = CASE WHEN :clear_resolution_2 = 1 THEN NULL ELSE resolvido_por END,
                    resolution_note = CASE WHEN :clear_resolution_3 = 1 THEN NULL ELSE resolution_note END
                WHERE company_id = :company_id AND id = :id
            SQL);
            $clearResolution = in_array($previousStatus, ['resolvida', 'ignorada_regra'], true) ? 1 : 0;
            $stmt->execute([
                'company_id' => $this->companyId,
                'id' => (int) ($existing['id'] ?? 0),
                'tipo' => $tipo,
                'severity' => $severity,
                'entity_type' => $entityType,
                'source_domain' => $sourceDomain,
                'source_origin' => $sourceOrigin,
                'descricao' => $descricao,
                'contexto_json' => $contextJson,
                'suggested_action' => $suggestedAction,
                'operational_status' => $status,
                'last_seen_period_start' => $periodStart,
                'last_seen_period_end' => $periodEnd,
                'occurrence_count' => $occurrenceCount,
                'clear_resolution_1' => $clearResolution,
                'clear_resolution_2' => $clearResolution,
                'clear_resolution_3' => $clearResolution,
            ]);

            return;
        }

        $stmt = $pdo->prepare(<<<'SQL'
            INSERT INTO biomie_divergencias (
                company_id, tipo, rule_code, severity, entity_type, source_domain, source_origin,
                referencia, descricao, contexto_json, suggested_action,
                operational_status, first_detected_at, last_detected_at,
                last_seen_period_start, last_seen_period_end, occurrence_count,
                detectado_em
            ) VALUES (
                :company_id, :tipo, :rule_code, :severity, :entity_type, :source_domain, :source_origin,
                :referencia, :descricao, :contexto_json, :suggested_action,
                'aberta', NOW(), NOW(),
                :last_seen_period_start, :last_seen_period_end, 1,
                NOW()
            )
        SQL);
        $stmt->execute([
            'company_id' => $this->companyId,
            'tipo' => $tipo,
            'rule_code' => $ruleCode,
            'severity' => $severity,
            'entity_type' => $entityType,
            'source_domain' => $sourceDomain,
            'source_origin' => $sourceOrigin,
            'referencia' => $referencia,
            'descricao' => $descricao,
            'contexto_json' => $contextJson,
            'suggested_action' => $suggestedAction,
            'last_seen_period_start' => $periodStart,
            'last_seen_period_end' => $periodEnd,
        ]);
    }

    /**
     * @param list<string> $statuses
     * @return list<array<string,mixed>>
     */
    public function listQueue(array $statuses = [], ?string $severity = null, int $limit = 100): array
    {
        ($this->ensureSchema)();
        $pdo = $this->pdo();

        $sql = 'SELECT * FROM biomie_divergencias';
        $params = ['company_id' => $this->companyId];
        $conditions = ['company_id = :company_id'];

        if ($statuses !== []) {
            $placeholders = [];
            foreach (array_values($statuses) as $index => $status) {
                $status = $this->normalizeOperationalStatus((string) $status);
                $key = 'status_' . $index;
                $placeholders[] = ':' . $key;
                $params[$key] = $status;
            }
            $conditions[] = 'operational_status IN (' . implode(', ', $placeholders) . ')';
        }

        if ($severity !== null && $severity !== '') {
            $conditions[] = 'severity = :severity';
            $params['severity'] = $this->normalizeSeverity($severity);
        }

        $sql .= ' WHERE ' . implode(' AND ', $conditions);

        $sql .= ' ORDER BY FIELD(severity, \'critical\', \'high\', \'medium\', \'low\'), last_detected_at DESC, id DESC LIMIT :lim';

        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':lim', max(1, min($limit, 500)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<string,mixed>
     */
    public function summarizeQueue(): array
    {
        ($this->ensureSchema)();
        $pdo = $this->pdo();

        $byStatus = [
            'aberta' => 0,
            'reincidente' => 0,
            'resolvida' => 0,
            'ignorada_regra' => 0,
        ];
        $bySeverity = [
            'critical' => 0,
            'high' => 0,
            'medium' => 0,
            'low' => 0,
        ];

        $stmt = $pdo->prepare(
            "SELECT
                SUM(CASE WHEN operational_status = 'aberta' THEN 1 ELSE 0 END) AS status_aberta,
                SUM(CASE WHEN operational_status = 'reincidente' THEN 1 ELSE 0 END) AS status_reincidente,
                SUM(CASE WHEN operational_status = 'resolvida' THEN 1 ELSE 0 END) AS status_resolvida,
                SUM(CASE WHEN operational_status = 'ignorada_regra' THEN 1 ELSE 0 END) AS status_ignorada_regra,
                SUM(CASE WHEN severity = 'critical' THEN 1 ELSE 0 END) AS severity_critical,
                SUM(CASE WHEN severity = 'high' THEN 1 ELSE 0 END) AS severity_high,
                SUM(CASE WHEN severity = 'medium' THEN 1 ELSE 0 END) AS severity_medium,
                SUM(CASE WHEN severity = 'low' THEN 1 ELSE 0 END) AS severity_low,
                SUM(CASE WHEN operational_status IN ('aberta', 'reincidente') THEN 1 ELSE 0 END) AS active_total
             FROM biomie_divergencias
             WHERE company_id = :company_id"
        );
        $stmt->execute(['company_id' => $this->companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        foreach (array_keys($byStatus) as $status) {
            $byStatus[$status] = (int) ($row['status_' . $status] ?? 0);
        }
        foreach (array_keys($bySeverity) as $severity) {
            $bySeverity[$severity] = (int) ($row['severity_' . $severity] ?? 0);
        }
        $active = (int) ($row['active_total'] ?? 0);

        return [
            'by_status' => $byStatus,
            'by_severity' => $bySeverity,
            'active_total' => $active,
        ];
    }

    public function updateOperationalStatus(int $id, string $status, ?string $actor = null, ?string $note = null): void
    {
        ($this->ensureSchema)();
        $pdo = $this->pdo();

        $normalizedStatus = $this->normalizeOperationalStatus($status);
        $resolvedAt = in_array($normalizedStatus, ['resolvida', 'ignorada_regra'], true)
            ? date('Y-m-d H:i:s')
            : null;

        $stmt = $pdo->prepare(<<<'SQL'
            UPDATE biomie_divergencias
            SET operational_status = :operational_status,
                resolvido_em = :resolvido_em,
                resolvido_por = :resolvido_por,
                resolution_note = :resolution_note
            WHERE company_id = :company_id AND id = :id
        SQL);
        $stmt->execute([
            'company_id' => $this->companyId,
            'id' => $id,
            'operational_status' => $normalizedStatus,
            'resolvido_em' => $resolvedAt,
            'resolvido_por' => $normalizedStatus === 'resolvida' || $normalizedStatus === 'ignorada_regra'
                ? $this->normalizeNullableString($actor)
                : null,
            'resolution_note' => $this->normalizeNullableString($note),
        ]);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function open(int $limit = 50): array
    {
        return $this->listQueue(['aberta', 'reincidente'], null, $limit);
    }

    private function normalizeSeverity(string $severity): string
    {
        $severity = strtolower(trim($severity));
        if (in_array($severity, ['low', 'medium', 'high', 'critical'], true)) {
            return $severity;
        }

        return 'medium';
    }

    private function normalizeOperationalStatus(string $status): string
    {
        $status = strtolower(trim($status));
        if (in_array($status, ['aberta', 'reincidente', 'resolvida', 'ignorada_regra'], true)) {
            return $status;
        }

        return 'aberta';
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (!is_scalar($value) && $value !== null) {
            return null;
        }

        $normalized = trim((string) ($value ?? ''));
        return $normalized !== '' ? $normalized : null;
    }

    private function normalizeNullableDate(mixed $value): ?string
    {
        if (!is_scalar($value) && $value !== null) {
            return null;
        }

        return Support::formatDate($value !== null ? (string) $value : null);
    }

    private function pdo(): PDO
    {
        $pdo = ($this->pdoResolver)();
        if (!$pdo instanceof PDO) {
            throw new \RuntimeException('PDO canonico indisponivel.');
        }

        return $pdo;
    }
}
