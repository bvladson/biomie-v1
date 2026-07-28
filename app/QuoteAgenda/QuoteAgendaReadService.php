<?php

declare(strict_types=1);

namespace BIomie\QuoteAgenda;

use BIomie\SchemaCapabilities;
use DateTimeImmutable;
use PDO;

/**
 * AGENDA DE ORÇAMENTOS — Fase 5: read model (KPIs, calendário, dia, detalhe).
 *
 * FONTE DE VERDADE: valor/cliente/vendedor vivem no CANÔNICO — nunca duplicados
 * no acompanhamento. A leitura NASCE no followup e faz LEFT JOIN no canônico:
 *
 *   · LEFT (não INNER): com os fluxos de DELETE+reinsert que ainda existem, um
 *     INNER faria o followup DESAPARECER se a linha canônica sumisse.
 *   · Nenhuma condição sobre p.* no WHERE — isso degradaria o LEFT para INNER
 *     na prática. Condições do canônico ficam no ON. ÚNICA EXCEÇÃO: o filtro de
 *     CANAL (`p.business_channel`), aplicado só quando o usuário estreita para
 *     varejo/licitação — aí a degradação é INTENCIONAL (followup órfão ou de
 *     outro canal deve sumir do recorte pedido). Sem canal pedido, nada muda.
 *   · Valor desconhecido NUNCA vira zero: `total_value = null` +
 *     `canonical_available = false` + `data_quality = 'canonical_missing'`.
 *     Nos agregados, `total_value` soma SÓ os conhecidos e vem acompanhado de
 *     `known_value_count` / `unknown_value_count`.
 *
 * URGÊNCIA: o servidor calcula a SEMÂNTICA (urgency + urgency_rank) via
 * FollowupDatePolicy — fonte única. A API nunca devolve cor/hex; a UI escolhe.
 *
 * FUSO: datas diárias em Fortaleza; timestamps técnicos em UTC.
 * NUNCA consulta o Omie.
 */
final class QuoteAgendaReadService
{
    private const T_SCHEDULE = 'biomie_sales_quote_followup_schedule';
    private const T_ORDERS   = 'biomie_pedidos_canonicos';
    private const T_POLICY   = 'biomie_quote_followup_policy';
    private const T_SNAPSHOT = 'biomie_quote_live_snapshot_sync';
    private const T_AUDIT    = 'biomie_quote_followup_charge_audit';

    public function __construct(
        private readonly PDO $pdo,
        private readonly int $companyId,
    ) {
    }

    /**
     * Itens abertos, já com urgência resolvida. Base de KPIs, calendário e dia.
     *
     * @param array{seller_id?:string,seller_ids?:list<string>,start?:string,end?:string,channels?:list<string>} $filters
     *        `seller_ids` = escopo do vendedor (lista de códigos). Vazio ⇒ nada.
     * @return list<array<string,mixed>>
     */
    public function openItems(array $filters = []): array
    {
        $policy = $this->policy();
        $today = new DateTimeImmutable('now', FollowupDatePolicy::timezone());

        $where = ['f.company_id = :cid', 'f.resolved_at IS NULL'];
        $params = ['cid' => $this->companyId];

        // Filtros SEMPRE sobre f.* — nada de p.* no WHERE (degradaria o LEFT JOIN).
        if (($filters['seller_id'] ?? '') !== '') {
            $where[] = 'f.seller_id = :seller';
            $params['seller'] = (string) $filters['seller_id'];
        }
        // Escopo do VENDEDOR: lista de códigos que ele representa. É a fronteira
        // de segurança da visão self — o endpoint FORÇA este filtro e o vendedor
        // não pode sobrepô-lo. Escopo VAZIO ⇒ NADA (nunca a carteira inteira):
        // um vendedor sem vínculo não pode "vazar" para ver tudo.
        if (array_key_exists('seller_ids', $filters)) {
            $ids = array_values(array_filter(
                array_map('strval', (array) $filters['seller_ids']),
                static fn (string $s): bool => $s !== '' && $s !== '0'
            ));
            if ($ids === []) {
                $where[] = '1 = 0';
            } else {
                $ph = [];
                foreach ($ids as $i => $sid) {
                    $ph[] = ":sid{$i}";
                    $params["sid{$i}"] = $sid;
                }
                $where[] = 'f.seller_id IN (' . implode(', ', $ph) . ')';
            }
        }
        if (($filters['start'] ?? '') !== '') {
            $where[] = 'f.next_followup_on >= :start';
            $params['start'] = (string) $filters['start'];
        }
        if (($filters['end'] ?? '') !== '') {
            $where[] = 'f.next_followup_on <= :end';
            $params['end'] = (string) $filters['end'];
        }

        // FILTRO DE CANAL (Filtro Comercial Inteligente): quando o usuário estreita
        // para varejo/licitação, restringe pelo canal do PEDIDO (p.business_channel).
        // É a ÚNICA exceção à regra "nada de p.* no WHERE": ao pedir um canal, um
        // followup órfão (sem pedido canônico) ou de outro canal deve mesmo sumir —
        // o LEFT vira efetivamente INNER só para o recorte pedido. Sem canal pedido
        // (todas), nada é adicionado e o LEFT JOIN é preservado. Valores por allowlist.
        if (array_key_exists('channels', $filters)) {
            $channels = array_values(array_filter(
                array_map(static fn ($c): string => strtolower(trim((string) $c)), (array) $filters['channels']),
                static fn (string $c): bool => in_array($c, ['varejo', 'licitacao'], true)
            ));
            // Guarda defensiva: ambiente sem a coluna não pode derrubar a Agenda.
            if ($channels !== [] && SchemaCapabilities::columnExists($this->pdo, self::T_ORDERS, 'business_channel')) {
                $chPh = [];
                foreach ($channels as $ci => $ch) {
                    $chPh[] = ":chan{$ci}";
                    $params["chan{$ci}"] = $ch;
                }
                $where[] = 'p.business_channel IN (' . implode(', ', $chPh) . ')';
            }
        }

        $sql = 'SELECT
                    f.id, f.omie_order_id, f.seller_id, f.quote_created_at, f.quote_created_source,
                    f.quote_forecast_on, f.next_followup_on, f.followup_step, f.charge_count,
                    f.last_manager_charge_at, f.last_seen_at, f.missing_since,
                    p.codigo_pedido, p.nome_cliente, p.documento_cliente, p.nome_vendedor,
                    p.valor_total, p.etapa_omie
                  FROM ' . self::T_SCHEDULE . ' AS f
                  LEFT JOIN ' . self::T_ORDERS . ' AS p
                    ON p.company_id = f.company_id
                   AND p.pedido_id_omie = f.omie_order_id
                 WHERE ' . implode(' AND ', $where) . '
                 ORDER BY f.next_followup_on ASC, f.id ASC';

        $st = $this->pdo->prepare($sql);
        $st->execute($params);

        $items = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $items[] = $this->decorate($r, $policy, $today);
        }

        return $items;
    }

    /**
     * Os quatro KPIs do topo.
     *
     * @param array{seller_id?:string,seller_ids?:list<string>,start?:string,end?:string,channels?:list<string>} $filters
     * @return array<string,mixed>
     */
    public function summary(array $filters = []): array
    {
        $items = $this->openItems($filters);
        $hoje = (new DateTimeImmutable('now', FollowupDatePolicy::timezone()))->format('Y-m-d');

        $k = [
            'charge_today' => ['count' => 0, 'total_value' => 0.0, 'unknown_value_count' => 0],
            'overdue'      => ['count' => 0, 'total_value' => 0.0, 'unknown_value_count' => 0],
            'open'         => ['count' => 0, 'total_value' => 0.0, 'unknown_value_count' => 0],
            'critical'     => ['count' => 0, 'total_value' => 0.0, 'unknown_value_count' => 0],
        ];
        $provisional = 0;
        $canonicalMissing = 0;

        foreach ($items as $i) {
            $buckets = ['open'];
            if ($i['urgency'] === 'today')    { $buckets[] = 'charge_today'; }
            if ($i['urgency'] === 'overdue')  { $buckets[] = 'overdue'; }
            if ($i['urgency'] === 'critical') { $buckets[] = 'overdue'; $buckets[] = 'critical'; }

            foreach ($buckets as $b) {
                $k[$b]['count']++;
                if ($i['canonical_available']) {
                    $k[$b]['total_value'] += (float) $i['value'];
                } else {
                    $k[$b]['unknown_value_count']++;
                }
            }
            if (!$i['date_is_official']) { $provisional++; }
            if (!$i['canonical_available']) { $canonicalMissing++; }
        }

        foreach ($k as $key => $v) {
            $k[$key]['total_value'] = round($v['total_value'], 2);
            $k[$key]['known_value_count'] = $v['count'] - $v['unknown_value_count'];
        }

        return [
            'reference_date' => $hoje,
            'kpis' => $k,
            'data_quality' => [
                'provisional_dates_count' => $provisional,   // KPI técnico "Datas provisórias: N de M"
                'total_count' => count($items),
                'canonical_missing_count' => $canonicalMissing, // esperado: 0
            ],
        ];
    }

    /**
     * Calendário agrupado por dia. day_urgency = MAIOR urgência entre os itens
     * não resolvidos do dia.
     *
     * @param array{seller_id?:string,seller_ids?:list<string>,start?:string,end?:string,channels?:list<string>} $filters
     * @return list<array<string,mixed>>
     */
    public function calendar(string $start, string $end, array $filters = []): array
    {
        $items = $this->openItems($filters + ['start' => $start, 'end' => $end]);

        /** @var array<string,array{
         *   date:string,quote_count:int,total_value:float,known_value_count:int,
         *   unknown_value_count:int,due_count:int,overdue_count:int,
         *   critical_count:int,day_urgency:string,urgency_rank:int
         * }> $byDay
         */
        $byDay = [];
        foreach ($items as $i) {
            $day = (string) $i['next_followup_on'];
            if ($day === '') { continue; }

            $byDay[$day] ??= [
                'date' => $day,
                'quote_count' => 0,
                'total_value' => 0.0,
                'known_value_count' => 0,
                'unknown_value_count' => 0,
                'due_count' => 0,
                'overdue_count' => 0,
                'critical_count' => 0,
                'day_urgency' => 'normal',
                'urgency_rank' => 1,
            ];

            $d = &$byDay[$day];
            $d['quote_count']++;
            if ($i['canonical_available']) {
                $d['total_value'] += (float) $i['value'];
                $d['known_value_count']++;
            } else {
                $d['unknown_value_count']++;
            }
            match ($i['urgency']) {
                'today'    => $d['due_count']++,
                'overdue'  => $d['overdue_count']++,
                'critical' => $d['critical_count']++,
                default    => null,
            };
            if ((int) $i['urgency_rank'] > (int) $d['urgency_rank']) {
                $d['urgency_rank'] = (int) $i['urgency_rank'];
                $d['day_urgency'] = (string) $i['urgency'];
            }
            unset($d);
        }

        foreach ($byDay as $day => $d) {
            // Sem nenhum valor conhecido, total_value é NULL — não zero.
            $byDay[$day]['total_value'] = $d['known_value_count'] > 0 ? round($d['total_value'], 2) : null;
        }

        ksort($byDay);

        return array_values($byDay);
    }

    /** Lista de um dia específico. @return list<array<string,mixed>> */
    /**
     * @param array{seller_id?:string,seller_ids?:list<string>,start?:string,end?:string,channels?:list<string>} $filters
     * @return list<array<string,mixed>>
     */
    public function day(string $date, array $filters = []): array
    {
        return $this->openItems($filters + ['start' => $date, 'end' => $date]);
    }

    /**
     * Detalhe de um acompanhamento.
     *
     * `$filters` carrega o escopo de vendedores: fora do escopo o item não é
     * encontrado (null ⇒ 404 no endpoint), e não "encontrado porém ocultado".
     *
     * @param  array{seller_id?:string,seller_ids?:list<string>,start?:string,end?:string,channels?:list<string>} $filters
     * @return array<string,mixed>|null
     */
    public function detail(string $omieOrderId, array $filters = []): ?array
    {
        foreach ($this->openItems($filters) as $i) {
            if ((string) $i['omie_order_id'] === $omieOrderId) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Lê DADOS e FRESCOR na MESMA visão consistente.
     *
     * Sem isso a resposta pode misturar estados de momentos diferentes (ex.: o
     * calendário de antes de um scan e o frescor de depois). READ ONLY + WITH
     * CONSISTENT SNAPSHOT dá uma visão única a todas as leituras.
     *
     * Nota: PDO::inTransaction() não enxerga `START TRANSACTION` via exec(),
     * então o controle é por flag própria.
     *
     * @param callable(self):mixed $reader
     * @return array{data:mixed,freshness:array<string,mixed>,generated_at:string}
     */
    public function readWithFreshness(callable $reader): array
    {
        $own = !$this->pdo->inTransaction();
        $consistent = false;

        if ($own) {
            // WITH CONSISTENT SNAPSHOT EXIGE REPEATABLE READ; esta instalação
            // roda em READ-COMMITTED e o comando falha com erro 4062. Usamos
            // `SET TRANSACTION`, que vale só para a PRÓXIMA transação desta
            // sessão — não altera a sessão de forma persistente nem afeta
            // qualquer outro código que compartilhe a conexão (há pool).
            try {
                $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $this->pdo->exec('START TRANSACTION READ ONLY, WITH CONSISTENT SNAPSHOT');
                $consistent = true;
            } catch (\Throwable) {
                // Hosts gerenciados podem recusar. Degradar é melhor que 500,
                // mas a degradação NÃO pode ser silenciosa: sem snapshot
                // consistente, dados e frescor podem vir de instantes
                // diferentes, e quem consome precisa saber disso.
                try {
                    $this->pdo->exec('START TRANSACTION READ ONLY');
                } catch (\Throwable) {
                    $own = false;   // nem transação houve; nada a commitar
                }
            }
        }

        try {
            $data = $reader($this);
            $freshness = $this->freshness();
        } finally {
            if ($own) {
                try { $this->pdo->exec('COMMIT'); } catch (\Throwable) {}
            }
        }

        return [
            'data' => $data,
            'freshness' => $freshness + ['consistent_snapshot' => $consistent],
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * Frescor do snapshot vivo — fonte ESPECÍFICA do banner.
     * Vencido ⇒ a API segue entregando o último snapshot, mas com is_stale=true.
     *
     * `running`/`failed` NÃO substituem `last_successful_at`: uma tentativa que
     * falhou sinaliza o erro sem invalidar o último sucesso.
     * `last_error` bruto NUNCA vai ao gestor — só código + mensagem sanitizada.
     *
     * @return array<string,mixed>
     */
    public function freshness(): array
    {
        $st = $this->pdo->prepare(
            'SELECT status, last_successful_at, last_error, snapshot_hash, live_total, eligible_total
               FROM ' . self::T_SNAPSHOT . ' WHERE company_id = :cid'
        );
        $st->execute(['cid' => $this->companyId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        $staleAfterMin = (int) ($this->policyRow()['stale_after_minutes'] ?? 45);
        $status = $row === false ? null : (string) $row['status'];
        $hasError = $row !== false && ($row['last_error'] ?? null) !== null;

        // Sem sucesso anterior ⇒ stale, sempre.
        if ($row === false || ($row['last_successful_at'] ?? null) === null) {
            return [
                'source_status' => $status,
                'is_stale' => true,
                'never_synced' => true,
                'last_successful_at' => null,
                'age_seconds' => null,
                'age_minutes' => null,
                'stale_after_minutes' => $staleAfterMin,
                'snapshot_hash' => null,
                'live_total' => null,
                'eligible_total' => null,
                'has_error' => $hasError,
                'error_code' => $hasError ? 'snapshot_sync_failed' : null,
                'error_message' => $hasError ? 'A última sincronização falhou. A equipe técnica foi notificada.' : null,
                'message' => 'DADOS DO OMIE DESATUALIZADOS — nenhuma sincronização bem-sucedida.',
            ];
        }

        // Idade em SEGUNDOS, tudo na mesma fonte de tempo (UTC do banco).
        $ageSec = (int) $this->pdo->query(
            'SELECT TIMESTAMPDIFF(SECOND, ' . $this->pdo->quote((string) $row['last_successful_at']) . ', NOW())'
        )->fetchColumn();

        // Limite fechado: 44:59 fresco · 45:00 stale.
        $isStale = $ageSec >= ($staleAfterMin * 60);
        $ageMin = intdiv($ageSec, 60);

        return [
            'source_status' => $status,
            'is_stale' => $isStale,
            'never_synced' => false,
            'last_successful_at' => self::iso((string) $row['last_successful_at']),
            'age_seconds' => $ageSec,
            'age_minutes' => $ageMin,
            'stale_after_minutes' => $staleAfterMin,
            'snapshot_hash' => $row['snapshot_hash'] !== null ? (string) $row['snapshot_hash'] : null,
            'live_total' => $row['live_total'] !== null ? (int) $row['live_total'] : null,
            'eligible_total' => $row['eligible_total'] !== null ? (int) $row['eligible_total'] : null,
            'has_error' => $hasError,
            'error_code' => $hasError ? 'snapshot_sync_failed' : null,
            'error_message' => $hasError ? 'A última sincronização falhou. A equipe técnica foi notificada.' : null,
            'message' => $isStale
                ? sprintf('DADOS DO OMIE DESATUALIZADOS — última sincronização há %d min.', $ageMin)
                : null,
        ];
    }

    /**
     * Histórico de cobranças de um acompanhamento (Fase 7).
     *
     * Só eventos REALMENTE concluídos entram:
     *   · charged_at IS NOT NULL          (a cobrança aconteceu)
     *   · response_status = 200           (terminou com sucesso)
     *   · new_charge_count = previous + 1 (incremento efetivo)
     * Retry idempotente NÃO cria evento novo — o replay não insere linha, então
     * ele naturalmente não aparece aqui.
     *
     * NUNCA expõe idempotency_key, request_fingerprint, response_body_json nem
     * erro interno: são detalhes de implementação/segurança, não trilha pública.
     *
     * Identidade histórica vem dos SNAPSHOTS (o pedido pode ter sido transferido
     * depois); os IDs seguem sendo a identidade oficial.
     *
     * @param  list<string>|null $sellerIds escopo de vendedores; null = sem restrição
     * @return array{followup_id:int,omie_order_id:string,events:list<array<string,mixed>>}|null
     */
    public function history(int $followupId, ?array $sellerIds = null): ?array
    {
        // Isolamento: followup de outra empresa é 404, não lista vazia. Fora do
        // ESCOPO de vendedores o tratamento é o mesmo — a trilha de cobranças
        // nomeia cliente e vendedor, então "existe mas você não vê" já vazaria.
        $where = ['company_id = :cid', 'id = :id'];
        $params = ['cid' => $this->companyId, 'id' => $followupId];

        if ($sellerIds !== null) {
            if ($sellerIds === []) {
                return null;
            }
            $ph = [];
            foreach (array_values($sellerIds) as $i => $sid) {
                $ph[] = ":hs{$i}";
                $params["hs{$i}"] = (string) $sid;
            }
            $where[] = 'seller_id IN (' . implode(', ', $ph) . ')';
        }

        $st = $this->pdo->prepare(
            'SELECT id, omie_order_id FROM ' . self::T_SCHEDULE . '
              WHERE ' . implode(' AND ', $where)
        );
        $st->execute($params);
        $followup = $st->fetch(PDO::FETCH_ASSOC);
        if ($followup === false) {
            return null;
        }

        $ev = $this->pdo->prepare(
            'SELECT charged_at, manager_user_id, manager_name_snapshot,
                    seller_id_at_charge, seller_name_at_charge,
                    previous_followup_step, new_followup_step,
                    previous_charge_count, new_charge_count,
                    previous_next_followup_on, new_next_followup_on
               FROM ' . self::T_AUDIT . '
              WHERE company_id = :cid
                AND followup_id = :fid
                AND charged_at IS NOT NULL
                AND response_status = 200
                AND new_charge_count = previous_charge_count + 1
              ORDER BY charged_at DESC, id DESC'
        );
        $ev->execute(['cid' => $this->companyId, 'fid' => (int) $followup['id']]);

        $events = [];
        foreach ($ev->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $events[] = [
                'charged_at' => self::iso((string) $r['charged_at']),   // UTC/ISO-8601
                'manager' => [
                    'id' => $r['manager_user_id'] !== null ? (int) $r['manager_user_id'] : null,
                    'name' => $r['manager_name_snapshot'],
                ],
                'seller' => [
                    'id' => $r['seller_id_at_charge'],
                    'name' => $r['seller_name_at_charge'],
                ],
                'charge_number' => (int) $r['new_charge_count'],
                'previous_step' => $r['previous_followup_step'] !== null ? (int) $r['previous_followup_step'] : null,
                'new_step' => $r['new_followup_step'] !== null ? (int) $r['new_followup_step'] : null,
                'next_followup_on' => $r['new_next_followup_on'],
            ];
        }

        return [
            'followup_id' => (int) $followup['id'],
            'omie_order_id' => (string) $followup['omie_order_id'],
            'events' => $events,
        ];
    }

    /** DATETIME UTC do banco → ISO-8601 com Z. */
    private static function iso(string $utc): string
    {
        return str_replace(' ', 'T', substr($utc, 0, 19)) . 'Z';
    }

    // ── Internos ─────────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private function decorate(array $r, FollowupDatePolicy $policy, DateTimeImmutable $today): array
    {
        $due = (string) ($r['next_followup_on'] ?? '');
        $step = (int) $r['followup_step'];
        $urgency = $due !== '' ? $policy->urgency($due, $step, $today) : 'normal';

        // Canônico ausente: NUNCA transformar desconhecido em zero.
        $canonicalAvailable = $r['valor_total'] !== null;

        $createdAt = new DateTimeImmutable((string) $r['quote_created_at'], FollowupDatePolicy::timezone());
        $ageDays = (int) $createdAt->setTime(0, 0)->diff($today->setTime(0, 0))->days;
        $source = (string) $r['quote_created_source'];
        $official = in_array($source, ['omie_dinc', 'omie_stage_history'], true);

        return [
            'followup_id' => (int) $r['id'],
            'omie_order_id' => (string) $r['omie_order_id'],
            'numero_pedido' => $r['codigo_pedido'],
            'customer' => $r['nome_cliente'],
            'seller_id' => $r['seller_id'],
            'seller_name' => $r['nome_vendedor'],
            'seller_identified' => ($r['seller_id'] ?? null) !== null,

            'value' => $canonicalAvailable ? (float) $r['valor_total'] : null,
            'canonical_available' => $canonicalAvailable,
            'data_quality' => $canonicalAvailable ? 'ok' : 'canonical_missing',

            'quote_created_at' => (string) $r['quote_created_at'], // Fortaleza
            'quote_created_source' => $source,
            'date_is_official' => $official,
            'age_days' => $ageDays,
            // Provisória subestima por construção: é "quando o BIomie viu".
            'age_label' => $official
                ? sprintf('Aberto há %d dias', $ageDays)
                : sprintf('Aberto há pelo menos %d dias', $ageDays),
            'age_note' => $official ? null : ($source === 'first_seen'
                ? 'Data provisória — primeira identificação pelo BIomie'
                : 'Data estimada pelo legado'),

            'forecast_on' => $r['quote_forecast_on'],
            'next_followup_on' => $due,
            'followup_step' => $step,
            'total_steps' => $policy->totalSteps(),
            'charge_count' => (int) $r['charge_count'],
            'last_manager_charge_at' => $r['last_manager_charge_at'], // UTC
            'is_critical' => $policy->isCritical($step),

            // Semântica no servidor; a cor é escolha da UI.
            'urgency' => $urgency,
            'urgency_rank' => QuoteFollowupChargeService::urgencyRank($urgency),

            'missing_since' => $r['missing_since'], // UTC
            'is_missing' => ($r['missing_since'] ?? null) !== null,
        ];
    }

    private function policy(): FollowupDatePolicy
    {
        return FollowupDatePolicy::fromPolicyRow($this->policyRow());
    }

    /** @return array<string,mixed> */
    private function policyRow(): array
    {
        /** @var array<string,array<string,mixed>> $cache */
        static $cache = [];
        $cacheKey = spl_object_id($this->pdo) . ':' . $this->companyId;
        if (array_key_exists($cacheKey, $cache)) { return $cache[$cacheKey]; }

        $st = $this->pdo->prepare(
            'SELECT company_id, cadence_days_json, cadence_model, weekend_policy, holiday_policy,
                    critical_after_step, stale_after_minutes, enabled
               FROM ' . self::T_POLICY . ' WHERE company_id = :cid'
        );
        $st->execute(['cid' => $this->companyId]);

        return $cache[$cacheKey] = ($st->fetch(PDO::FETCH_ASSOC) ?: []);
    }
}
