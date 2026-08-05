<?php

declare(strict_types=1);

namespace BIomie\Canonical\Support;

/**
 * Normaliza o valor de `fonte_sync` para o dominio real da coluna.
 *
 * ─── O BUG QUE ISTO CORRIGE ─────────────────────────────────────────────────
 * `fonte_sync` e `ENUM('cron','manual','webhook')` nas quatro tabelas canonicas.
 * O sync passava o GATILHO direto para la — `cron-delta`, `cron-backfill`,
 * `job` — e nenhum deles e membro. Sob STRICT_TRANS_TABLES isso vira
 * SQLSTATE[01000] 1265 "Data truncated for column 'fonte_sync'" e derruba a
 * gravacao inteira; ANTES do STRICT, virava a string vazia em silencio. Ha
 * 9.882 linhas com `fonte_sync = ''` espalhadas pelas quatro tabelas, todas
 * dessa epoca.
 *
 * ─── POR QUE NORMALIZAR EM VEZ DE AMPLIAR O ENUM ────────────────────────────
 * `cron-delta` e `cron-backfill` nao sao FONTES, sao GATILHOS: os dois trazem o
 * dado do mesmo lugar (a API da Omie, por cron) e diferem apenas em quando e por
 * que rodaram. Ampliar o ENUM misturaria dois conceitos na mesma coluna e o
 * dominio cresceria a cada gatilho novo que alguem inventasse.
 *
 * O gatilho NAO se perde: ele ja e gravado onde pertence — `trigger_source` em
 * `biomie_sync_runs` e `source` em `biomie_sync_state` (que e VARCHAR e guarda
 * `cron-delta` sem problema).
 *
 * ─── POR QUE UM PONTO SO ────────────────────────────────────────────────────
 * As quatro escritas canonicas (pedido, NF, titulo, XML) faziam
 * `(string) ($x['fonte_sync'] ?? 'cron')` cada uma por conta propria. Consertar
 * nos quatro lugares sem um ponto comum deixaria o quinto — que aparece — livre
 * para repetir o defeito.
 */
final class CanonicalSyncSource
{
    /** Dominio REAL da coluna nas quatro tabelas canonicas. */
    public const DOMAIN = ['cron', 'manual', 'webhook'];

    private const DEFAULT = 'cron';

    /**
     * Mapeia gatilhos conhecidos para a fonte correspondente.
     *
     * Prefixo, nao igualdade: `cron-delta`, `cron-backfill` e qualquer
     * `cron-<qualquer coisa>` futuro caem em 'cron' sem precisar de manutencao.
     */
    private const PREFIX_MAP = [
        'cron'    => 'cron',
        'job'     => 'cron',
        'queue'   => 'cron',
        'webhook' => 'webhook',
        'manual'  => 'manual',
        'admin'   => 'manual',
        'cli'     => 'manual',
        'api'     => 'manual',
    ];

    /**
     * @param mixed $value valor cru vindo do payload de sync
     */
    public static function normalize(mixed $value): string
    {
        $raw = strtolower(trim((string) ($value ?? '')));
        if ($raw === '') {
            return self::DEFAULT;
        }
        if (in_array($raw, self::DOMAIN, true)) {
            return $raw;
        }

        foreach (self::PREFIX_MAP as $prefix => $target) {
            if ($raw === $prefix || str_starts_with($raw, $prefix . '-') || str_starts_with($raw, $prefix . '_')) {
                return $target;
            }
        }

        // Desconhecido cai no padrao em vez de estourar: a alternativa seria
        // derrubar o sync por causa do rotulo da procedencia, que e metadado.
        // O gatilho real continua registrado em biomie_sync_runs.trigger_source.
        return self::DEFAULT;
    }
}
