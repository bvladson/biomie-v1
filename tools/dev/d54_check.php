<?php
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();
$pdo = \BIomie\Database::connect(new \BIomie\Config());

$root = dirname(__DIR__, 2);
$pass = 0; $fail = 0;

// Check mart rows
$rows = (int) $pdo->query('SELECT COUNT(*) FROM mart_fiscal_items')->fetchColumn();
$ok = $rows > 0;
echo ($ok ? 'PASS' : 'FAIL') . " mart_fiscal_items: $rows rows\n";
$ok ? $pass++ : $fail++;

// Check key files
$files = [
    'api/fiscal_summary.php', 'api/fiscal_nf_list.php', 'api/fiscal_nf_detail.php',
    'api/fiscal_build_mart.php', 'api/fiscal_ncm.php', 'api/fiscal_alerts.php',
    'api/fiscal_reform.php', 'api/fiscal_tax_extract_run.php',
    'app/Modules/Fiscal/Infrastructure/FiscalMartBuilder.php',
    'app/Modules/Fiscal/Infrastructure/FiscalIntelligenceService.php',
    'assets/js/fiscal.js', 'assets/css/fiscal.css',
    'views/fiscal.html', 'fiscal.php', 'bin/fiscal_build_mart.php',
];
foreach ($files as $f) {
    $ok = file_exists($root . '/' . $f);
    echo ($ok ? 'PASS' : 'FAIL') . " $f\n";
    $ok ? $pass++ : $fail++;
}

// Check that JS has correct keys
$js = file_get_contents($root . '/assets/js/fiscal.js');
$checks = [
    'd.rows || []'          => str_contains($js, 'd.rows || []'),
    'd.result.inserted'     => str_contains($js, 'd.result && d.result.inserted'),
    'kpis.carga_pct'        => str_contains($js, 'kpis.carga_pct'),
    'kpis.total_nfs'        => str_contains($js, 'kpis.total_nfs'),
    'r.icms || r.pis'       => str_contains($js, "r.icms || 'R\$ 0,00'"),
    'h.valor_total'         => str_contains($js, "h.valor_total || 'R\$ 0,00'"),
    'v.nome'                => str_contains($js, "v.nome || 'Sem vendedor'"),
    'no d.data bug'         => !str_contains($js, 'd.data || []'),
    'no d.inserted bug'     => !str_contains($js, 'rows: d.inserted,'),
    'no kpis._carga_pct bug'=> !str_contains($js, 'kpis._carga_pct'),
];
foreach ($checks as $label => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . " JS[$label]\n";
    $ok ? $pass++ : $fail++;
}

echo "\n=== D54 RESULT: $pass PASS, $fail FAIL ===\n";
