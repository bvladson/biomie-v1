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

echo "=== CANONICAL ===\n";
$rows = $pdo->query("
    SELECT nota_key, numero_nf, serie_nf, pedido_id_omie,
           operation_scope, operation_scope_rule, operation_scope_confidence,
           omie_operation_name, valor_total
    FROM biomie_notas_fiscais_canonicas
    WHERE numero_nf IN ('00001285','00001286')
    ORDER BY numero_nf
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo "  " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}

echo "\n=== BRONZE (ide + compl + CFOP) ===\n";
$ids = array_column($pdo->query("
    SELECT nota_id_omie FROM biomie_notas_fiscais_canonicas
    WHERE numero_nf IN ('00001285','00001286')
")->fetchAll(PDO::FETCH_ASSOC), 'nota_id_omie');
if ($ids) {
    $in = implode(',', array_map('intval', $ids));
    $rows2 = $pdo->query("
        SELECT
            endpoint,
            JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.compl.nIdNF'))      AS nIdNF,
            JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.compl.nIdPedido'))   AS nIdPedido,
            JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.ide.natOp'))         AS natOp,
            JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.ide.finNFe'))        AS finNFe,
            JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.det[0].prod.CFOP'))  AS cfop,
            ingested_at
        FROM biomie_omie_bronze
        WHERE domain_name = 'invoices'
          AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.compl.nIdNF')) AS UNSIGNED) IN ($in)
        ORDER BY ingested_at DESC
        LIMIT 6
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows2 as $r) {
        echo "  " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
    }
}

echo "\n=== PEDIDOS VINCULADOS ===\n";
$rows3 = $pdo->query("
    SELECT pc.pedido_id_omie, pc.operation_scope, pc.operation_scope_rule,
           pc.numero_pedido, pc.omie_operation_name
    FROM biomie_pedidos_canonicos pc
    INNER JOIN biomie_notas_fiscais_canonicas nf ON nf.pedido_id_omie = pc.pedido_id_omie
    WHERE nf.numero_nf IN ('00001285','00001286')
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows3 as $r) {
    echo "  " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}
if (!$rows3) {
    echo "  (nenhum pedido canônico vinculado)\n";
}

echo "\n=== SCOPE_RULES ativos ===\n";
$rows4 = $pdo->query("
    SELECT id, normalized_pattern, scope, priority, source
    FROM biomie_operation_scope_rules
    WHERE is_active = 1
    ORDER BY priority ASC
    LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows4 as $r) {
    echo "  " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}
