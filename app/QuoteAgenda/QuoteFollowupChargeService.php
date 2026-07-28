<?php

declare(strict_types=1);

namespace BIomie\QuoteAgenda;

use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * AGENDA DE ORÇAMENTOS — Fase 5: ação "Cobrança realizada", idempotente.
 *
 * Contrato (alinhado ao draft IETF Idempotency-Key e à prática da Stripe):
 *
 *  | Situação                          | Resposta                        |
 *  |-----------------------------------|---------------------------------|
 *  | Chave ausente                     | 400 missing_idempotency_key     |
 *  | Primeira cobrança concluída       | 200                             |
 *  | Retry, mesma chave e fingerprint  | 200 + resultado ARMAZENADO      |
 *  | Mesma chave, requisição diferente | 422 idempotency_key_reused      |
 *  | Mesma chave ainda processando     | 409 idempotency_in_progress     |
 *  | Estado da agenda já mudou         | 409 stale_followup_state        |
 *  | Followup de outra empresa         | 404                             |
 *
 * DUPLO CLIQUE: o Idempotency-Key protege o RETRY da mesma operação, mas dois
 * cliques geram DUAS chaves distintas. Por isso o caller envia também
 * `expected_charge_count`; dentro da transação, após SELECT ... FOR UPDATE,
 * divergência ⇒ 409 stale_followup_state. Assim duas chaves concorrendo pelo
 * mesmo botão não produzem duas cobranças.
 *
 * FUSO: auditoria e timestamps em UTC (NOW() do banco);
 *       next_followup_on é DATE calculado em Fortaleza pela FollowupDatePolicy.
 *
 * NUNCA consulta o Omie.
 */
final class QuoteFollowupChargeService
{
    public const OPERATION = 'quote_followup_charge:v1';

    private const T_SCHEDULE = 'biomie_sales_quote_followup_schedule';
    private const T_AUDIT    = 'biomie_quote_followup_charge_audit';
    private const T_POLICY   = 'biomie_quote_followup_policy';

    /** Espera LIMITADA pela reserva concorrente; estourou ⇒ 409 + Retry-After. */
    private const LOCK_WAIT_SECONDS = 3;

    /**
     * Colunas de snapshot de identidade (Fase 7) podem não existir ainda.
     * Mesmo padrão de `CanonicalWriteRepository::tryUpdateOrderScope`: o código
     * funciona ANTES e DEPOIS da migration, tornando a ordem de deploy segura.
     */
    private ?bool $snapshotColumnsExist = null;

    public function __construct(
        private readonly PDO $pdo,
        private readonly int $companyId,
    ) {
    }

    /**
     * Fingerprint determinístico: tudo que altera o resultado.
     *
     * @param array<string,mixed> $input
     */
    public static function fingerprint(int $companyId, array $input): string
    {
        $parts = [
            'operation'             => self::OPERATION,
            'company_id'            => $companyId,
            'followup_id'           => (string) ($input['followup_id'] ?? ''),
            'omie_order_id'         => (string) ($input['omie_order_id'] ?? ''),
            'manager_user_id'       => (string) ($input['manager_user_id'] ?? ''),
            'expected_charge_count' => (string) ($input['expected_charge_count'] ?? ''),
            'note'                  => (string) ($input['note'] ?? ''),
        ];

        return hash('sha256', json_encode($parts, JSON_UNESCAPED_UNICODE) ?: '');
    }

    /**
     * @param array<string,mixed> $input followup_id|omie_order_id, manager_user_id,
     *        manager_name (só snapshot — fora do fingerprint), expected_charge_count, note
     * @return array{status:int,body:array<string,mixed>,replayed:bool}
     */
    public function charge(string $idempotencyKey, array $input): array
    {
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '') {
            return $this->fail(400, 'missing_idempotency_key', 'Cabeçalho Idempotency-Key é obrigatório.');
        }
        if (!array_key_exists('expected_charge_count', $input)) {
            return $this->fail(400, 'missing_expected_charge_count', 'expected_charge_count é obrigatório (proteção contra duplo clique).');
        }

        $fingerprint = self::fingerprint($this->companyId, $input);

        // ── Replay / conflito: decidido ANTES de abrir a transação de escrita ────
        $existing = $this->findAudit($idempotencyKey);
        if ($existing !== null) {
            return $this->resolveExisting($existing, $fingerprint);
        }

        $started = !$this->pdo->inTransaction();
        if ($started) { $this->pdo->beginTransaction(); }

        try {
            // 1. Reserva a chave (UNIQUE serializa corridas de mesma chave).
            $reserve = $this->reserveKey($idempotencyKey, $fingerprint, $input);
            $auditId = $reserve['id'];
            if ($auditId === null) {
                // ROLLBACK EXPLÍCITO antes de responder — nada da tentativa persiste.
                if ($started && $this->pdo->inTransaction()) { $this->pdo->rollBack(); }

                // Espera limitada estourou: a outra requisição segue aberta.
                if ($reserve['conflict'] === 'in_progress') {
                    return $this->fail(409, 'idempotency_in_progress',
                        'Operação com esta chave ainda está em andamento.', ['retry_after' => 1]);
                }

                // Duplicidade: a primeira commitou ⇒ replay (ou 422 se fingerprint difere).
                $again = $this->findAudit($idempotencyKey);

                return $again !== null
                    ? $this->resolveExisting($again, $fingerprint)
                    : $this->fail(409, 'idempotency_in_progress',
                        'Operação com esta chave ainda está em andamento.', ['retry_after' => 1]);
            }

            // 2. Trava o followup.
            $row = $this->lockFollowup($input);
            if ($row === null) {
                if ($started && $this->pdo->inTransaction()) { $this->pdo->rollBack(); }

                // 404 não persiste chave (rollback já desfez a reserva).
                return $this->fail(404, 'followup_not_found', 'Acompanhamento não encontrado nesta empresa.');
            }

            // 3. Estado esperado (protege duplo clique com chaves diferentes).
            $currentCount = (int) $row['charge_count'];
            if ((int) $input['expected_charge_count'] !== $currentCount) {
                if ($started && $this->pdo->inTransaction()) { $this->pdo->rollBack(); }

                return $this->fail(409, 'stale_followup_state',
                    sprintf('A agenda já mudou: esperado charge_count=%d, atual=%d.', (int) $input['expected_charge_count'], $currentCount),
                    ['current_charge_count' => $currentCount]);
            }

            if ($row['resolved_at'] !== null) {
                if ($started && $this->pdo->inTransaction()) { $this->pdo->rollBack(); }

                return $this->fail(409, 'followup_already_resolved', 'Este orçamento já foi encerrado.');
            }

            // 4. Incrementa EXATAMENTE uma vez e calcula o próximo marco absoluto.
            $policy = FollowupDatePolicy::fromPolicyRow($this->loadPolicy());
            $prevStep = (int) $row['followup_step'];
            $newStep  = $policy->nextStep($prevStep);
            $createdAt = new DateTimeImmutable((string) $row['quote_created_at'], FollowupDatePolicy::timezone());
            $prevDue = $row['next_followup_on'] !== null ? (string) $row['next_followup_on'] : null;
            $newDue  = $policy->dueOn($createdAt, $newStep);
            $newCount = $currentCount + 1;

            $this->pdo->prepare(
                'UPDATE ' . self::T_SCHEDULE . '
                    SET charge_count = charge_count + 1,
                        followup_step = :step,
                        next_followup_on = :due,
                        last_manager_charge_at = NOW(),
                        last_manager_user_id = :uid,
                        updated_at = NOW()
                  WHERE id = :id'
            )->execute([
                'step' => $newStep,
                'due'  => $newDue,
                'uid'  => $input['manager_user_id'] ?? null,
                'id'   => (int) $row['id'],
            ]);

            // 5. Resposta + auditoria completa.
            $body = [
                'ok' => true,
                'followup_id' => (int) $row['id'],
                'omie_order_id' => (string) $row['omie_order_id'],
                'charge_count' => $newCount,
                'followup_step' => $newStep,
                'next_followup_on' => $newDue,
                'is_critical' => $policy->isCritical($newStep),
                'urgency' => $policy->urgency($newDue, $newStep),
                'urgency_rank' => self::urgencyRank($policy->urgency($newDue, $newStep)),
            ];

            // SNAPSHOT de identidade: o pedido pode ser transferido depois, então o
            // histórico não pode depender do vendedor ATUAL do canônico.
            $sellerIdAtCharge = ($row['seller_id'] ?? null) !== null ? (string) $row['seller_id'] : null;
            $sellerNameAtCharge = $this->sellerNameNow($sellerIdAtCharge);

            $withSnapshots = $this->hasSnapshotColumns();
            $snapshotSql = $withSnapshots
                ? 'manager_name_snapshot = :mname,
                        seller_id_at_charge = :sid, seller_name_at_charge = :sname,'
                : '';

            $params = [
                'fid' => (int) $row['id'],
                'pstep' => $prevStep, 'nstep' => $newStep,
                'pcount' => $currentCount, 'ncount' => $newCount,
                'pdue' => $prevDue, 'ndue' => $newDue,
                'body' => json_encode($body, JSON_UNESCAPED_UNICODE),
                'id' => $auditId,
            ];
            if ($withSnapshots) {
                $params['mname'] = ($input['manager_name'] ?? null) ?: null;
                $params['sid'] = $sellerIdAtCharge;
                $params['sname'] = $sellerNameAtCharge;
            }

            $this->pdo->prepare(
                'UPDATE ' . self::T_AUDIT . '
                    SET followup_id = :fid,
                        previous_followup_step = :pstep, new_followup_step = :nstep,
                        previous_charge_count = :pcount, new_charge_count = :ncount,
                        previous_next_followup_on = :pdue, new_next_followup_on = :ndue,
                        ' . $snapshotSql . '
                        response_status = 200, response_body_json = :body,
                        charged_at = NOW(), completed_at = NOW()
                  WHERE id = :id'
            )->execute($params);

            if ($started && $this->pdo->inTransaction()) { $this->pdo->commit(); }

            return ['status' => 200, 'body' => $body, 'replayed' => false];
        } catch (Throwable $e) {
            // Falha na transação: rollback de cobrança E da reserva — a mesma
            // chave pode tentar de novo.
            if ($started && $this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $e;
        }
    }

    public static function urgencyRank(string $urgency): int
    {
        return match ($urgency) {
            'critical' => 4,
            'overdue'  => 3,
            'today', 'due' => 2,
            default    => 1,
        };
    }

    // ── Internos ─────────────────────────────────────────────────────────────────

    /** @return array<string,mixed>|null */
    private function findAudit(string $key): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT * FROM ' . self::T_AUDIT . ' WHERE company_id = :cid AND idempotency_key = :k'
        );
        $st->execute(['cid' => $this->companyId, 'k' => $key]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Replay, reuso indevido ou ainda em andamento.
     *
     * @param array<string,mixed> $audit
     * @return array{status:int,body:array<string,mixed>,replayed:bool}
     */
    private function resolveExisting(array $audit, string $fingerprint): array
    {
        if (!hash_equals((string) $audit['request_fingerprint'], $fingerprint)) {
            return $this->fail(422, 'idempotency_key_reused',
                'Esta Idempotency-Key já foi usada com outra requisição.');
        }

        if ($audit['completed_at'] === null) {
            return $this->fail(409, 'idempotency_in_progress',
                'Operação com esta chave ainda está em andamento. Tente novamente em instantes.');
        }

        // Replay: mesmo status e MESMO corpo da primeira execução.
        $body = json_decode((string) $audit['response_body_json'], true);

        return [
            'status' => (int) ($audit['response_status'] ?? 200),
            'body' => is_array($body) ? $body : ['ok' => true],
            'replayed' => true,
        ];
    }

    /**
     * Reserva a chave. O UNIQUE serializa corridas: se outra requisição já
     * inseriu a MESMA chave e ainda não commitou, este INSERT BLOQUEIA no índice.
     *
     * Por isso a espera é limitada (LOCK_WAIT_SECONDS) e os erros são
     * DISTINGUIDOS — tratar timeout como duplicidade mascararia a corrida:
     *   1062 duplicate  → a outra requisição commitou ⇒ replay/422
     *   1205 timeout / 1213 deadlock → a outra AINDA está em andamento ⇒ 409
     *
     * @param array<string,mixed> $input
     * @return array{id:?int,conflict:?string}
     */
    private function reserveKey(string $key, string $fingerprint, array $input): array
    {
        $previousTimeout = null;
        try {
            $previousTimeout = $this->pdo->query('SELECT @@session.innodb_lock_wait_timeout')->fetchColumn();
            $this->pdo->exec('SET SESSION innodb_lock_wait_timeout = ' . self::LOCK_WAIT_SECONDS);
        } catch (Throwable) {
            // sem permissão para ajustar: segue com o default do servidor
        }

        try {
            $this->pdo->prepare(
                'INSERT INTO ' . self::T_AUDIT . '
                    (company_id, followup_id, omie_order_id, idempotency_key, request_fingerprint,
                     manager_user_id, created_at)
                 VALUES (:cid, NULL, :oid, :k, :fp, :uid, NOW())'
            )->execute([
                'cid' => $this->companyId,
                'oid' => (string) ($input['omie_order_id'] ?? '0'),
                'k' => $key,
                'fp' => $fingerprint,
                'uid' => $input['manager_user_id'] ?? null,
            ]);

            return ['id' => (int) $this->pdo->lastInsertId(), 'conflict' => null];
        } catch (Throwable $e) {
            $code = 0;
            if ($e instanceof \PDOException && isset($e->errorInfo[1])) {
                $code = (int) $e->errorInfo[1];
            }

            return match ($code) {
                1062 => ['id' => null, 'conflict' => 'duplicate'],
                1205, 1213 => ['id' => null, 'conflict' => 'in_progress'],
                default => throw $e,
            };
        } finally {
            if ($previousTimeout !== null) {
                try { $this->pdo->exec('SET SESSION innodb_lock_wait_timeout = ' . (int) $previousTimeout); }
                catch (Throwable) {}
            }
        }
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>|null
     */
    private function lockFollowup(array $input): ?array
    {
        $byId = isset($input['followup_id']) && (int) $input['followup_id'] > 0;
        $sql = 'SELECT * FROM ' . self::T_SCHEDULE . '
                 WHERE company_id = :cid AND ' . ($byId ? 'id = :ref' : 'omie_order_id = :ref') . '
                 FOR UPDATE';
        $st = $this->pdo->prepare($sql);
        $st->execute([
            'cid' => $this->companyId,
            'ref' => $byId ? (int) $input['followup_id'] : (string) ($input['omie_order_id'] ?? ''),
        ]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** As 3 colunas de snapshot já existem? (cacheado por instância) */
    private function hasSnapshotColumns(): bool
    {
        if ($this->snapshotColumnsExist !== null) {
            return $this->snapshotColumnsExist;
        }
        try {
            $n = (int) $this->pdo->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = '" . self::T_AUDIT . "'
                    AND COLUMN_NAME IN ('manager_name_snapshot','seller_id_at_charge','seller_name_at_charge')"
            )->fetchColumn();

            return $this->snapshotColumnsExist = ($n === 3);
        } catch (Throwable) {
            return $this->snapshotColumnsExist = false;
        }
    }

    /** Nome do vendedor NESTE instante — congelado na auditoria. */
    private function sellerNameNow(?string $sellerId): ?string
    {
        if ($sellerId === null || $sellerId === '') {
            return null;
        }
        try {
            $st = $this->pdo->prepare(
                'SELECT nome FROM biomie_vendedores_omie
                  WHERE company_id = :cid AND codigo_omie = :s LIMIT 1'
            );
            $st->execute(['cid' => $this->companyId, 's' => $sellerId]);
            $nome = $st->fetchColumn();

            return $nome === false || $nome === null ? null : (string) $nome;
        } catch (Throwable) {
            return null; // nome é conveniência histórica; o ID é a identidade oficial
        }
    }

    /** @return array<string,mixed> */
    private function loadPolicy(): array
    {
        $st = $this->pdo->prepare('SELECT * FROM ' . self::T_POLICY . ' WHERE company_id = :cid');
        $st->execute(['cid' => $this->companyId]);

        return $st->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array<string,mixed> $extra
     * @return array{status:int,body:array<string,mixed>,replayed:bool}
     */
    private function fail(int $status, string $code, string $message, array $extra = []): array
    {
        return [
            'status' => $status,
            'body' => ['ok' => false, 'error' => $code, 'message' => $message] + $extra,
            'replayed' => false,
        ];
    }
}
