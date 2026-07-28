<?php

declare(strict_types=1);

namespace BIomie;

use BIomie\Support\TenantContext;
use PDO;

final class FinanceCollectionActionRepository
{
    public const TABLE = 'biomie_finance_collection_actions';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function available(): bool
    {
        return SchemaCapabilities::tableExists($this->pdo, self::TABLE);
    }

    /** @param array<string,mixed> $action */
    public function record(array $action): int
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
            INSERT INTO biomie_finance_collection_actions (
                company_id, titulo_key, cliente_codigo_omie, vendedor_codigo_omie,
                actor_user_id, action_type, note, next_action_date, created_at
            ) VALUES (
                :company_id, :titulo_key, :cliente_codigo_omie, :vendedor_codigo_omie,
                :actor_user_id, :action_type, :note, :next_action_date, :created_at
            )
        SQL);
        $stmt->execute([
            'company_id' => TenantContext::companyId(),
            'titulo_key' => trim((string) ($action['titulo_key'] ?? '')),
            'cliente_codigo_omie' => $this->nullableString($action['cliente_codigo_omie'] ?? null),
            'vendedor_codigo_omie' => $this->nullableString($action['vendedor_codigo_omie'] ?? null),
            'actor_user_id' => $this->nullablePositiveInt($action['actor_user_id'] ?? null),
            'action_type' => trim((string) ($action['action_type'] ?? '')),
            'note' => $this->nullableString($action['note'] ?? null),
            'next_action_date' => $this->nullableString($action['next_action_date'] ?? null),
            'created_at' => $this->nullableString($action['created_at'] ?? null) ?? date('Y-m-d H:i:s'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return list<array<string,mixed>> */
    public function list(
        ?string $tituloKey = null,
        ?string $clienteCodigoOmie = null,
        ?string $vendedorCodigoOmie = null,
        int $limit = 100,
    ): array {
        $where = [TenantContext::sqlFilter('a')];
        $params = [];

        if ($this->nullableString($tituloKey) !== null) {
            $where[] = 'a.titulo_key = :titulo_key';
            $params['titulo_key'] = trim((string) $tituloKey);
        }
        if ($this->nullableString($clienteCodigoOmie) !== null) {
            $where[] = 'a.cliente_codigo_omie = :cliente_codigo_omie';
            $params['cliente_codigo_omie'] = trim((string) $clienteCodigoOmie);
        }
        if ($this->nullableString($vendedorCodigoOmie) !== null) {
            $where[] = 'a.vendedor_codigo_omie = :vendedor_codigo_omie';
            $params['vendedor_codigo_omie'] = trim((string) $vendedorCodigoOmie);
        }

        $stmt = $this->pdo->prepare(
            'SELECT a.id, a.company_id, a.actor_user_id, a.titulo_key, a.cliente_codigo_omie,
                    a.vendedor_codigo_omie, a.action_type, a.note,
                    a.next_action_date, a.created_at, u.username, u.full_name
             FROM ' . self::TABLE . ' a
             LEFT JOIN biomie_users u ON u.id = a.actor_user_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY a.id DESC
             LIMIT :limit'
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', max(1, min(500, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return $this->normalizeRows($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return list<array<string,mixed>> */
    public function between(string $startAt, string $endAt, int $limit = 10000): array
    {
        $tenantFilter = TenantContext::sqlFilter('a');
        $stmt = $this->pdo->prepare(
            'SELECT a.id, a.company_id, a.actor_user_id, a.titulo_key, a.cliente_codigo_omie,
                    a.vendedor_codigo_omie, a.action_type, a.note,
                    a.next_action_date, a.created_at, u.username, u.full_name
             FROM ' . self::TABLE . ' a
             LEFT JOIN biomie_users u ON u.id = a.actor_user_id
             WHERE ' . $tenantFilter . '
               AND a.created_at BETWEEN :start_at AND :end_at
             ORDER BY a.id ASC
             LIMIT :limit'
        );
        $stmt->bindValue(':start_at', $startAt);
        $stmt->bindValue(':end_at', $endAt);
        $stmt->bindValue(':limit', max(1, min(50000, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return $this->normalizeRows($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function normalizeRows(array $rows): array
    {
        $normalized = [];
        foreach ($rows as $row) {
            $normalized[] = [
                'id' => (int) ($row['id'] ?? 0),
                'company_id' => (int) ($row['company_id'] ?? 0),
                'actor_user_id' => $row['actor_user_id'] !== null ? (int) $row['actor_user_id'] : null,
                'actor_username' => (string) ($row['username'] ?? 'Sistema'),
                'actor_full_name' => (string) ($row['full_name'] ?? 'Sistema'),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'titulo_key' => (string) ($row['titulo_key'] ?? ''),
                'cliente_codigo_omie' => (string) ($row['cliente_codigo_omie'] ?? ''),
                'vendedor_codigo_omie' => (string) ($row['vendedor_codigo_omie'] ?? ''),
                'action_type' => (string) ($row['action_type'] ?? ''),
                'note' => (string) ($row['note'] ?? ''),
                'next_action_date' => (string) ($row['next_action_date'] ?? ''),
            ];
        }

        return $normalized;
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_scalar($value) && $value !== null) {
            return null;
        }
        $normalized = trim((string) ($value ?? ''));
        return $normalized !== '' ? $normalized : null;
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        $normalized = (int) $value;
        return $normalized > 0 ? $normalized : null;
    }
}
