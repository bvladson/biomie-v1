<?php

declare(strict_types=1);

namespace BIomie\Support;

use BIomie\Auth;
use BIomie\QuoteAgenda\SellerBindingResolver;
use PDO;
use Throwable;

/**
 * Escopo SQL transversal do seletor global de vendedor.
 *
 * `vendor_id` e `vendor_ids` vindos da URL nunca sao confiaveis: eles apenas
 * restringem a visibilidade concedida por SellerVisibilityScope. Quando nao ha
 * vendedor solicitado, o predicado continua aplicando o escopo completo do
 * usuario.
 *
 * Em contexto consolidado a visibilidade e resolvida empresa por empresa. Isso
 * evita que um codigo permitido na empresa A libere, por coincidencia, linhas
 * do mesmo codigo na empresa B.
 *
 * A implementacao usa somente allowedSellerIds(), presente tanto na versao
 * enxuta de producao quanto na versao local mais nova de SellerVisibilityScope.
 */
final class VendorScopeFilter
{
    public const MAX_REQUESTED_IDS = 50;

    private const MAX_ID_LENGTH = 191;

    private static int $placeholderSequence = 0;

    /**
     * Codigos solicitados pelo navegador.
     *
     * `vendor_ids` aceita CSV (`A,B`) e a forma de query string
     * `vendor_ids[]=A&vendor_ids[]=B`. O `vendor_id` antigo continua sendo
     * aceito como fallback. Entrada malformada ou acima do limite falha
     * fechado no condition().
     *
     * @return list<string>
     */
    public static function requestedIds(): array
    {
        if (array_key_exists('vendor_ids', $_GET)) {
            $requested = self::normalizeRequestedIds($_GET['vendor_ids']);
            if ($requested !== []) {
                return $requested;
            }
        }

        return self::normalizeRequestedIds($_GET['vendor_id'] ?? '');
    }

    /**
     * Normaliza uma selecao explicita para uso fora de uma requisicao HTTP.
     *
     * @return list<string>
     *
     * @throws \InvalidArgumentException
     */
    public static function normalizeRequestedIds(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        $values = is_array($raw) ? array_values($raw) : [$raw];
        $requested = [];
        $seen = [];

        foreach ($values as $value) {
            if (!is_string($value) && !is_int($value)) {
                throw new \InvalidArgumentException('Filtro de vendedores invalido.');
            }

            foreach (explode(',', (string) $value) as $part) {
                $sellerId = trim($part);
                if ($sellerId === '') {
                    continue;
                }
                if (
                    strlen($sellerId) > self::MAX_ID_LENGTH
                    || preg_match('/[\x00-\x1F\x7F]/', $sellerId) === 1
                ) {
                    throw new \InvalidArgumentException('Codigo de vendedor invalido.');
                }

                // O prefixo evita que chaves numericas sejam convertidas pelo
                // PHP e preserva codigos distintos como "1" e "01".
                $seenKey = "\0" . $sellerId;
                if (isset($seen[$seenKey])) {
                    continue;
                }
                $seen[$seenKey] = true;
                $requested[] = $sellerId;

                if (count($requested) > self::MAX_REQUESTED_IDS) {
                    throw new \InvalidArgumentException(
                        'Selecione no maximo ' . self::MAX_REQUESTED_IDS . ' vendedores.'
                    );
                }
            }
        }

        return $requested;
    }

    /** Primeiro codigo solicitado, mantido para consumidores escalares antigos. */
    public static function requestedId(): string
    {
        return self::requestedIds()[0] ?? '';
    }

    /**
     * Gera o predicado seguro para o usuario e tenant autenticados.
     *
     * Falhas de identidade, tenant ou resolucao do escopo retornam `1=0`.
     * O chamador deve sempre adicionar o resultado ao WHERE, inclusive quando
     * nenhum vendor_id tiver sido informado.
     *
     * @param array<string,mixed> $params
     */
    public static function condition(
        PDO $pdo,
        string $alias,
        array &$params,
        string $column = 'codigo_vendedor_omie',
        string $companyColumn = 'company_id'
    ): string {
        try {
            $user = Auth::currentUser();
            $userId = is_array($user) ? (int) ($user['id'] ?? 0) : 0;
            $companyIds = TenantContext::allowedCompanyIds();
            $selfOnly = !Auth::hasCapability('sales.quotes.followup.view')
                && Auth::hasCapability('sales.quotes.followup.self');

            return self::conditionForContext(
                $pdo,
                $userId,
                $companyIds,
                self::requestedIds(),
                $alias,
                $params,
                $column,
                $companyColumn,
                $selfOnly,
            );
        } catch (Throwable) {
            return '1=0';
        }
    }

    /**
     * Variante explicita, util para workers e testes sem sessao HTTP.
     *
     * @param list<int>              $companyIds
     * @param string|list<string>    $requestedIds
     * @param array<string,mixed>    $params
     */
    public static function conditionForContext(
        PDO $pdo,
        int $userId,
        array $companyIds,
        string|array $requestedIds,
        string $alias,
        array &$params,
        string $column = 'codigo_vendedor_omie',
        string $companyColumn = 'company_id',
        bool $selfOnly = false
    ): string {
        if ($userId <= 0 || !self::validIdentifier($column) || !self::validIdentifier($companyColumn)) {
            return '1=0';
        }
        if ($alias !== '' && !self::validIdentifier($alias)) {
            return '1=0';
        }

        $companies = array_values(array_unique(array_filter(
            array_map('intval', $companyIds),
            static fn (int $companyId): bool => $companyId > 0
        )));
        if ($companies === []) {
            return '1=0';
        }

        try {
            $requestedIds = self::normalizeRequestedIds($requestedIds);
        } catch (\InvalidArgumentException) {
            return '1=0';
        }

        $prefix = $alias !== '' ? rtrim($alias, '.') . '.' : '';
        $sellerColumn = $prefix . $column;
        $tenantColumn = $prefix . $companyColumn;
        $scope = new SellerVisibilityScope($pdo);
        $binding = $selfOnly ? new SellerBindingResolver($pdo) : null;
        $branches = [];
        $callSequence = self::$placeholderSequence++;
        $colonKeys = self::usesColonKeys($params);

        /**
         * @param scalar $value
         */
        $bind = static function (string $stem, mixed $value) use (&$params, $callSequence, $colonKeys): string {
            $attempt = 0;
            do {
                $name = 'vsf_' . $callSequence . '_' . $stem;
                if ($attempt > 0) {
                    $name .= '_' . $attempt;
                }
                $attempt++;
            } while (array_key_exists($name, $params) || array_key_exists(':' . $name, $params));

            $params[$colonKeys ? ':' . $name : $name] = $value;
            return ':' . $name;
        };

        try {
            foreach ($companies as $companyIndex => $companyId) {
                // A capacidade `self` e uma fronteira mais restrita do que o
                // modo administrativo: sem vinculo, o resultado e vazio real.
                $allowed = $binding !== null
                    ? $binding->sellerIdsFor($userId, $companyId)
                    : $scope->allowedSellerIds($companyId, $userId);

                if ($allowed !== null) {
                    $allowed = array_values(array_unique(array_filter(
                        array_map(
                            static fn (mixed $sellerId): string => trim((string) $sellerId),
                            $allowed
                        ),
                        static fn (string $sellerId): bool => $sellerId !== '' && $sellerId !== '0'
                    )));
                }

                if ($requestedIds !== []) {
                    $effectiveRequested = $allowed === null
                        ? $requestedIds
                        : array_values(array_intersect($requestedIds, $allowed));
                    if ($effectiveRequested === []) {
                        continue;
                    }

                    $companyPlaceholder = $bind('company_' . $companyIndex, $companyId);
                    $sellerPlaceholders = [];
                    foreach ($effectiveRequested as $sellerIndex => $sellerId) {
                        $sellerPlaceholders[] = $bind(
                            'seller_' . $companyIndex . '_' . $sellerIndex,
                            $sellerId
                        );
                    }
                    if (count($sellerPlaceholders) === 1) {
                        $branches[] = "({$tenantColumn} = {$companyPlaceholder} AND {$sellerColumn} = "
                            . $sellerPlaceholders[0] . ')';
                    } else {
                        $branches[] = "({$tenantColumn} = {$companyPlaceholder} AND {$sellerColumn} IN ("
                            . implode(', ', $sellerPlaceholders)
                            . '))';
                    }
                    continue;
                }

                if ($allowed === []) {
                    continue;
                }

                $companyPlaceholder = $bind('company_' . $companyIndex, $companyId);
                if ($allowed === null) {
                    $branches[] = "({$tenantColumn} = {$companyPlaceholder})";
                    continue;
                }

                $sellerPlaceholders = [];
                foreach ($allowed as $sellerIndex => $sellerId) {
                    $sellerPlaceholders[] = $bind(
                        'seller_' . $companyIndex . '_' . $sellerIndex,
                        $sellerId
                    );
                }
                $branches[] = "({$tenantColumn} = {$companyPlaceholder} AND {$sellerColumn} IN ("
                    . implode(', ', $sellerPlaceholders)
                    . '))';
            }
        } catch (Throwable) {
            // Uma empresa cujo escopo nao pode ser resolvido invalida o contexto
            // inteiro. Retorno parcial seria dificil de detectar e auditar.
            return '1=0';
        }

        return $branches === [] ? '1=0' : '(' . implode(' OR ', $branches) . ')';
    }

    /** @param array<string,mixed> $params */
    private static function usesColonKeys(array $params): bool
    {
        foreach (array_keys($params) as $key) {
            if (is_string($key)) {
                return str_starts_with($key, ':');
            }
        }

        return false;
    }

    private static function validIdentifier(string $identifier): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) === 1;
    }

}
