<?php

declare(strict_types=1);

namespace BIomie\QuoteAgenda;

use BIomie\CronLock;
use BIomie\Logger;
use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * AGENDA DE ORÇAMENTOS — Fase 4: scanner do snapshot vivo.
 *
 * Orquestra: lock por empresa → política → estado `running` → serviço →
 * followups → ausências (`missing_since`) → estado final. NUNCA consulta o Omie
 * pedido a pedido (listagem em lote, recomendação da própria Omie).
 *
 * REGRAS CRÍTICAS
 *  · A busca da API acontece FORA da transação.
 *  · Mutações só começam depois de concluir e validar TODAS as páginas.
 *  · Upsert canônico + followups + estado final DENTRO da transação.
 *  · Snapshot incompleto ⇒ `failed` e NENHUMA ausência marcada.
 *  · Pedido que saiu do snapshot recebe `missing_since` — nunca resolução automática.
 *  · Reaparecimento limpa `missing_since`.
 *  · Atualizar followup NUNCA zera charge_count, última cobrança ou auditoria.
 *  · `last_successful_at` permanece intacto quando a tentativa falha.
 *
 * FUSO: timestamps técnicos gravados com NOW() do banco (UTC);
 *       `quote_created_at` guarda o wall-clock de Fortaleza vindo do Omie;
 *       `next_followup_on` é DATE calculado em Fortaleza.
 */
final class QuoteLiveSnapshotScanner
{
    private const T_SCHEDULE = 'biomie_sales_quote_followup_schedule';
    private const T_POLICY   = 'biomie_quote_followup_policy';
    private const T_SNAPSHOT = 'biomie_quote_live_snapshot_sync';

    public function __construct(
        private readonly PDO $pdo,
        private readonly QuoteLiveSnapshotSource $service,
        private readonly Logger $logger,
        private readonly int $companyId,
    ) {
    }

    /**
     * @return array<string,mixed> relatório da execução
     */
    public function scan(bool $apply, string $etapa = '10'): array
    {
        $t0 = microtime(true);

        // Nunca concorrer com o cron comercial da mesma empresa.
        $lock = CronLock::acquire($this->pdo, 'biomie:realtime_pipeline:company:' . $this->companyId, $this->logger, 120);
        if ($lock === null) {
            return ['ok' => false, 'skipped' => true, 'reason' => 'lock_busy', 'company_id' => $this->companyId];
        }

        try {
            $policyRow = $this->loadPolicy();
            $policy = FollowupDatePolicy::fromPolicyRow($policyRow);

            if ($apply) {
                $this->markRunning();
            }

            // ── 1. BUSCA + VALIDAÇÃO — fora de transação ────────────────────────
            $snap = $this->service->capture($etapa);
            $diff = $this->service->diffAgainstCanonical($snap);

            $blocking = $snap->problems;
            if ($diff['emissao_suspeita'] !== []) {
                $blocking[] = 'data_emissao mudaria fora da regra legada: ' . implode(',', array_keys($diff['emissao_suspeita']));
            }

            if ($blocking !== []) {
                if ($apply) {
                    // failed NÃO zera last_successful_at e NÃO marca ausências.
                    $this->markFailed(implode(' · ', $blocking), $snap, (int) ((microtime(true) - $t0) * 1000));
                }

                return [
                    'ok' => false,
                    'company_id' => $this->companyId,
                    'blocking' => $blocking,
                    'live_total' => $snap->liveTotal(),
                    'eligible_total' => $snap->eligibleTotal(),
                    'applied' => false,
                ];
            }

            $report = [
                'ok' => true,
                'company_id' => $this->companyId,
                'live_total' => $snap->liveTotal(),
                'eligible_total' => $snap->eligibleTotal(),
                'excluded' => $snap->excludedIds(),
                'snapshot_hash' => $snap->snapshotHash,
                'canonical_existing' => count($diff['existing']),
                'canonical_missing' => $diff['missing'],
                'drifts' => $diff['drifts'],
                'applied' => false,
            ];

            if (!$apply) {
                return $report;
            }

            // ── 2. MUTAÇÕES — uma transação ─────────────────────────────────────
            $started = !$this->pdo->inTransaction();
            if ($started) { $this->pdo->beginTransaction(); }
            try {
                $upsert = $this->service->upsertEligible($snap);
                $follow = $this->syncFollowups($snap, $policy);
                $missing = $this->markMissing($snap);
                $this->markSuccess($snap, (int) ((microtime(true) - $t0) * 1000));
                if ($started) { $this->pdo->commit(); }

                $report['applied'] = true;
                $report['upsert'] = $upsert;
                $report['followups'] = $follow;
                $report['missing_marked'] = $missing;
            } catch (Throwable $e) {
                if ($started && $this->pdo->inTransaction()) { $this->pdo->rollBack(); }
                $this->markFailed($e->getMessage(), $snap, (int) ((microtime(true) - $t0) * 1000));
                throw $e;
            }

            $report['duration_ms'] = (int) ((microtime(true) - $t0) * 1000);

            return $report;
        } finally {
            $lock->release();
        }
    }

    // ── Followups ────────────────────────────────────────────────────────────────

    /**
     * Cria/atualiza followups dos elegíveis. Idempotente e NÃO-destrutivo:
     * nunca zera charge_count, followup_step, cobrança ou auditoria.
     *
     * @return array{created:int,refreshed:int,reappeared:int}
     */
    private function syncFollowups(QuoteLiveSnapshot $snap, FollowupDatePolicy $policy): array
    {
        $created = 0; $refreshed = 0; $reappeared = 0;
        $tz = FollowupDatePolicy::timezone();
        $hoje = new DateTimeImmutable('now', $tz);

        $sel = $this->pdo->prepare(
            'SELECT id, followup_step, charge_count, missing_since, resolved_at
               FROM ' . self::T_SCHEDULE . '
              WHERE company_id = :cid AND omie_order_id = :oid'
        );

        foreach ($snap->eligibleById as $id => $row) {
            $createdAt = $this->resolveCreatedAt($row);
            $forecastOn = ($row['data_previsao_faturamento'] ?? null) ?: null;
            $sellerId = trim((string) ($row['codigo_vendedor_omie'] ?? ''));
            $sellerId = $sellerId !== '' ? $sellerId : null;

            $sel->execute(['cid' => $this->companyId, 'oid' => $id]);
            $cur = $sel->fetch(PDO::FETCH_ASSOC);

            if ($cur === false) {
                // Semeia o passo pela IDADE: um orçamento antigo não pode entrar
                // como "1ª cobrança amanhã".
                $step = $policy->seedStep($createdAt['at'], $hoje);
                $ins = $this->pdo->prepare(
                    'INSERT INTO ' . self::T_SCHEDULE . '
                        (company_id, omie_order_id, seller_id, quote_created_at, quote_created_source,
                         quote_forecast_on, next_followup_on, followup_step, charge_count, last_seen_at, created_at, updated_at)
                     VALUES
                        (:cid, :oid, :seller, :created_at, :src, :forecast, :due, :step, 0, NOW(), NOW(), NOW())'
                );
                $ins->execute([
                    'cid' => $this->companyId,
                    'oid' => $id,
                    'seller' => $sellerId,
                    'created_at' => $createdAt['at']->format('Y-m-d H:i:s'),
                    'src' => $createdAt['source'],
                    'forecast' => $forecastOn,
                    'due' => $policy->dueOn($createdAt['at'], $step),
                    'step' => $step,
                ]);
                $created++;
                continue;
            }

            if (($cur['missing_since'] ?? null) !== null) { $reappeared++; }

            // Atualiza apenas o que vem da origem + presença. Preserva passo,
            // contador, cobrança e auditoria.
            $step = (int) ($cur['followup_step'] ?? 1);
            $upd = $this->pdo->prepare(
                'UPDATE ' . self::T_SCHEDULE . '
                    SET seller_id = :seller,
                        quote_created_at = :created_at,
                        quote_created_source = :src,
                        quote_forecast_on = :forecast,
                        next_followup_on = :due,
                        last_seen_at = NOW(),
                        missing_since = NULL,
                        reconciliation_attempts = 0,
                        updated_at = NOW()
                  WHERE id = :id'
            );
            $upd->execute([
                'seller' => $sellerId,
                'created_at' => $createdAt['at']->format('Y-m-d H:i:s'),
                'src' => $createdAt['source'],
                'forecast' => $forecastOn,
                'due' => $policy->dueOn($createdAt['at'], $step),
                'id' => (int) $cur['id'],
            ]);
            $refreshed++;
        }

        return ['created' => $created, 'refreshed' => $refreshed, 'reappeared' => $reappeared];
    }

    /**
     * Resolve a data de criação. Ordem de confiança:
     *   omie_dinc → omie_stage_history (se já disponível) → first_seen.
     *
     * `legacy_fallback` fica só para compatibilidade/importações antigas: não há
     * razão para usar data_emissao (que pode conter PREVISÃO e gerar idade crítica
     * FALSA) num orçamento que acabou de ser visto.
     *
     * `first_seen` SUBESTIMA a idade por construção — é "quando o BIomie viu", não
     * "quando o vendedor criou". Por isso a UI mostra "aberto há PELO MENOS X dias".
     *
     * @param array<string,mixed> $row
     * @return array{at:DateTimeImmutable,source:string}
     */
    private function resolveCreatedAt(array $row): array
    {
        $tz = FollowupDatePolicy::timezone();

        $dInc = trim((string) ($row['data_inclusao_omie'] ?? ''));
        if ($dInc !== '') {
            $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $dInc, $tz);
            if ($dt instanceof DateTimeImmutable) {
                return ['at' => $dt, 'source' => 'omie_dinc'];
            }
        }

        // Sem dInc: usa o instante da primeira identificação, em Fortaleza.
        return ['at' => new DateTimeImmutable('now', $tz), 'source' => 'first_seen'];
    }

    /**
     * Marca ausências. Só roda com snapshot COMPLETO (garantido pelo caller).
     * Ausência NÃO resolve nada — apenas `missing_since` + tentativas.
     */
    private function markMissing(QuoteLiveSnapshot $snap): int
    {
        $ids = $snap->eligibleIds();
        $params = [$this->companyId];
        $notIn = '';
        if ($ids !== []) {
            $notIn = ' AND omie_order_id NOT IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $params = [$this->companyId, ...$ids];
        }

        $st = $this->pdo->prepare(
            'UPDATE ' . self::T_SCHEDULE . '
                SET missing_since = COALESCE(missing_since, NOW()),
                    reconciliation_attempts = reconciliation_attempts + 1,
                    updated_at = NOW()
              WHERE company_id = ? AND resolved_at IS NULL' . $notIn
        );
        $st->execute($params);

        return $st->rowCount();
    }

    // ── Estado / frescor ─────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function loadPolicy(): array
    {
        $st = $this->pdo->prepare('SELECT * FROM ' . self::T_POLICY . ' WHERE company_id = :cid');
        $st->execute(['cid' => $this->companyId]);

        return $st->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    private function markRunning(): void
    {
        $this->pdo->prepare(
            'INSERT INTO ' . self::T_SNAPSHOT . ' (company_id, started_at, status, created_at, updated_at)
             VALUES (:cid, NOW(), \'running\', NOW(), NOW())
             ON DUPLICATE KEY UPDATE started_at = NOW(), status = \'running\', updated_at = NOW()'
        )->execute(['cid' => $this->companyId]);
    }

    private function markSuccess(QuoteLiveSnapshot $snap, int $ms): void
    {
        $this->pdo->prepare(
            'INSERT INTO ' . self::T_SNAPSHOT . '
                (company_id, started_at, completed_at, last_successful_at, status,
                 live_total, eligible_total, snapshot_hash, duration_ms, last_error, created_at, updated_at)
             VALUES (:cid, NOW(), NOW(), NOW(), \'success\', :lt, :et, :hash, :ms, NULL, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                completed_at = NOW(), last_successful_at = NOW(), status = \'success\',
                live_total = VALUES(live_total), eligible_total = VALUES(eligible_total),
                snapshot_hash = VALUES(snapshot_hash), duration_ms = VALUES(duration_ms),
                last_error = NULL, updated_at = NOW()'
        )->execute([
            'cid' => $this->companyId,
            'lt' => $snap->liveTotal(),
            'et' => $snap->eligibleTotal(),
            'hash' => $snap->snapshotHash,
            'ms' => $ms,
        ]);
    }

    /** Falha NUNCA toca last_successful_at — é a base do banner de frescor. */
    private function markFailed(string $error, ?QuoteLiveSnapshot $snap, int $ms): void
    {
        try {
            $this->pdo->prepare(
                'INSERT INTO ' . self::T_SNAPSHOT . '
                    (company_id, started_at, completed_at, status, live_total, eligible_total, duration_ms, last_error, created_at, updated_at)
                 VALUES (:cid, NOW(), NOW(), \'failed\', :lt, :et, :ms, :err, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE
                    completed_at = NOW(), status = \'failed\',
                    live_total = VALUES(live_total), eligible_total = VALUES(eligible_total),
                    duration_ms = VALUES(duration_ms), last_error = VALUES(last_error), updated_at = NOW()'
            )->execute([
                'cid' => $this->companyId,
                'lt' => $snap?->liveTotal(),
                'et' => $snap?->eligibleTotal(),
                'ms' => $ms,
                'err' => mb_substr($error, 0, 500),
            ]);
        } catch (Throwable) {
            // registrar falha nunca pode mascarar a falha original
        }
    }
}
