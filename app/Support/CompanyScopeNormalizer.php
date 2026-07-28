<?php

declare(strict_types=1);

namespace BIomie\Support;

/**
 * NORMALIZADOR DE ESCOPO DE EMPRESA — valida a operação pedida contra o autorizado.
 *
 * Não é uma allowlist simples: `mixed` (varejo+licitação numa empresa) só é
 * permitido quando o usuário tem OS DOIS canais. Fail-closed — combinação
 * inválida NUNCA cai para varejo por padrão; falha explícita.
 *
 *   Autorizado            Pedido      Resultado
 *   varejo                varejo      varejo
 *   varejo                licitacao   FORBIDDEN
 *   varejo                mixed       FORBIDDEN
 *   licitacao             licitacao   licitacao
 *   licitacao             varejo      FORBIDDEN
 *   licitacao             mixed       FORBIDDEN
 *   varejo+licitacao      varejo      varejo
 *   varejo+licitacao      licitacao   licitacao
 *   varejo+licitacao      mixed       mixed   (só com a flag ligada)
 *   nenhum                qualquer    FORBIDDEN
 *
 * NÃO lança exceção própria: usa TenantContextUnavailableException (mesma do
 * resolver) com reason code, para o teste e o log verem o motivo exato e o
 * endpoint traduzir para o 403/reset já existente.
 */
final class CompanyScopeNormalizer
{
    private const VALID = ['varejo', 'licitacao', 'mixed'];
    private const BOTH = ['licitacao', 'varejo']; // ordenado (SORT_STRING)

    /**
     * @param  list<string> $allowedScopes canais autorizados do usuário na empresa
     * @param  bool          $allowMixed    a flag do módulo (mixed_company_context_enabled)
     * @return 'varejo'|'licitacao'|'mixed'
     */
    public static function normalize(string $requested, array $allowedScopes, string $default = '', bool $allowMixed = true): string
    {
        $scope = strtolower(trim($requested));
        if ($scope === '') {
            $scope = strtolower(trim($default));
        }

        if (!in_array($scope, self::VALID, true)) {
            throw new TenantContextUnavailableException(
                'context_business_scope_invalid',
                400,
                'business_scope deve ser varejo, licitacao ou mixed.',
            );
        }

        $allowed = array_values(array_unique(array_map('strval', $allowedScopes)));
        sort($allowed, SORT_STRING);

        if ($scope === 'mixed') {
            if (!$allowMixed) {
                // Módulo desligado: mixed ainda não é uma opção real. Recusa
                // explícita — jamais degrada para um canal único.
                throw new TenantContextUnavailableException(
                    'context_mixed_disabled',
                    403,
                    'O contexto "Todas as operações" não está habilitado.',
                );
            }
            if ($allowed !== self::BOTH) {
                throw new TenantContextUnavailableException(
                    'context_business_scope_forbidden',
                    403,
                    'O usuário não possui acesso às duas operações.',
                );
            }

            return 'mixed';
        }

        if (!in_array($scope, $allowed, true)) {
            throw new TenantContextUnavailableException(
                'context_business_scope_forbidden',
                403,
                'Operação comercial não autorizada.',
            );
        }

        return $scope;
    }

    /**
     * Canais EFETIVOS de um business_scope. mixed = os dois; single = ele mesmo.
     *
     * @return list<string> ordenado
     */
    public static function effectiveChannels(string $businessScope): array
    {
        $channels = match ($businessScope) {
            'varejo' => ['varejo'],
            'licitacao' => ['licitacao'],
            'mixed' => ['licitacao', 'varejo'],
            default => [],
        };
        sort($channels, SORT_STRING);

        return $channels;
    }
}
