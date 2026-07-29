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

use BIomie\Modules\Fiscal\Domain\RegimeTaxProjector;
use BIomie\Modules\Fiscal\Domain\TaxRegime;

$opts = getopt('', ['start:', 'end:', 'company:', 'json']);
$json = isset($opts['json']);
$start = (string) ($opts['start'] ?? date('Y-01-01'));
$end = (string) ($opts['end'] ?? date('Y-m-d'));
$only = strtoupper(trim((string) ($opts['company'] ?? '')));

$config = new \BIomie\Config();
$pdo = \BIomie\Database::connect($config, 'db');

$regimes = [];
try {
    foreach ($pdo->query('SELECT source_company_id, regime_tributario FROM fiscal_establishments')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $regimes[(int) $r['source_company_id']] = (string) $r['regime_tributario'];
    }
} catch (Throwable) {
    // Sem a tabela, o regime sai do dominio pelo slug.
}

$companies = $pdo->query('SELECT id, slug FROM biomie_companies WHERE ativo = 1 ORDER BY id')
    ->fetchAll(PDO::FETCH_ASSOC) ?: [];

$scalar = static function (PDO $pdo, string $sql, array $params): array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
};

$result = [];
foreach ($companies as $c) {
    $slug = (string) $c['slug'];
    if ($only !== '' && strtoupper($slug) !== $only) {
        continue;
    }
    $cid = (int) $c['id'];
    $regime = $regimes[$cid] ?? TaxRegime::forSlug($slug);

    $rec = $scalar(
        $pdo,
        "SELECT COALESCE(SUM(valor_total),0) receita,
                COUNT(DISTINCT DATE_FORMAT(data_competencia,'%Y-%m')) meses
           FROM biomie_notas_fiscais_canonicas
          WHERE company_id=:c AND data_competencia BETWEEN :s AND :e
            AND status_fiscal='autorizada'",
        [':c' => $cid, ':s' => $start, ':e' => $end]
    );

    // RBT12 do Simples: receita bruta dos ultimos 12 meses a contar do fim do periodo.
    $rbt = $scalar(
        $pdo,
        "SELECT COALESCE(SUM(valor_total),0) rbt12
           FROM biomie_notas_fiscais_canonicas
          WHERE company_id=:c AND status_fiscal='autorizada'
            AND data_competencia > DATE_SUB(:e1, INTERVAL 12 MONTH)
            AND data_competencia <= :e2",
        [':c' => $cid, ':e1' => $end, ':e2' => $end]
    );

    // Destacado nas saidas — MESMO filtro da apuracao, senao os dois lados
    // medem universos diferentes e a comparacao perde sentido.
    $dest = $scalar(
        $pdo,
        "SELECT COALESCE(SUM(valor_pis),0) pis,
                COALESCE(SUM(valor_cofins),0) cofins,
                COALESCE(SUM(valor_icms),0) icms,
                COALESCE(SUM(valor_icms_st),0)+COALESCE(SUM(valor_difal_destino),0)
                  +COALESCE(SUM(valor_fecop),0)+COALESCE(SUM(valor_ipi),0)
                  +COALESCE(SUM(valor_iss),0) outros
           FROM mart_fiscal_items
          WHERE company_id=:c AND data_emissao BETWEEN :s AND :e
            AND direction='saida' AND status_fiscal='autorizada'",
        [':c' => $cid, ':s' => $start, ':e' => $end]
    );

    $cred = ['pis' => 0.0, 'cofins' => 0.0, 'icms' => 0.0, 'docs' => 0];
    try {
        $cred = $scalar(
            $pdo,
            "SELECT COALESCE(SUM(i.valor_pis),0) pis, COALESCE(SUM(i.valor_cofins),0) cofins,
                    COALESCE(SUM(i.valor_icms),0) icms, COUNT(DISTINCT e.entry_key) docs
               FROM fiscal_purchase_entries e
               JOIN fiscal_purchase_entry_items i
                 ON i.company_id=e.company_id AND i.entry_key=e.entry_key
              WHERE e.company_id=:c AND e.competencia BETWEEN :s AND :e",
            [':c' => $cid, ':s' => substr($start, 0, 7), ':e' => substr($end, 0, 7)]
        ) + $cred;
    } catch (Throwable) {
        // Sem tabela de entradas o credito fica zero e o alerta do Real dispara.
    }

    $projection = RegimeTaxProjector::project($regime, [
        'receita_mes' => (float) ($rec['receita'] ?? 0),
        'rbt12' => (float) ($rbt['rbt12'] ?? 0),
        'meses_no_periodo' => max(1, (int) ($rec['meses'] ?? 1)),
        'destacado_pis' => (float) ($dest['pis'] ?? 0),
        'destacado_cofins' => (float) ($dest['cofins'] ?? 0),
        'destacado_icms' => (float) ($dest['icms'] ?? 0),
        'destacado_outros' => (float) ($dest['outros'] ?? 0),
        'credito_pis' => (float) ($cred['pis'] ?? 0),
        'credito_cofins' => (float) ($cred['cofins'] ?? 0),
        'credito_icms' => (float) ($cred['icms'] ?? 0),
    ]);

    $atual = $scalar(
        $pdo,
        'SELECT COALESCE(SUM(tributos_brutos),0) t FROM mart_fiscal_monthly_settlement
          WHERE company_id=:c AND competencia BETWEEN :s AND :e',
        [':c' => $cid, ':s' => substr($start, 0, 7), ':e' => substr($end, 0, 7)]
    );

    $result[] = [
        'company' => $slug,
        'company_id' => $cid,
        'regime' => $regime,
        'regime_label' => TaxRegime::profile($regime)['label'],
        'apuracao_atual' => round((float) ($atual['t'] ?? 0), 2),
        'documentos_entrada' => (int) ($cred['docs'] ?? 0),
        'projecao' => $projection,
        'diferenca' => round((float) $projection['tributo_total'] - (float) ($atual['t'] ?? 0), 2),
    ];
}

if ($json) {
    echo json_encode([
        'ok' => true,
        'period' => ['start' => $start, 'end' => $end],
        'disclaimer' => 'Estimativa gerencial. Nao substitui a apuracao do contador.',
        'companies' => $result,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
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
