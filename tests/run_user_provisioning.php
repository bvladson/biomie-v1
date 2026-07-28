<?php

declare(strict_types=1);

/**
 * PROVISIONAMENTO DE USUÁRIO — transação, autorização e regras.
 *
 * O que precisa ser verdade (e é onde um erro custa caro):
 *  · ATOMICIDADE — falha em QUALQUER etapa não pode deixar usuário órfão,
 *    acesso solto ou visibilidade pendurada. Conta meio-criada é a que enxerga
 *    demais.
 *  · AUTORIZAÇÃO RECALCULADA — o payload do cliente nunca decide nada: papel
 *    acima do próprio, empresa que o ator não tem e vendedor de outra empresa
 *    têm de ser recusados no servidor.
 *  · REGRAS — uma única empresa padrão; selected vazio é erro; own sem vínculo
 *    resulta em ZERO (nunca "todos").
 *
 * Roda inteiro no test_db (TestDatabaseGuard barra produção).
 *
 *   php tests/run_user_provisioning.php
 */

require_once __DIR__ . '/TestSupport.php';

use BIomie\Admin\AdminLogService;
use BIomie\Admin\RbacService;
use BIomie\Admin\UserProvisioningService;
use BIomie\Support\SellerVisibilityScope;

$checks = [];
$check = static function (string $name, bool $ok, mixed $detail = null) use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};

$pdo = test_pdo();
$env = test_environment();

// ── Cenário isolado: tabelas TEMPORARY sombreiam as reais ───────────────────
$pdo->exec('CREATE TEMPORARY TABLE biomie_users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(64) NOT NULL, full_name VARCHAR(120) NOT NULL, email VARCHAR(160) NULL,
    password_hash VARCHAR(255) NOT NULL, role VARCHAR(20) NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1,
    password_changed_at DATETIME NULL, created_by INT NULL, updated_by INT NULL,
    UNIQUE KEY uq_u (username), UNIQUE KEY uq_e (email)
) ENGINE=InnoDB');
$pdo->exec('CREATE TEMPORARY TABLE biomie_companies (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY, slug VARCHAR(32), nome_fantasia VARCHAR(80), ativo TINYINT(1) DEFAULT 1
) ENGINE=InnoDB');
$pdo->exec('CREATE TEMPORARY TABLE biomie_user_companies (
    user_id INT UNSIGNED NOT NULL, company_id TINYINT UNSIGNED NOT NULL,
    can_varejo TINYINT(1) DEFAULT 1, can_licitacao TINYINT(1) DEFAULT 0,
    is_default TINYINT(1) DEFAULT 0, role_override VARCHAR(20) NULL, default_canal VARCHAR(20) NULL,
    PRIMARY KEY (user_id, company_id)
) ENGINE=InnoDB');
$pdo->exec('CREATE TEMPORARY TABLE biomie_vendedores_omie (
    id INT AUTO_INCREMENT PRIMARY KEY, company_id TINYINT UNSIGNED, codigo_omie VARCHAR(32), nome VARCHAR(120), ativo TINYINT(1) DEFAULT 1
) ENGINE=InnoDB');
$pdo->exec("CREATE TEMPORARY TABLE biomie_user_seller_scope (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company_id TINYINT UNSIGNED, user_id INT UNSIGNED,
    visibility_mode ENUM('all','own','selected') NOT NULL DEFAULT 'all',
    created_by INT UNSIGNED NULL, updated_by INT UNSIGNED NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_s (company_id, user_id)
) ENGINE=InnoDB");
$pdo->exec('CREATE TEMPORARY TABLE biomie_user_visible_sellers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company_id TINYINT UNSIGNED, user_id INT UNSIGNED,
    seller_id VARCHAR(32), created_by INT UNSIGNED NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_v (company_id, user_id, seller_id)
) ENGINE=InnoDB');
$pdo->exec('CREATE TEMPORARY TABLE biomie_quote_seller_binding (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT, company_id BIGINT, seller_id VARCHAR(32),
    UNIQUE KEY uq_b (company_id, seller_id)
) ENGINE=InnoDB');

// RBAC também precisa ser sombreado: as tabelas REAIS têm FK para a
// biomie_users REAL, e nosso usuário nasce na TEMPORARY. Sem isto o INSERT em
// biomie_user_roles viola a FK — falha do CENÁRIO, não do código.
$pdo->exec('CREATE TEMPORARY TABLE biomie_roles (
    id INT AUTO_INCREMENT PRIMARY KEY, role_key VARCHAR(32), name VARCHAR(64) NULL
) ENGINE=InnoDB');
$pdo->exec('CREATE TEMPORARY TABLE biomie_user_roles (
    user_id INT UNSIGNED NOT NULL, role_id INT NOT NULL, assigned_by INT NULL,
    PRIMARY KEY (user_id, role_id)
) ENGINE=InnoDB');
$pdo->exec('CREATE TEMPORARY TABLE biomie_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY, permission_key VARCHAR(64)
) ENGINE=InnoDB');
$pdo->exec('CREATE TEMPORARY TABLE biomie_role_permissions (
    role_id INT NOT NULL, permission_id INT NOT NULL, PRIMARY KEY (role_id, permission_id)
) ENGINE=InnoDB');
$pdo->exec("INSERT INTO biomie_roles (role_key,name) VALUES
    ('viewer','Visualizador'),('analyst','Analista'),('manager','Gestor'),('admin','Administrador')");

$pdo->exec("INSERT INTO biomie_companies (id,slug,nome_fantasia,ativo) VALUES
    (1,'MAG_FILIAL','Mag Filial',1),(2,'EXPERT','Expert',1),(3,'UNI','Uni Design',1)");
$pdo->exec("INSERT INTO biomie_vendedores_omie (company_id,codigo_omie,nome,ativo) VALUES
    (1,'C1_A','Alexandre',1),(1,'C1_B','Samantha',1),(1,'C1_C','Caio',1),
    (2,'C2_A','Vendedor Expert',1)");

// Ator: admin com acesso às empresas 1 e 2 (NÃO tem a 3).
$pdo->exec("INSERT INTO biomie_users (id,username,full_name,email,password_hash,role) VALUES
    (100,'ator','Ator Admin','ator@x.com','h','admin')");
$pdo->exec('INSERT INTO biomie_user_companies (user_id,company_id) VALUES (100,1),(100,2)');

$svc = new UserProvisioningService($pdo, new RbacService($pdo), new AdminLogService($pdo, $env['logger']), $env['logger']);

$base = static function (array $over = []): array {
    return array_merge([
        'name' => 'Alexandre Teste', 'username' => 'alex_teste', 'email' => 'alex@teste.com',
        'password' => 'senha12345', 'is_active' => true,
        'companies' => [['company_id' => 1, 'role' => 'viewer', 'can_varejo' => true, 'is_default' => true]],
        'visibility' => ['1' => ['mode' => 'selected', 'seller_ids' => ['C1_A', 'C1_B']]],
    ], $over);
};
$countAll = static fn (PDO $p): array => [
    'users' => (int) $p->query('SELECT COUNT(*) FROM biomie_users')->fetchColumn(),
    'links' => (int) $p->query('SELECT COUNT(*) FROM biomie_user_companies')->fetchColumn(),
    'scope' => (int) $p->query('SELECT COUNT(*) FROM biomie_user_seller_scope')->fetchColumn(),
    'picks' => (int) $p->query('SELECT COUNT(*) FROM biomie_user_visible_sellers')->fetchColumn(),
];

// ── 1. AUTORIZAÇÃO — o payload não manda ────────────────────────────────────
$before = $countAll($pdo);

$r = $svc->provision($base(['companies' => [['company_id' => 3, 'role' => 'viewer', 'is_default' => true]]]), 100, 'admin');
$check('🔴 empresa que o ATOR não possui é recusada',
    $r['http_status'] === 422 && isset($r['body']['errors']['companies.0.company_id']),
    json_encode($r['body']['errors'] ?? []));

$r = $svc->provision($base(['companies' => [['company_id' => 1, 'role' => 'admin', 'is_default' => true]]]), 100, 'manager');
$check('🔴 papel ACIMA do próprio é recusado (manager não cria admin)',
    $r['http_status'] === 422 && isset($r['body']['errors']['companies.0.role']));

// Papel DESCONHECIDO no payload é RECUSADO (422), não normalizado em silêncio.
// Valor fora do conjunto fechado = adulteração do cliente → allowlist recusa.
$r = $svc->provision($base(['username' => 'papel_fake', 'email' => 'pf@x.com',
    'companies' => [['company_id' => 1, 'role' => 'superhacker', 'is_default' => true]]]), 100, 'admin');
$check('🔴 papel BRUTO desconhecido ⇒ 422 (não rebaixado a viewer em silêncio)',
    $r['http_status'] === 422 && isset($r['body']['errors']['companies.0.role']),
    json_encode($r['body']['errors'] ?? []) . ' status=' . $r['http_status']);
$check('mensagem sinaliza INVALID_ROLE',
    strpos((string) ($r['body']['errors']['companies.0.role'] ?? ''), 'INVALID_ROLE') !== false);
$check('🔴 payload de papel adulterado NÃO criou usuário',
    (int) $pdo->query("SELECT COUNT(*) FROM biomie_users WHERE username='papel_fake'")->fetchColumn() === 0);

$r = $svc->provision($base(['visibility' => ['1' => ['mode' => 'selected', 'seller_ids' => ['C2_A']]]]), 100, 'admin');
$check('🔴 vendedor de OUTRA empresa é recusado',
    $r['http_status'] === 422 && isset($r['body']['errors']['visibility.1.seller_ids']));

$r = $svc->provision($base(['companies' => [['company_id' => 999, 'role' => 'viewer', 'is_default' => true]]]), 100, 'admin');
$check('empresa inexistente é recusada', $r['http_status'] === 422);

$check('🔴 NENHUMA recusa gravou coisa alguma', $countAll($pdo) === $before, json_encode($countAll($pdo)));

// ── 2. REGRAS ───────────────────────────────────────────────────────────────
$r = $svc->provision($base(['companies' => [
    ['company_id' => 1, 'role' => 'viewer', 'is_default' => true],
    ['company_id' => 2, 'role' => 'viewer', 'is_default' => true],
]]), 100, 'admin');
$check('🔴 duas empresas padrão é erro', $r['http_status'] === 422 && isset($r['body']['errors']['companies.default']));

$r = $svc->provision($base(['companies' => [['company_id' => 1, 'role' => 'viewer', 'is_default' => false]]]), 100, 'admin');
$check('nenhuma empresa padrão é erro', $r['http_status'] === 422 && isset($r['body']['errors']['companies.default']));

$r = $svc->provision($base(['visibility' => ['1' => ['mode' => 'selected', 'seller_ids' => []]]]), 100, 'admin');
$check('🔴 selected com lista VAZIA é erro (não vira "todos")',
    $r['http_status'] === 422 && isset($r['body']['errors']['visibility.1.seller_ids']));

$r = $svc->provision($base(['companies' => []]), 100, 'admin');
$check('sem empresa é erro', $r['http_status'] === 422 && isset($r['body']['errors']['companies']));

$r = $svc->provision($base(['password' => '123']), 100, 'admin');
$check('senha curta é erro', $r['http_status'] === 422 && isset($r['body']['errors']['password']));

$check('🔴 nenhuma violação de regra gravou nada', $countAll($pdo) === $before);

// ── 3. SUCESSO — tudo junto, num commit ─────────────────────────────────────
$r = $svc->provision($base(['companies' => [
    ['company_id' => 1, 'role' => 'viewer', 'can_varejo' => true, 'is_default' => true],
    ['company_id' => 2, 'role' => 'manager', 'can_varejo' => true, 'is_default' => false],
], 'visibility' => [
    '1' => ['mode' => 'selected', 'seller_ids' => ['C1_A', 'C1_B']],
    '2' => ['mode' => 'all', 'seller_ids' => []],
]]), 100, 'admin');

$check('provisionamento bem-sucedido devolve 201', $r['http_status'] === 201, json_encode($r['body']));
$newId = (int) ($r['body']['user']['id'] ?? 0);
$check('usuário criado com id', $newId > 0);
$check('summary traz empresa padrão', ($r['body']['summary']['default_company'] ?? '') === 'Mag Filial', json_encode($r['body']['summary'] ?? []));
$check('summary conta vendedores visíveis', ($r['body']['summary']['visible_sellers'] ?? -1) === 2);

$after = $countAll($pdo);
$check('🔴 TODAS as estruturas apareceram juntas',
    $after['users'] === $before['users'] + 1 && $after['links'] === $before['links'] + 2
    && $after['scope'] === $before['scope'] + 2 && $after['picks'] === $before['picks'] + 2,
    json_encode($after));

// Papel GLOBAL = o MENOR concedido (fail-safe se um vínculo sumir).
$globalRole = (string) $pdo->query("SELECT role FROM biomie_users WHERE id={$newId}")->fetchColumn();
$check('🔴 papel global = o MENOS privilegiado concedido', $globalRole === 'viewer', $globalRole);

$ov = $pdo->query("SELECT company_id, role_override FROM biomie_user_companies WHERE user_id={$newId} ORDER BY company_id")
    ->fetchAll(PDO::FETCH_KEY_PAIR);
$check('papel por empresa gravado como override', $ov == [1 => 'viewer', 2 => 'manager'], json_encode($ov));

// ── 4. O ESCOPO RESOLVE como configurado ────────────────────────────────────
$scope = new SellerVisibilityScope($pdo);
$ids = $scope->allowedSellerIds(1, $newId);
sort($ids);
$check('🔴 empresa 1 resolve para os 2 vendedores escolhidos', $ids === ['C1_A', 'C1_B'], json_encode($ids));
$check('empresa 2 resolve para todos (modo all)', $scope->allowedSellerIds(2, $newId) === null);
$check('🔴 vendedor NÃO escolhido é invisível', !$scope->canSee(1, $newId, 'C1_C'));

// ── 5. ATOMICIDADE — falha no meio desfaz tudo ──────────────────────────────
$before2 = $countAll($pdo);
// Derruba a tabela de visibilidade: a inserção do escopo falha DEPOIS de já ter
// criado usuário e vínculos. Se a transação funcionar, nada sobra.
$pdo->exec('DROP TEMPORARY TABLE biomie_user_seller_scope');
$r = $svc->provision($base(['username' => 'falha_atomica', 'email' => 'falha@x.com']), 100, 'admin');
$check('falha no meio devolve PROVISIONING_FAILED',
    $r['http_status'] === 500 && ($r['body']['code'] ?? '') === 'PROVISIONING_FAILED');
$check('🔴 mensagem de falha NÃO vaza detalhe técnico',
    ($r['body']['message'] ?? '') === 'Nenhuma alteração foi aplicada.'
    && !isset($r['body']['errors']) && strpos(json_encode($r['body']), 'SQLSTATE') === false);

$after2 = ['users' => (int) $pdo->query('SELECT COUNT(*) FROM biomie_users')->fetchColumn(),
           'links' => (int) $pdo->query('SELECT COUNT(*) FROM biomie_user_companies')->fetchColumn()];
$check('🔴 ROLLBACK: usuário órfão NÃO existe',
    $after2['users'] === $before2['users'], "antes={$before2['users']} depois={$after2['users']}");
$check('🔴 ROLLBACK: vínculo de empresa NÃO sobrou',
    $after2['links'] === $before2['links'], "antes={$before2['links']} depois={$after2['links']}");
$check('🔴 usuário da tentativa falha não existe',
    (int) $pdo->query("SELECT COUNT(*) FROM biomie_users WHERE username='falha_atomica'")->fetchColumn() === 0);

// ── 6. Unicidade ────────────────────────────────────────────────────────────
$pdo->exec("CREATE TEMPORARY TABLE biomie_user_seller_scope (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company_id TINYINT UNSIGNED, user_id INT UNSIGNED,
    visibility_mode ENUM('all','own','selected') NOT NULL DEFAULT 'all',
    created_by INT UNSIGNED NULL, updated_by INT UNSIGNED NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_s (company_id, user_id)) ENGINE=InnoDB");
$r = $svc->provision($base(), 100, 'admin');   // mesmo username já criado
$check('🔴 username duplicado vira erro de CAMPO (não falha interna)',
    $r['http_status'] === 422 && isset($r['body']['errors']['username']), json_encode($r['body']['errors'] ?? []));

$passed = count(array_filter($checks, static fn (array $r): bool => $r['ok']));
$result = ['ok' => $passed === count($checks), 'total' => count($checks),
           'passed' => $passed, 'failed' => count($checks) - $passed, 'checks' => $checks];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
exit($result['ok'] ? 0 : 1);
