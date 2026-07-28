<?php

declare(strict_types=1);

namespace BIomie\QuoteAgenda;

/**
 * AGENDA DE ORÇAMENTOS — decisão sobre UM pedido consultado no Omie (Fase 2).
 *
 * Decisão PURA, sem I/O: recebe o que o Omie respondeu sobre um pedido e diz o
 * que fazer com ele na Agenda.
 *
 * ─── POR QUE ISTO EXISTE SEPARADO ───────────────────────────────────────────
 * Tirar um orçamento da Agenda por engano é invisível: ele simplesmente some e
 * o vendedor nunca é cobrado. O caminho perigoso não é o sucesso — é o ERRO de
 * rede. Um timeout, um 429 ou um 500 NUNCA podem ser lidos como "sumiu".
 *
 * ─── AUSÊNCIA NÃO É PROVA; CONFIRMAÇÃO É ────────────────────────────────────
 * Só duas coisas desativam um orçamento:
 *   · o Omie CONFIRMA outra etapa (virou pedido, faturou, cancelou…)
 *   · o Omie CONFIRMA que o pedido não existe mais ("não cadastrado")
 * Qualquer outra coisa — inclusive não saber — mantém o orçamento na Agenda.
 *
 * @see QuoteOrderRefresher  aplica esta decisão e faz o I/O
 */
final class QuoteOrderDecision
{
    /** Continua sendo orçamento (etapa 10): atualiza e mantém. */
    public const KEEP = 'keep';
    /** Saiu da etapa 10 com etapa CONFIRMADA: resolve com o motivo. */
    public const RESOLVE = 'resolve';
    /** Omie confirmou que o pedido não existe: resolve como vanished. */
    public const VANISHED = 'vanished';
    /** Não deu para saber (timeout/429/500/resposta ilegível): NÃO mexe. */
    public const RETRY = 'retry';

    /** Etapa do Omie → resolução. Só etapas CONHECIDAS resolvem. */
    private const ETAPA_RESOLUTION = [
        '20' => 'converted',   // virou pedido/autorizado
        '50' => 'invoiced',    // faturado
        '60' => 'closed',      // entrega finalizada (pós-venda)
        '70' => 'cancelled',   // cancelado
        '80' => 'returned',    // devolvido
    ];

    /** Erros da Omie que significam "não existe mais" — confirmação, não palpite. */
    private const NOT_FOUND_MARKERS = [
        'pedido nao cadastrado',
        'pedido não cadastrado',
        'nao cadastrado',
        'não cadastrado',
        'not found',
    ];

    /**
     * HTTP/estados que são FALHA TRANSITÓRIA. Nunca desativam.
     * 425 (Too Early) e 429 (Too Many Requests) são throttle; 5xx é servidor.
     */
    private const TRANSIENT_HTTP = [408, 425, 429, 500, 502, 503, 504];

    /**
     * Decide a partir de uma consulta BEM SUCEDIDA (o Omie respondeu o pedido).
     *
     * @param string|int|null $etapa etapa devolvida pelo Omie
     * @return array{action:string,resolution:?string,reason:string}
     */
    public static function fromEtapa(mixed $etapa): array
    {
        $e = trim((string) ($etapa ?? ''));

        if ($e === '') {
            // Pedido existe mas sem etapa legível: não sabemos. NÃO desativa.
            return self::r(self::RETRY, null, 'etapa ausente ou ilegível na resposta');
        }
        if ($e === '10') {
            return self::r(self::KEEP, null, 'segue em Orçamento (etapa 10)');
        }
        if (isset(self::ETAPA_RESOLUTION[$e])) {
            return self::r(self::RESOLVE, self::ETAPA_RESOLUTION[$e], "saiu para a etapa {$e}");
        }

        // Etapa DESCONHECIDA: o Omie pode ter etapas customizadas por empresa.
        // Resolver às cegas removeria da Agenda um pedido que talvez ainda seja
        // acionável — preferimos manter e registrar para investigação humana.
        return self::r(self::RETRY, null, "etapa desconhecida ({$e}) — mantido por segurança");
    }

    /**
     * Decide a partir de uma FALHA de consulta.
     *
     * @param int|null $httpStatus status HTTP, quando houver
     * @param string   $errorText  mensagem de erro do Omie/transporte
     * @return array{action:string,resolution:?string,reason:string}
     */
    public static function fromFailure(?int $httpStatus, string $errorText): array
    {
        $txt = self::normalize($errorText);

        // Throttle/servidor/timeout ⇒ SEMPRE retry. Checado ANTES do "não
        // encontrado" porque um 429 pode vir com corpo genérico.
        if ($httpStatus !== null && in_array($httpStatus, self::TRANSIENT_HTTP, true)) {
            return self::r(self::RETRY, null, "falha transitória HTTP {$httpStatus}");
        }
        if (self::looksTransient($txt)) {
            return self::r(self::RETRY, null, 'falha transitória de transporte');
        }

        // "Não cadastrado" é CONFIRMAÇÃO de exclusão na origem.
        foreach (self::NOT_FOUND_MARKERS as $marker) {
            if (str_contains($txt, $marker)) {
                return self::r(self::VANISHED, 'vanished', 'Omie confirmou que o pedido não existe');
            }
        }

        // Erro desconhecido: na dúvida, mantém.
        return self::r(self::RETRY, null, 'erro não classificado — mantido por segurança');
    }

    /** Marcadores de falha transitória em texto livre. */
    private static function looksTransient(string $txt): bool
    {
        foreach ([
            'timeout', 'timed out', 'time-out', 'connection', 'conexao', 'conexão',
            'temporariamente', 'temporarily', 'try again', 'tente novamente',
            'reagende', 'consumo indevido', 'rate limit', 'too many',
            'ssl', 'network', 'could not resolve', 'gateway',
        ] as $m) {
            if (str_contains($txt, $m)) {
                return true;
            }
        }

        return false;
    }

    private static function normalize(string $s): string
    {
        $s = mb_strtolower(trim($s));
        // Remove acentos para casar "não cadastrado" e "nao cadastrado".
        return strtr($s, [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a',
            'é' => 'e', 'ê' => 'e', 'í' => 'i',
            'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c',
        ]);
    }

    /** @return array{action:string,resolution:?string,reason:string} */
    private static function r(string $action, ?string $resolution, string $reason): array
    {
        return ['action' => $action, 'resolution' => $resolution, 'reason' => $reason];
    }

    /**
     * A decisão retira o orçamento da Agenda?
     *
     * @param array{action?:string,resolution?:?string,reason?:string} $decision
     */
    public static function deactivates(array $decision): bool
    {
        return in_array($decision['action'] ?? '', [self::RESOLVE, self::VANISHED], true);
    }
}
