<?php
$host = '193.203.175.140';
$port = 3306;
$db   = 'u106688109_Comercial';
$user = 'u106688109_Comercial_user';
$pass = 'Mag@6135';

$options = getopt('', ['start:', 'end:', 'json']);
$start = $options['start'] ?? '2026-05-01';
$end = $options['end'] ?? '2026-05-31';
$isJson = isset($options['json']);

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage() . "\n");
}

$report = [
    'connection' => [
        'database' => $pdo->query("SELECT DATABASE()")->fetchColumn(),
        'user' => $pdo->query("SELECT CURRENT_USER()")->fetchColumn(),
        'version' => $pdo->query("SELECT VERSION()")->fetchColumn(),
    ],
    'tables' => [],
    'bronze_diagnostic' => []
];

$tablesConfig = [
    'biomie_omie_bronze' => ['date' => ['created_at', 'data_sincronizacao', 'data_extracao', 'id'], 'val' => []],
    'biomie_pedidos_canonicos' => ['date' => ['data_previsao', 'data_pedido', 'data_emissao'], 'val' => ['valor_total']],
    'biomie_notas_fiscais_canonicas' => ['date' => ['data_emissao'], 'val' => ['valor_total']],
    'biomie_notas_fiscais_itens_canonicos' => ['date' => ['created_at', 'id'], 'val' => ['valor_total', 'valor_item']],
    'mart_fiscal_nfe' => ['date' => ['data_emissao'], 'val' => ['valor_nf', 'valor_total']],
    'mart_cliente_periodo' => ['date' => ['competencia'], 'val' => ['faturado', 'receita', 'valor_total'], 'is_month' => true],
    'mart_compradores_por_geo' => ['date' => ['competencia'], 'val' => ['faturamento', 'receita'], 'is_month' => true],
    'mart_vendedor_dia' => ['date' => ['data_venda', 'data_faturamento', 'data_referencia'], 'val' => ['faturamento', 'valor_total']],
    'mart_produto_periodo' => ['date' => ['competencia'], 'val' => ['receita', 'faturamento'], 'is_month' => true],
];

foreach ($tablesConfig as $tableName => $conf) {
    try {
        $exists = $pdo->query("SHOW TABLES LIKE '$tableName'")->rowCount() > 0;
        if (!$exists) {
            $report['tables'][$tableName] = ['status' => 'missing'];
            continue;
        }

        $columns = $pdo->query("DESCRIBE $tableName")->fetchAll(PDO::FETCH_COLUMN);
        
        $dateCol = null;
        foreach ($conf['date'] as $col) {
            if (in_array($col, $columns)) {
                $dateCol = $col;
                break;
            }
        }
        
        $valCol = null;
        foreach ($conf['val'] as $col) {
            if (in_array($col, $columns)) {
                $valCol = $col;
                break;
            }
        }
        
        $totalGeral = $pdo->query("SELECT COUNT(*) FROM $tableName")->fetchColumn();
        
        $minDate = null;
        $maxDate = null;
        $periodCount = null;
        $periodVal = null;
        
        if ($dateCol) {
            $minDate = $pdo->query("SELECT MIN($dateCol) FROM $tableName")->fetchColumn();
            $maxDate = $pdo->query("SELECT MAX($dateCol) FROM $tableName")->fetchColumn();

            $startFilter = $conf['is_month'] ?? false ? substr($start, 0, 7) : $start;
            $endFilter = $conf['is_month'] ?? false ? substr($end, 0, 7) : $end;

            $periodCountStmt = $pdo->prepare("SELECT COUNT(*) FROM $tableName WHERE $dateCol BETWEEN :s AND :e");
            $periodCountStmt->execute(['s' => $startFilter, 'e' => $endFilter]);
            $periodCount = $periodCountStmt->fetchColumn();

            if ($valCol) {
                $periodValStmt = $pdo->prepare("SELECT SUM($valCol) FROM $tableName WHERE $dateCol BETWEEN :s AND :e");
                $periodValStmt->execute(['s' => $startFilter, 'e' => $endFilter]);
                $periodVal = $periodValStmt->fetchColumn();
            }
        }

        $report['tables'][$tableName] = [
            'status' => 'ok',
            'total_geral' => (int)$totalGeral,
            'min_data' => $minDate,
            'max_data' => $maxDate,
            'period_count' => $periodCount !== null ? (int)$periodCount : null,
            'period_sum' => $periodVal ? (float)$periodVal : null,
            'cols_used' => "date=$dateCol, val=$valCol"
        ];
    } catch (Exception $e) {
        $report['tables'][$tableName] = ['status' => 'error', 'msg' => $e->getMessage()];
    }
}

// 4. Bronze Omie May Diagnostic
$bronzeTable = isset($report['tables']['biomie_omie_bronze']) && $report['tables']['biomie_omie_bronze']['status'] === 'ok' 
    ? 'biomie_omie_bronze' : null;

if ($bronzeTable) {
    try {
        $dateCol = 'data_sincronizacao';
        $columns = $pdo->query("DESCRIBE $bronzeTable")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array($dateCol, $columns)) {
            $dateCol = 'id'; // fallback
        }
        
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM $bronzeTable WHERE payload LIKE '%2026-05%' OR (endpoint LIKE '%nf%' AND $dateCol >= '2026-05-01')");
        $stmt->execute();
        $report['bronze_diagnostic']['bronze_payloads_2026_05'] = (int)$stmt->fetchColumn();

        $report['bronze_diagnostic']['bronze_latest_fetched_at'] = $pdo->query("SELECT MAX($dateCol) FROM $bronzeTable")->fetchColumn();
        
        $stmt = $pdo->query("SELECT endpoint, COUNT(*) as c FROM $bronzeTable GROUP BY endpoint");
        $report['bronze_diagnostic']['bronze_operations_sample'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $stmt = $pdo->query("SELECT COUNT(*) FROM $bronzeTable WHERE endpoint LIKE '%nf%' AND payload LIKE '%2026-05%'");
        $report['bronze_diagnostic']['bronze_may_nfs_detected'] = (int)$stmt->fetchColumn();
        
        $stmt = $pdo->query("SELECT COUNT(*) FROM $bronzeTable WHERE endpoint LIKE '%pedido%' AND payload LIKE '%2026-05%'");
        $report['bronze_diagnostic']['bronze_may_orders_detected'] = (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        $report['bronze_diagnostic']['error'] = $e->getMessage();
    }
}

// 5. Canonical Fiscal May Diagnostic
$canonicalNfs = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS nfs, COALESCE(SUM(valor_total),0) AS receita, MIN(data_emissao) AS min_data, MAX(data_emissao) AS max_data FROM biomie_notas_fiscais_canonicas WHERE data_emissao BETWEEN :s AND :e");
    $stmt->execute(['s' => $start, 'e' => $end]);
    $canonical = $stmt->fetch();
    $canonicalNfs = (int)$canonical['nfs'];
    $report['canonical_diagnostic'] = [
        'canonical_nfs_2026_05' => $canonicalNfs,
        'canonical_revenue_2026_05' => (float)$canonical['receita'],
        'canonical_min_date' => $canonical['min_data'],
        'canonical_max_date' => $canonical['max_data'],
        'date_column_used' => 'data_emissao'
    ];
} catch (Exception $e) {
    $report['canonical_diagnostic'] = ['error' => $e->getMessage()];
}

// 6. Mart Geo Diagnostic
$martGeoRows = 0;
try {
    $startMonth = substr($start, 0, 7);
    $endMonth = substr($end, 0, 7);
    $stmt = $pdo->prepare("SELECT COUNT(*) AS qtd_rows, COALESCE(SUM(faturamento),0) AS revenue, COUNT(DISTINCT bairro) AS hoods, MIN(competencia) AS min_d, MAX(competencia) AS max_d FROM mart_compradores_por_geo WHERE competencia BETWEEN :s AND :e");
    $stmt->execute(['s' => $startMonth, 'e' => $endMonth]);
    $geo = $stmt->fetch();
    $martGeoRows = (int)$geo['qtd_rows'];
    $report['geo_mart_diagnostic'] = [
        'mart_geo_rows_2026_05' => $martGeoRows,
        'mart_geo_revenue_2026_05' => (float)$geo['revenue'],
        'mart_geo_neighborhoods_2026_05' => (int)$geo['hoods'],
        'mart_geo_min_date' => $geo['min_d'],
        'mart_geo_max_date' => $geo['max_d'],
    ];
} catch (Exception $e) {
    $report['geo_mart_diagnostic'] = ['error' => $e->getMessage()];
}

if ($isJson) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} else {
    print_r($report);
}
