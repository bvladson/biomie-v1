<?php

declare(strict_types=1);

/**
 * Lista leve de vendedores do contexto ativo — fonte do seletor global.
 *
 * Em contexto consolidado, resolve visibilidade empresa por empresa e só então
 * agrupa códigos Omie iguais. Assim o seletor tem uma opção por código sem
 * perder a informação das empresas autorizadas que ela representa.
 */

use BIomie\Auth;
use BIomie\Commercial\CommercialChannelScope;
use BIomie\Database;
use BIomie\Http;
use BIomie\QuoteAgenda\SellerBindingResolver;
use BIomie\Support\SellerVisibilityScope;
use BIomie\Support\TenantContext;

require __DIR__ . '/bootstrap.php';

/** @var \BIomie\Config $config definido em api/bootstrap.php */
/** @var \BIomie\Logger $logger definido em api/bootstrap.php */

Auth::requireCapability('sales.view', true);

try {
    $conn = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    if (!Database::isConfigured($config, $conn)) {
        Http::json([
            'ok'    => false,
            'error' => ['code' => 'DB_UNAVAILABLE', 'message' => 'Banco de dados nao configurado.', 'details' => null],
        ], 503);
    }

    $pdo = Database::connect($config, $conn);

    // VISIBILIDADE: o dropdown mostra o ROSTER — os NOMES dos vendedores. Sem
    // escopo aqui, esconder os dados mas listar os nomes ainda vaza quem existe.
    // No consolidado, um codigo autorizado na empresa A não pode liberar nem
    // revelar o mesmo código na empresa B.
    $companyIds = array_values(array_unique(array_filter(
        array_map('intval', TenantContext::allowedCompanyIds()),
        static fn (int $companyId): bool => $companyId > 0
    )));
    if ($companyIds === []) {
        throw new RuntimeException('Contexto de empresas vazio.');
    }

    $userId = (int) (Auth::currentUser()['id'] ?? 0);
    $sellerVisibility = new SellerVisibilityScope($pdo);
    $sellerSelfOnly = !Auth::hasCapability('sales.quotes.followup.view')
        && Auth::hasCapability('sales.quotes.followup.self');
    $bindingResolver = $sellerSelfOnly ? new SellerBindingResolver($pdo) : null;

    $params = [];
    $visibilityBranches = [];
    $companyScopes = [];
    foreach ($companyIds as $companyIndex => $companyId) {
        if ($sellerSelfOnly) {
            $sellerScopeMode = SellerVisibilityScope::MODE_OWN;
            $allowedSellerIds = $bindingResolver?->sellerIdsFor($userId, $companyId) ?? [];
        } else {
            $sellerScopeMode = $sellerVisibility->mode($companyId, $userId);
            $allowedSellerIds = $sellerVisibility->allowedSellerIds($companyId, $userId);
        }

        if ($allowedSellerIds !== null) {
            $allowedSellerIds = array_values(array_unique(array_filter(
                array_map(
                    static fn (mixed $sellerId): string => trim((string) $sellerId),
                    $allowedSellerIds
                ),
                static fn (string $sellerId): bool => $sellerId !== '' && $sellerId !== '0'
            )));
        }

        $companyScopes[] = [
            'company_id' => $companyId,
            'mode' => $sellerScopeMode,
            'restricted' => $allowedSellerIds !== null,
            'allowed_count' => $allowedSellerIds === null ? null : count($allowedSellerIds),
        ];
        if ($allowedSellerIds === []) {
            continue;
        }

        $companyKey = 'seller_list_company_' . $companyIndex;
        $params[$companyKey] = $companyId;
        $branch = 'v.company_id = :' . $companyKey;
        if ($allowedSellerIds !== null) {
            $placeholders = [];
            foreach ($allowedSellerIds as $sellerIndex => $sellerId) {
                $key = 'seller_list_' . $companyIndex . '_' . $sellerIndex;
                $placeholders[] = ':' . $key;
                $params[$key] = $sellerId;
            }
            $branch .= ' AND v.codigo_omie IN (' . implode(', ', $placeholders) . ')';
        }
        $visibilityBranches[] = '(' . $branch . ')';
    }
    $visCond = $visibilityBranches === []
        ? '1 = 0'
        : '(' . implode(' OR ', $visibilityBranches) . ')';

    $sql = "SELECT v.company_id, v.codigo_omie AS id, v.nome AS name,
                   COALESCE(NULLIF(TRIM(c.nome_fantasia), ''), NULLIF(TRIM(c.slug), ''),
                            CONCAT('Empresa ', v.company_id)) AS company_name
            FROM biomie_vendedores_omie v
            LEFT JOIN biomie_companies c ON c.id = v.company_id
            WHERE {$visCond}
              AND v.codigo_omie IS NOT NULL AND v.codigo_omie <> ''
              AND v.nome IS NOT NULL AND TRIM(v.nome) <> ''
            ORDER BY v.nome ASC, v.company_id ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $vendorGroups = [];
    foreach ($rows as $row) {
        $companyId = (int) ($row['company_id'] ?? 0);
        $id   = trim((string) ($row['id'] ?? ''));
        $name = trim((string) ($row['name'] ?? ''));
        $companyName = trim((string) ($row['company_name'] ?? ''));
        if ($companyId <= 0 || $id === '') {
            continue;
        }

        $resolvedName = $name !== '' ? $name : $id;
        $resolvedCompanyName = $companyName !== '' ? $companyName : 'Empresa ' . $companyId;
        $groupKey = "\0" . $id;
        $vendorGroups[$groupKey] ??= [
            'id' => $id,
            'names' => [],
            'companies' => [],
        ];
        if (!in_array($resolvedName, $vendorGroups[$groupKey]['names'], true)) {
            $vendorGroups[$groupKey]['names'][] = $resolvedName;
        }
        $vendorGroups[$groupKey]['companies'][$companyId] = $resolvedCompanyName;
    }

    $vendors = [];
    foreach ($vendorGroups as $group) {
        $id = (string) $group['id'];
        $names = array_values($group['names']);
        natcasesort($names);
        $names = array_values($names);
        $companies = $group['companies'];
        ksort($companies, SORT_NUMERIC);
        $companyIdsForVendor = array_map('intval', array_keys($companies));
        $companyNamesForVendor = array_values($companies);
        $primaryName = $names[0] ?? (string) $id;
        $companyCount = count($companyIdsForVendor);
        $label = $primaryName;
        if ($companyCount > 1) {
            $label .= ' · ' . $companyCount . ' empresas';
        }

        $vendors[] = [
            'id' => (string) $id,
            'name' => $primaryName,
            'label' => $label,
            'aliases' => $names,
            'company_ids' => $companyIdsForVendor,
            'company_names' => $companyNamesForVendor,
            'company_count' => $companyCount,
        ];
    }
    usort(
        $vendors,
        static fn (array $left, array $right): int =>
            strnatcasecmp((string) $left['name'], (string) $right['name'])
    );

    $scopeModes = array_values(array_unique(array_column($companyScopes, 'mode')));
    $restrictedScopes = array_values(array_filter(
        $companyScopes,
        static fn (array $scope): bool => (bool) $scope['restricted']
    ));
    $allRestricted = count($restrictedScopes) === count($companyScopes);
    $allowedCount = $allRestricted
        ? array_sum(array_map(
            static fn (array $scope): int => (int) ($scope['allowed_count'] ?? 0),
            $companyScopes
        ))
        : null;

    // ESCOPO DE CANAL — para o header decidir se mostra o toggle de operação
    // (varejo/licitação/todas). Só faz sentido em contexto de UMA empresa; o
    // toggle aparece quando há 2 canais autorizados (mixed).
    $channelScope = null;
    $singleCompanyId = TenantContext::companyIdOrNull();
    if ($singleCompanyId !== null) {
        $allowedChannels = (new CommercialChannelScope($pdo))->allowedChannels($singleCompanyId, $userId);
        $channelScope = [
            'company_id'     => $singleCompanyId,
            'business_scope' => TenantContext::businessScope(),
            'allowed'        => $allowedChannels,
            'is_mixed'       => TenantContext::isMixed(),
        ];
    }

    Http::json([
        'ok'      => true,
        'vendors' => $vendors,
        'count'   => count($vendors),
        'seller_scope' => [
            'mode'          => count($scopeModes) === 1 ? $scopeModes[0] : 'mixed',
            'restricted'    => $restrictedScopes !== [],
            'allowed_count' => $allowedCount,
            'companies'     => $companyScopes,
        ],
        'channel_scope' => $channelScope,
    ]);
} catch (Throwable $e) {
    $logger->error('vendors_list falhou.', ['message' => $e->getMessage()]);
    Http::json([
        'ok'    => false,
        'error' => ['code' => 'VENDORS_LIST_FAILED', 'message' => 'Nao foi possivel carregar a lista de vendedores.', 'details' => null],
    ], 500);
}
