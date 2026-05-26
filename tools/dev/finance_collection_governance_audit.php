<?php

/**
 * tools/dev/finance_collection_governance_audit.php
 *
 * CLI Auditor for FIN.R3.3.
 * Generates summaries, productivity reports, follow-ups, and risk queues.
 * Supports terminal, JSON, and CSV exports.
 *
 * Usage:
 *   php tools/dev/finance_collection_governance_audit.php [--start=YYYY-MM-DD] [--end=YYYY-MM-DD] [--pretty] [--json] [--csv] [--vendor=...] [--actor=...] [--critical-only]
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Config;
use BIomie\Database;
use BIomie\Support\Filters\Presets\FinanceCollectionFilters;

$opts = getopt('', ['start:', 'end:', 'pretty', 'json', 'csv', 'vendor:', 'actor:', 'critical-only']);
$start = $opts['start'] ?? date('Y-m-d', strtotime('-30 days'));
$end = $opts['end'] ?? date('Y-m-d');
$pretty = isset($opts['pretty']);
$jsonMode = isset($opts['json']);
$csvMode = isset($opts['csv']);
$vendorFilter = $opts['vendor'] ?? '';
$actorFilter = $opts['actor'] ?? '';
$criticalOnly = isset($opts['critical-only']);

$today = date('Y-m-d');

// ── Connect DB ───────────────────────────────────────────────────────────
$config = new Config();
$conn = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo = Database::connect($config, $conn);

if (!\BIomie\Http::tableExists($pdo, 'biomie_titulos_financeiros_canonicos')) {
    echo "Erro: Tabela biomie_titulos_financeiros_canonicos nao existe.\n";
    exit(1);
}

// ── 1. Fetch Open Titles in Date Range ───────────────────────────────────
$titlesSql = "
    SELECT 
        titulo_key,
        numero_documento,
        nome_cliente,
        documento_cliente,
        data_vencimento,
        valor_aberto,
        valor_liquido,
        codigo_vendedor_omie,
        nome_vendedor,
        status_titulo,
        documento_origem_ref,
        codigo_pedido_origem,
        pedido_id_omie,
        data_emissao
    FROM biomie_titulos_financeiros_canonicos
    WHERE data_vencimento BETWEEN :start AND :end
      AND status_titulo NOT IN ('liquidado','recebido','pago') 
      AND COALESCE(valor_aberto, 0) > 0
";

$titlesStmt = $pdo->prepare($titlesSql);
$titlesStmt->execute(['start' => $start, 'end' => $end]);
$allOpenTitles = $titlesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// ── 2. Fetch Logs of type finance.collection_action ──────────────────────
$logStart = date('Y-m-d 00:00:00', strtotime($start . ' -180 days'));
$logEnd = $end . ' 23:59:59';

$logsSql = "
    SELECT l.id, l.actor_user_id, l.event_type, l.details_json, l.created_at, u.username, u.full_name
    FROM biomie_admin_logs l
    LEFT JOIN biomie_users u ON u.id = l.actor_user_id
    WHERE l.event_type = 'finance.collection_action'
      AND l.created_at BETWEEN :log_start AND :log_end
    ORDER BY l.id ASC
    LIMIT 10000
";

$logsStmt = $pdo->prepare($logsSql);
$logsStmt->execute(['log_start' => $logStart, 'log_end' => $logEnd]);
$logs = $logsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// ── 3. Process Actions in Memory ─────────────────────────────────────────
$latestActionByTitle = [];
$allActionsByTitle = [];
$actionsInPeriod = [];

$startTs = strtotime($start . ' 00:00:00');
$endTs = strtotime($end . ' 23:59:59');

foreach ($logs as $log) {
    $details = json_decode((string) ($log['details_json'] ?? '{}'), true) ?: [];
    $tkey = (string) ($details['titulo_key'] ?? '');
    if ($tkey === '') {
        continue;
    }
    
    $action = [
        'id' => (int) $log['id'],
        'actor_user_id' => $log['actor_user_id'] !== null ? (int) $log['actor_user_id'] : null,
        'actor_username' => (string) ($log['username'] ?? 'Sistema'),
        'actor_full_name' => (string) ($log['full_name'] ?? 'Sistema'),
        'created_at' => $log['created_at'],
        'action_type' => (string) ($details['action_type'] ?? ''),
        'note' => (string) ($details['note'] ?? ''),
        'next_action_date' => (string) ($details['next_action_date'] ?? ''),
        'vendedor_codigo_omie' => (string) ($details['vendedor_codigo_omie'] ?? ''),
        'cliente_codigo_omie' => (string) ($details['cliente_codigo_omie'] ?? ''),
    ];
    
    $latestActionByTitle[$tkey] = $action;
    $allActionsByTitle[$tkey][] = $action;
    
    $createdTs = strtotime($log['created_at']);
    if ($createdTs >= $startTs && $createdTs <= $endTs) {
        $actionsInPeriod[] = $action;
    }
}

// ── 4. Calculate Risk Queue and Prioritization ───────────────────────────
$riskQueue = [];

foreach ($allOpenTitles as $title) {
    $tkey = $title['titulo_key'];
    $lastAct = $latestActionByTitle[$tkey] ?? null;
    
    $isNoVendor = false;
    $vCode = trim((string)($title['codigo_vendedor_omie'] ?? ''));
    $vName = trim((string)($title['nome_vendedor'] ?? ''));
    if ($vCode === '' && ($vName === '' || in_array(strtolower($vName), ['sem vendedor', 'sem vendedor definido', 'nao informado', 'nao identificado'], true))) {
        $isNoVendor = true;
    }
    
    $vKey = $isNoVendor ? '__sem_vendedor__' : ($vCode !== '' ? trim($vCode) : trim($vName));
    $vDispName = $isNoVendor ? FinanceCollectionFilters::VENDOR_LABEL_MISSING : ($vName !== '' ? $vName : 'Vendedor ' . $vCode);
    
    $valAberto = (float) $title['valor_aberto'];
    $venc = $title['data_vencimento'];
    $diasAtraso = $venc < $today ? (int) round((strtotime($today) - strtotime($venc)) / 86400) : 0;
    
    $level = 3;
    $label = 'Rotina';
    $motivo = 'Acompanhamento de rotina';
    $rec = 'Acompanhar vencimento';
    
    $lastActType = $lastAct ? $lastAct['action_type'] : '';
    $nextDate = $lastAct ? $lastAct['next_action_date'] : '';
    $daysSinceLastAct = $lastAct ? (int) round((strtotime($today) - strtotime(substr($lastAct['created_at'], 0, 10))) / 86400) : 999;
    
    if ($lastActType === 'promessa_pagamento' && $nextDate !== '' && $nextDate < $today) {
        $level = 1;
        $label = 'Crítico';
        $motivo = 'Promessa de pagamento vencida/quebrada';
        $rec = 'Cobrar hoje (follow-up promessa)';
    } elseif ($nextDate !== '' && $nextDate < $today && $diasAtraso > 0) {
        $level = 1;
        $label = 'Crítico';
        $motivo = 'Retorno agendado vencido com título atrasado';
        $rec = 'Cobrar hoje (reagendar contato)';
    } elseif ($diasAtraso > 0 && $lastAct === null) {
        $level = 1;
        $label = 'Crítico';
        $motivo = 'Título vencido sem histórico de contato';
        $rec = 'Cobrar hoje (primeiro contato)';
    } elseif ($diasAtraso > 30 && $valAberto >= 5000.0) {
        $level = 1;
        $label = 'Crítico';
        $motivo = 'Título de alto valor vencido há mais de 30 dias';
        $rec = 'Cobrar hoje (notificação formal)';
    } elseif ($isNoVendor && $valAberto >= 2000.0) {
        $level = 1;
        $label = 'Crítico';
        $motivo = 'Título de alto valor sem vendedor atribuído';
        $rec = 'Revisar vínculo financeiro';
    } // -- Priority 2 --
    elseif ($nextDate === $today) {
        $level = 2;
        $label = 'Atenção';
        $motivo = 'Retorno agendado para hoje';
        $rec = $lastActType === 'promessa_pagamento' ? 'Acompanhar promessa de pagamento' : 'Cobrar hoje (retorno agendado)';
    } elseif ($diasAtraso > 0 && $daysSinceLastAct > 15) {
        $level = 2;
        $label = 'Atenção';
        $motivo = 'Título vencido sem novos contatos há mais de 15 dias';
        $rec = 'Cobrar hoje (reativar contato)';
    } elseif ($lastActType === 'promessa_pagamento' && $nextDate === $today) {
        $level = 2;
        $label = 'Atenção';
        $motivo = 'Promessa de pagamento vencendo hoje';
        $rec = 'Acompanhar recebimento/cobrar hoje';
    } elseif (in_array(strtolower($title['status_titulo']), ['liquidado', 'recebido', 'pago'], true) && $valAberto > 0) {
        $level = 2;
        $label = 'Atenção';
        $motivo = 'Título quitado no Omie mas com saldo aberto local';
        $rec = 'Validar baixa no caixa';
    }
    
    $riskQueue[] = [
        'prioridade_nivel' => $level,
        'prioridade_label' => $label,
        'prioridade_motivo' => $motivo,
        'acao_recomendada' => $rec,
        'titulo_key' => $tkey,
        'numero_documento' => (string) $title['numero_documento'],
        'nome_cliente' => (string) $title['nome_cliente'],
        'documento_cliente' => (string) $title['documento_cliente'],
        'data_vencimento' => $venc,
        'valor_aberto' => $valAberto,
        'valor_titulo' => (float) $title['valor_liquido'],
        'codigo_vendedor_omie' => $vCode,
        'nome_vendedor' => $vDispName,
        'vendedor_key' => $vKey,
        'dias_atraso' => $diasAtraso,
        'status_titulo' => (string) $title['status_titulo'],
        'documento_origem_ref' => (string) $title['documento_origem_ref'],
        'codigo_pedido_origem' => (string) $title['codigo_pedido_origem'],
        'pedido_id_omie' => (string) $title['pedido_id_omie'],
        'data_emissao' => (string) $title['data_emissao'],
        'ultima_acao' => $lastAct,
    ];
}

// ── 5. Filtering the Results ─────────────────────────────────────────────
$filteredRiskQueue = [];
foreach ($riskQueue as $item) {
    $lastAct = $item['ultima_acao'];
    $lastActType = $lastAct ? $lastAct['action_type'] : '';
    $nextDate = $lastAct ? $lastAct['next_action_date'] : '';
    
    if ($vendorFilter !== '' && $item['vendedor_key'] !== $vendorFilter && $item['codigo_vendedor_omie'] !== $vendorFilter) {
        continue;
    }
    if ($actorFilter !== '' && (!$lastAct || (string)$lastAct['actor_user_id'] !== $actorFilter)) {
        continue;
    }
    if ($criticalOnly && $item['prioridade_nivel'] !== 1) {
        continue;
    }
    
    $filteredRiskQueue[] = $item;
}

// ── 6. Aggregate Summary metrics ─────────────────────────────────────────
$titulosAbertos = count($filteredRiskQueue);
$valorAbertos = 0.0;
$titulosVencidos = 0;
$valorVencidos = 0.0;

$titulosComAcaoPeriodoKeys = [];
$titulosSemAcao = 0;
$titulosSemAcaoVencidos = 0;

$cobradosHoje = 0;
$retornosHoje = 0;
$retornosAtrasados = 0;
$promessasAtivas = 0;
$promessasVencidas = 0;
$revisoesAbertas = 0;
$semVendedor = 0;
$valorSemVendedor = 0.0;

$actionMixCounts = [];

foreach ($filteredRiskQueue as $item) {
    $valAberto = $item['valor_aberto'];
    $valorAbertos += $valAberto;
    $venc = $item['data_vencimento'];
    $isVencido = $venc < $today;
    
    if ($isVencido) {
        $titulosVencidos++;
        $valorVencidos += $valAberto;
    }
    
    $lastAct = $item['ultima_acao'];
    $lastActType = $lastAct ? $lastAct['action_type'] : '';
    $nextDate = $lastAct ? $lastAct['next_action_date'] : '';
    
    if ($lastAct === null) {
        $titulosSemAcao++;
        if ($isVencido) {
            $titulosSemAcaoVencidos++;
        }
    } else {
        $actionMixCounts[$lastActType] = ($actionMixCounts[$lastActType] ?? 0) + 1;
        
        $createdDay = substr($lastAct['created_at'], 0, 10);
        if ($createdDay === $today) {
            $cobradosHoje++;
        }
        
        if ($nextDate !== '') {
            if ($nextDate === $today) {
                $retornosHoje++;
            } elseif ($nextDate < $today) {
                $retornosAtrasados++;
            }
        }
        
        if ($lastActType === 'promessa_pagamento') {
            if ($nextDate >= $today) {
                $promessasAtivas++;
            } else {
                $promessasVencidas++;
            }
        }
        
        if ($lastActType === 'revisar_vinculo_financeiro') {
            $revisoesAbertas++;
        }
    }
    
    if ($item['vendedor_key'] === '__sem_vendedor__') {
        $semVendedor++;
        $valorSemVendedor += $valAberto;
    }
    
    if (isset($allActionsByTitle[$item['titulo_key']])) {
        foreach ($allActionsByTitle[$item['titulo_key']] as $act) {
            $actDay = substr($act['created_at'], 0, 10);
            if ($actDay >= $start && $actDay <= $end) {
                $titulosComAcaoPeriodoKeys[$item['titulo_key']] = true;
                break;
            }
        }
    }
}

// ── 7. Calculate Productivity (Only within start/end period) ────────────
$prodGroup = [];
foreach ($actionsInPeriod as $act) {
    $actorId = (int) $act['actor_user_id'];
    $actorName = $act['actor_full_name'] !== '' ? $act['actor_full_name'] : $act['actor_username'];
    $type = $act['action_type'];
    
    if ($vendorFilter !== '' && $act['vendedor_codigo_omie'] !== $vendorFilter) {
        continue;
    }
    
    if (!isset($prodGroup[$actorId])) {
        $prodGroup[$actorId] = [
            'actor_user_id' => $actorId,
            'actor_name' => $actorName,
            'total_acoes' => 0,
            'titulos_unicos_map' => [],
            'clientes_unicos_map' => [],
            'cobrado_hoje' => 0,
            'whatsapp_enviado' => 0,
            'ligacao_realizada' => 0,
            'email_enviado' => 0,
            'aguardando_retorno' => 0,
            'promessa_pagamento' => 0,
            'validar_baixa_caixa' => 0,
            'revisar_vinculo_financeiro' => 0,
            'primeira_acao_at' => $act['created_at'],
            'ultima_acao_at' => $act['created_at'],
        ];
    }
    
    $p = &$prodGroup[$actorId];
    $p['total_acoes']++;
    $p['titulos_unicos_map'][$act['titulo_key']] = true;
    $p['clientes_unicos_map'][$act['cliente_codigo_omie']] = true;
    
    if (isset($p[$type])) {
        $p[$type]++;
    }
    
    if ($act['created_at'] < $p['primeira_acao_at']) {
        $p['primeira_acao_at'] = $act['created_at'];
    }
    if ($act['created_at'] > $p['ultima_acao_at']) {
        $p['ultima_acao_at'] = $act['created_at'];
    }
}

$productivity = [];
foreach ($prodGroup as $p) {
    $p['titulos_unicos'] = count($p['titulos_unicos_map']);
    $p['clientes_unicos'] = count($p['clientes_unicos_map']);
    unset($p['titulos_unicos_map'], $p['clientes_unicos_map']);
    $productivity[] = $p;
}

// ── 8. Calculate Vendor Governance ───────────────────────────────────────
$vendorGroup = [];
foreach ($filteredRiskQueue as $item) {
    $vKey = $item['vendedor_key'];
    $vName = $item['nome_vendedor'];
    $valAberto = $item['valor_aberto'];
    $venc = $item['data_vencimento'];
    $isVencido = $venc < $today;
    
    if (!isset($vendorGroup[$vKey])) {
        $vendorGroup[$vKey] = [
            'vendedor_codigo_omie' => $item['codigo_vendedor_omie'],
            'nome_vendedor' => $vName,
            'titulos_abertos' => 0,
            'valor_aberto' => 0.0,
            'titulos_vencidos' => 0,
            'valor_vencido' => 0.0,
            'titulos_com_acao' => 0,
            'titulos_sem_acao' => 0,
            'promessas_ativas' => 0,
            'promessas_vencidas' => 0,
            'retornos_hoje' => 0,
            'retornos_atrasados' => 0,
            'maior_atraso' => 0,
        ];
    }
    
    $v = &$vendorGroup[$vKey];
    $v['titulos_abertos']++;
    $v['valor_aberto'] += $valAberto;
    
    if ($isVencido) {
        $v['titulos_vencidos']++;
        $v['valor_vencido'] += $valAberto;
        $overdueDays = (int) round((strtotime($today) - strtotime($venc)) / 86400);
        if ($overdueDays > $v['maior_atraso']) {
            $v['maior_atraso'] = $overdueDays;
        }
    }
    
    $lastAct = $item['ultima_acao'];
    if ($lastAct === null) {
        $v['titulos_sem_acao']++;
    } else {
        $v['titulos_com_acao']++;
        $lastActType = $lastAct['action_type'];
        $nextDate = $lastAct['next_action_date'];
        
        if ($lastActType === 'promessa_pagamento') {
            if ($nextDate >= $today) {
                $v['promessas_ativas']++;
            } else {
                $v['promessas_vencidas']++;
            }
        }
        
        if ($nextDate !== '') {
            if ($nextDate === $today) {
                $v['retornos_hoje']++;
            } elseif ($nextDate < $today) {
                $v['retornos_atrasados']++;
            }
        }
    }
}

$vendorGovernance = [];
foreach ($vendorGroup as $vKey => $v) {
    $tot = $v['titulos_abertos'];
    $v['percentual_carteira_acionada'] = $tot > 0 ? round(($v['titulos_com_acao'] / $tot) * 100, 2) : 0.0;
    
    if ($vKey === '__sem_vendedor__') {
        $v['status_governanca'] = 'sem_vendedor';
    } elseif ($v['promessas_vencidas'] > 0 || $v['retornos_atrasados'] > 0 || ($v['titulos_vencidos'] > 0 && $v['titulos_sem_acao'] === $v['titulos_abertos'])) {
        $v['status_governanca'] = 'critico';
    } elseif ($v['percentual_carteira_acionada'] < 50.0 || $v['retornos_hoje'] > 0) {
        $v['status_governanca'] = 'atencao';
    } else {
        $v['status_governanca'] = 'ok';
    }
    
    $vendorGovernance[] = $v;
}

// ── 9. Calculate Action Mix ──────────────────────────────────────────────
$actionMix = [];
$totalActioned = array_sum($actionMixCounts);
foreach ($actionMixCounts as $type => $count) {
    $actionMix[] = [
        'action_type' => $type,
        'qtd' => $count,
        'percentual' => $totalActioned > 0 ? round(($count / $totalActioned) * 100, 2) : 0.0,
    ];
}

// ── 10. Calculate Follow-ups ─────────────────────────────────────────────
$fList = [
    'vencidos' => [],
    'hoje' => [],
    'proximos' => [],
];

foreach ($filteredRiskQueue as $item) {
    $lastAct = $item['ultima_acao'];
    if ($lastAct === null || $lastAct['next_action_date'] === '') {
        continue;
    }
    
    $nextDate = $lastAct['next_action_date'];
    $daysDiff = (int) round((strtotime($nextDate) - strtotime($today)) / 86400);
    
    $followRow = [
        'titulo_key' => $item['titulo_key'],
        'numero_documento' => $item['numero_documento'],
        'cliente' => $item['nome_cliente'],
        'vendedor' => $item['nome_vendedor'],
        'action_type' => $lastAct['action_type'],
        'note' => mb_strlen($lastAct['note']) > 150 ? mb_substr($lastAct['note'], 0, 147) . '...' : $lastAct['note'],
        'next_action_date' => $nextDate,
        'dias_para_retorno' => $daysDiff,
        'actor' => $lastAct['actor_username'],
        'created_at' => $lastAct['created_at'],
        'valor_aberto' => $item['valor_aberto'],
        'vencimento' => $item['data_vencimento'],
        'dias_atraso' => $item['dias_atraso'],
    ];
    
    if ($nextDate < $today) {
        $fList['vencidos'][] = $followRow;
    } elseif ($nextDate === $today) {
        $fList['hoje'][] = $followRow;
    } else {
        $fList['proximos'][] = $followRow;
    }
}

usort($fList['vencidos'], static fn($a, $b) => strcmp($a['next_action_date'], $b['next_action_date']));
usort($fList['hoje'], static fn($a, $b) => strcmp($a['titulo_key'], $b['titulo_key']));
usort($fList['proximos'], static fn($a, $b) => strcmp($a['next_action_date'], $b['next_action_date']));

// ── 11. Sorting and Formatting Output ────────────────────────────────────
usort($filteredRiskQueue, static function($a, $b) {
    if ($a['prioridade_nivel'] === $b['prioridade_nivel']) {
        return $b['valor_aberto'] <=> $a['valor_aberto'];
    }
    return $a['prioridade_nivel'] <=> $b['prioridade_nivel'];
});

// CSV Mode
if ($csvMode) {
    $exportDir = dirname(__DIR__, 2) . '/storage/exports';
    if (!is_dir($exportDir)) {
        @mkdir($exportDir, 0755, true);
    }
    
    $filename = 'finance_collection_governance_audit_' . date('Ymd_His') . '.csv';
    $filepath = $exportDir . '/' . $filename;
    
    $fp = fopen($filepath, 'w');
    if (!$fp) {
        echo "Erro ao criar arquivo CSV.\n";
        exit(1);
    }
    
    fprintf($fp, chr(0xEF).chr(0xBB).chr(0xBF));
    
    fputcsv($fp, [
        'Prioridade Nivel', 'Prioridade Label', 'Prioridade Motivo', 'Acao Recomendada',
        'Titulo Key', 'Documento', 'Cliente', 'Vendedor', 'Vencimento', 'Valor Aberto',
        'Valor Titulo', 'Dias Atraso', 'Ultima Acao', 'Proxima Acao Data'
    ]);
    
    foreach ($filteredRiskQueue as $item) {
        $lastAct = $item['ultima_acao'] ?? null;
        fputcsv($fp, [
            $item['prioridade_nivel'], $item['prioridade_label'], $item['prioridade_motivo'], $item['acao_recomendada'],
            $item['titulo_key'], $item['numero_documento'], $item['nome_cliente'], $item['nome_vendedor'],
            $item['data_vencimento'], $item['valor_aberto'], $item['valor_titulo'], $item['dias_atraso'],
            $lastAct ? $lastAct['action_type'] : '', $lastAct ? $lastAct['next_action_date'] : ''
        ]);
    }
    fclose($fp);
    echo "Audit CSV successfully exported to: $filepath\n";
    exit(0);
}

// JSON Mode
if ($jsonMode) {
    echo json_encode([
        'ok' => true,
        'summary' => [
            'titulos_abertos' => $titulosAbertos,
            'valor_aberto' => round($valorAbertos, 2),
            'titulos_vencidos' => $titulosVencidos,
            'valor_vencido' => round($valorVencidos, 2),
            'titulos_com_acao_periodo' => count($titulosComAcaoPeriodoKeys),
            'titulos_sem_acao' => $titulosSemAcao,
            'titulos_sem_acao_vencidos' => $titulosSemAcaoVencidos,
            'cobrados_hoje' => $cobradosHoje,
            'retornos_hoje' => $retornosHoje,
            'retornos_atrasados' => $retornosAtrasados,
            'promessas_pagamento_ativas' => $promessasAtivas,
            'promessas_pagamento_vencidas' => $promessasVencidas,
            'revisoes_vinculo_abertas' => $revisoesAbertas,
            'sem_vendedor_definido' => $semVendedor,
            'valor_sem_vendedor' => round($valorSemVendedor, 2),
        ],
        'productivity' => $productivity,
        'followups' => $fList,
        'risk_queue' => $filteredRiskQueue,
        'vendor_governance' => $vendorGovernance,
        'action_mix' => $actionMix
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

// Pretty print CLI formatting
$cGreen = "\033[32m";
$cYellow = "\033[33m";
$cRed = "\033[31m";
$cReset = "\033[0m";

$status = 'OK';
$warnings = [];

if ($promessasVencidas > 0) {
    $status = 'WARNING';
    $warnings[] = "Existem {$promessasVencidas} promessa(s) de pagamento vencida(s)/quebrada(s).";
}
if ($retornosAtrasados > 0) {
    $status = 'WARNING';
    $warnings[] = "Existem {$retornosAtrasados} retorno(s) agendado(s) atrasado(s).";
}
if ($semVendedor > 0) {
    $status = 'WARNING';
    $warnings[] = "Existem {$semVendedor} título(s) sem vendedor definido (Total: R$ " . number_format($valorSemVendedor, 2, ',', '.') . ").";
}

$statusColor = $status === 'OK' ? $cGreen : $cYellow;

echo "\n";
echo "========================================================================\n";
echo "          BIOMIE FINANCE COLLECTION GOVERNANCE REPORT\n";
echo "========================================================================\n";
echo "Período analisado: {$start} até {$end}\n";
echo "Status Geral: {$statusColor}{$status}{$cReset}\n";
if (!empty($warnings)) {
    echo "Avisos Operacionais:\n";
    foreach ($warnings as $w) {
        echo "  - {$cYellow}{$w}{$cReset}\n";
    }
}
echo "------------------------------------------------------------------------\n\n";

echo "RESUMO EXECUTIVO DE COBRANÇA:\n";
echo "  Títulos em aberto:                  {$titulosAbertos} (Total: R$ " . number_format($valorAbertos, 2, ',', '.') . ")\n";
echo "  Títulos vencidos:                   {$titulosVencidos} (Total: R$ " . number_format($valorVencidos, 2, ',', '.') . ")\n";
echo "  Títulos sem ação:                   {$titulosSemAcao} ({$titulosSemAcaoVencidos} vencidos)\n";
echo "  Acionados no período:               " . count($titulosComAcaoPeriodoKeys) . "\n";
echo "  Contatos realizados hoje:           {$cobradosHoje}\n";
echo "  Promessas ativas/vencidas:          {$promessasAtivas} / {$promessasVencidas}\n";
echo "  Retornos agendados hoje/atrasados:  {$retornosHoje} / {$retornosAtrasados}\n";
echo "\n";

echo "PRODUTIVIDADE DOS OPERADORES:\n";
if (empty($productivity)) {
    echo "  Nenhuma produtividade registrada no período.\n";
} else {
    foreach ($productivity as $p) {
        echo "  - Operador: {$p['actor_name']}\n";
        echo "    Total ações:  {$p['total_acoes']} | Títulos únicos: {$p['titulos_unicos']} | Clientes: {$p['clientes_unicos']}\n";
        echo "    WhatsApp:     {$p['whatsapp_enviado']} | Ligações: {$p['ligacao_realizada']} | Email: {$p['email_enviado']}\n";
        echo "    Promessas:    {$p['promessa_pagamento']} | Retornos: {$p['aguardando_retorno']}\n";
    }
}
echo "\n";

echo "GOVERNANÇA DE CARTEIRA POR VENDEDOR:\n";
foreach ($vendorGovernance as $vg) {
    $pct = number_format((float) ($vg['percentual_carteira_acionada'] ?? 0), 1) . '%';
    $vgStatusColor = $vg['status_governanca'] === 'critico' ? $cRed : ($vg['status_governanca'] === 'atencao' ? $cYellow : $cGreen);
    echo "  - " . str_pad($vg['nome_vendedor'], 30, ' ') . " | Aberto: R$ " . str_pad(number_format((float)$vg['valor_aberto'], 2, ',', '.'), 14, ' ', STR_PAD_LEFT) . " | Acionado: " . str_pad($pct, 6, ' ', STR_PAD_LEFT) . " | Status: [{$vgStatusColor}" . strtoupper($vg['status_governanca']) . "{$cReset}]\n";
}
echo "\n";

echo "AGENDA DE RETORNOS (FOLLOW-UPS):\n";
echo "  - Retornos Atrasados:  " . count($fList['vencidos']) . "\n";
echo "  - Retornos para Hoje:  " . count($fList['hoje']) . "\n";
echo "  - Próximos Retornos:   " . count($fList['proximos']) . "\n";
echo "\n";

echo "FILA DE RISCO OPERACIONAL (TOP 10 CRÍTICOS):\n";
$riskCount = 0;
foreach ($filteredRiskQueue as $r) {
    if ($riskCount >= 10) break;
    $riskCount++;
    
    $levelColor = $r['prioridade_nivel'] === 1 ? $cRed : ($r['prioridade_nivel'] === 2 ? $cYellow : $cGreen);
    echo "  " . str_pad("#$riskCount", 4, ' ') . " [{$levelColor}" . $r['prioridade_label'] . "{$cReset}] - " . $r['prioridade_motivo'] . "\n";
    echo "       Título: {$r['numero_documento']} ({$r['titulo_key']}) | R$ " . number_format((float)$r['valor_aberto'], 2, ',', '.') . " | Atraso: {$r['dias_atraso']} dias\n";
    echo "       Cliente: {$r['nome_cliente']} | Vendedor: {$r['nome_vendedor']}\n";
    echo "       Ação Recomendada: {$cGreen}" . $r['acao_recomendada'] . "{$cReset}\n\n";
}

echo "DISTRIBUIÇÃO DE CONTATOS (ACTION MIX):\n";
if (empty($actionMix)) {
    echo "  Nenhuma ação no histórico.\n";
} else {
    foreach ($actionMix as $am) {
        echo "  - " . str_pad($am['action_type'], 30, ' ') . ": {$am['qtd']} (" . $am['percentual'] . "%)\n";
    }
}
echo "\n";

$recs = [];
if ($promessasVencidas > 0) {
    $recs[] = "Ligar prioritariamente para os clientes com promessas de pagamento vencidas.";
}
if ($retornosAtrasados > 0) {
    $recs[] = "Reagendar ou executar os retornos de cobrança que estão em atraso.";
}
if ($semVendedor > 0) {
    $recs[] = "Atribuir vendedores aos títulos sem vendedor definido para iniciar fluxo de contato.";
}
if ($titulosSemAcaoVencidos > 0) {
    $recs[] = "Iniciar primeiro contato de cobrança para os {$titulosSemAcaoVencidos} títulos vencidos sem ação.";
}

if (!empty($recs)) {
    echo "------------------------------------------------------------------------\n";
    echo "RECOMENDAÇÕES OPERACIONAIS:\n";
    foreach ($recs as $idx => $r) {
        $num = $idx + 1;
        echo "  $num. $r\n";
    }
    echo "\n";
}

echo "========================================================================\n\n";
exit(0);
