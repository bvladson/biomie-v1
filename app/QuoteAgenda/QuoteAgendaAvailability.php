<?php

declare(strict_types=1);

namespace BIomie\QuoteAgenda;

use BIomie\Config;
use PDO;
use Throwable;

/**
 * Resolve a disponibilidade de LEITURA da Agenda de Orcamentos.
 *
 * Ha dois gates independentes:
 *  - o interruptor global de ambiente e um kill switch quando explicitamente OFF;
 *  - a politica `enabled` libera somente a empresa ativa.
 *
 * Se a variavel global estiver ausente, a politica por empresa e a fonte de
 * verdade. Assim um deploy que nao copie `.env.local.php` nao apaga a tela das
 * empresas previamente ativadas, sem liberar empresas ainda nao aprovadas.
 *
 * Este resolver nunca inicia scanner, webhook, notificacao ou qualquer escrita.
 */
final class QuoteAgendaAvailability
{
    /**
     * @return array{
     *   enabled:bool,
     *   reason:string,
     *   error:string|null,
     *   message:string,
     *   global_mode:string,
     *   company_policy_enabled:bool|null
     * }
     */
    public static function forCompany(Config $config, PDO $pdo, int $companyId): array
    {
        $globalEnabled = (bool) $config->get('features.quote_agenda_enabled', false);
        $globalExplicit = (bool) $config->get('features.quote_agenda_enabled_explicit', true);

        // OFF explicito sempre vence. Nao consulta o banco e nao pode ser
        // contornado por uma politica de empresa que tenha ficado ligada.
        if ($globalExplicit && !$globalEnabled) {
            return self::fromSignals($globalEnabled, true, null);
        }

        if ($companyId <= 0) {
            return self::state(
                false,
                'tenant_unavailable',
                'tenant_unavailable',
                'A Agenda de Orcamentos exige uma empresa ativa por vez.',
                $globalExplicit ? 'on' : 'company_policy',
                null,
            );
        }

        try {
            $st = $pdo->prepare(
                'SELECT enabled
                   FROM biomie_quote_followup_policy
                  WHERE company_id = :company_id
                  LIMIT 1'
            );
            $st->execute(['company_id' => $companyId]);
            $raw = $st->fetchColumn();
        } catch (Throwable) {
            return self::state(
                false,
                'availability_unavailable',
                'feature_status_unavailable',
                'Nao foi possivel confirmar a disponibilidade da Agenda agora.',
                $globalExplicit ? 'on' : 'company_policy',
                null,
            );
        }

        $companyEnabled = $raw === false ? null : (bool) $raw;

        return self::fromSignals($globalEnabled, $globalExplicit, $companyEnabled);
    }

    /**
     * Funcao pura usada pelo runtime e pelos testes do gate.
     *
     * @return array{
     *   enabled:bool,
     *   reason:string,
     *   error:string|null,
     *   message:string,
     *   global_mode:string,
     *   company_policy_enabled:bool|null
     * }
     */
    public static function fromSignals(
        bool $globalEnabled,
        bool $globalExplicit,
        ?bool $companyEnabled,
    ): array {
        $globalMode = $globalExplicit
            ? ($globalEnabled ? 'on' : 'off')
            : 'company_policy';

        if ($globalExplicit && !$globalEnabled) {
            return self::state(
                false,
                'global_disabled',
                'feature_disabled',
                'A Agenda de Orcamentos esta temporariamente pausada.',
                $globalMode,
                $companyEnabled,
            );
        }

        if ($companyEnabled === null) {
            return self::state(
                false,
                'company_not_configured',
                'company_feature_not_configured',
                'A Agenda de Orcamentos ainda nao foi configurada para esta empresa.',
                $globalMode,
                null,
            );
        }

        if (!$companyEnabled) {
            return self::state(
                false,
                'company_disabled',
                'company_feature_disabled',
                'A Agenda de Orcamentos ainda nao foi ativada para esta empresa.',
                $globalMode,
                false,
            );
        }

        return self::state(
            true,
            'enabled',
            null,
            '',
            $globalMode,
            true,
        );
    }

    /**
     * @return array{
     *   enabled:bool,
     *   reason:string,
     *   error:string|null,
     *   message:string,
     *   global_mode:string,
     *   company_policy_enabled:bool|null
     * }
     */
    private static function state(
        bool $enabled,
        string $reason,
        ?string $error,
        string $message,
        string $globalMode,
        ?bool $companyPolicyEnabled,
    ): array {
        return [
            'enabled' => $enabled,
            'reason' => $reason,
            'error' => $error,
            'message' => $message,
            'global_mode' => $globalMode,
            'company_policy_enabled' => $companyPolicyEnabled,
        ];
    }
}
