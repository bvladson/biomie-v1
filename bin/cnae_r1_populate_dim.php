<?php
declare(strict_types=1);
/**
 * CNAE.R1 — Popula biomie_cnae_dim com descrições oficiais IBGE + dados do mart
 * Fontes: 1) mart_compradores_por_geo (dados Omie)  2) mapeamento IBGE hardcoded
 * Uso: php bin/cnae_r1_populate_dim.php [--dry-run]
 */
$projectRoot = dirname(__DIR__);
require $projectRoot . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require $projectRoot . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

$dryRun = in_array('--dry-run', $argv ?? []);
$pdo    = \BIomie\Database::connect(new \BIomie\Config(), 'db');

echo "[CNAE.R1] Populando biomie_cnae_dim — " . date('Y-m-d H:i:s') . " dry=" . ($dryRun?'true':'false') . "\n\n";

// ── Mapeamento IBGE oficial (top CNAE clientes MAG Móveis + comuns Ceará) ────
$ibge = [
    // Construção civil
    '4120400' => ['Construção de edifícios',                          'C - Ind. Transformação'],
    '4110700' => ['Incorporação de empreendimentos imobiliários',     'L - Imob./Aluguel'],
    '4121600' => ['Construção de edifícios comerciais e industriais', 'C - Ind. Transformação'],
    // Comércio moveis/varejo
    '4754701' => ['Comércio varejista de móveis',                     'G - Comércio'],
    '4754702' => ['Comércio varejista de artigos de colchoaria',      'G - Comércio'],
    '4759801' => ['Comércio varejista de artigos de tapeçaria',       'G - Comércio'],
    '4759899' => ['Comércio varejista de outros artigos de uso doméstico', 'G - Comércio'],
    '4761001' => ['Comércio varejista de livros',                     'G - Comércio'],
    '4763601' => ['Comércio varejista de brinquedos e artigos recreativos', 'G - Comércio'],
    '4789004' => ['Comércio varejista de animais vivos e artigos e alimentos para animais de estimação', 'G - Comércio'],
    // Educação
    '8511200' => ['Educação infantil — creche',                       'P - Educação'],
    '8512100' => ['Educação infantil — pré-escola',                   'P - Educação'],
    '8513900' => ['Ensino fundamental',                               'P - Educação'],
    '8520100' => ['Ensino médio',                                     'P - Educação'],
    '8531700' => ['Educação superior — graduação',                    'P - Educação'],
    '8532500' => ['Educação superior — graduação e pós-graduação',    'P - Educação'],
    '8533300' => ['Educação superior — pós-graduação e extensão',     'P - Educação'],
    '8541400' => ['Educação profissional de nível técnico',           'P - Educação'],
    '8542200' => ['Educação profissional de nível tecnológico',       'P - Educação'],
    '8550301' => ['Administração de caixas escolares',                'P - Educação'],
    '8599604' => ['Treinamento em desenvolvimento profissional e gerencial', 'P - Educação'],
    '8599699' => ['Outras atividades de ensino não especificadas',    'P - Educação'],
    // Saúde
    '8610101' => ['Atividades de atendimento hospitalar, exceto pronto-socorro e unidades para atendimento a urgências', 'Q - Saúde'],
    '8621601' => ['UTI móvel',                                        'Q - Saúde'],
    '8630503' => ['Atividade médica ambulatorial com recursos para realização de procedimentos cirúrgicos', 'Q - Saúde'],
    '8630506' => ['Serviços de vacinação e imunização humana',        'Q - Saúde'],
    '8650001' => ['Atividades de enfermagem',                         'Q - Saúde'],
    '8650002' => ['Atividades de profissionais da nutrição',          'Q - Saúde'],
    '8650004' => ['Atividades de fisioterapia',                       'Q - Saúde'],
    '8650005' => ['Atividades de terapia ocupacional',                'Q - Saúde'],
    // Financeiro / Seguros
    '6499301' => ['Clubes de investimento',                           'K - Financeiro'],
    '6499302' => ['Sociedades de investimento',                       'K - Financeiro'],
    '6499399' => ['Outras atividades de serviços financeiros não especificadas', 'K - Financeiro'],
    '6630400' => ['Atividades de administração de fundos por contrato ou comissão', 'K - Financeiro'],
    '6712500' => ['Atividades de intermediários em transações de títulos e valores mobiliários', 'K - Financeiro'],
    '6719901' => ['Bolsas de valores',                                'K - Financeiro'],
    '6612601' => ['Corretoras de títulos e valores mobiliários',      'K - Financeiro'],
    '6630100' => ['Gestão de ativos para terceiros',                  'K - Financeiro'],
    // Jurídico / Contabilidade
    '6911701' => ['Serviços advocatícios',                            'M - Prof. Científico'],
    '6911702' => ['Atividades auxiliares da justiça',                 'M - Prof. Científico'],
    '6920601' => ['Atividades de contabilidade',                      'M - Prof. Científico'],
    '6920602' => ['Atividades de consultoria e auditoria contábil e tributária', 'M - Prof. Científico'],
    // TI / Tecnologia
    '6201501' => ['Desenvolvimento de programas de computador sob encomenda', 'J - TI'],
    '6201502' => ['Web design',                                       'J - TI'],
    '6202300' => ['Desenvolvimento e licenciamento de programas de computador', 'J - TI'],
    '6209100' => ['Suporte técnico, manutenção e outros serviços em TI', 'J - TI'],
    '6311900' => ['Tratamento de dados, provedores de serviços de aplicação', 'J - TI'],
    // Imobiliário
    '6810201' => ['Compra e venda de imóveis próprios',               'L - Imob./Aluguel'],
    '6810202' => ['Aluguel de imóveis próprios',                      'L - Imob./Aluguel'],
    '6822600' => ['Gestão e administração da propriedade imobiliária', 'L - Imob./Aluguel'],
    // Locação / Serviços
    '7711000' => ['Locação de automóveis sem condutor',               'N - Serv. Adm.'],
    '7719501' => ['Locação de embarcações sem tripulação',            'N - Serv. Adm.'],
    '7719502' => ['Locação de aeronaves sem tripulação',              'N - Serv. Adm.'],
    '7732201' => ['Locação de máquinas e equipamentos para construção sem operador', 'N - Serv. Adm.'],
    '7732202' => ['Locação de andaimes e outros equipamentos para construção sem operador', 'N - Serv. Adm.'],
    // Alimentação
    '5611201' => ['Restaurantes e similares',                         'I - Alojamento/Alim.'],
    '5611203' => ['Lanchonetes, casas de chá, de sucos e similares',  'I - Alojamento/Alim.'],
    '5620101' => ['Fornecimento de alimentos preparados preponderantemente para empresas', 'I - Alojamento/Alim.'],
    // Outros serviços
    '8011101' => ['Atividades de vigilância e segurança privada',     'N - Serv. Adm.'],
    '8121400' => ['Limpeza em prédios e em domicílios',               'N - Serv. Adm.'],
    '9430800' => ['Atividades de associações de defesa de direitos sociais', 'S - Outros Serv.'],
    '9491000' => ['Atividades de organizações religiosas ou filosóficas', 'S - Outros Serv.'],
];

// ── Step 1: Insert from mart (existing codes + descriptions from Omie) ────────
echo "  [1] Importando CNAEs do mart...\n";
$martCnaes = $pdo->query("
    SELECT cnae_codigo, MAX(cnae_descricao) AS cnae_descricao, COUNT(*) AS n, SUM(faturamento) AS fat
    FROM mart_compradores_por_geo
    WHERE cnae_codigo IS NOT NULL AND TRIM(cnae_codigo) != ''
    GROUP BY cnae_codigo
    ORDER BY fat DESC
")->fetchAll(\PDO::FETCH_ASSOC);
echo "    Encontrados: " . count($martCnaes) . " CNAEs únicos no mart\n";

$fromMart = 0;
if (!$dryRun) {
    foreach ($martCnaes as $r) {
        $cod   = trim($r['cnae_codigo']);
        $desc  = trim($r['cnae_descricao'] ?? '');
        if (empty($cod)) continue;
        // Normalize code (remove dots/slashes/hyphens for comparison)
        $normCod = preg_replace('/\D/', '', $cod);
        // Try to find in IBGE map
        $ibgeDesc = $ibge[$normCod][0] ?? ($ibge[$cod][0] ?? $desc);
        $segmento = $ibge[$normCod][1] ?? ($ibge[$cod][1] ?? null);
        $pdo->prepare("
            INSERT INTO biomie_cnae_dim (cnae_codigo, cnae_codigo_normalizado, cnae_descricao, origem, updated_at)
            VALUES (:cod, :norm, :desc, :origem, NOW())
            ON DUPLICATE KEY UPDATE
                cnae_descricao = COALESCE(NULLIF(VALUES(cnae_descricao), ''), cnae_descricao),
                cnae_codigo_normalizado = COALESCE(VALUES(cnae_codigo_normalizado), cnae_codigo_normalizado),
                origem = 'mart_omie_ibge',
                updated_at = NOW()
        ")->execute([':cod'=>$cod,':norm'=>$normCod,':desc'=>$ibgeDesc,':origem'=>'mart_omie']);
        $fromMart++;
    }
}
echo "    Inseridos/atualizados: $fromMart\n\n";

// ── Step 2: Insert remaining IBGE codes not yet in dim ────────────────────────
echo "  [2] Inserindo mapeamento IBGE para códigos não presentes...\n";
$fromIbge = 0;
if (!$dryRun) {
    foreach ($ibge as $code => $data) {
        $code = (string)$code;
        [$desc, $segmento] = $data;
        $norm = preg_replace('/\D/', '', $code);
        $pdo->prepare("
            INSERT INTO biomie_cnae_dim (cnae_codigo, cnae_codigo_normalizado, cnae_descricao, origem)
            VALUES (:cod, :norm, :desc, 'ibge_ref')
            ON DUPLICATE KEY UPDATE
                cnae_descricao = IF(cnae_descricao IS NULL OR TRIM(cnae_descricao)='', VALUES(cnae_descricao), cnae_descricao),
                cnae_codigo_normalizado = COALESCE(cnae_codigo_normalizado, VALUES(cnae_codigo_normalizado)),
                updated_at = NOW()
        ")->execute([':cod'=>$code,':norm'=>$norm,':desc'=>$desc]);
        $fromIbge++;
    }
}
echo "    IBGE entries: $fromIbge\n\n";

// ── Result ─────────────────────────────────────────────────────────────────────
$total = (int)$pdo->query("SELECT COUNT(*) FROM biomie_cnae_dim")->fetchColumn();
$comDesc = (int)$pdo->query("SELECT COUNT(*) FROM biomie_cnae_dim WHERE cnae_descricao IS NOT NULL AND TRIM(cnae_descricao)!=''")->fetchColumn();
echo "  biomie_cnae_dim total: $total\n";
echo "  Com descrição: $comDesc\n\n";

// Show sample
$sample = $pdo->query("
    SELECT c.cnae_codigo, c.cnae_descricao, COALESCE(SUM(m.faturamento),0) AS fat
    FROM biomie_cnae_dim c
    LEFT JOIN mart_compradores_por_geo m ON m.cnae_codigo = c.cnae_codigo
    GROUP BY c.cnae_codigo, c.cnae_descricao
    ORDER BY fat DESC
    LIMIT 12
")->fetchAll(\PDO::FETCH_ASSOC);
echo "  Top CNAEs com descrição:\n";
foreach ($sample as $r) {
    printf("    %-12s %-50s %s\n", $r['cnae_codigo'], substr($r['cnae_descricao']??'sem desc',0,49),
        (float)$r['fat'] > 0 ? 'R$ ' . number_format((float)$r['fat'],2,',','.') : '-');
}
echo "\n[CNAE.R1] Concluído\n";
