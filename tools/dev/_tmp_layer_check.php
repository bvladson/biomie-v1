<?php
declare(strict_types=1);
require_once 'app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once 'app/Bootstrap.php';
\BIomie\Bootstrap::init();
$c   = \BIomie\Config::fromEnvironment();
$con = \BIomie\Database::isConfigured($c, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo = \BIomie\Database::connect($c, $con);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function checkCount(PDO $pdo, string $table, string $where = '1=1', array $params = []): int {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE {$where}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    } catch (\Throwable $e) {
        echo "Error checking table {$table}: " . $e->getMessage() . "\n";
        return -1;
    }
}

echo "--- CANONICAL DATA --- \n";
echo "biomie_notas_fiscais_canonicas (2026-05-25): " . checkCount($pdo, 'biomie_notas_fiscais_canonicas', 'data_emissao = ?', ['2026-05-25']) . "\n";
echo "biomie_pedidos_canonicos (2026-05-25): " . checkCount($pdo, 'biomie_pedidos_canonicos', '1=1') . "\n";

echo "\n--- GOLD MARTS (2026-05-25) --- \n";
$mv = $pdo->query("SELECT SUM(valor_nfe) as valor_nfe, SUM(nfes_emitidas) as nfes_emitidas FROM mart_vendedor_dia WHERE dia = '2026-05-25'")->fetch(PDO::FETCH_ASSOC);
echo "mart_vendedor_dia (2026-05-25): " . json_encode($mv) . "\n";
