<?php

declare(strict_types=1);

namespace BIomie\QuoteAgenda;

use PDO;
use Throwable;

/**
 * AGENDA DE ORÇAMENTOS — refresh DIRECIONADO de um pedido (Fase 2).
 *
 * Gatilho principal: webhook da Omie. A reconciliação periódica (scanner) é a
 * rede de segurança — não o caminho normal.
 *
 * ─── GARANTIAS ──────────────────────────────────────────────────────────────
 * · DEBOUNCE por pedido: não reconsulta o MESMO registro dentro da janela
 *   (a Omie restringe chamadas redundantes ao mesmo registro em 60s).
 * · CONFIRMAÇÃO, nunca inferência: só desativa com etapa confirmada ou
 *   "não cadastrado". Timeout/425/429/5xx ⇒ retry, o orçamento FICA.
 *   (A regra vive em {@see QuoteOrderDecision}, testada isoladamente.)
 * · LOG SEMPRE: toda decisão vira linha em biomie_quote_order_refresh_log —
 *   inclusive `retry`. É o que prova depois que a rede não apagou nada.
 * · DRY-RUN: decide e registra (applied=0) sem tocar no acompanhamento.
 *
 * Somente LEITURA no Omie: este serviço nunca escreve no ERP.
 */
final class QuoteOrderRefresher
{
    private const T_LOG = 'biomie_quote_order_refresh_log';
    private const T_SCHEDULE = 'biomie_sales_quote_followup_schedule';

    /**
     * DEBOUNCE POR PEDIDO (segundos) — NÃO confundir com o cooldown do botão.
     *
     * São dois controles com propósitos distintos:
     *  · DEBOUNCE (aqui): evita RECONSULTAR o MESMO pedido à Omie em rajada. A
     *    Omie relibera o mesmo registro após 60s; usamos 75s (60 + 15s de margem)
     *    para nunca bater na fronteira exata e levar um 425/"aguarde".
     *  · COOLDOWN DO BOTÃO ({@see api/quote_agenda_refresh_request.php},
     *    REMOTE_COOLDOWN_SECONDS=120): limita quantas vezes o gestor dispara uma
     *    sincronização remota da EMPRESA inteira. É por-empresa, não por-pedido.
     * Um não substitui o outro; vivem em camadas diferentes de propósito.
     */
    public const DEBOUNCE_SECONDS = 75;

    /**
     * Origens válidas. A coluna `source` é NOT NULL SEM default justamente para
     * que um fluxo novo sem origem explícita falhe — mas o sql_mode deste
     * servidor NÃO é estrito (`NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION`),
     * então o MariaDB inseriria '' em silêncio em vez de recusar. A garantia
     * precisa existir aqui no código para valer de fato.
     */
    public const SOURCES = ['webhook', 'manual', 'cli', 'scanner'];

    /**
     * Origens AUTOMÁTICAS cuja DESATIVAÇÃO (resolve/vanished) passa pelo portão
     * enquanto a auto-desativação estiver desligada. Cobre a corrida entre o
     * webhook e o scanner: qualquer um deles que confirme a saída da etapa 10
     * fica RETIDO até a 1ª validação manual — não importa quem chegou primeiro.
     *
     * `cli` fica DE FORA de propósito: é o único caminho deliberado (exige
     * intenção explícita, pedido e empresa no comando) e por isso pode aplicar.
     */
    public const AUTO_DEACTIVATION_SOURCES = ['webhook', 'scanner', 'manual'];

    /** Cache da checagem da tabela de log (null = ainda não verificado). */
    private ?bool $logReady = null;

    /**
     * @param callable(string):array{ok?:bool,etapa?:mixed,error?:string,http?:?int} $consultar
     *        recebe o omie_order_id e devolve
     *        ['ok'=>bool, 'etapa'=>mixed, 'error'=>string, 'http'=>?int].
     *        Injetado para o serviço ser testável sem rede. As chaves são
     *        OPCIONAIS de propósito: o callable vem de fora e pode devolver algo
     *        malformado — nesse caso tratamos como falha (⇒ retry), nunca como
     *        confirmação de que o pedido sumiu.
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly int $companyId,
        private $consultar,
    ) {
    }

    /**
     * Atualiza UM pedido. `$apply=false` decide e registra sem mutar a Agenda.
     *
     * PORTÃO DA DESATIVAÇÃO (centralizado aqui, não em cada caller):
     * enquanto `$autoDeactivationEnabled=false` (padrão SEGURO), uma decisão
     * resolve/vanished vinda de origem AUTOMÁTICA ({@see AUTO_DEACTIVATION_SOURCES}
     * = webhook/scanner/manual) faz uma EXECUÇÃO REAL (não dry-run) que decide e
     * registra normalmente, MAS RETÉM a desativação: não mexe no acompanhamento e
     * devolve `held=true` (log em `is_dry_run=0, applied=0`). keep/retry seguem
     * automáticos em qualquer origem. `cli` nunca é retido (caminho deliberado).
     *
     * O default false é proposital: um caller novo que esqueça o parâmetro NÃO
     * consegue desativar por acidente — cai no lado seguro (retém).
     *
     * @return array<string,mixed>
     */
    public function refresh(
        string $omieOrderId,
        string $source = 'webhook',
        bool $apply = false,
        bool $autoDeactivationEnabled = false
    ): array {
        $orderId = trim($omieOrderId);
        if ($orderId === '' || !ctype_digit($orderId)) {
            return ['ok' => false, 'skipped' => true, 'reason' => 'omie_order_id inválido', 'order_id' => $omieOrderId];
        }

        // Origem obrigatória e conhecida. Sem isto, o servidor não-estrito
        // gravaria source='' e a auditoria passaria a mentir sobre de onde a
        // decisão veio — pior que não ter a linha.
        if (!in_array($source, self::SOURCES, true)) {
            return [
                'ok' => false, 'skipped' => true, 'order_id' => $orderId,
                'reason' => sprintf('origem "%s" inválida (esperado: %s) — nada consultado nem gravado',
                    $source, implode('|', self::SOURCES)),
            ];
        }

        // ── PRÉ-CONDIÇÃO: a trilha precisa existir ──────────────────────────
        // Sem a tabela de log não há debounce (não sabemos se acabamos de
        // consultar) NEM auditoria (não conseguiríamos provar depois por que um
        // orçamento saiu da Agenda). Agir às cegas nessas condições contraria a
        // regra do módulo — então recusamos, em vez de estourar ou adivinhar.
        if (!$this->logTableReady()) {
            return [
                'ok' => false, 'skipped' => true, 'order_id' => $orderId,
                'reason' => 'log/debounce indisponível (migração da Fase 2 pendente) — nada foi consultado nem alterado',
            ];
        }

        // ── DEBOUNCE ────────────────────────────────────────────────────────
        // Evita martelar o mesmo registro quando o Omie dispara vários eventos
        // em sequência (edição salva N vezes). Não é cache do resultado: é
        // recusa de CHAMADA — o dado já foi confirmado há segundos.
        $since = $this->secondsSinceLastCheck($orderId);
        if ($since !== null && $since < self::DEBOUNCE_SECONDS) {
            return [
                'ok' => true, 'skipped' => true, 'debounced' => true,
                'order_id' => $orderId, 'seconds_since_last_check' => $since,
                'reason' => sprintf('consultado há %ds (debounce de %ds)', $since, self::DEBOUNCE_SECONDS),
            ];
        }

        // ── CONSULTA (somente leitura) ──────────────────────────────────────
        try {
            $resp = ($this->consultar)($orderId);
        } catch (Throwable $e) {
            // Exceção de transporte é falha transitória por definição.
            $resp = ['ok' => false, 'error' => $e->getMessage(), 'http' => null];
        }

        $decision = ($resp['ok'] ?? false) === true
            ? QuoteOrderDecision::fromEtapa($resp['etapa'] ?? null)
            : QuoteOrderDecision::fromFailure(
                isset($resp['http']) ? (int) $resp['http'] : null,
                (string) ($resp['error'] ?? '')
            );

        $etapaSeen = ($resp['ok'] ?? false) === true && ($resp['etapa'] ?? null) !== null
            ? substr((string) $resp['etapa'], 0, 8) : null;

        // ── PORTÃO (centralizado) ───────────────────────────────────────────
        // Retém a desativação quando: é execução real, a decisão desativa, a
        // auto-desativação está DESLIGADA e a origem é automática. Vale para
        // webhook E scanner — fecha a corrida entre eles (quem confirmar a saída
        // primeiro fica retido igual). `cli` não entra na lista ⇒ nunca retido.
        $mustHold = $apply
            && QuoteOrderDecision::deactivates($decision)
            && !$autoDeactivationEnabled
            && in_array($source, self::AUTO_DEACTIVATION_SOURCES, true);

        // ── APLICAÇÃO ───────────────────────────────────────────────────────
        $mutated = 0;
        $held = false;
        if ($apply && QuoteOrderDecision::deactivates($decision)) {
            if ($mustHold) {
                // Decisão real registrada, desativação RETIDA p/ validação
                // manual. Nada muta; applied fica 0 (mutated=0).
                $held = true;
            } else {
                $mutated = $this->deactivate($orderId, (string) $decision['resolution']);
            }
        } elseif ($apply && $decision['action'] === QuoteOrderDecision::KEEP) {
            // Reapareceu/segue vivo: limpa marca de ausência pendente.
            $mutated = $this->clearMissing($orderId);
        }

        // ── LOG (sempre, inclusive retry e dry-run) ────────────────────────
        // `source` é SEMPRE a origem real (webhook/manual/cli/scanner) — o modo
        // vai em `is_dry_run`. Antes o dry-run sobrescrevia a origem, o que
        // apagava de onde a execução tinha vindo.
        // `applied` = houve MUTAÇÃO de fato (não "rodou em modo apply").
        $this->log(
            $orderId, $decision, $etapaSeen, $source,
            $resp['http'] ?? null,
            !$apply,                       // is_dry_run
            $apply && $mutated > 0         // applied
        );

        return [
            'ok' => true,
            'order_id' => $orderId,
            'action' => $decision['action'],
            'resolution' => $decision['resolution'],
            'reason' => $decision['reason'],
            'etapa_seen' => $etapaSeen,
            'deactivates' => QuoteOrderDecision::deactivates($decision),
            'applied' => $apply && $mutated > 0,  // mutação de fato (não "modo apply")
            'held' => $held,                       // desativação decidida mas RETIDA
            'rows_changed' => $mutated,
        ];
    }

    /**
     * A tabela de log/debounce existe? Cacheado por instância.
     *
     * Funciona tanto com a tabela real quanto com uma TEMPORARY de mesmo nome
     * (usada nos testes), porque a checagem é uma consulta trivial e não o
     * INFORMATION_SCHEMA — que não enxerga tabelas temporárias.
     */
    public function logTableReady(): bool
    {
        if ($this->logReady !== null) {
            return $this->logReady;
        }
        try {
            $this->pdo->query('SELECT 1 FROM ' . self::T_LOG . ' LIMIT 1');

            return $this->logReady = true;
        } catch (Throwable) {
            return $this->logReady = false;
        }
    }

    /** Segundos desde a última consulta a este pedido, ou null se nunca. */
    public function secondsSinceLastCheck(string $orderId): ?int
    {
        $st = $this->pdo->prepare(
            'SELECT TIMESTAMPDIFF(SECOND, MAX(checked_at), NOW())
               FROM ' . self::T_LOG . '
              WHERE company_id = :c AND omie_order_id = :o'
        );
        $st->execute(['c' => $this->companyId, 'o' => $orderId]);
        $v = $st->fetchColumn();

        return $v === null || $v === false ? null : (int) $v;
    }

    /**
     * Resolve o acompanhamento. NÃO apaga: marca resolvido com o motivo, para a
     * trilha (e as cobranças já registradas) permanecerem auditáveis.
     */
    private function deactivate(string $orderId, string $resolution): int
    {
        $st = $this->pdo->prepare(
            'UPDATE ' . self::T_SCHEDULE . '
                SET resolved_at = COALESCE(resolved_at, NOW()),
                    resolution = COALESCE(resolution, :res),
                    updated_at = NOW()
              WHERE company_id = :c AND omie_order_id = :o AND resolved_at IS NULL'
        );
        $st->execute(['res' => $resolution, 'c' => $this->companyId, 'o' => $orderId]);

        return $st->rowCount();
    }

    /** Confirmado vivo: zera ausência pendente (não mexe em cobrança/step). */
    private function clearMissing(string $orderId): int
    {
        $st = $this->pdo->prepare(
            'UPDATE ' . self::T_SCHEDULE . '
                SET missing_since = NULL, reconciliation_attempts = 0,
                    last_seen_at = NOW(), updated_at = NOW()
              WHERE company_id = :c AND omie_order_id = :o
                AND resolved_at IS NULL AND missing_since IS NOT NULL'
        );
        $st->execute(['c' => $this->companyId, 'o' => $orderId]);

        return $st->rowCount();
    }

    /**
     * Registra a decisão. MODO (`is_dry_run`) e MUTAÇÃO (`applied`) são campos
     * distintos de propósito — ver o comentário da migration 20260722000000.
     *
     * @param array{action:string,resolution:?string,reason:string} $decision
     */
    private function log(
        string $orderId,
        array $decision,
        ?string $etapaSeen,
        string $source,
        ?int $http,
        bool $isDryRun,
        bool $applied
    ): void {
        try {
            $st = $this->pdo->prepare(
                'INSERT INTO ' . self::T_LOG . '
                    (company_id, omie_order_id, decision, resolution, etapa_seen, reason,
                     source, http_status, is_dry_run, applied, checked_at, created_at)
                 VALUES (:c, :o, :d, :res, :et, :rz, :src, :http, :dry, :ap, NOW(), NOW())'
            );
            $st->execute([
                'c' => $this->companyId,
                'o' => $orderId,
                'd' => $decision['action'],
                'res' => $decision['resolution'],
                'et' => $etapaSeen,
                'rz' => substr($decision['reason'], 0, 255),
                'src' => $source,
                'http' => $http,
                'dry' => $isDryRun ? 1 : 0,
                'ap' => $applied ? 1 : 0,
            ]);
        } catch (Throwable) {
            // O log é importante, mas perdê-lo não pode derrubar o refresh.
        }
    }
}
