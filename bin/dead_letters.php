<?php
require dirname(__DIR__) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();
$p = \BIomie\Database::connect(new \BIomie\Config(), 'db');
$rows = $p->query("SELECT id, queue, type, error_message, created_at FROM biomie_dead_letter_jobs ORDER BY created_at DESC")->fetchAll(\PDO::FETCH_ASSOC);
echo count($rows) . " dead letter jobs:\n";
foreach ($rows as $j) {
    echo "  ID:{$j['id']} | {$j['type']} | {$j['created_at']}\n";
    echo "  Error: " . substr($j['error_message']??'', 0, 200) . "\n\n";
}
echo "=== CLIENTS WITHOUT GEO (top 20 by value) ===\n";
$missing = $p->query("
    SELECT nf.codigo_cliente_omie, nf.nome_cliente,
           COUNT(*) AS nfs, COALESCE(SUM(nf.valor_total),0) AS valor,
           d.cep, d.cidade AS cidade_dim, d.estado AS estado_dim
    FROM biomie_notas_fiscais_canonicas nf
    LEFT JOIN biomie_clientes_geo_dim g ON g.codigo_cliente_omie=nf.codigo_cliente_omie
    LEFT JOIN biomie_clientes_dim d ON d.codigo_cliente_omie=nf.codigo_cliente_omie
    WHERE nf.status_fiscal='autorizada' AND g.codigo_cliente_omie IS NULL
    GROUP BY nf.codigo_cliente_omie, nf.nome_cliente, d.cep, d.cidade, d.estado
    ORDER BY valor DESC LIMIT 20
")->fetchAll(\PDO::FETCH_ASSOC);
foreach ($missing as $r) {
    printf("  %-50s %2d NFs  R$%10s  cep:%-10s cidade:%s %s\n",
        substr($r['nome_cliente']??'',0,49), $r['nfs'],
        number_format((float)$r['valor'],2,',','.'),
        $r['cep']??'-', $r['cidade_dim']??'-', $r['estado_dim']??'');
}
echo "\n=== CNAE DIM (biomie_cnae_dim) ===\n";
$cnaeDim = (int)$p->query("SELECT COUNT(*) FROM biomie_cnae_dim")->fetchColumn();
echo "Rows: $cnaeDim\n";
$cols = $p->query("SHOW COLUMNS FROM biomie_cnae_dim")->fetchAll(\PDO::FETCH_ASSOC);
echo "Columns: " . implode(', ', array_column($cols,'Field')) . "\n";
$topCnaes = $p->query("SELECT cnae_codigo, cnae_descricao, COUNT(*) AS n, SUM(faturamento) AS fat FROM mart_compradores_por_geo WHERE cnae_codigo IS NOT NULL AND TRIM(cnae_codigo)!='' GROUP BY cnae_codigo, cnae_descricao ORDER BY fat DESC LIMIT 12")->fetchAll(\PDO::FETCH_ASSOC);
echo "\nCNAEs no mart (para popular dim):\n";
foreach ($topCnaes as $r) printf("  %-12s %-50s %d clientes  R$ %s\n", $r['cnae_codigo'], substr($r['cnae_descricao']??'',0,49), $r['n'], number_format((float)$r['fat'],2,',','.'));
