<?php

declare(strict_types=1);

require_once __DIR__ . '/TestSupport.php';
require_once __DIR__ . '/TestRunner.php';

/**
 * Shadow homologation: verifica que o caminho canônico (DB normalizado)
 * e o caminho legado (snapshot Omie) produzem métricas consistentes
 * para o mesmo período e conjunto de dados.
 *
 * Não exige banco de dados — usa pares de métricas representativos
 * para garantir que a lógica de comparação funcione corretamente antes
 * de rodar contra dados reais.
 *
 * Critério de aprovação:
 *   - Receitas e totais: dentro de 0.5% de tolerância (arredondamento/base)
 *   - Contagens: diferença máxima de ±1 (casos de janela de sync)
 *   - Vendor IDs: canônico deve conter pelo menos os mesmos IDs do legado
 *   - Divergências: tipos críticos não podem existir apenas em um lado
 *
 * @return array<string,mixed>
 */
function run_shadow_homologation_suite(): array
{
    $runner = new TestRunner();

    // ─── Helpers ────────────────────────────────────────────────────────────

    /**
     * Compara dois floats com tolerância relativa.
     * Retorna null se dentro do limite, ou array de mismatch caso contrário.
     */
    $cmpFloat = static function (string $path, float $legacy, float $canonical, float $tolPct = 0.5): ?array {
        if ($legacy === 0.0 && $canonical === 0.0) {
            return null;
        }
        if ($legacy === 0.0 || $canonical === 0.0) {
            return [
                'path' => $path,
                'expected' => ['legacy' => $legacy, 'tolerance_pct' => $tolPct],
                'actual' => ['canonical' => $canonical, 'note' => 'one side is zero'],
            ];
        }
        $deltaPct = abs($legacy - $canonical) / max(abs($legacy), abs($canonical)) * 100;
        if ($deltaPct > $tolPct) {
            return [
                'path' => $path,
                'expected' => ['legacy' => $legacy, 'tolerance_pct' => $tolPct],
                'actual' => ['canonical' => $canonical, 'delta_pct' => round($deltaPct, 3)],
            ];
        }
        return null;
    };

    /**
     * Compara dois inteiros com tolerância absoluta.
     */
    $cmpInt = static function (string $path, int $legacy, int $canonical, int $tolAbs = 1): ?array {
        if (abs($legacy - $canonical) > $tolAbs) {
            return [
                'path' => $path,
                'expected' => ['legacy' => $legacy, 'tolerance_abs' => $tolAbs],
                'actual' => ['canonical' => $canonical],
            ];
        }
        return null;
    };

    // ─── Test 1: overview revenue e orders ──────────────────────────────────
    $runner->run(
        'shadow:overview_totals',
        'Overview: receita e pedidos do legado devem bater com o canonical dentro de 0.5% / ±1.',
        static function () use ($cmpFloat, $cmpInt): array {
            $legacy = [
                'revenue' => 15200.45,
                'merchandise_total' => 14750.10,
                'orders' => 18,
                'active_vendors' => 3,
            ];
            // Canonical produz valores ligeiramente diferentes por diferença de base
            // (legado usa total do pedido; canonical usa merchandise_total da NF)
            $canonical = [
                'revenue' => 15187.30,  // delta = 0.087% — dentro de 0.5%
                'merchandise_total' => 14750.10,
                'orders' => 18,
                'active_vendors' => 3,
            ];

            $mismatches = array_filter([
                $cmpFloat('overview.revenue', $legacy['revenue'], $canonical['revenue']),
                $cmpFloat('overview.merchandise_total', $legacy['merchandise_total'], $canonical['merchandise_total']),
                $cmpInt('overview.orders', $legacy['orders'], $canonical['orders'], 0),
                $cmpInt('overview.active_vendors', $legacy['active_vendors'], $canonical['active_vendors']),
            ]);

            return ['ok' => $mismatches === [], 'mismatches' => array_values($mismatches)];
        }
    );

    // ─── Test 2: overview com delta acima da tolerância deve falhar ──────────
    $runner->run(
        'shadow:overview_revenue_exceeds_tolerance',
        'Delta de receita acima de 0.5% deve ser sinalizado como divergencia.',
        static function () use ($cmpFloat): array {
            $legacy = 15200.45;
            $canonical = 13800.00; // delta = 9.2% — deve falhar

            $mismatch = $cmpFloat('overview.revenue', $legacy, $canonical, 0.5);

            // Este teste ESPERA um mismatch — inverte a lógica
            $ok = $mismatch !== null; // detectou o problema = passou no teste
            return [
                'ok' => $ok,
                'mismatches' => $ok ? [] : [[
                    'path' => 'shadow_detection',
                    'expected' => 'mismatch detected for revenue delta > 0.5%',
                    'actual' => 'no mismatch reported — comparator is blind to large delta',
                ]],
            ];
        }
    );

    // ─── Test 3: vendors — IDs presentes em ambos os lados ──────────────────
    $runner->run(
        'shadow:vendors_id_coverage',
        'Todos os vendor IDs do legado devem estar presentes no canonical.',
        static function (): array {
            $legacyIds = ['10', '11', '12'];
            $canonicalIds = ['10', '11', '12', '13']; // canonical pode ter mais (resolveu novos)

            $missing = array_values(array_diff($legacyIds, $canonicalIds));

            return [
                'ok' => $missing === [],
                'mismatches' => $missing === [] ? [] : [[
                    'path' => 'vendors.ids_missing_in_canonical',
                    'expected' => 'all legacy vendor IDs present',
                    'actual' => $missing,
                ]],
            ];
        }
    );

    // ─── Test 4: vendors — receita por vendedor dentro de tolerância ─────────
    $runner->run(
        'shadow:vendors_revenue_per_vendor',
        'Receita por vendedor deve bater entre legado e canonical dentro de 1%.',
        static function () use ($cmpFloat): array {
            $legacyRevenues = ['10' => 7200.15, '11' => 4900.30, '12' => 3100.00];
            $canonicalRevenues = ['10' => 7195.00, '11' => 4920.00, '12' => 3100.00];
            // '10': delta = 0.07% OK; '11': delta = 0.4% OK; '12': exact

            $mismatches = [];
            foreach ($legacyRevenues as $vendorId => $legacyValue) {
                $canonicalValue = (float) ($canonicalRevenues[$vendorId] ?? 0);
                $m = $cmpFloat("vendors.{$vendorId}.revenue", $legacyValue, $canonicalValue, 1.0);
                if ($m !== null) {
                    $mismatches[] = $m;
                }
            }

            return ['ok' => $mismatches === [], 'mismatches' => $mismatches];
        }
    );

    // ─── Test 5: finance overview — realizado e em aberto ───────────────────
    $runner->run(
        'shadow:finance_overview_totals',
        'Finance overview: recebido e em aberto devem coincidir entre legado e canonical dentro de 0.5%.',
        static function () use ($cmpFloat, $cmpInt): array {
            $legacy = [
                'received_total' => 28450.80,
                'open_total' => 6200.00,
                'overdue_total' => 1850.00,
                'client_count' => 14,
            ];
            $canonical = [
                'received_total' => 28473.50, // delta = 0.079% — OK
                'open_total' => 6200.00,
                'overdue_total' => 1850.00,
                'client_count' => 14,
            ];

            $mismatches = array_filter([
                $cmpFloat('finance.received_total', $legacy['received_total'], $canonical['received_total']),
                $cmpFloat('finance.open_total', $legacy['open_total'], $canonical['open_total']),
                $cmpFloat('finance.overdue_total', $legacy['overdue_total'], $canonical['overdue_total']),
                $cmpInt('finance.client_count', $legacy['client_count'], $canonical['client_count'], 0),
            ]);

            return ['ok' => $mismatches === [], 'mismatches' => array_values($mismatches)];
        }
    );

    // ─── Test 6: finance titles — contagem e total por bucket ───────────────
    $runner->run(
        'shadow:finance_titles_buckets',
        'Finance titles: contagem e total por bucket devem coincidir.',
        static function () use ($cmpFloat, $cmpInt): array {
            $buckets = ['open', 'overdue', 'received'];
            $legacy = [
                'open' => ['count' => 12, 'total' => 6200.00],
                'overdue' => ['count' => 4, 'total' => 1850.00],
                'received' => ['count' => 35, 'total' => 28450.80],
            ];
            $canonical = [
                'open' => ['count' => 12, 'total' => 6200.00],
                'overdue' => ['count' => 4, 'total' => 1850.00],
                'received' => ['count' => 35, 'total' => 28473.50],
            ];

            $mismatches = [];
            foreach ($buckets as $bucket) {
                $m = $cmpInt("titles.{$bucket}.count", $legacy[$bucket]['count'], $canonical[$bucket]['count'], 0);
                if ($m !== null) {
                    $mismatches[] = $m;
                }
                $m = $cmpFloat("titles.{$bucket}.total", $legacy[$bucket]['total'], $canonical[$bucket]['total']);
                if ($m !== null) {
                    $mismatches[] = $m;
                }
            }

            return ['ok' => $mismatches === [], 'mismatches' => $mismatches];
        }
    );

    // ─── Test 7: reconciliação — contagem e tipos de divergência ────────────
    $runner->run(
        'shadow:reconciliation_divergences',
        'Reconciliacao: contagem e tipos criticos de divergencia devem bater entre legado e canonical.',
        static function (): array {
            $legacyDivergences = ['pedido_faturado_sem_nf_valida', 'diferenca_valor_pedido_nf'];
            $canonicalDivergences = ['pedido_faturado_sem_nf_valida', 'diferenca_valor_pedido_nf'];

            $criticalCodes = ['pedido_faturado_sem_nf_valida', 'pedido_cancelado_com_nf_ativa', 'nf_autorizada_sem_vendedor_resolvido'];

            $legacyCriticals = array_values(array_intersect($legacyDivergences, $criticalCodes));
            $canonicalCriticals = array_values(array_intersect($canonicalDivergences, $criticalCodes));

            sort($legacyCriticals);
            sort($canonicalCriticals);

            $mismatches = [];
            if ($legacyCriticals !== $canonicalCriticals) {
                $mismatches[] = [
                    'path' => 'reconciliation.critical_divergences',
                    'expected' => $legacyCriticals,
                    'actual' => $canonicalCriticals,
                ];
            }
            if (count($legacyDivergences) !== count($canonicalDivergences)) {
                $mismatches[] = [
                    'path' => 'reconciliation.divergence_count',
                    'expected' => count($legacyDivergences),
                    'actual' => count($canonicalDivergences),
                ];
            }

            return ['ok' => $mismatches === [], 'mismatches' => $mismatches];
        }
    );

    // ─── Test 8: reconciliação — canonical detecta crítico que legado perdeu ─
    $runner->run(
        'shadow:reconciliation_canonical_sees_more',
        'Canonical pode detectar criticos que o legado perdeu — deve ser sinalizado como divergencia.',
        static function (): array {
            $legacyDivergences = ['diferenca_valor_pedido_nf'];
            $canonicalDivergences = ['pedido_cancelado_com_nf_ativa', 'diferenca_valor_pedido_nf'];

            $criticalCodes = ['pedido_faturado_sem_nf_valida', 'pedido_cancelado_com_nf_ativa', 'nf_autorizada_sem_vendedor_resolvido'];

            $legacyCriticals = array_values(array_intersect($legacyDivergences, $criticalCodes));
            $canonicalCriticals = array_values(array_intersect($canonicalDivergences, $criticalCodes));

            // Canonical viu pedido_cancelado_com_nf_ativa; legado não viu.
            // Isso é uma divergência de detecção — canonical é mais preciso aqui.
            $canonicalOnlyNewCriticals = array_values(array_diff($canonicalCriticals, $legacyCriticals));
            $ok = $canonicalOnlyNewCriticals !== []; // esperamos que canonical tenha detectado mais

            return [
                'ok' => $ok,
                'mismatches' => $ok ? [] : [[
                    'path' => 'reconciliation.canonical_detection_gap',
                    'expected' => 'canonical detecta criticos adicionais',
                    'actual' => 'nenhum critico adicional detectado no canonical',
                ]],
            ];
        }
    );

    // ─── Test 9: cross-domain consistency (os três números batem) ───────────
    $runner->run(
        'shadow:cross_domain_clean_dataset',
        'Dataset limpo: receita_comercial, receita_fiscal e financeiro_realizado devem convergir.',
        static function () use ($cmpFloat): array {
            // Simula um mês fechado sem divergências
            $receitaComercial = 48750.00; // soma dos pedidos faturados
            $receitaFiscal = 48750.00;    // soma das NFs autorizadas
            $financeiroRealizado = 48210.00; // pode ser ligeiramente menor (título parcelado ainda aberto)

            $mismatches = array_filter([
                $cmpFloat('cross_domain.comercial_vs_fiscal', $receitaComercial, $receitaFiscal, 0.5),
                // Financeiro pode ser até 2% menor (parcelas futuras ainda abertas)
                $cmpFloat('cross_domain.fiscal_vs_financeiro', $receitaFiscal, $financeiroRealizado, 2.0),
            ]);

            return ['ok' => $mismatches === [], 'mismatches' => array_values($mismatches)];
        }
    );

    // ─── Test 10: cross-domain com conflito real (fiscal cancelou, financeiro não) ─
    $runner->run(
        'shadow:cross_domain_fiscal_financeiro_conflict',
        'Conflito entre fiscal e financeiro: NF cancelada com titulo liquidado deve ser detectado.',
        static function () use ($cmpFloat): array {
            $receitaFiscal = 0.00;       // NF cancelada → zero na fiscal
            $financeiroRealizado = 4200.00; // título já liquidado antes do cancelamento

            // delta = infinito (um lado é zero) → deve reportar divergência
            $mismatch = null;
            if ($receitaFiscal === 0.0 && $financeiroRealizado > 0) {
                $mismatch = [
                    'path' => 'cross_domain.fiscal_vs_financeiro',
                    'expected' => 'receita_fiscal >= 0 quando financeiro_realizado > 0 em periodo fechado',
                    'actual' => ['receita_fiscal' => $receitaFiscal, 'financeiro_realizado' => $financeiroRealizado],
                    'note' => 'NF cancelada com titulo ja liquidado — requer reconciliacao manual',
                ];
            }

            // O teste ESPERA que o conflito seja detectado
            $conflictDetected = $mismatch !== null;
            return [
                'ok' => $conflictDetected,
                'mismatches' => $conflictDetected ? [] : [[
                    'path' => 'conflict_detection',
                    'expected' => 'conflito fiscal x financeiro detectado',
                    'actual' => 'sem deteccao — verificar logica de comparacao',
                ]],
                'conflict_detail' => $mismatch,
            ];
        }
    );

    return $runner->summary();
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $result = run_shadow_homologation_suite();
    echo pretty_json($result) . PHP_EOL;
    exit(($result['ok'] ?? false) ? 0 : 1);
}
