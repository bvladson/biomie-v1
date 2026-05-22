<?php
$host = '193.203.175.140';
$port = 3306;
$db   = 'u106688109_Comercial';
$user = 'u106688109_Comercial_user';
$pass = 'Mag@6135';

$options = getopt('', ['start:', 'end:', 'json']);
$start = $options['start'] ?? '2026-05-01';
$end = $options['end'] ?? '2026-05-31';

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage() . "\n");
}

$report = [];

$report['connection'] = [
    'database' => $pdo->query("SELECT DATABASE()")->fetchColumn(),
    'user' => $pdo->query("SELECT CURRENT_USER()")->fetchColumn(),
    'version' => $pdo->query("SELECT VERSION()")->fetchColumn(),
];

// Bronze May diagnostic
try {
    $report['bronze_diagnostic']['bronze_payloads_2026_05'] = (int)$pdo->query("SELECT COUNT(*) FROM biomie_omie_bronze WHERE payload_json LIKE '%2026-05%'")->fetchColumn();
    $report['bronze_diagnostic']['bronze_may_nfs_detected'] = (int)$pdo->query("SELECT COUNT(*) FROM biomie_omie_bronze WHERE endpoint LIKE '%nf%' AND payload_json LIKE '%2026-05%'")->fetchColumn();
    $report['bronze_diagnostic']['bronze_may_orders_detected'] = (int)$pdo->query("SELECT COUNT(*) FROM biomie_omie_bronze WHERE endpoint LIKE '%pedido%' AND payload_json LIKE '%2026-05%'")->fetchColumn();
    $report['bronze_diagnostic']['bronze_latest_fetched_at'] = $pdo->query("SELECT MAX(ingested_at) FROM biomie_omie_bronze")->fetchColumn();
    
    $stmt = $pdo->query("SELECT endpoint, COUNT(*) as c FROM biomie_omie_bronze GROUP BY endpoint");
    $report['bronze_diagnostic']['bronze_operations_sample'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) {
    $report['bronze_diagnostic']['error'] = $e->getMessage();
}

// Canonical Fiscal May Diagnostic
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS nfs, COALESCE(SUM(valor_total),0) AS receita, MIN(data_emissao) AS min_data, MAX(data_emissao) AS max_data FROM biomie_notas_fiscais_canonicas WHERE data_emissao BETWEEN :s AND :e");
    $stmt->execute(['s' => $start, 'e' => $end]);
    $canonical = $stmt->fetch();
    $report['canonical_diagnostic'] = [
        'canonical_nfs_2026_05' => (int)$canonical['nfs'],
        'canonical_revenue_2026_05' => (float)$canonical['receita'],
        'canonical_min_date' => $canonical['min_data'],
        'canonical_max_date' => $canonical['max_data'],
        'date_column_used' => 'data_emissao'
    ];
} catch (Exception $e) {
    $report['canonical_diagnostic'] = ['error' => $e->getMessage()];
}

// Mart Geo Diagnostic
try {
    $startMonth = substr($start, 0, 7);
    $endMonth = substr($end, 0, 7);
    $stmt = $pdo->prepare("SELECT COUNT(*) AS qtd_rows, COALESCE(SUM(faturamento),0) AS revenue, COUNT(DISTINCT bairro_norm) AS hoods, MIN(competencia) AS min_d, MAX(competencia) AS max_d FROM mart_compradores_por_geo WHERE competencia BETWEEN '2026-05' AND '2026-05'");
    $stmt->execute();
    $geo = $stmt->fetch();
    $report['geo_mart_diagnostic'] = [
        'mart_geo_rows_2026_05' => (int)$geo['qtd_rows'],
        'mart_geo_revenue_2026_05' => (float)$geo['revenue'],
        'mart_geo_neighborhoods_2026_05' => (int)$geo['hoods'],
        'mart_geo_min_date' => $geo['min_d'],
        'mart_geo_max_date' => $geo['max_d'],
    ];
} catch (Exception $e) {
    $report['geo_mart_diagnostic'] = ['error' => $e->getMessage()];
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
