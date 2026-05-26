<?php

/**
 * tools/dev/finance_collection_actions_audit.php
 *
 * Read-only diagnostics tool for FASE 0.
 * Audits existing collection action logs in biomie_admin_logs.
 *
 * Usage:
 *   php tools/dev/finance_collection_actions_audit.php [--start=YYYY-MM-DD] [--end=YYYY-MM-DD] [--pretty] [--json] [--limit=100]
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Config;
use BIomie\Database;

// Parse CLI options
$opts = getopt('', ['start:', 'end:', 'pretty', 'json', 'limit:']);
$start = $opts['start'] ?? null;
$end = $opts['end'] ?? null;
$pretty = isset($opts['pretty']);
$jsonMode = isset($opts['json']);
$limit = isset($opts['limit']) ? (int)$opts['limit'] : 100;

$today = date('Y-m-d');

$config = new Config();
$conn = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo = null;
$dbConnected = false;

try {
    if (Database::isConfigured($config, $conn)) {
        $pdo = Database::connect($config, $conn);
        $dbConnected = true;
    }
} catch (\Throwable $e) {
    // Handled below
}

if (!$dbConnected || !$pdo) {
    $out = [
        'status' => 'CRITICAL',
        'message' => 'Banco de dados inacessível.',
        'count' => 0,
        'recommendations' => ['Verifique a conexão de banco de dados em config/.env.local.php.']
    ];
    if ($jsonMode) {
        echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        echo "========================================================================\n";
        echo "          BIOMIE FINANCE COLLECTION ACTIONS AUDIT\n";
        echo "========================================================================\n";
        echo "Status: CRITICAL\n";
        echo "Mensagem: Banco de dados inacessível.\n";
        echo "========================================================================\n";
    }
    exit(1);
}

// 1. Check if logs table exists
$tableExists = (bool) $pdo->query("
    SELECT COUNT(*) FROM information_schema.TABLES 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biomie_admin_logs'
")->fetchColumn();

if (!$tableExists) {
    $out = [
        'status' => 'CRITICAL',
        'message' => 'Tabela biomie_admin_logs não existe.',
        'count' => 0,
        'recommendations' => ['Rode as migrations para criar a tabela biomie_admin_logs.']
    ];
    if ($jsonMode) {
        echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        echo "Status: CRITICAL\n";
        echo "Mensagem: Tabela biomie_admin_logs não existe.\n";
    }
    exit(1);
}

// Fetch all open titles to check "titles in open without actions"
$openTitlesSql = "
    SELECT titulo_key, numero_documento, valor_aberto, data_vencimento, nome_vendedor, codigo_vendedor_omie
    FROM biomie_titulos_financeiros_canonicos
    WHERE status_titulo NOT IN ('liquidado','recebido','pago') AND COALESCE(valor_aberto, 0) > 0
";
$openTitlesStmt = $pdo->query($openTitlesSql);
$openTitles = $openTitlesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$openTitleKeys = array_column($openTitles, 'titulo_key');
$openTitlesMap = [];
foreach ($openTitles as $ot) {
    $openTitlesMap[$ot['titulo_key']] = $ot;
}

// Fetch actions from biomie_admin_logs
$sql = "
    SELECT l.id, l.actor_user_id, l.details_json, l.created_at, u.username
    FROM biomie_admin_logs l
    LEFT JOIN biomie_users u ON u.id = l.actor_user_id
    WHERE l.event_type = 'finance.collection_action'
";

$params = [];
if ($start !== null) {
    $sql .= " AND l.created_at >= :start";
    $params['start'] = $start . ' 00:00:00';
}
if ($end !== null) {
    $sql .= " AND l.created_at <= :end";
    $params['end'] = $end . ' 23:59:59';
}

$sql .= " ORDER BY l.id DESC LIMIT :limit";

$stmt = $pdo->prepare($sql);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
foreach ($params as $k => $v) {
    $stmt->bindValue(':' . $k, $v);
}
$stmt->execute();
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Metrics counters
$totalActions = 0;
$byActionType = [];
$byActor = [];
$byVendor = [];
$uniqueTitles = [];
$uniqueClients = [];
$overdueNextActions = [];
$todayNextActions = [];
$overduePromises = [];
$invalidJson = 0;
$missingTitleKey = 0;
$missingActionType = 0;
$missingVendorOmie = 0;
$openReviewLinks = [];

// To find titles without action ever, we need all actions ever logged.
// Let's fetch all actions' details_json to check history for open titles
$allActionsStmt = $pdo->query("
    SELECT details_json 
    FROM biomie_admin_logs 
    WHERE event_type = 'finance.collection_action'
");
$allActions = $allActionsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$titlesWithActionEver = [];
foreach ($allActions as $a) {
    $det = json_decode((string)($a['details_json'] ?? '{}'), true);
    if (is_array($det) && isset($det['titulo_key'])) {
        $titlesWithActionEver[$det['titulo_key']] = true;
    }
}

$openTitlesNoAction = [];
foreach ($openTitles as $ot) {
    if (!isset($titlesWithActionEver[$ot['titulo_key']])) {
        $openTitlesNoAction[] = $ot;
    }
}

foreach ($logs as $log) {
    $totalActions++;
    $details = json_decode((string) ($log['details_json'] ?? ''), true);
    if (!is_array($details)) {
        $invalidJson++;
        continue;
    }

    $tkey = trim((string) ($details['titulo_key'] ?? ''));
    if ($tkey === '') {
        $missingTitleKey++;
    } else {
        $uniqueTitles[$tkey] = true;
    }

    $actionType = trim((string) ($details['action_type'] ?? ''));
    if ($actionType === '') {
        $missingActionType++;
    } else {
        $byActionType[$actionType] = ($byActionType[$actionType] ?? 0) + 1;
    }

    $client = trim((string) ($details['cliente_codigo_omie'] ?? ''));
    if ($client !== '') {
        $uniqueClients[$client] = true;
    }

    $vendor = trim((string) ($details['vendedor_codigo_omie'] ?? ''));
    if ($vendor === '' || $vendor === '0') {
        $missingVendorOmie++;
    } else {
        $byVendor[$vendor] = ($byVendor[$vendor] ?? 0) + 1;
    }

    $actor = (string) ($log['username'] ?? 'user_' . ($log['actor_user_id'] ?? 'unknown'));
    $byActor[$actor] = ($byActor[$actor] ?? 0) + 1;

    $nextActionDate = trim((string) ($details['next_action_date'] ?? ''));
    if ($nextActionDate !== '') {
        if ($nextActionDate < $today) {
            $overdueNextActions[] = [
                'id' => $log['id'],
                'titulo_key' => $tkey,
                'next_action_date' => $nextActionDate,
                'action_type' => $actionType,
            ];
            if ($actionType === 'promessa_pagamento') {
                $overduePromises[] = [
                    'id' => $log['id'],
                    'titulo_key' => $tkey,
                    'next_action_date' => $nextActionDate,
                ];
            }
        } elseif ($nextActionDate === $today) {
            $todayNextActions[] = [
                'id' => $log['id'],
                'titulo_key' => $tkey,
                'next_action_date' => $nextActionDate,
                'action_type' => $actionType,
            ];
        }
    }

    if ($actionType === 'revisar_vinculo_financeiro' && in_array($tkey, $openTitleKeys, true)) {
        // Find if this was the latest action for this open title
        // For simplicity in audit, just list it as open review action
        $openReviewLinks[$tkey] = [
            'titulo_key' => $tkey,
            'vendedor_codigo_omie' => $vendor,
            'created_at' => $log['created_at'],
        ];
    }
}

// Check for anomalies to determine status
$status = 'OK';
$warnings = [];
if ($invalidJson > 0) {
    $status = 'WARNING';
    $warnings[] = "Detectado(s) {$invalidJson} registro(s) com details_json inválido.";
}
if ($missingTitleKey > 0) {
    $status = 'WARNING';
    $warnings[] = "Detectado(s) {$missingTitleKey} registro(s) sem titulo_key.";
}
if ($missingActionType > 0) {
    $status = 'WARNING';
    $warnings[] = "Detectado(s) {$missingActionType} registro(s) sem action_type.";
}

$recommendations = [];
if ($status === 'WARNING') {
    $recommendations[] = "Investigar logs antigos ou inserções diretas no banco de dados para corrigir campos obrigatórios ausentes.";
}
if (count($overduePromises) > 0) {
    $recommendations[] = "Entrar em contato com clientes que quebraram promessas de pagamento (" . count($overduePromises) . " promessas vencidas).";
}
if (count($openReviewLinks) > 0) {
    $recommendations[] = "Revisar " . count($openReviewLinks) . " vínculos de títulos financeiros sem vendedor atribuído.";
}

$out = [
    'status' => $status,
    'total_actions_period' => $totalActions,
    'by_action_type' => $byActionType,
    'by_actor' => $byActor,
    'by_vendor' => $byVendor,
    'unique_titles_count' => count($uniqueTitles),
    'unique_clients_count' => count($uniqueClients),
    'overdue_next_actions_count' => count($overdueNextActions),
    'today_next_actions_count' => count($todayNextActions),
    'overdue_promises_count' => count($overduePromises),
    'invalid_json_count' => $invalidJson,
    'missing_title_key_count' => $missingTitleKey,
    'missing_action_type_count' => $missingActionType,
    'missing_vendor_omie_count' => $missingVendorOmie,
    'open_review_links_count' => count($openReviewLinks),
    'open_titles_without_action_count' => count($openTitlesNoAction),
    'warnings' => $warnings,
    'recommendations' => $recommendations,
    'limit_applied' => $limit,
    'note' => 'Details_json parse is performed in PHP memory which is optimized for reasonable log sizes (< 100k events). For high-scale, query using native JSON functions where supported.'
];

if ($jsonMode) {
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit($status === 'CRITICAL' ? 1 : 0);
}

// Pretty print CLI formatting
$cGreen = "\033[32m";
$cYellow = "\033[33m";
$cRed = "\033[31m";
$cReset = "\033[0m";

$statusColor = $status === 'OK' ? $cGreen : ($status === 'WARNING' ? $cYellow : $cRed);

echo "\n";
echo "========================================================================\n";
echo "          BIOMIE FINANCE COLLECTION ACTIONS AUDIT\n";
echo "========================================================================\n";
echo "Período analisado: " . ($start ?? 'Início') . " até " . ($end ?? 'Hoje') . "\n";
echo "Status Geral: {$statusColor}{$status}{$cReset}\n";
if (!empty($warnings)) {
    echo "Avisos:\n";
    foreach ($warnings as $w) {
        echo "  - {$cYellow}{$w}{$cReset}\n";
    }
}
echo "------------------------------------------------------------------------\n\n";

echo "MÉTRICAS DO PERÍODO (Limite aplicado: {$limit} registros):\n";
echo "  Total de ações coletadas:              {$totalActions}\n";
echo "  Títulos únicos acionados:               " . count($uniqueTitles) . "\n";
echo "  Clientes únicos acionados:              " . count($uniqueClients) . "\n";
echo "  Títulos em aberto sem nenhuma ação:     " . count($openTitlesNoAction) . "\n";
echo "  Ações de Revisar Vínculo abertas:       " . count($openReviewLinks) . "\n";
echo "  Próximas ações vencidas:                " . count($overdueNextActions) . "\n";
echo "  Próximas ações para hoje:               " . count($todayNextActions) . "\n";
echo "  Promessas de pagamento vencidas:        " . count($overduePromises) . "\n";
echo "\n";

echo "AÇÕES POR TIPO:\n";
foreach ($byActionType as $type => $count) {
    echo "  - " . str_pad($type, 30, ' ') . ": {$count}\n";
}
echo "\n";

echo "AÇÕES POR OPERADOR:\n";
foreach ($byActor as $act => $count) {
    echo "  - " . str_pad($act, 30, ' ') . ": {$count}\n";
}
echo "\n";

echo "AÇÕES POR VENDEDOR OMIE:\n";
foreach ($byVendor as $vnd => $count) {
    echo "  - Código " . str_pad($vnd, 23, ' ') . ": {$count}\n";
}
echo "\n";

echo "ANOMALIAS DE INTEGRIDADE LOG:\n";
echo "  - JSON inválido:                        {$invalidJson}\n";
echo "  - Sem titulo_key:                       {$missingTitleKey}\n";
echo "  - Sem action_type:                      {$missingActionType}\n";
echo "  - Sem vendedor_codigo_omie:             {$missingVendorOmie}\n";
echo "\n";

if (!empty($recommendations)) {
    echo "------------------------------------------------------------------------\n";
    echo "RECOMENDAÇÕES:\n";
    foreach ($recommendations as $idx => $rec) {
        $num = $idx + 1;
        echo "  {$num}. {$rec}\n";
    }
    echo "\n";
}

echo "========================================================================\n\n";

exit($status === 'CRITICAL' ? 1 : 0);
