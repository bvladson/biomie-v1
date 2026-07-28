<?php

declare(strict_types=1);

/**
 * AGENDA DE ORÇAMENTOS — tela única do gestor (Fase 6).
 *
 * Exige sales.quotes.followup.view (gestor). A tela NUNCA consulta o Omie:
 * consome exclusivamente os endpoints api/quote_agenda_*.php do BIomie.
 *
 * A leitura exige o gate global (quando configurado) e a política da empresa.
 * Se qualquer gate negar, a página renderiza um aviso — nada de dado é carregado.
 */

use BIomie\Auth;
use BIomie\Config;
use BIomie\Database;
use BIomie\PageRenderer;
use BIomie\QuoteAgenda\QuoteAgendaAvailability;
use BIomie\Support\TenantContext;

require __DIR__ . '/app/page_bootstrap.php';
Auth::requireAnyCapability([
    'sales.quotes.followup.view',
    'sales.quotes.followup.self',
], false);

$config = new Config();
$conn = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$moduleState = QuoteAgendaAvailability::forCompany(
    $config,
    Database::connect($config, $conn),
    (int) TenantContext::companyId(),
);
$moduleEnabled = (bool) $moduleState['enabled'];

echo PageRenderer::renderPhp('quote_agenda.php', [
    // Desabilitado => page_key sem assets: o navegador não baixa CSS/JS inúteis.
    'page_key'       => $moduleEnabled ? 'agenda-orcamentos' : 'agenda-orcamentos-inativa',
    'user'           => Auth::currentUser(),
    'is_admin'       => Auth::isAdmin(),
    'module_enabled' => $moduleEnabled,
    'module_state'   => $moduleState,
    'can_manage'     => Auth::hasCapability('sales.quotes.followup.manage'),
    'can_history'    => Auth::hasCapability('sales.quotes.followup.view'),
]);
