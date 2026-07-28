<?php
/**
 * Diagnóstico rápido: verifica colunas e executa queries do FiscalIntelligenceService
 * CLI only — use: php tools/dev/fiscal_diag.php
 */
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

$pdo = \BIomie\Database::connect(new \BIomie\Config());

echo "=== Colunas de biomie_notas_fiscais_canonicas ===\n";
$cols = $pdo->query("SHOW COLUMNS FROM biomie_notas_fiscais_canonicas")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) {
    echo "  {$c['Field']} {$c['Type']} {$c['Null']}\n";
}

echo "\n=== Testando executiveSummary ===\n";
try {
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*)                          AS total_nfs,
            SUM(valor_total)                  AS faturamento,
            SUM(valor_icms)                   AS icms,
            SUM(valor_pis)                    AS pis,
            SUM(valor_cofins)                 AS cofins,
            SUM(valor_ipi)                    AS ipi,
            SUM(valor_iss)                    AS iss,
            SUM(valor_trib_total)             AS trib_total
        FROM biomie_notas_fiscais_canonicas
        WHERE data_emissao BETWEEN :s AND :e
    ");
    $stmt->execute([':s' => '2026-01-01', ':e' => '2026-12-31']);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "  OK: " . json_encode($row) . "\n";
} catch (\Throwable $e) {
    echo "  ERRO: " . $e->getMessage() . "\n";
}

echo "\n=== Testando monthlyTrend ===\n";
try {
    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(data_emissao,'%Y-%m') AS month, COUNT(*) AS total_nfs
        FROM biomie_notas_fiscais_canonicas
        WHERE status_fiscal='autorizada' AND data_emissao BETWEEN :s AND :e
        GROUP BY month ORDER BY month
    ");
    $stmt->execute([':s' => '2026-01-01', ':e' => '2026-12-31']);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "  OK: " . count($rows) . " meses\n";
    foreach ($rows as $r) echo "    {$r['month']}: {$r['total_nfs']} NFs\n";
} catch (\Throwable $e) {
    echo "  ERRO: " . $e->getMessage() . "\n";
}

echo "\n=== Testando nfList count ===\n";
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas n
        WHERE n.status_fiscal = :status AND n.data_emissao BETWEEN :s AND :e
    ");
    $stmt->execute([':status' => 'autorizada', ':s' => '2026-01-01', ':e' => '2026-12-31']);
    $total = (int) $stmt->fetchColumn();
    echo "  Count: $total\n";
} catch (\Throwable $e) {
    echo "  ERRO: " . $e->getMessage() . "\n";
}

echo "\n=== Testando nfList SELECT ===\n";
try {
    $stmt = $pdo->prepare("
        SELECT n.nota_key, n.numero_nf, n.serie_nf, n.chave_nfe,
               n.data_emissao, n.nome_cliente, n.documento_cliente,
               n.nome_vendedor, n.valor_total, n.valor_icms,
               n.valor_pis, n.valor_cofins, n.valor_ipi, n.valor_iss,
               n.valor_trib_total, n.status_fiscal,
               ROUND(n.valor_trib_total/NULLIF(n.valor_total,0)*100,2) AS carga_pct
        FROM biomie_notas_fiscais_canonicas n
        WHERE n.status_fiscal = :status AND n.data_emissao BETWEEN :s AND :e
        ORDER BY n.data_emissao DESC LIMIT 3 OFFSET 0
    ");
    $stmt->bindValue(':status', 'autorizada');
    $stmt->bindValue(':s', '2026-01-01');
    $stmt->bindValue(':e', '2026-12-31');
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "  OK: " . count($rows) . " linhas\n";
    foreach ($rows as $r) echo "    NF#{$r['numero_nf']} {$r['data_emissao']} {$r['nome_cliente']}\n";
} catch (\Throwable $e) {
    echo "  ERRO: " . $e->getMessage() . "\n";
}

echo "\n=== Testando gapQuery ===\n";
try {
    $gap = $pdo->prepare("
        SELECT COUNT(*) AS sem_titulo, SUM(n.valor_total) AS valor_sem_titulo
        FROM biomie_notas_fiscais_canonicas n
        LEFT JOIN biomie_bridge_nf_titulo b ON b.nota_key = n.nota_key
        WHERE n.status_fiscal='autorizada' AND n.data_emissao BETWEEN :s AND :e AND b.nota_key IS NULL
    ");
    $gap->execute([':s' => '2026-01-01', ':e' => '2026-12-31']);
    $row = $gap->fetch(PDO::FETCH_ASSOC);
    echo "  OK: " . json_encode($row) . "\n";
} catch (\Throwable $e) {
    echo "  ERRO: " . $e->getMessage() . "\n";
}
