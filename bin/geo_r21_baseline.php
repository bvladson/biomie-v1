<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();
$p = \BIomie\Database::connect(new \BIomie\Config(), 'db');

echo "=== DEAD LETTER JOBS ===\n";
$dl = $p->query("SELECT id, queue, type, error_message, created_at FROM biomie_dead_letter_jobs ORDER BY created_at DESC")->fetchAll(\PDO::FETCH_ASSOC);
if (!$dl) { echo "Nenhum.\n"; }
foreach ($dl as $j) {
    echo "ID:{$j['id']} queue:{$j['queue']} type:{$j['type']}\n";
    echo "  err: " . substr($j['error_message'] ?? '', 0, 120) . "\n";
    echo "  created: {$j['created_at']}\n\n";
}

echo "=== BASELINE GEO COVERAGE ===\n";
$auth       = (int)$p->query("SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas WHERE status_fiscal='autorizada'")->fetchColumn();
$geo        = (int)$p->query("SELECT COUNT(DISTINCT n.id) FROM biomie_notas_fiscais_canonicas n JOIN biomie_clientes_geo_dim g ON g.codigo_cliente_omie=n.codigo_cliente_omie WHERE n.status_fiscal='autorizada'")->fetchColumn();
$total_cli  = (int)$p->query("SELECT COUNT(*) FROM biomie_clientes_geo_dim")->fetchColumn();
$sem_cid    = (int)$p->query("SELECT COUNT(*) FROM biomie_clientes_geo_dim WHERE cidade_norm IS NULL OR TRIM(cidade_norm)=''")->fetchColumn();
$sem_bairro = (int)$p->query("SELECT COUNT(*) FROM biomie_clientes_geo_dim WHERE bairro_norm IS NULL OR TRIM(bairro_norm)=''")->fetchColumn();
$cep_count  = (int)$p->query("SELECT COUNT(*) FROM biomie_clientes_dim WHERE cep IS NOT NULL AND TRIM(cep)!=''")->fetchColumn();
$mart_sem   = (int)$p->query("SELECT COUNT(*) FROM mart_compradores_por_geo WHERE cidade_norm IS NULL OR TRIM(cidade_norm)=''")->fetchColumn();
$mart_fat   = (float)$p->query("SELECT COALESCE(SUM(faturamento),0) FROM mart_compradores_por_geo WHERE cidade_norm IS NULL OR TRIM(cidade_norm)=''")->fetchColumn();

echo "NFs autorizadas:    $auth\n";
echo "NFs com geo:        $geo (" . round($geo/$auth*100,1) . "%)\n";
echo "NFs SEM geo:        " . ($auth-$geo) . " (" . round(($auth-$geo)/$auth*100,1) . "%)\n";
echo "Clientes geo_dim:   $total_cli\n";
echo "Sem cidade:         $sem_cid ($total_cli)\n";
echo "Sem bairro:         $sem_bairro ($total_cli)\n";
echo "Clientes com CEP:   $cep_count (fonte: clientes_dim)\n";
echo "Mart sem cidade:    $mart_sem rows = R$ " . number_format($mart_fat,2,',','.') . "\n";

echo "\n=== CLIENTES SEM GEO (top 20) ===\n";
$missing = $p->query("
    SELECT nf.codigo_cliente_omie, nf.nome_cliente,
           COUNT(*) AS nfs, COALESCE(SUM(nf.valor_total),0) AS valor,
           d.cep, d.cidade AS cidade_dim, d.bairro AS bairro_dim
    FROM biomie_notas_fiscais_canonicas nf
    LEFT JOIN biomie_clientes_geo_dim g ON g.codigo_cliente_omie=nf.codigo_cliente_omie
    LEFT JOIN biomie_clientes_dim d ON d.codigo_cliente_omie=nf.codigo_cliente_omie
    WHERE nf.status_fiscal='autorizada' AND g.codigo_cliente_omie IS NULL
    GROUP BY nf.codigo_cliente_omie, nf.nome_cliente, d.cep, d.cidade, d.bairro
    ORDER BY valor DESC LIMIT 20
")->fetchAll(\PDO::FETCH_ASSOC);
foreach ($missing as $r) {
    printf("  %-45s %2d NFs  R$ %10s  cep:%s  cidade_dim:%s\n",
        substr($r['nome_cliente']??'',0,44),
        $r['nfs'],
        number_format((float)$r['valor'],2,',','.'),
        $r['cep'] ?? '-',
        $r['cidade_dim'] ?? '-'
    );
}

echo "\n=== CNAE DIM STATUS ===\n";
$cnaeDim = (int)$p->query("SELECT COUNT(*) FROM biomie_cnae_dim")->fetchColumn();
echo "biomie_cnae_dim rows: $cnaeDim\n";
$topCnaes = $p->query("SELECT cnae_codigo, cnae_descricao, COUNT(*) AS n FROM mart_compradores_por_geo WHERE cnae_codigo IS NOT NULL GROUP BY cnae_codigo, cnae_descricao ORDER BY n DESC LIMIT 10")->fetchAll(\PDO::FETCH_ASSOC);
echo "Top 10 CNAEs no mart (para popular dim):\n";
foreach ($topCnaes as $r) printf("  %-14s %s\n", $r['cnae_codigo'], $r['cnae_descricao']??'sem desc');
