<?php

declare(strict_types=1);

/**
 * Escopo seguro do filtro global de vendedor.
 *
 * As tabelas TEMPORARY isolam completamente a matriz de visibilidade. O teste
 * prova a propriedade importante no consolidado: um codigo autorizado em uma
 * empresa nao libera o mesmo codigo em outra empresa.
 *
 *   php tests/run_vendor_scope_filter.php
 */

require_once __DIR__ . '/TestSupport.php';

use BIomie\Support\VendorScopeFilter;

$checks = [];
$check = static function (string $name, bool $ok, mixed $detail = null) use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};

$pdo = test_pdo();

$pdo->exec('CREATE TEMPORARY TABLE biomie_quote_seller_binding (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT NOT NULL, company_id BIGINT NOT NULL, seller_id VARCHAR(32) NOT NULL,
    created_by BIGINT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id), UNIQUE KEY uq_seller_binding (company_id, seller_id)
) ENGINE=InnoDB');

$pdo->exec("CREATE TEMPORARY TABLE biomie_user_seller_scope (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL,
    visibility_mode ENUM('all','own','selected') NOT NULL DEFAULT 'all',
    created_by INT UNSIGNED NULL, updated_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id), UNIQUE KEY uq_user_seller_scope (company_id, user_id)
) ENGINE=InnoDB");

$pdo->exec('CREATE TEMPORARY TABLE biomie_user_visible_sellers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL,
    seller_id VARCHAR(32) NOT NULL, created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id), UNIQUE KEY uq_user_visible_seller (company_id, user_id, seller_id)
) ENGINE=InnoDB');

$pdo->exec('CREATE TEMPORARY TABLE vendor_scope_facts (
    company_id INT UNSIGNED NOT NULL,
    seller_id VARCHAR(32) NOT NULL,
    label VARCHAR(32) NOT NULL,
    PRIMARY KEY (company_id, seller_id)
) ENGINE=InnoDB');

$mode = $pdo->prepare(
    'INSERT INTO biomie_user_seller_scope (company_id, user_id, visibility_mode)
     VALUES (:company_id, :user_id, :mode)'
);
$pick = $pdo->prepare(
    'INSERT INTO biomie_user_visible_sellers (company_id, user_id, seller_id)
     VALUES (:company_id, :user_id, :seller_id)'
);
$fact = $pdo->prepare(
    'INSERT INTO vendor_scope_facts (company_id, seller_id, label)
     VALUES (:company_id, :seller_id, :label)'
);

$restrictedUser = 8201;
foreach ([[1, 'selected'], [2, 'selected']] as [$companyId, $visibilityMode]) {
    $mode->execute(['company_id' => $companyId, 'user_id' => $restrictedUser, 'mode' => $visibilityMode]);
}
foreach ([[1, 'A'], [1, 'B'], [2, 'C']] as [$companyId, $sellerId]) {
    $pick->execute(['company_id' => $companyId, 'user_id' => $restrictedUser, 'seller_id' => $sellerId]);
}

$allUser = 8202;
foreach ([1, 2] as $companyId) {
    $mode->execute(['company_id' => $companyId, 'user_id' => $allUser, 'mode' => 'all']);
}
$binding = $pdo->prepare(
    'INSERT INTO biomie_quote_seller_binding (user_id, company_id, seller_id)
     VALUES (:user_id, :company_id, :seller_id)'
);
$binding->execute(['user_id' => $allUser, 'company_id' => 1, 'seller_id' => 'A']);
$binding->execute(['user_id' => $allUser, 'company_id' => 2, 'seller_id' => 'C']);

$emptyUser = 8203;
$mode->execute(['company_id' => 1, 'user_id' => $emptyUser, 'mode' => 'selected']);

foreach ([
    [1, 'A', '1:A'],
    [1, 'B', '1:B'],
    [1, 'X', '1:X'],
    [2, 'B', '2:B'],
    [2, 'C', '2:C'],
    [2, 'X', '2:X'],
    [3, 'Z', '3:Z'],
] as [$companyId, $sellerId, $label]) {
    $fact->execute(['company_id' => $companyId, 'seller_id' => $sellerId, 'label' => $label]);
}

/**
 * @return list<string>
 */
$visibleLabels = static function (
    int $userId,
    array $companyIds,
    string|array $requestedIds = ''
) use ($pdo): array {
    $params = [];
    $condition = VendorScopeFilter::conditionForContext(
        $pdo,
        $userId,
        $companyIds,
        $requestedIds,
        'f',
        $params,
        'seller_id',
    );
    $stmt = $pdo->prepare("SELECT label FROM vendor_scope_facts f WHERE {$condition} ORDER BY label");
    $stmt->execute($params);
    return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
};

$check(
    'sem vendor_id aplica o escopo em todas as empresas',
    $visibleLabels($restrictedUser, [1, 2]) === ['1:A', '1:B', '2:C'],
    $visibleLabels($restrictedUser, [1, 2])
);
$check(
    'vendor_id permitido em A nao libera o mesmo codigo em B',
    $visibleLabels($restrictedUser, [1, 2], 'B') === ['1:B'],
    $visibleLabels($restrictedUser, [1, 2], 'B')
);
$check(
    'vendor_ids multiplos sao intersectados empresa por empresa',
    $visibleLabels($restrictedUser, [1, 2], ['B', 'C']) === ['1:B', '2:C'],
    $visibleLabels($restrictedUser, [1, 2], ['B', 'C'])
);
$check(
    'vendor_ids mistos ignoram somente o codigo forjado',
    $visibleLabels($restrictedUser, [1, 2], ['B', 'X']) === ['1:B'],
    $visibleLabels($restrictedUser, [1, 2], ['B', 'X'])
);
$check(
    'CSV multiplo e aceito e deduplicado',
    $visibleLabels($restrictedUser, [1, 2], ' B, C, B ') === ['1:B', '2:C'],
    $visibleLabels($restrictedUser, [1, 2], ' B, C, B ')
);
$check(
    'vendor_id forjado fora do escopo retorna zero linhas',
    $visibleLabels($restrictedUser, [1, 2], 'X') === []
);
$check(
    'modo all ainda respeita as empresas do tenant',
    $visibleLabels($allUser, [1, 2]) === ['1:A', '1:B', '1:X', '2:B', '2:C', '2:X']
);
$check(
    'modo all + vendor_id restringe nas duas empresas permitidas',
    $visibleLabels($allUser, [1, 2], 'B') === ['1:B', '2:B']
);
$check(
    'modo all + vendor_ids mantem somente os codigos pedidos no tenant',
    $visibleLabels($allUser, [1, 2], ['A', 'C']) === ['1:A', '2:C'],
    $visibleLabels($allUser, [1, 2], ['A', 'C'])
);
$check(
    'selected vazio e vazio real, nunca todos',
    $visibleLabels($emptyUser, [1]) === []
);
$selfParams = [];
$selfCondition = VendorScopeFilter::conditionForContext(
    $pdo,
    $allUser,
    [1, 2],
    '',
    'f',
    $selfParams,
    'seller_id',
    'company_id',
    true
);
$selfStmt = $pdo->prepare(
    "SELECT label FROM vendor_scope_facts f WHERE {$selfCondition} ORDER BY label"
);
$selfStmt->execute($selfParams);
$check(
    'capacidade self usa todos os vinculos por empresa e nao degrada para all',
    array_map('strval', $selfStmt->fetchAll(PDO::FETCH_COLUMN)) === ['1:A', '2:C']
);
$selfMultiParams = [];
$selfMultiCondition = VendorScopeFilter::conditionForContext(
    $pdo,
    $allUser,
    [1, 2],
    ['A', 'C', 'X'],
    'f',
    $selfMultiParams,
    'seller_id',
    'company_id',
    true
);
$selfMultiStmt = $pdo->prepare(
    "SELECT label FROM vendor_scope_facts f WHERE {$selfMultiCondition} ORDER BY label"
);
$selfMultiStmt->execute($selfMultiParams);
$check(
    'capacidade self intersecta multiplos pedidos com os vinculos por empresa',
    array_map('strval', $selfMultiStmt->fetchAll(PDO::FETCH_COLUMN)) === ['1:A', '2:C']
);
$selfEmptyParams = [];
$selfEmptyCondition = VendorScopeFilter::conditionForContext(
    $pdo,
    $emptyUser,
    [1],
    '',
    'f',
    $selfEmptyParams,
    'seller_id',
    'company_id',
    true
);
$check(
    'capacidade self sem vinculo falha fechado',
    $selfEmptyCondition === '1=0'
);
$check('usuario invalido falha fechado', $visibleLabels(0, [1, 2]) === []);
$check('tenant vazio falha fechado', $visibleLabels($allUser, []) === []);
$check(
    'mais de MAX_REQUESTED_IDS falha fechado',
    $visibleLabels(
        $allUser,
        [1, 2],
        array_map(static fn (int $index): string => 'seller-' . $index, range(1, VendorScopeFilter::MAX_REQUESTED_IDS + 1))
    ) === []
);
$check(
    'array aninhado malformado falha fechado',
    $visibleLabels($allUser, [1, 2], [['A']]) === []
);
$check(
    'caractere de controle no codigo falha fechado',
    $visibleLabels($allUser, [1, 2], ["A\nB"]) === []
);
$check('alias invalido falha fechado', (static function () use ($pdo, $allUser): bool {
    $params = [];
    return VendorScopeFilter::conditionForContext(
        $pdo,
        $allUser,
        [1],
        '',
        'f;DROP',
        $params,
        'seller_id'
    ) === '1=0';
})());

// Duas aplicacoes na mesma query nao podem reutilizar placeholders.
$params = [];
$conditionA = VendorScopeFilter::conditionForContext(
    $pdo,
    $restrictedUser,
    [1, 2],
    '',
    'f',
    $params,
    'seller_id'
);
$conditionB = VendorScopeFilter::conditionForContext(
    $pdo,
    $restrictedUser,
    [1, 2],
    'B',
    'f',
    $params,
    'seller_id'
);
preg_match_all('/:([A-Za-z0-9_]+)/', $conditionA, $matchesA);
preg_match_all('/:([A-Za-z0-9_]+)/', $conditionB, $matchesB);
$check(
    'placeholders sao unicos entre chamadas',
    array_intersect($matchesA[1] ?? [], $matchesB[1] ?? []) === []
);

$colonParams = [':existing' => 'preservado'];
$colonCondition = VendorScopeFilter::conditionForContext(
    $pdo,
    $restrictedUser,
    [1],
    'A',
    'f',
    $colonParams,
    'seller_id'
);
$generatedKeys = array_values(array_filter(
    array_keys($colonParams),
    static fn (string $key): bool => $key !== ':existing'
));
$check(
    'estilo de bind com dois-pontos e preservado sem colisao',
    $colonParams[':existing'] === 'preservado'
        && $generatedKeys !== []
        && count(array_filter($generatedKeys, static fn (string $key): bool => str_starts_with($key, ':'))) === count($generatedKeys)
        && str_contains($colonCondition, ':vsf_')
);

$check(
    'normalizador preserva ordem e deduplica CSV e array',
    VendorScopeFilter::normalizeRequestedIds([' B, A ', 'B', '01', '1']) === ['B', 'A', '01', '1']
);
$previousGet = $_GET;
try {
    $_GET = ['vendor_ids' => ['C', 'B,C'], 'vendor_id' => 'A'];
    $check(
        'vendor_ids da URL prevalece sobre vendor_id legado',
        VendorScopeFilter::requestedIds() === ['C', 'B']
    );
    $_GET = ['vendor_ids' => '', 'vendor_id' => 'A'];
    $check(
        'vendor_id legado e fallback quando vendor_ids esta vazio',
        VendorScopeFilter::requestedIds() === ['A']
    );
    $_GET = ['vendor_ids' => ' , ', 'vendor_id' => 'A'];
    $check(
        'vendor_id legado tambem e fallback para CSV multiplo vazio',
        VendorScopeFilter::requestedIds() === ['A']
    );
} finally {
    $_GET = $previousGet;
}

// Trava contratual: os cinco consumidores devem usar a API autenticada com PDO.
foreach ([
    'orders_search.php',
    'nf_periodo_table.php',
    'finance_search.php',
    'vendors_search.php',
    'products_search.php',
] as $endpoint) {
    $source = (string) file_get_contents(dirname(__DIR__) . '/api/' . $endpoint);
    $check(
        "{$endpoint} aplica VendorScopeFilter com PDO",
        str_contains($source, 'VendorScopeFilter::condition($pdo,')
    );
}

$dashboardSource = (string) file_get_contents(dirname(__DIR__) . '/api/commercial_dashboard.php');
$check(
    'dashboard comercial aplica vendor_ids em NF, carteira, agenda e metas',
    str_contains($dashboardSource, 'VendorScopeFilter::requestedIds()')
        && substr_count($dashboardSource, 'VendorScopeFilter::conditionForContext(') >= 2
        && str_contains($dashboardSource, 'TenantContext::allowedCompanyIds()')
        && str_contains($dashboardSource, '$quotesRequested = $fVendorIds')
        && str_contains($dashboardSource, 'foreach ($companyIds as $targetCompanyId)')
        && str_contains($dashboardSource, "'requested_vendor_ids' => \$fVendorIds")
);

$passed = count(array_filter($checks, static fn (array $result): bool => $result['ok']));
$result = [
    'ok' => $passed === count($checks),
    'total' => count($checks),
    'passed' => $passed,
    'failed' => count($checks) - $passed,
    'checks' => $checks,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
exit($result['ok'] ? 0 : 1);
