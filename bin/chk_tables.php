<?php
$b = dirname(__DIR__);
require $b . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();
$c = new \BIomie\Config();
$p = \BIomie\Database::connect($c, 'db');
$tables = [
    'biomie_notas_fiscais_itens_canonicos',
    'biomie_produtos_canonicos',
    'biomie_documentos_xml_canonicos',
    'mart_vendas_periodo',
    'mart_vendas_produto',
    'mart_geo_vendas_bairro',
    'mart_produto_periodo',
    'mart_pedidos_periodo',
    'mart_estoque_produto',
];
foreach ($tables as $t) {
    try {
        $n = $p->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        echo "OK $t = $n rows\n";
    } catch (Exception $e) {
        echo "MISS $t\n";
    }
}
