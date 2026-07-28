<?php

declare(strict_types=1);

namespace BIomie\QuoteAgenda;

/**
 * AGENDA DE ORÇAMENTOS — quando é LEGÍTIMO notificar o gestor (Fase 8).
 *
 * Decisão PURA, sem I/O: recebe o estado observado e devolve se a notificação
 * pode sair e o que ela tem direito de AFIRMAR.
 *
 * ─── POR QUE ISTO EXISTE SEPARADO ───────────────────────────────────────────
 * A notificação da Agenda termina numa pessoa cobrando outra pessoa. Se o dado
 * estiver velho, o gestor cobra um vendedor por um orçamento que já virou
 * pedido — e isso NÃO tem rollback: a mensagem já foi lida, a conversa já
 * aconteceu. O caso da EXPERT provou o dano concreto: R$133.000 já faturados
 * apareciam como carteira aberta porque o sync estava parado.
 *
 * Por isso a regra central: **dado vencido NUNCA vira cobrança**. Preferimos
 * silêncio a um número que parece atual e não é.
 *
 * ─── DOIS EVENTOS, DUAS AUDIÊNCIAS ──────────────────────────────────────────
 * · EVENT_DIGEST (negócio → gestor): SUPRIMIDO quando a fonte está vencida.
 * · EVENT_STALE  (técnico → operação): EMITIDO justamente quando está vencida.
 * São o mesmo fato visto por dois ângulos; misturá-los produziria ou silêncio
 * total (ninguém saberia que o sync caiu) ou cobrança indevida.
 *
 * @see QuoteAgendaNotifier  aplica esta decisão e faz o I/O
 */
final class QuoteAgendaNotificationPolicy
{
    public const EVENT_DIGEST = 'quote_agenda.daily_digest';
    public const EVENT_STALE  = 'quote_agenda.source_stale';

    /** Motivos de supressão — cada um é uma resposta diferente do operador. */
    public const R_OK                     = 'ok';
    public const R_MODULE_DISABLED        = 'module_disabled';
    public const R_NOTIFICATIONS_DISABLED = 'notifications_disabled';
    public const R_POLICY_DISABLED        = 'policy_disabled';
    public const R_NEVER_SYNCED           = 'never_synced';
    public const R_STALE_SOURCE           = 'stale_source';
    public const R_NOTHING_ACTIONABLE     = 'nothing_actionable';
    public const R_NO_RECIPIENTS          = 'no_recipients';

    /**
     * Um acompanhamento só é COBRÁVEL se todas forem verdadeiras.
     *
     * Deliberadamente conservador: na dúvida, fica de fora da cobrança e entra
     * como qualificador. Um item a menos na mensagem é irrelevante; um item
     * indevido a mais custa a confiança do vendedor no sistema.
     *
     * @param array<string,mixed> $item item de openItems()
     */
    public static function isChargeable(array $item): bool
    {
        // Já resolvido (virou pedido/NF/cancelou): cobrar seria absurdo.
        if (($item['resolved_at'] ?? null) !== null || ($item['resolution'] ?? null) !== null) {
            return false;
        }
        // Sumiu do snapshot vivo e a ausência NÃO foi confirmada. Não sabemos
        // se ainda existe — ausência nunca é prova. Fica fora da cobrança.
        if (($item['missing_since'] ?? null) !== null) {
            return false;
        }
        // Só vence hoje ou já venceu. Orçamento no prazo não gera cobrança.
        $urg = (string) ($item['urgency'] ?? '');

        return in_array($urg, ['today', 'overdue', 'critical'], true);
    }

    /**
     * Decide o digest de negócio.
     *
     * @param array<string,bool> $flags module_enabled/notifications_enabled/policy_enabled.
     *        Tipado como mapa aberto de propósito: as chaves vêm de config e do
     *        banco, então a AUSÊNCIA é um estado possível — e é tratada como
     *        "desligado", nunca como "ligado".
     * @param array<string,mixed> $freshness bloco de QuoteAgendaReadService::freshness()
     * @param list<array<string,mixed>> $items itens abertos da empresa
     * @param int $recipients quantidade de destinatários elegíveis
     * @return array<string,mixed>
     */
    public static function decideDigest(array $flags, array $freshness, array $items, int $recipients): array
    {
        $base = [
            'event'                => self::EVENT_DIGEST,
            'decision'             => 'suppress',
            'reason'               => self::R_OK,
            'claims_current_data'  => false,
            'actionable'           => 0,
            'counts'               => ['today' => 0, 'overdue' => 0, 'critical' => 0],
            'qualifiers'           => [],
            'total_value'          => null,
            'unknown_value_count'  => 0,
            'severity'             => 'info',
        ];

        // ── Portões de configuração ─────────────────────────────────────────
        // Ordem importa: o motivo devolvido orienta a ação do operador, então
        // reportamos o portão MAIS EXTERNO que está fechado.
        if (!($flags['module_enabled'] ?? false)) {
            return ['reason' => self::R_MODULE_DISABLED] + $base;
        }
        if (!($flags['notifications_enabled'] ?? false)) {
            return ['reason' => self::R_NOTIFICATIONS_DISABLED] + $base;
        }
        if (!($flags['policy_enabled'] ?? false)) {
            return ['reason' => self::R_POLICY_DISABLED] + $base;
        }

        // ── Portão de FRESCOR: o que impede a cobrança indevida ─────────────
        // Nunca "envia com ressalva": um número no corpo da mensagem é lido
        // como atual por quem o recebe, por mais avisos que existam em volta.
        if (($freshness['never_synced'] ?? true) === true) {
            return ['reason' => self::R_NEVER_SYNCED] + $base;
        }
        if (($freshness['is_stale'] ?? true) === true) {
            return ['reason' => self::R_STALE_SOURCE] + $base;
        }

        // ── Conteúdo ────────────────────────────────────────────────────────
        $counts = ['today' => 0, 'overdue' => 0, 'critical' => 0];
        $chargeable = [];
        $provisional = 0;
        $unreconciled = 0;
        $unknownValue = 0;
        $total = 0.0;
        $known = 0;

        foreach ($items as $item) {
            if (($item['missing_since'] ?? null) !== null
                && ($item['resolved_at'] ?? null) === null) {
                $unreconciled++;
            }
            if (!self::isChargeable($item)) {
                continue;
            }
            $chargeable[] = $item;

            $urg = (string) ($item['urgency'] ?? '');
            if (isset($counts[$urg])) {
                $counts[$urg]++;
            }
            // Data provisória SUBESTIMA a idade (first_seen = "quando o BIomie
            // viu", não quando o vendedor criou). A mensagem precisa dizer
            // "pelo menos", nunca cravar o número. Default `false` de propósito:
            // item sem o campo é tratado como provisório, não como oficial.
            if (($item['date_is_official'] ?? false) !== true) {
                $provisional++;
            }
            // Valor desconhecido NUNCA entra como zero: distorceria o total
            // para baixo e faria a cobrança parecer menos relevante do que é.
            if (($item['value'] ?? null) === null) {
                $unknownValue++;
            } else {
                $total += (float) $item['value'];
                $known++;
            }
        }

        $qualifiers = [];
        if ($provisional > 0)  { $qualifiers[] = 'provisional_dates'; }
        if ($unreconciled > 0) { $qualifiers[] = 'unreconciled_items'; }
        if ($unknownValue > 0) { $qualifiers[] = 'unknown_values'; }

        if ($chargeable === []) {
            return ['reason' => self::R_NOTHING_ACTIONABLE, 'qualifiers' => $qualifiers] + $base;
        }
        // Sem quem receber, "enviar" é só gravar ruído.
        if ($recipients <= 0) {
            return ['reason' => self::R_NO_RECIPIENTS, 'qualifiers' => $qualifiers] + $base;
        }

        return [
            'event'               => self::EVENT_DIGEST,
            'decision'            => 'send',
            'reason'              => self::R_OK,
            'claims_current_data' => true,
            'actionable'          => count($chargeable),
            'counts'              => $counts,
            'qualifiers'          => $qualifiers,
            'total_value'         => $known > 0 ? round($total, 2) : null,
            'unknown_value_count' => $unknownValue,
            'provisional_count'   => $provisional,
            'unreconciled_count'  => $unreconciled,
            'severity'            => $counts['critical'] > 0 ? 'warning' : 'info',
        ];
    }

    /**
     * Decide o alerta TÉCNICO de fonte vencida (audiência: operação).
     *
     * Depende de `policy_enabled`: o alerta existe para PROTEGER a cobrança
     * ativa de dados velhos. Se a cobrança está desligada para a empresa, não
     * há o que proteger, e uma empresa nunca ativada (ou sem orçamentos)
     * dispararia "nunca sincronizou" para sempre — puro ruído. O gate por
     * política é o sinal real de "esta empresa está em operação".
     *
     * Como as flags module/notifications são GLOBAIS e a política é POR
     * EMPRESA, sem este gate toda empresa inativa alertaria ao ligar o módulo.
     *
     * @param array<string,bool> $flags mapa aberto: chave ausente = desligado.
     * @param array<string,mixed> $freshness
     * @return array<string,mixed>
     */
    public static function decideStale(array $flags, array $freshness): array
    {
        $suppress = [
            'event'    => self::EVENT_STALE,
            'decision' => 'suppress',
            'reason'   => self::R_OK,
            'severity' => 'warning',
        ];

        if (!($flags['module_enabled'] ?? false)) {
            return ['reason' => self::R_MODULE_DISABLED] + $suppress;
        }
        if (!($flags['notifications_enabled'] ?? false)) {
            return ['reason' => self::R_NOTIFICATIONS_DISABLED] + $suppress;
        }
        if (!($flags['policy_enabled'] ?? false)) {
            return ['reason' => self::R_POLICY_DISABLED] + $suppress;
        }

        $never = ($freshness['never_synced'] ?? true) === true;
        $stale = ($freshness['is_stale'] ?? true) === true;
        if (!$never && !$stale) {
            return ['reason' => 'source_fresh'] + $suppress;
        }

        return [
            'event'        => self::EVENT_STALE,
            'decision'     => 'send',
            'reason'       => $never ? self::R_NEVER_SYNCED : self::R_STALE_SOURCE,
            'severity'     => 'warning',
            'age_minutes'  => $freshness['age_minutes'] ?? null,
            'never_synced' => $never,
            // Erro interno NUNCA vai para a mensagem: só o código sanitizado.
            'error_code'   => $freshness['error_code'] ?? null,
        ];
    }
}
