<?php

declare(strict_types=1);

/**
 * tools/dev/product_stock_low_inventory_audit.php
 *
 * EST.R1 — Auditoria de estoque baixo e ruptura.
 *
 * Lista:
 *   - Produtos em ruptura (saldo <= 0)
 *   - Produtos abaixo do mínimo (0 < saldo <= minimo)
 *   - Produtos com alto giro (NFs recentes) mas saldo baixo
 *
 * Usage:
 *   php tools/dev/product_stock_low_inventory_audit.php --pretty
 *   php tools/dev/product_stock_low_inventory_audit.php --json
 *   php tools/dev/product_stock_low_inventory_audit.php --limit=50 --pretty
 *
 * Exit: 0=OK (sem ruptura crítica) 1=ruptura encontrada 2=DB error
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Config;
use BIomie\Database;
use BIomie\Stock\StockReadRepository;

$opts     = getopt('', ['pretty', 'json', 'limit:']);
$isPretty = isset($opts['pretty']);
$isJson   = isset($opts['json']);
$limit    = isset($opts['limit']) ? max(1, min(500, (int) $opts['limit'])) : 100;

try {
    $config = new Config();
    $conn   = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo    = Database::connect($config, $conn);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (\Throwable $e) {
    echo ($isJson ? json_encode(['error' => $e->getMessage()]) : 'ERRO DB: ' . $e->getMessage()) . "\n";
    exit(2);
}

$repo  = new StockReadRepository($pdo);
$stats = $repo->statsGerais();
$tables = $repo->tablesExist();

if (!$tables['saldos']) {
    $msg = 'Tabela biomie_produtos_estoque_saldos não existe — execute migrations EST.R1';
    echo ($isJson ? json_encode(['error' => $msg]) : $msg) . "\n";
    exit(2);
}

$baixo    = $repo->saldosAbaixoMinimo($limit);
$ruptura  = [];

// Ruptura separada (saldo <= 0)
try {
    $st = $pdo->prepare(
        "SELECT codigo_produto, codigo_local, descricao_produto, nome_local,
                estoque_atual, estoque_minimo, unidade, atualizado_em
         FROM biomie_produtos_estoque_saldos
         WHERE estoque_atual <= 0
         ORDER BY atualizado_em DESC
         LIMIT :lim"
    );
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->execute();
    $ruptura = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable) {}

// Alto giro com saldo baixo: produtos vendidos nos últimos 30 dias com saldo < 10 unidades
$altoGiro = [];
try {
    $cutoff = date('Y-m-d', strtotime('-30 days'));
    $st = $pdo->prepare(
        "SELECT s.codigo_produto, MAX(s.descricao_produto) AS descricao_produto,
                SUM(s.estoque_atual) AS saldo_total, SUM(s.estoque_minimo) AS minimo_total,
                MAX(s.atualizado_em) AS ultima_atualizacao,
                COUNT(DISTINCT i.nota_key) AS nfs_30d,
                COALESCE(SUM(i.quantidade), 0) AS qtd_vendida_30d
         FROM biomie_produtos_estoque_saldos s
         INNER JOIN biomie_notas_fiscais_itens_canonicos i ON i.codigo_produto = s.codigo_produto
         INNER JOIN biomie_notas_fiscais_canonicas nf ON nf.nota_key = i.nota_key
             AND nf.status_fiscal = 'autorizada'
             AND nf.data_competencia >= :cutoff
         WHERE s.estoque_atual > 0
           AND s.estoque_atual < 10
         GROUP BY s.codigo_produto
         HAVING nfs_30d >= 3
         ORDER BY nfs_30d DESC
         LIMIT :lim"
    );
    $st->bindValue(':cutoff', $cutoff);
    $st->bindValue(':lim', min($limit, 50), PDO::PARAM_INT);
    $st->execute();
    $altoGiro = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable) {}

$hasRuptura = count($ruptura) > 0 || $stats['ruptura'] > 0;

$output = [
    'tool'      => 'product_stock_low_inventory_audit',
    'date'      => date('Y-m-d H:i:s'),
    'stats'     => $stats,
    'ruptura'   => ['count' => count($ruptura), 'items' => $ruptura],
    'baixo_minimo' => ['count' => count($baixo), 'items' => $baixo],
    'alto_giro_baixo_saldo' => ['count' => count($altoGiro), 'items' => $altoGiro],
    'summary'   => [
        'critical' => $hasRuptura,
        'note'     => $hasRuptura
            ? count($ruptura) . ' produto(s) em ruptura — reposição urgente.'
            : (count($baixo) > 0
                ? count($baixo) . ' produto(s) abaixo do mínimo. Sem ruptura ativa.'
                : 'Estoque dentro do normal. Sem ruptura ou abaixo do mínimo.'),
    ],
];

if ($isJson) {
    echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit($hasRuptura ? 1 : 0);
}

$bar = str_repeat('═', 70);
$sep = str_repeat('─', 70);
echo "\n{$bar}\n Estoque Baixo e Ruptura — Auditoria EST.R1\n{$bar}\n";
printf(" Stats: total=%d  com_saldo=%d  abaixo_min=%d  ruptura=%d  sem_sync=%d\n{$sep}\n\n",
    $stats['total'], $stats['com_saldo'], $stats['abaixo_minimo'], $stats['ruptura'], $stats['sem_saldo']);

if (empty($ruptura)) {
    echo " \033[32mSem produtos em ruptura.\033[0m\n\n";
} else {
    echo " \033[31mRUPTURA (" . count($ruptura) . " produto(s)):\033[0m\n";
    foreach (array_slice($ruptura, 0, 20) as $r) {
        printf("   %-40s  local=%-20s  saldo=%.2f  min=%.2f\n",
            substr($r['descricao_produto'] ?? $r['codigo_produto'], 0, 40),
            substr($r['nome_local'] ?? $r['codigo_local'], 0, 20),
            (float)($r['estoque_atual'] ?? 0), (float)($r['estoque_minimo'] ?? 0));
    }
    echo "\n";
}

if (empty($baixo)) {
    echo " \033[32mSem produtos abaixo do mínimo.\033[0m\n\n";
} else {
    echo " \033[33mABAIXO DO MÍNIMO (" . count($baixo) . " produto(s)):\033[0m\n";
    foreach (array_slice($baixo, 0, 20) as $r) {
        printf("   %-40s  local=%-20s  saldo=%.2f  min=%.2f\n",
            substr($r['descricao_produto'] ?? $r['codigo_produto'], 0, 40),
            substr($r['nome_local'] ?? $r['codigo_local'], 0, 20),
            (float)($r['estoque_atual'] ?? 0), (float)($r['estoque_minimo'] ?? 0));
    }
    echo "\n";
}

if (!empty($altoGiro)) {
    echo " \033[33mALTO GIRO + SALDO BAIXO (últimos 30 dias, " . count($altoGiro) . " produto(s)):\033[0m\n";
    foreach (array_slice($altoGiro, 0, 10) as $r) {
        printf("   %-40s  saldo=%.2f  nfs_30d=%d  qtd_vendida=%.0f\n",
            substr($r['descricao_produto'] ?? $r['codigo_produto'], 0, 40),
            (float)($r['saldo_total'] ?? 0), (int)($r['nfs_30d'] ?? 0), (float)($r['qtd_vendida_30d'] ?? 0));
    }
    echo "\n";
}

echo "{$sep}\n";
printf(" %s  —  %s\n",
    $hasRuptura ? "\033[31m✗ CRÍTICO\033[0m" : "\033[32m✔ OK\033[0m",
    $output['summary']['note']
);
echo "{$bar}\n\n";

exit($hasRuptura ? 1 : 0);
