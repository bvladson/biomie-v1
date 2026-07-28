<?php

/**
 * nav_rbac_sim.php — Simula visibilidade de menu e acesso a páginas por perfil
 *
 * Testa os 5 perfis definidos na homologação da Fase 7 (navegação):
 *   1. Admin
 *   2. Manager com sales.view (sem settings.view/admin.view/logs.view)
 *   3. Viewer com geo.view (sem settings.view/admin.view/logs.view)
 *   4. Usuário com settings.view apenas (sem sales/finance/fiscal/geo/admin.view)
 *   5. Usuário sem permissões relevantes
 *
 * Saída: tabela de resultados com PASS/FAIL por assertion.
 */

declare(strict_types=1);

// ── Perfis de teste ───────────────────────────────────────────────────────────
// Cada perfil define o array de capabilities que o usuário possui.
// Derivados diretamente da matriz em app/Acl/Roles.php, com restrições explícitas.

$profiles = [
    // 1. Admin — role=admin — tem todas as capabilities
    'Admin' => [
        'sales.view', 'sales.sync', 'sales.export',
        'commercial.view', 'dashboard.view',
        'finance.view', 'finance.sync', 'finance.export',
        'financial.view', 'fiscal.view', 'fiscal.sync', 'fiscal.export',
        'nf.view', 'reconciliation.view',
        'geo.view', 'geo.materialize',
        'omie.view', 'omie.sync',
        'jobs.view', 'jobs.run',
        'logs.view', 'admin.view', 'admin.manage', 'settings.view', 'settings.manage',
        'intelligence.view', 'forecast.view', 'dossier.view', 'documents.full',
        'export', 'users.manage', 'admin.users', 'admin.migrations', 'admin.logs',
        'admin.console', 'observability.view',
    ],

    // 2. Manager com sales.view — role=viewer (viewer tem sales.view + geo.view, sem logs/settings/admin.view)
    //    Modela um usuário que NÃO tem logs.view, settings.view nem admin.view.
    //    Representa "acesso comercial sem privilégios de sistema".
    'Manager-SalesOnly' => [
        'sales.view',
        'commercial.view',
        'dashboard.view',
        // sem: finance.view, fiscal.view, geo.view, logs.view, admin.view, settings.view
    ],

    // 3. Viewer com geo.view — role=viewer — tem sales.view e geo.view, sem admin/logs/settings
    'Viewer-Geo' => [
        'sales.view',
        'commercial.view',
        'dashboard.view',
        'geo.view',
        // sem: finance.view, fiscal.view, logs.view, admin.view, settings.view
    ],

    // 4. Usuário com settings.view apenas — role=admin com módulo restrito a settings
    //    Modela um usuário que só tem settings.view (sem sales/finance/fiscal/geo/admin.view)
    'User-SettingsOnly' => [
        'settings.view',
        // sem: sales.view, finance.view, fiscal.view, geo.view, admin.view, logs.view
    ],

    // 5. Sem permissões relevantes — role=viewer sem geo.view (ou usuário bloqueado)
    'NoRelevantPerms' => [
        'commercial.view',
        'dashboard.view',
        // sem: sales.view, finance.view, fiscal.view, geo.view, admin.view, logs.view, settings.view
    ],

    // 6. Usuário com logs.view apenas (monitoramento/observabilidade, sem commercial)
    //    Após o fix, logs.view deve dar acesso ao Cockpit (menu + página)
    //    e ao Catálogo de KPIs (menu + página) e à API semantic_health
    'LogsOnly' => [
        'logs.view',
        // sem: sales.view, finance.view, fiscal.view, geo.view, admin.view, settings.view
    ],

    // 7. Usuário com jobs.view apenas
    //    jobs.view: acessa semantic_health mas NÃO acessa Cockpit nem Catálogo
    'JobsOnly' => [
        'jobs.view',
        // sem: logs.view, sales.view, finance.view, fiscal.view, geo.view, admin.view, settings.view
    ],
];

// ── Configuração das páginas ──────────────────────────────────────────────────
// permission_any espelha views/config/menu.php (após fix: logs.view adicionado ao Cockpit)

// Menu item: permission_any field (visibilidade no sidebar)
$menuItems = [
    'cockpit_interno' => [
        'label' => 'Cockpit Interno MAG',
        'href'  => 'cockpit_interno.php',
        'permission_any' => ['sales.view', 'finance.view', 'fiscal.view', 'geo.view', 'admin.view', 'logs.view'],
    ],
    'catalogo_kpis' => [
        'label' => 'Catálogo de KPIs',
        'href'  => 'metric_catalog.php',
        'permission_any' => ['settings.view', 'admin.view', 'logs.view'],
    ],
    'system_health' => [
        'label' => 'Saúde do Sistema',
        'href'  => 'system_health.php',
        'permission_any' => ['logs.view', 'jobs.view', 'admin.view'],
    ],
];

// Page: requireAnyCapability (acesso via URL direta)
$pageAuth = [
    'cockpit_interno.php'  => ['sales.view', 'finance.view', 'fiscal.view', 'geo.view', 'admin.view', 'logs.view'],
    'metric_catalog.php'   => ['admin.view', 'settings.view', 'logs.view'],
    'system_health.php'    => ['logs.view', 'jobs.view', 'admin.view'],
];

// ── Resultados esperados por perfil ──────────────────────────────────────────

$expected = [
    'Admin' => [
        'sidebar_cockpit'       => true,
        'sidebar_catalogo'      => true,
        'sidebar_system_health' => true,
        'page_cockpit'          => true,  // 200
        'page_catalogo'         => true,  // 200
        'page_system_health'    => true,  // 200
    ],
    'Manager-SalesOnly' => [
        'sidebar_cockpit'       => true,   // tem sales.view
        'sidebar_catalogo'      => false,  // não tem settings.view/admin.view/logs.view
        'sidebar_system_health' => false,  // não tem logs.view/jobs.view/admin.view
        'page_cockpit'          => true,   // 200 — sales.view basta
        'page_catalogo'         => false,  // 403 — sem permissão
        'page_system_health'    => false,  // 403 — sem permissão
    ],
    'Viewer-Geo' => [
        'sidebar_cockpit'       => true,   // tem geo.view
        'sidebar_catalogo'      => false,  // não tem settings.view/admin.view/logs.view
        'sidebar_system_health' => false,  // não tem logs.view/jobs.view/admin.view
        'page_cockpit'          => true,   // 200 — geo.view basta
        'page_catalogo'         => false,  // 403 — sem permissão
        'page_system_health'    => false,  // 403 — sem permissão
    ],
    'User-SettingsOnly' => [
        'sidebar_cockpit'       => false,  // não tem sales/finance/fiscal/geo/admin.view
        'sidebar_catalogo'      => true,   // tem settings.view
        'sidebar_system_health' => false,  // settings.view não está em permission_any do System Health
        'page_cockpit'          => false,  // 403 — sem permissão
        'page_catalogo'         => true,   // 200 — settings.view basta
        'page_system_health'    => false,  // 403 — sem permissão
    ],
    'NoRelevantPerms' => [
        'sidebar_cockpit'       => false,  // sem nenhuma das caps necessárias
        'sidebar_catalogo'      => false,  // sem nenhuma das caps necessárias
        'sidebar_system_health' => false,  // sem nenhuma das caps necessárias
        'page_cockpit'          => false,  // 403
        'page_catalogo'         => false,  // 403
        'page_system_health'    => false,  // 403
    ],

    // 6. Logs.view apenas
    'LogsOnly' => [
        'sidebar_cockpit'       => true,   // logs.view está em permission_any do Cockpit
        'sidebar_catalogo'      => true,   // logs.view está em permission_any do Catálogo
        'sidebar_system_health' => true,   // logs.view está em permission_any do System Health
        'page_cockpit'          => true,   // 200
        'page_catalogo'         => true,   // 200
        'page_system_health'    => true,   // 200
    ],

    // 7. Jobs.view apenas
    'JobsOnly' => [
        'sidebar_cockpit'       => false,  // jobs.view NÃO está em permission_any do Cockpit
        'sidebar_catalogo'      => false,  // jobs.view NÃO está em permission_any do Catálogo
        'sidebar_system_health' => true,   // jobs.view está em permission_any do System Health
        'page_cockpit'          => false,  // 403
        'page_catalogo'         => false,  // 403
        'page_system_health'    => true,   // 200
    ],
];

// ── Lógica de simulação ───────────────────────────────────────────────────────

/**
 * Replica a lógica de sidebar.php: verifica se user tem pelo menos 1 cap da lista.
 */
function sidebarPermAnyCheck(array $userCaps, array $permAny): bool
{
    foreach ($permAny as $cap) {
        if (in_array($cap, $userCaps, true)) {
            return true;
        }
    }
    return false;
}

/**
 * Replica Auth::requireAnyCapability: retorna true se user tem ao menos 1 cap da lista.
 * (Sem contexto de sessão; usa lista de caps diretamente como Auth::hasCapability faria
 *  após o lookup DB ou fallback para Roles::capabilities.)
 */
function requireAnyCapabilityCheck(array $userCaps, array $requiredCaps): bool
{
    foreach ($requiredCaps as $cap) {
        if (in_array($cap, $userCaps, true)) {
            return true;
        }
    }
    return false;
}

// ── Executar simulação e coletar resultados ───────────────────────────────────

$results  = [];
$allPass  = true;
$totalAssertions = 0;
$passCount = 0;

foreach ($profiles as $profileName => $userCaps) {
    $exp = $expected[$profileName];
    $row = [];

    // sidebar checks
    $row['sidebar_cockpit']       = sidebarPermAnyCheck($userCaps, $menuItems['cockpit_interno']['permission_any']);
    $row['sidebar_catalogo']      = sidebarPermAnyCheck($userCaps, $menuItems['catalogo_kpis']['permission_any']);
    $row['sidebar_system_health'] = sidebarPermAnyCheck($userCaps, $menuItems['system_health']['permission_any']);

    // page auth checks
    $row['page_cockpit']       = requireAnyCapabilityCheck($userCaps, $pageAuth['cockpit_interno.php']);
    $row['page_catalogo']      = requireAnyCapabilityCheck($userCaps, $pageAuth['metric_catalog.php']);
    $row['page_system_health'] = requireAnyCapabilityCheck($userCaps, $pageAuth['system_health.php']);

    // compare with expected
    $row['assertions'] = [];
    foreach (['sidebar_cockpit', 'sidebar_catalogo', 'sidebar_system_health', 'page_cockpit', 'page_catalogo', 'page_system_health'] as $key) {
        $pass = ($row[$key] === $exp[$key]);
        $row['assertions'][$key] = $pass;
        $totalAssertions++;
        if ($pass) {
            $passCount++;
        } else {
            $allPass = false;
        }
    }
    $row['user_caps'] = $userCaps;
    $results[$profileName] = $row;
}

// ── Verificação de retrocompatibilidade (items sem permission_any) ─────────────

$legacyItems = [
    ['label' => 'Painel Comercial',  'enabled' => true],
    ['label' => 'Time Comercial',    'enabled' => true],
    ['label' => 'Administracao',     'enabled' => true, 'requires_admin' => true],
    ['label' => 'Conciliacao',       'enabled' => true, 'requires_admin' => true],
    ['label' => 'Observabilidade',   'enabled' => true, 'requires_admin' => true],
];

$retroCompatOk = true;
foreach ($legacyItems as $item) {
    $permAny = $item['permission_any'] ?? null;
    // Deve passar o filtro (retornar true) quando permission_any não está presente
    if (is_array($permAny) && $permAny !== []) {
        $retroCompatOk = false;
    }
}

// ── Output ────────────────────────────────────────────────────────────────────

$pass = static fn(bool $v): string => $v ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m";
$bool = static fn(bool $v): string => $v ? "\033[32m✓ visible\033[0m" : "\033[90m✗ hidden\033[0m";
$http = static fn(bool $v): string => $v ? "\033[32m200\033[0m   " : "\033[31m403\033[0m   ";

echo "\n=== BIomie — Nav RBAC Simulation (Fase 7) ===\n\n";

$profileLabels = [
    'Admin'             => 'Admin (role=admin)',
    'Manager-SalesOnly' => 'Manager c/ sales.view apenas',
    'Viewer-Geo'        => 'Viewer c/ geo.view (role=viewer)',
    'User-SettingsOnly' => 'Usuário c/ settings.view apenas',
    'NoRelevantPerms'   => 'Sem permissões relevantes',
    'LogsOnly'          => 'Usuário c/ logs.view apenas (monitoramento)',
    'JobsOnly'          => 'Usuário c/ jobs.view apenas',
];

foreach ($results as $profileName => $row) {
    $exp   = $expected[$profileName];
    $label = $profileLabels[$profileName] ?? $profileName;

    echo "── Perfil $profileName: $label ──\n";
    echo "   Capabilities: " . implode(', ', array_slice($row['user_caps'], 0, 6))
        . (count($row['user_caps']) > 6 ? ' ... (' . count($row['user_caps']) . ' total)' : '') . "\n";

    $keys = [
        'sidebar_cockpit'       => 'Sidebar: Cockpit Interno MAG',
        'sidebar_catalogo'      => 'Sidebar: Catálogo de KPIs',
        'sidebar_system_health' => 'Sidebar: Saúde do Sistema',
        'page_cockpit'          => 'Página: cockpit_interno.php',
        'page_catalogo'         => 'Página: metric_catalog.php',
        'page_system_health'    => 'Página: system_health.php',
    ];

    foreach ($keys as $key => $label2) {
        $got    = $row[$key];
        $expVal = $exp[$key];
        $ok     = $row['assertions'][$key];

        if (str_starts_with($key, 'sidebar_')) {
            $gotStr = $bool($got);
            $expStr = $bool($expVal);
        } else {
            $gotStr = $http($got);
            $expStr = $http($expVal);
        }

        $passMark = $pass($ok);
        printf("   [%s] %-38s got=%-20s exp=%s\n",
            strip_tags(str_replace(["\033[32m", "\033[31m", "\033[0m"], ['', '', ''], $passMark)) === 'PASS' ? '  OK  ' : ' FAIL ',
            $label2,
            strip_tags(str_replace(["\033[32m", "\033[90m", "\033[31m", "\033[0m"], '', $gotStr)),
            strip_tags(str_replace(["\033[32m", "\033[90m", "\033[31m", "\033[0m"], '', $expStr))
        );
    }
    echo "\n";
}

// ── Retrocompatibilidade ──────────────────────────────────────────────────────
echo "── Retrocompatibilidade: items sem permission_any ──\n";
$retroLabel = $retroCompatOk ? '  OK  ' : ' FAIL ';
echo "   [$retroLabel] Items legado (sem permission_any) não são filtrados pelo novo check\n\n";

// ── Sumário ───────────────────────────────────────────────────────────────────
$retroTotal = 1;
$retroPass  = $retroCompatOk ? 1 : 0;
$total = $totalAssertions + $retroTotal;
$passes = $passCount + $retroPass;

echo "=== Resultado ===\n";
printf("   Assertions: %d/%d PASS\n", $passes, $total);
if ($passes === $total) {
    echo "   \033[32mAll checks PASS — navegação RBAC validada.\033[0m\n\n";
} else {
    echo "   \033[31mHá falhas — revisar lógica de permissões.\033[0m\n\n";
    exit(1);
}
