<?php

declare(strict_types=1);

require_once __DIR__ . '/TestSupport.php';
require_once __DIR__ . '/run_golden_endpoints.php';
require_once __DIR__ . '/run_replay_idempotency.php';
require_once __DIR__ . '/run_freshness_fallback.php';
require_once __DIR__ . '/run_consistency_homologation.php';
require_once __DIR__ . '/run_shadow_homologation.php';

/**
 * Suíte completa de cobertura — Fase 2 + Fase 8.
 *
 * Suítes:
 *   golden_endpoints       — shapes e valores fixos dos 6 endpoints legados
 *   operational_resilience — replay, idempotência, janela delta sobreposta
 *   freshness_fallback     — contrato de dados em todos os modos (live/stale/reconciled)
 *   consistency_homologation — cross-domain: pedido x NF x título x vendedor
 *   shadow_homologation    — comparação canônico vs legado: totais, contagens, IDs, divergências
 *
 * Critério de encerramento da Fase 8:
 *   - Todas as suítes passam em verde
 *   - Nenhum caso "cross_domain" mostra divergência não tipada
 *   - Nenhum endpoint aparece saudável com dados zerados (Fase 7)
 *
 * @return array<string,mixed>
 */
function run_phase2_coverage_suite(): array
{
    $suites = [
        'golden_endpoints'        => run_golden_endpoints_suite(),
        'operational_resilience'  => run_replay_idempotency_suite(),
        'freshness_fallback'      => run_freshness_fallback_suite(),
        'consistency_homologation' => run_consistency_homologation_suite(),
        'shadow_homologation'     => run_shadow_homologation_suite(),
    ];

    $passed = 0;
    $failed = 0;
    $totalTests = 0;
    $passedTests = 0;
    foreach ($suites as $result) {
        if ((bool) ($result['ok'] ?? false)) {
            $passed++;
        } else {
            $failed++;
        }
        $totalTests += (int) ($result['total'] ?? 0);
        $passedTests += (int) ($result['passed'] ?? 0);
    }

    $checklist = [
        'cockpit_atualizado'      => (bool) ($suites['golden_endpoints']['ok'] ?? false),
        'paginas_conversam'       => (bool) ($suites['consistency_homologation']['ok'] ?? false),
        'numeros_batem'           => (bool) ($suites['shadow_homologation']['ok'] ?? false),
        'reconciliacao_confirma'  => (bool) ($suites['consistency_homologation']['ok'] ?? false),
        'fallback_confiavel'      => (bool) ($suites['freshness_fallback']['ok'] ?? false),
        'replay_idempotente'      => (bool) ($suites['operational_resilience']['ok'] ?? false),
    ];

    return [
        'ok'             => $failed === 0,
        'total_suites'   => count($suites),
        'passed_suites'  => $passed,
        'failed_suites'  => $failed,
        'total_tests'    => $totalTests,
        'passed_tests'   => $passedTests,
        'failed_tests'   => $totalTests - $passedTests,
        'checklist'      => $checklist,
        'suites'         => $suites,
    ];
}

$result = run_phase2_coverage_suite();
echo pretty_json($result) . PHP_EOL;
exit(($result['ok'] ?? false) ? 0 : 1);
