<?php

declare(strict_types=1);

namespace BIomie\QuoteAgenda;

use BIomie\Acl\Roles;
use BIomie\Notifications\NotificationService;
use BIomie\Ops\AlertNotifier;
use DateTimeImmutable;
use PDO;

/**
 * AGENDA DE ORÇAMENTOS — emissão das notificações (Fase 8).
 *
 * Aplica a decisão de {@see QuoteAgendaNotificationPolicy} e faz o I/O:
 * destinatários, deduplicação, cooldown, canais e auditoria.
 *
 * ─── DEDUPLICAÇÃO E COOLDOWN SÃO O MESMO MECANISMO ──────────────────────────
 * Não criamos tabela de estado: `biomie_notifications` JÁ é o livro-razão.
 * `dedupe_key` + janela dão os dois comportamentos, mudando só a chave:
 *   · digest → chave COM o dia civil  ⇒ no máximo 1 por empresa por dia
 *   · stale  → chave SEM o dia + janela de cooldown ⇒ no máximo 1 a cada N horas
 * A consequência boa: o que o gestor vê no sininho é exatamente o que serviu
 * de trava. Não há um segundo estado que possa divergir do primeiro.
 *
 * ─── AGREGADO, NUNCA POR ORÇAMENTO ──────────────────────────────────────────
 * Uma mensagem por empresa por dia. 41 mensagens seriam ignoradas em bloco na
 * primeira semana, e aí a Agenda perderia o canal para sempre.
 *
 * ─── QUEM RECEBE ────────────────────────────────────────────────────────────
 * Só quem tem `sales.quotes.followup.view` NAQUELA empresa. O vendedor não
 * entra no BIomie e nunca é destinatário: quem cobra é o gestor.
 */
final class QuoteAgendaNotifier
{
    private const CAPABILITY = 'sales.quotes.followup.view';

    /** Digest: uma vez por dia civil. Janela larga cobre reexecuções do cron. */
    private const DIGEST_WINDOW_SECONDS = 20 * 3600;

    /** Fonte vencida: no máximo 1 alerta a cada 6h por empresa. */
    private const STALE_COOLDOWN_SECONDS = 6 * 3600;

    public function __construct(
        private readonly PDO $pdo,
        private readonly NotificationService $notifications,
        private readonly ?AlertNotifier $alerts = null,
    ) {
    }

    /**
     * Processa uma empresa. `$apply=false` decide e explica sem emitir nada.
     *
     * Os dois eventos têm cadências naturais diferentes: o digest é diário (e
     * deve chegar de manhã), o alerta de fonte vencida é frequente (para pegar
     * um sync caído rápido). `$only` deixa o cron escolher qual processar sem
     * duplicar a leitura de dados. `null` = ambos (uso interativo/dry-run).
     *
     * @param array<string,bool> $flags module/notifications/policy — chave ausente = desligado.
     * @param array<string,mixed> $freshness
     * @param list<array<string,mixed>> $items
     * @param 'digest'|'stale'|null $only
     * @return array<string,mixed>
     */
    public function runForCompany(
        int $companyId,
        string $companySlug,
        array $flags,
        array $freshness,
        array $items,
        bool $apply = false,
        ?string $only = null
    ): array {
        $recipients = $this->recipientsFor($companyId);

        $digest = QuoteAgendaNotificationPolicy::decideDigest($flags, $freshness, $items, count($recipients));
        $stale  = QuoteAgendaNotificationPolicy::decideStale($flags, $freshness);

        $out = [
            'company_id'   => $companyId,
            'company_slug' => $companySlug,
            'recipients'   => count($recipients),
            'applied'      => $apply,
            'digest'       => $digest + ['emitted' => false, 'dedup_hit' => false],
            'stale'        => $stale  + ['emitted' => false, 'dedup_hit' => false],
        ];

        if ($only !== 'stale' && $digest['decision'] === 'send') {
            $out['digest'] = array_merge(
                $out['digest'],
                $this->emitDigest($companyId, $companySlug, $digest, $freshness, $apply)
            );
        }
        if ($only !== 'digest' && $stale['decision'] === 'send') {
            $out['stale'] = array_merge(
                $out['stale'],
                $this->emitStale($companyId, $companySlug, $stale, $apply)
            );
        }

        return $out;
    }

    /**
     * Destinatários com a capability NAQUELA empresa.
     *
     * `role_override` em biomie_user_companies vence o papel global: um usuário
     * pode ser manager no grupo e apenas leitor numa empresa específica.
     *
     * @return list<array{id:int,username:string,role:string}>
     */
    public function recipientsFor(int $companyId): array
    {
        $st = $this->pdo->prepare(
            'SELECT u.id, u.username, u.role, uc.role_override
               FROM biomie_users u
               JOIN biomie_user_companies uc ON uc.user_id = u.id
              WHERE u.is_active = 1 AND uc.company_id = :cid'
        );
        $st->execute(['cid' => $companyId]);

        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $role = trim((string) ($r['role_override'] ?? '')) !== ''
                ? (string) $r['role_override']
                : (string) $r['role'];
            if (!in_array(self::CAPABILITY, Roles::capabilities($role), true)) {
                continue;
            }
            $out[] = ['id' => (int) $r['id'], 'username' => (string) $r['username'], 'role' => $role];
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $d
     * @param array<string,mixed> $freshness
     * @return array<string,mixed>
     */
    private function emitDigest(int $companyId, string $slug, array $d, array $freshness, bool $apply): array
    {
        $day = (new DateTimeImmutable('now', FollowupDatePolicy::timezone()))->format('Y-m-d');
        $key = sprintf('quote_agenda:digest:%d:%s', $companyId, $day);

        $counts = $d['counts'];
        $title = sprintf('Agenda de Orçamentos · %d para cobrar hoje', (int) $d['actionable']);

        $lines = [];
        if ($counts['overdue'] > 0 || $counts['critical'] > 0) {
            $lines[] = sprintf('%d atrasado(s), sendo %d crítico(s)',
                (int) $counts['overdue'] + (int) $counts['critical'], (int) $counts['critical']);
        }
        if ($counts['today'] > 0) {
            $lines[] = sprintf('%d vence(m) hoje', (int) $counts['today']);
        }
        if ($d['total_value'] !== null) {
            $lines[] = 'Valor em jogo: ' . self::brl((float) $d['total_value'])
                // Total parcial é dito como parcial. Um número "redondo" que
                // omite itens sem valor induziria a leitura errada.
                . ((int) $d['unknown_value_count'] > 0
                    ? sprintf(' (%d sem valor conhecido, fora do total)', (int) $d['unknown_value_count'])
                    : '');
        }
        if (($d['provisional_count'] ?? 0) > 0) {
            $lines[] = sprintf('%d com data provisória — idade é "pelo menos", não exata',
                (int) $d['provisional_count']);
        }
        if (($d['unreconciled_count'] ?? 0) > 0) {
            $lines[] = sprintf('%d fora da cobrança: sumiram do Omie e ainda não foram reconciliados',
                (int) $d['unreconciled_count']);
        }
        $lines[] = sprintf('Dados do Omie de %d min atrás', (int) ($freshness['age_minutes'] ?? 0));

        if (!$apply) {
            return ['emitted' => false, 'preview' => ['title' => $title, 'lines' => $lines, 'dedupe_key' => $key]];
        }

        // Trava ANTES de qualquer canal: se já saiu hoje, nada é reenviado.
        if ($this->alreadySent($key, $companyId, self::DIGEST_WINDOW_SECONDS)) {
            return ['emitted' => false, 'dedup_hit' => true, 'dedupe_key' => $key];
        }

        $id = $this->notifications->push([
            'category'   => 'business',
            'severity'   => (string) $d['severity'],
            'priority'   => 'high',
            'icon'       => 'event_upcoming',
            'title'      => $title,
            'body'       => implode(' · ', $lines),
            'link'       => '/quote_agenda.php',
            'company_id' => $companyId,
            'source'     => 'quote_agenda',
            'dedupe_key' => $key,
            'dedupe_window_seconds' => self::DIGEST_WINDOW_SECONDS,
        ]);

        // push() devolve null tanto por dedup quanto por ERRO. Já descartamos
        // dedup acima, então null aqui é falha de gravação: não seguimos para o
        // Telegram, senão a mensagem sairia sem ficar registrada e o próximo
        // ciclo a enviaria de novo.
        if ($id === null) {
            return ['emitted' => false, 'error' => 'notification_write_failed', 'dedupe_key' => $key];
        }

        $this->alerts?->send((string) $d['severity'], sprintf('[%s] %s', $slug, $title), $lines);

        return ['emitted' => true, 'notification_id' => $id, 'dedupe_key' => $key];
    }

    /**
     * @param array<string,mixed> $s
     * @return array<string,mixed>
     */
    private function emitStale(int $companyId, string $slug, array $s, bool $apply): array
    {
        // SEM o dia na chave: a janela É o cooldown.
        $key = sprintf('quote_agenda:stale:%d', $companyId);

        $title = ($s['never_synced'] ?? false)
            ? 'Agenda de Orçamentos · fonte nunca sincronizada'
            : sprintf('Agenda de Orçamentos · dados do Omie desatualizados (%d min)', (int) ($s['age_minutes'] ?? 0));

        $lines = [
            'A cobrança de vendedores foi SUSPENSA para evitar cobrança indevida.',
            'O digest diário não será enviado enquanto a fonte estiver vencida.',
        ];
        if (($s['error_code'] ?? null) !== null) {
            // Código sanitizado; o erro bruto fica só no log técnico.
            $lines[] = 'Código: ' . (string) $s['error_code'];
        }

        if (!$apply) {
            return ['emitted' => false, 'preview' => ['title' => $title, 'lines' => $lines, 'dedupe_key' => $key]];
        }

        if ($this->alreadySent($key, $companyId, self::STALE_COOLDOWN_SECONDS)) {
            return ['emitted' => false, 'dedup_hit' => true, 'dedupe_key' => $key];
        }

        $id = $this->notifications->push([
            'category'   => 'sync',
            'severity'   => 'warning',
            'priority'   => 'high',
            'icon'       => 'sync_problem',
            'title'      => $title,
            'body'       => implode(' · ', $lines),
            'company_id' => $companyId,
            'source'     => 'quote_agenda',
            'dedupe_key' => $key,
            'dedupe_window_seconds' => self::STALE_COOLDOWN_SECONDS,
        ]);
        if ($id === null) {
            return ['emitted' => false, 'error' => 'notification_write_failed', 'dedupe_key' => $key];
        }

        $this->alerts?->send('warning', sprintf('[%s] %s', $slug, $title), $lines);

        return ['emitted' => true, 'notification_id' => $id, 'dedupe_key' => $key];
    }

    /**
     * Já houve emissão desta chave dentro da janela?
     *
     * Consulta explícita (em vez de confiar no retorno de push()) para separar
     * "deduplicado" de "falhou ao gravar" — respostas operacionais opostas.
     */
    public function alreadySent(string $key, int $companyId, int $windowSeconds): bool
    {
        $st = $this->pdo->prepare(
            'SELECT 1 FROM biomie_notifications
              WHERE dedupe_key = :k
                AND (company_id <=> :cid)
                AND created_at >= (NOW() - INTERVAL :w SECOND)
              LIMIT 1'
        );
        $st->bindValue(':k', $key);
        $st->bindValue(':cid', $companyId, PDO::PARAM_INT);
        $st->bindValue(':w', max(1, $windowSeconds), PDO::PARAM_INT);
        $st->execute();

        return $st->fetchColumn() !== false;
    }

    private static function brl(float $v): string
    {
        return 'R$ ' . number_format($v, 2, ',', '.');
    }
}
