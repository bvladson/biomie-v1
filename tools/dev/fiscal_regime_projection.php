<?php

declare(strict_types=1);

/**
 * Projecao fiscal por regime — quanto cada empresa deve de imposto e credito.
 *
 * A apuracao de mart_fiscal_monthly_settlement soma apenas o tributo DESTACADO
 * nas notas de saida. Isso e correto na origem, mas incompleto como informacao
 * gerencial: nem todo tributo passa pela nota.
 *
 *   Simples (EXPERT, UNI_DESIGN) — a nota nao destaca nada, a apuracao devolve
 *     R$ 0, e o DAS mensal (4% a 19% conforme o RBT12) fica invisivel.
 *   Presumido (ERGOOFFICE) — IRPJ (presuncao 8%) e CSLL (presuncao 12%) sao
 *     pagos por fora e nao aparecem em nota nenhuma.
 *   Real (MAG_FILIAL, MAG_MATRIZ) — a projecao REPRODUZ a apuracao ao centavo;
 *     IRPJ/CSLL dependem do lucro contabil e ficam marcados como nao
 *     projetaveis, em vez de virar numero inventado.
 *
 * Que as duas empresas do Real batam exatamente com a apuracao existente e a
 * validacao de que o projetor nao esta somando nada indevido.
 *
 * Uso:
 *   php tools/dev/fiscal_regime_projection.php
 *   php tools/dev/fiscal_regime_projection.php --start=2026-01-01 --end=2026-07-31
 *   php tools/dev/fiscal_regime_projection.php --company=EXPERT --json
 *
 * ESTIMATIVA GERENCIAL — nao substitui a apuracao do contador.
 */

$root = dirname(__DIR__, 2);
require $root . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require $root . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Modules\Fiscal\Domain\TaxRegime;
use BIomie\Modules\Fiscal\Infrastructure\FiscalRegimeProjectionService;

$opts = getopt('', ['start:', 'end:', 'company:', 'json']);
$json = isset($opts['json']);
$start = (string) ($opts['start'] ?? date('Y-01-01'));
$end = (string) ($opts['end'] ?? date('Y-m-d'));
$only = strtoupper(trim((string) ($opts['company'] ?? '')));

$config = new \BIomie\Config();
$pdo = \BIomie\Database::connect($config, 'db');

$companies = $pdo->query('SELECT id, slug FROM biomie_companies WHERE ativo = 1 ORDER BY id')
    ->fetchAll(PDO::FETCH_ASSOC) ?: [];

if ($only !== '') {
    $companies = array_values(array_filter(
        $companies,
        static fn (array $c): bool => strtoupper((string) $c['slug']) === $only
    ));
}

// MESMO servico que a API usa. Se cada lado montasse suas queries, o relatorio
// e o painel divergiriam no primeiro ajuste de filtro.
$service = new FiscalRegimeProjectionService($pdo);
$payload = $service->forCompanies($companies, $start, $end);
$result = $payload['companies'];

if ($json) {
    echo json_encode(['ok' => true] + $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(0);
}

$brl = static fn (float $v): string => number_format($v, 2, ',', '.');

echo "Projecao fiscal por regime — {$start} a {$end}\n";
echo str_repeat('=', 78), PHP_EOL;

$totalAtual = 0.0;
$totalProj = 0.0;
foreach ($result as $r) {
    $p = $r['projecao'];
    $totalAtual += $r['apuracao_atual'];
    $totalProj += (float) $p['tributo_total'];

    printf("\n%s  [%s]\n", $r['company'], $r['regime_label']);
    printf("  receita no periodo   %18s\n", $brl((float) $p['receita_base']));
    if ($r['regime'] === TaxRegime::SIMPLES_NACIONAL) {
        printf(
            "  RBT12                %18s   faixa %d · efetiva %.3f%%\n",
            $brl((float) $p['rbt12']),
            $p['detalhe']['faixa'],
            $p['detalhe']['aliquota_efetiva_pct']
        );
    }
    printf("  apuracao atual       %18s\n", $brl($r['apuracao_atual']));
    printf("  PROJETADO            %18s   carga %.2f%%\n", $brl((float) $p['tributo_total']), $p['carga_pct']);
    printf(
        "  diferenca            %18s%s\n",
        $brl((float) $r['diferenca']),
        abs((float) $r['diferenca']) > 1.0 ? '   <<< nao aparece na apuracao' : '   (bate — nada a projetar)'
    );

    $parts = [];
    foreach ($p['tributos'] as $k => $v) {
        if ((float) $v > 0.0) {
            $parts[] = $k . ' ' . $brl((float) $v);
        }
    }
    if ($parts !== []) {
        echo '  tributos: ', implode(' · ', $parts), PHP_EOL;
    }
    if ($p['credito_projetavel']) {
        printf("  credito de entradas  %18s   (%d documento(s))\n", $brl((float) $p['credito_total']), $r['documentos_entrada']);
    }
    foreach ($p['nao_projetavel'] as $np) {
        printf("  [nao projetavel] %s — %s\n", $np['tributo'], $np['motivo']);
    }
    foreach ($p['alertas'] as $a) {
        printf("  [%s] %s\n", strtoupper((string) $a['severity']), $a['message']);
    }
}

echo PHP_EOL, str_repeat('=', 78), PHP_EOL;
printf(
    "GRUPO   apuracao %s   projetado %s   invisivel hoje %s\n",
    $brl($totalAtual),
    $brl($totalProj),
    $brl($totalProj - $totalAtual)
);
echo "Estimativa gerencial — nao substitui a apuracao do contador.\n";
