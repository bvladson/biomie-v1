<?php
declare(strict_types=1);
$projectRoot = dirname(__DIR__);
require $projectRoot . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require $projectRoot . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

$pdo = \BIomie\Database::connect(new \BIomie\Config(), 'db');
echo "[CNAE.R1 BULK] " . date('Y-m-d H:i:s') . "\n";

// Step 1: Bulk insert from mart (single query)
$n1 = $pdo->exec("
    INSERT IGNORE INTO biomie_cnae_dim (cnae_codigo, cnae_codigo_normalizado, cnae_descricao, origem)
    SELECT
        cnae_codigo,
        REGEXP_REPLACE(cnae_codigo, '[^0-9]', ''),
        MAX(cnae_descricao),
        'mart_omie'
    FROM mart_compradores_por_geo
    WHERE cnae_codigo IS NOT NULL AND TRIM(cnae_codigo) != ''
    GROUP BY cnae_codigo
");
echo "  From mart: $n1 inserted\n";

// Step 2: Insert hardcoded IBGE descriptions
$ibge = [
    ['4120400','4120400','Construção de edifícios'],
    ['8511200','8511200','Educação infantil — creche'],
    ['8512100','8512100','Educação infantil — pré-escola'],
    ['8513900','8513900','Ensino fundamental'],
    ['8520100','8520100','Ensino médio'],
    ['8531700','8531700','Educação superior — graduação'],
    ['8541400','8541400','Educação profissional de nível técnico'],
    ['4754701','4754701','Comércio varejista de móveis'],
    ['4759801','4759801','Comércio varejista de artigos de tapeçaria, cortinas e persianas'],
    ['6911701','6911701','Serviços advocatícios'],
    ['6920601','6920601','Atividades de contabilidade'],
    ['6920602','6920602','Consultoria e auditoria contábil e tributária'],
    ['6630400','6630400','Administração de fundos por contrato ou comissão'],
    ['7732201','7732201','Locação de máquinas e equipamentos para construção'],
    ['8650004','8650004','Atividades de fisioterapia'],
    ['8630503','8630503','Atividade médica ambulatorial com recursos cirúrgicos'],
    ['6110801','6110801','Serviços de telefonia fixa comutada'],
    ['6201501','6201501','Desenvolvimento de programas de computador sob encomenda'],
    ['6209100','6209100','Suporte técnico e manutenção em TI'],
    ['6810201','6810201','Compra e venda de imóveis próprios'],
    ['8011101','8011101','Atividades de vigilância e segurança privada'],
    ['5611201','5611201','Restaurantes e similares'],
    ['9430800','9430800','Atividades de associações de defesa de direitos sociais'],
    ['9491000','9491000','Atividades de organizações religiosas ou filosóficas'],
];
$n2 = 0;
foreach ($ibge as $row) {
    $affected = $pdo->exec("
        INSERT INTO biomie_cnae_dim (cnae_codigo, cnae_codigo_normalizado, cnae_descricao, origem)
        VALUES ('{$row[0]}', '{$row[1]}', " . $pdo->quote($row[2]) . ", 'ibge_ref')
        ON DUPLICATE KEY UPDATE
            cnae_descricao = IF(cnae_descricao IS NULL OR TRIM(cnae_descricao) = '' OR cnae_descricao = cnae_codigo, " . $pdo->quote($row[2]) . ", cnae_descricao),
            origem = 'ibge_ref',
            updated_at = NOW()
    ");
    $n2 += $affected;
}
echo "  IBGE updates: $n2\n";

$total  = (int)$pdo->query("SELECT COUNT(*) FROM biomie_cnae_dim")->fetchColumn();
$comD   = (int)$pdo->query("SELECT COUNT(*) FROM biomie_cnae_dim WHERE cnae_descricao IS NOT NULL AND TRIM(cnae_descricao)!='' AND cnae_descricao != cnae_codigo")->fetchColumn();
echo "  Total dim: $total | Com descrição real: $comD\n";

// Show sample
$sample = $pdo->query("
    SELECT c.cnae_codigo, c.cnae_descricao, COALESCE(SUM(m.faturamento),0) AS fat
    FROM biomie_cnae_dim c
    LEFT JOIN mart_compradores_por_geo m ON m.cnae_codigo=c.cnae_codigo
    GROUP BY c.cnae_codigo, c.cnae_descricao
    ORDER BY fat DESC LIMIT 10
")->fetchAll(\PDO::FETCH_ASSOC);
echo "\n  Top CNAEs:\n";
foreach ($sample as $r) printf("    %-12s %-48s %s\n", $r['cnae_codigo'], substr($r['cnae_descricao']??'-',0,47), 'R$ '.number_format((float)$r['fat'],2,',','.'));
echo "\n[CNAE.R1 BULK] Done\n";
