<?php
require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();
$pdo = \BIomie\Database::connect(new \BIomie\Config(), 'flight_plan_db');

echo "=== biomie_notas_fiscais_itens_canonicos columns ===\n";
foreach ($pdo->query('DESCRIBE biomie_notas_fiscais_itens_canonicos')->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo $r['Field'] . ' | ' . $r['Type'] . "\n";
}

echo "\n=== Sample items (3) ===\n";
$st = $pdo->query("SELECT * FROM biomie_notas_fiscais_itens_canonicos LIMIT 3");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}

echo "\n=== Total items ===\n";
echo $pdo->query("SELECT COUNT(*) FROM biomie_notas_fiscais_itens_canonicos")->fetchColumn() . " items\n";

echo "\n=== Sample pedidos_canonicos with status=autorizado ===\n";
$st2 = $pdo->query("SELECT status_comercial, COUNT(*) AS cnt, SUM(valor_total) AS total FROM biomie_pedidos_canonicos GROUP BY status_comercial ORDER BY cnt DESC LIMIT 10");
foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $r) {
    printf("  %-12s  %5d pedidos  R\$ %.2f\n", $r['status_comercial'], $r['cnt'], $r['total']);
}
