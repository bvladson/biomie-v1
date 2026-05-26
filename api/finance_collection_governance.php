<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Database;
use BIomie\Http;
use BIomie\RequestContext;
use BIomie\Support\Filters\FilterNormalizer;
use BIomie\Support\Filters\Pagination;
use BIomie\Support\Filters\Presets\FinanceCollectionFilters;

require __DIR__ . '/bootstrap.php';

Auth::requireCapability('finance.view', true);

function fcgAgingBucket(?string $dueDate, string $today): string
{
    $dueDate = trim((string) $dueDate);
    if ($dueDate === '') {
        return 'sem_data';
    }
    if ($dueDate === $today) {
        return 'vence_hoje';
    }
    if ($dueDate > $today) {
        return 'a_vencer';
    }

    $diffDays = (int) round((strtotime($today) - strtotime($dueDate)) / 86400);
    if ($diffDays <= 7) {
        return '1_7';
    }
    if ($diffDays <= 15) {
        return '8_15';
    }
    if ($diffDays <= 30) {
        return '16_30';
    }
    if ($diffDays <= 60) {
        return '31_60';
    }
    return '60_plus';
}

try {
    $today = date('Y-m-d');
    
    // ── Parse Date Range ─────────────────────────────────────────────────────
    $range = Http::resolveDateRange();
    $start = $range['start'];
    $end = $range['end'];
    Http::validateDateRange($start, $end);
    
    // ── Parse Filters ────────────────────────────────────────────────────────
    $vendorFilter = FilterNormalizer::text((string) ($_GET['vendor'] ?? ''), 100);
    $actorFilter = FilterNormalizer::text((string) ($_GET['actor_user_id'] ?? ''), 20);
    $actionTypeFilter = FilterNormalizer::text((string) ($_GET['action_type'] ?? ''), 50);
    $statusOperacionalFilter = FilterNormalizer::text((string) ($_GET['status_operacional'] ?? ''), 40);
    $agingFilter = FilterNormalizer::text((string) ($_GET['aging'] ?? ''), 40);
    $qFilter = FilterNormalizer::text((string) ($_GET['q'] ?? ''), 100);
    
    $onlyOverdue = (int) ($_GET['only_overdue'] ?? 0) === 1;
    $onlyDueToday = (int) ($_GET['only_due_today'] ?? 0) === 1;
    $onlyBrokenPromises = (int) ($_GET['only_broken_promises'] ?? 0) === 1;
    $onlyNoAction = (int) ($_GET['only_no_action'] ?? 0) === 1;
    $onlyOpenLinkReview = (int) ($_GET['only_open_link_review'] ?? 0) === 1;
    $onlyCritical = (int) ($_GET['only_critical'] ?? 0) === 1; // "Ver somente críticos" (Priority 1)
    
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = min(200, max(1, (int) ($_GET['per_page'] ?? 50)));
    $sort = FilterNormalizer::text((string) ($_GET['sort'] ?? 'prioridade'), 40);
    $direction = FilterNormalizer::direction((string) ($_GET['direction'] ?? 'asc'));
    
    // ── Connect DB ───────────────────────────────────────────────────────────
    $config = new \BIomie\Config();
    $conn = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo = Database::connect($config, $conn);
    
    if (!Http::tableExists($pdo, 'biomie_titulos_financeiros_canonicos')) {
        Http::jsonConflictMissingTable('biomie_titulos_financeiros_canonicos');
    }

    $t0 = microtime(true);
    
    // ── 1. Fetch Open Titles in Date Range ───────────────────────────────────
    // Date range filters titles by data_vencimento
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
            codigo_cliente_omie,
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
    // To ensure a safe window, retrieve logs created in a window (e.g. from 180 days before start to end)
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
        
        // Track actions ever for each title
        $latestActionByTitle[$tkey] = $action;
        $allActionsByTitle[$tkey][] = $action;
        
        // Actions created within the period
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
        
        // Check vendor missing using project condition logic
        $isNoVendor = false;
        $vCode = trim((string)($title['codigo_vendedor_omie'] ?? ''));
        $vName = trim((string)($title['nome_vendedor'] ?? ''));
        if ($vCode === '' && ($vName === '' || in_array(strtolower($vName), ['sem vendedor', 'sem vendedor definido', 'nao informado', 'nao identificado'], true))) {
            $isNoVendor = true;
        }
        
        $vKey = $isNoVendor ? '__sem_vendedor__' : (trim((string) ($title['codigo_vendedor_omie'] ?? '')) !== '' ? trim((string) $title['codigo_vendedor_omie']) : trim((string) ($title['nome_vendedor'] ?? '')));
        $vDispName = $isNoVendor ? FinanceCollectionFilters::VENDOR_LABEL_MISSING : ($vName !== '' ? $vName : 'Vendedor ' . $vCode);
        
        $valAberto = (float) $title['valor_aberto'];
        $venc = $title['data_vencimento'];
        $diasAtraso = $venc < $today ? (int) round((strtotime($today) - strtotime($venc)) / 86400) : 0;
        
        // Prioritization logic (FASE 3)
        $level = 3;
        $label = 'Rotina';
        $motivo = 'Acompanhamento de rotina';
        $rec = 'Acompanhar vencimento';
        
        $lastActType = $lastAct ? $lastAct['action_type'] : '';
        $nextDate = $lastAct ? $lastAct['next_action_date'] : '';
        $lastActDate = $lastAct ? substr($lastAct['created_at'], 0, 10) : '';
        $daysSinceLastAct = $lastActDate !== '' ? (int) round((strtotime($today) - strtotime($lastActDate)) / 86400) : 999;
        $isPromiseBroken = $lastActType === 'promessa_pagamento' && $nextDate !== '' && $nextDate < $today;
        $isReturnLate = $nextDate !== '' && $nextDate < $today;
        $agingBucket = fcgAgingBucket($venc, $today);
        
        if ($isPromiseBroken) {
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
        } // -- Priority 2: Atenção --
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
        } // -- Priority 3: Rotina --
        elseif ($venc >= $today && $venc <= date('Y-m-d', strtotime('+7 days'))) {
            $level = 3;
            $label = 'Rotina';
            $motivo = 'Título vencendo nos próximos 7 dias';
            $rec = 'Acompanhar vencimento';
        } elseif ($lastActType === 'aguardando_retorno' && $nextDate >= $today) {
            $level = 3;
            $label = 'Rotina';
            $motivo = 'Aguardando retorno do cliente dentro do prazo';
            $rec = 'Acompanhar retorno';
        } elseif ($lastAct !== null && $daysSinceLastAct < 5) {
            $level = 3;
            $label = 'Rotina';
            $motivo = 'Cobrança recente realizada nos últimos 5 dias';
            $rec = 'Acompanhar vencimento / aguardar data';
        }

        $statusOperacional = $isNoVendor ? 'sem_vendedor' : ($level === 1 ? 'critico' : ($level === 2 ? 'atencao' : 'rotina'));
        
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
            'valor_vencido' => $diasAtraso > 0 ? $valAberto : 0.0,
            'valor_titulo' => (float) $title['valor_liquido'],
            'codigo_vendedor_omie' => $vCode,
            'codigo_cliente_omie' => (string) ($title['codigo_cliente_omie'] ?? ''),
            'nome_vendedor' => $vDispName,
            'vendedor_key' => $vKey,
            'dias_atraso' => $diasAtraso,
            'aging_bucket' => $agingBucket,
            'status_operacional' => $statusOperacional,
            'status_titulo' => (string) $title['status_titulo'],
            'documento_origem_ref' => (string) $title['documento_origem_ref'],
            'codigo_pedido_origem' => (string) $title['codigo_pedido_origem'],
            'pedido_id_omie' => (string) $title['pedido_id_omie'],
            'data_emissao' => (string) $title['data_emissao'],
            'ultima_acao' => $lastAct,
            'ultima_acao_at' => $lastAct['created_at'] ?? '',
            'next_action_date' => $nextDate,
            'total_acoes' => count($allActionsByTitle[$tkey] ?? []),
            'titulos_sem_acao' => $lastAct === null ? 1 : 0,
            'promessas_vencidas' => $isPromiseBroken ? 1 : 0,
            'retornos_atrasados' => $isReturnLate ? 1 : 0,
        ];
    }
    
    // ── 5. Filtering the Results ─────────────────────────────────────────────
    $filteredRiskQueue = [];
    
    foreach ($riskQueue as $item) {
        $lastAct = $item['ultima_acao'];
        $lastActType = $lastAct ? $lastAct['action_type'] : '';
        $nextDate = $lastAct ? $lastAct['next_action_date'] : '';
        
        // Vendor filter
        if ($vendorFilter !== '') {
            $vendorNeedle = mb_strtolower($vendorFilter);
            $vendorName = mb_strtolower($item['nome_vendedor']);
            if ($item['vendedor_key'] !== $vendorFilter && $item['codigo_vendedor_omie'] !== $vendorFilter && !str_contains($vendorName, $vendorNeedle)) {
                continue;
            }
        }
        
        // Actor filter
        if ($actorFilter !== '') {
            if (!$lastAct || (string) $lastAct['actor_user_id'] !== $actorFilter) {
                continue;
            }
        }
        
        // Action type filter
        if ($actionTypeFilter !== '') {
            if (!$lastAct || $lastActType !== $actionTypeFilter) {
                continue;
            }
        }

        if ($statusOperacionalFilter !== '') {
            if ($item['status_operacional'] !== $statusOperacionalFilter) {
                continue;
            }
        }

        if ($agingFilter !== '') {
            if ($item['aging_bucket'] !== $agingFilter) {
                continue;
            }
        }
        
        // Text Search Filter (q)
        if ($qFilter !== '') {
            $qLower = mb_strtolower($qFilter);
            $match = str_contains(mb_strtolower($item['nome_cliente']), $qLower)
                  || str_contains(mb_strtolower($item['documento_cliente']), $qLower)
                  || str_contains(mb_strtolower($item['numero_documento']), $qLower)
                  || str_contains(mb_strtolower($item['nome_vendedor']), $qLower)
                  || str_contains(mb_strtolower($item['titulo_key']), $qLower)
                  || str_contains(mb_strtolower($item['documento_origem_ref']), $qLower)
                  || str_contains(mb_strtolower($item['codigo_pedido_origem']), $qLower)
                  || ($lastAct && str_contains(mb_strtolower($lastAct['note']), $qLower));
                  
            if (!$match) {
                continue;
            }
        }
        
        // Operational/Status filters
        if ($onlyOverdue && $item['dias_atraso'] <= 0) {
            continue;
        }
        if ($onlyDueToday && $nextDate !== $today) {
            continue;
        }
        if ($onlyBrokenPromises && !($lastActType === 'promessa_pagamento' && $nextDate !== '' && $nextDate < $today)) {
            continue;
        }
        if ($onlyNoAction && $lastAct !== null) {
            continue;
        }
        if ($onlyOpenLinkReview && $lastActType !== 'revisar_vinculo_financeiro') {
            continue;
        }
        if ($onlyCritical && $item['prioridade_nivel'] !== 1) {
            continue;
        }
        
        $filteredRiskQueue[] = $item;
    }
    
    // ── 6. Aggregate Summary metrics (calculated on the filtered set) ────────
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
    $actionMixValues = [];

    $agingBuckets = [
        'a_vencer'   => ['label' => 'A vencer', 'qtd_titulos' => 0, 'valor_aberto' => 0.0, 'qtd_com_acao' => 0],
        'vence_hoje' => ['label' => 'Vence hoje', 'qtd_titulos' => 0, 'valor_aberto' => 0.0, 'qtd_com_acao' => 0],
        '1_7'        => ['label' => '1 a 7 dias', 'qtd_titulos' => 0, 'valor_aberto' => 0.0, 'qtd_com_acao' => 0],
        '8_15'       => ['label' => '8 a 15 dias', 'qtd_titulos' => 0, 'valor_aberto' => 0.0, 'qtd_com_acao' => 0],
        '16_30'      => ['label' => '16 a 30 dias', 'qtd_titulos' => 0, 'valor_aberto' => 0.0, 'qtd_com_acao' => 0],
        '31_60'      => ['label' => '31 a 60 dias', 'qtd_titulos' => 0, 'valor_aberto' => 0.0, 'qtd_com_acao' => 0],
        '60_plus'    => ['label' => '60+ dias', 'qtd_titulos' => 0, 'valor_aberto' => 0.0, 'qtd_com_acao' => 0],
    ];
    
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
            // Count for action mix
            $actionMixCounts[$lastActType] = ($actionMixCounts[$lastActType] ?? 0) + 1;
            $actionMixValues[$lastActType] = ($actionMixValues[$lastActType] ?? 0.0) + $valAberto;
            
            // Check if action was registered today
            $createdDay = substr($lastAct['created_at'], 0, 10);
            if ($createdDay === $today) {
                $cobradosHoje++;
            }
            
            // Next action date checks
            if ($nextDate !== '') {
                if ($nextDate === $today) {
                    $retornosHoje++;
                } elseif ($nextDate < $today) {
                    $retornosAtrasados++;
                }
            }
            
            // Promessas checks
            if ($lastActType === 'promessa_pagamento' && $nextDate !== '') {
                if ($nextDate >= $today) {
                    $promessasAtivas++;
                } else {
                    $promessasVencidas++;
                }
            }
            
            // Revisão vínculo checks
            if ($lastActType === 'revisar_vinculo_financeiro') {
                $revisoesAbertas++;
            }
        }
        
        // Vendor check
        if ($item['vendedor_key'] === '__sem_vendedor__') {
            $semVendedor++;
            $valorSemVendedor += $valAberto;
        }
        
        // Count titles with actions recorded within start/end range
        if (isset($allActionsByTitle[$item['titulo_key']])) {
            foreach ($allActionsByTitle[$item['titulo_key']] as $act) {
                $actDay = substr($act['created_at'], 0, 10);
                if ($actDay >= $start && $actDay <= $end) {
                    $titulosComAcaoPeriodoKeys[$item['titulo_key']] = true;
                    break;
                }
            }
        }

        $bucketKey = $item['aging_bucket'];

        if (isset($agingBuckets[$bucketKey])) {
            $agingBuckets[$bucketKey]['qtd_titulos']++;
            $agingBuckets[$bucketKey]['valor_aberto'] += $valAberto;
            if ($item['ultima_acao'] !== null) {
                $agingBuckets[$bucketKey]['qtd_com_acao']++;
            }
        }
    }

    $slaAging = [];
    foreach ($agingBuckets as $key => $b) {
        $tot = $b['qtd_titulos'];
        $b['percentual_sla'] = $tot > 0 ? round(($b['qtd_com_acao'] / $tot) * 100, 2) : 0.0;
        $b['valor_aberto'] = round($b['valor_aberto'], 2);
        $b['bucket'] = $key;
        $slaAging[] = $b;
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
    unset($p);
    
    // Clean up temporary maps in productivity array
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
            
            if ($lastActType === 'promessa_pagamento' && $nextDate !== '') {
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
    unset($v);
    
    $vendorGovernance = [];
    foreach ($vendorGroup as $vKey => $v) {
        $tot = $v['titulos_abertos'];
        $v['percentual_carteira_acionada'] = $tot > 0 ? round(($v['titulos_com_acao'] / $tot) * 100, 2) : 0.0;
        
        // Status logic
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
            'valor_aberto_associado' => round($actionMixValues[$type] ?? 0.0, 2),
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
        } elseif ($nextDate <= date('Y-m-d', strtotime($today . ' +7 days'))) {
            $fList['proximos'][] = $followRow;
        }
    }
    
    // Sort follow-ups
    usort($fList['vencidos'], static fn($a, $b) => strcmp($a['next_action_date'], $b['next_action_date']));
    usort($fList['hoje'], static fn($a, $b) => strcmp($a['titulo_key'], $b['titulo_key']));
    usort($fList['proximos'], static fn($a, $b) => strcmp($a['next_action_date'], $b['next_action_date']));
    
    // ── 11. Sorting and Pagination for Risk Queue ────────────────────────────
    $sortMap = [
        'valor_aberto' => 'valor_aberto',
        'valor_vencido' => 'valor_vencido',
        'vencimento' => 'data_vencimento',
        'dias_atraso' => 'dias_atraso',
        'next_action_date' => 'next_action_date',
        'ultima_acao_at' => 'ultima_acao_at',
        'total_acoes' => 'total_acoes',
        'titulos_sem_acao' => 'titulos_sem_acao',
        'promessas_vencidas' => 'promessas_vencidas',
        'retornos_atrasados' => 'retornos_atrasados',
        'nome_cliente' => 'nome_cliente',
        'prioridade' => 'prioridade_nivel',
    ];
    
    $sortField = $sortMap[$sort] ?? 'prioridade_nivel';
    $isAsc = $direction === 'asc';
    
    usort($filteredRiskQueue, static function($a, $b) use ($sortField, $isAsc) {
        $valA = $a[$sortField] ?? 0;
        $valB = $b[$sortField] ?? 0;
        
        if ($valA === $valB) {
            // Tie breaker by valor_aberto desc
            return $b['valor_aberto'] <=> $a['valor_aberto'];
        }
        
        if (is_string($valA)) {
            return $isAsc ? strcmp($valA, $valB) : strcmp($valB, $valA);
        }
        
        return $isAsc ? ($valA <=> $valB) : ($valB <=> $valA);
    });
    
    $totalFiltered = count($filteredRiskQueue);
    $pagination = new Pagination($totalFiltered, $page, $perPage);
    
    $paginatedRiskQueue = array_slice($filteredRiskQueue, $pagination->offset, $pagination->perPage);
    
    // ── Output ───────────────────────────────────────────────────────────────
    $durationMs = round((microtime(true) - $t0) * 1000, 1);
    
    Http::json([
        'ok' => true,
        'period' => [
            'start' => $start,
            'end' => $end,
            'reference_date' => $today,
        ],
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
        'risk_queue' => $paginatedRiskQueue,
        'vendor_governance' => $vendorGovernance,
        'action_mix' => $actionMix,
        'sla_aging' => $slaAging,
        'pagination' => $pagination->toArray(),
        'filters' => [
            'start' => $start,
            'end' => $end,
            'vendor' => $vendorFilter,
            'actor_user_id' => $actorFilter,
            'action_type' => $actionTypeFilter,
            'status_operacional' => $statusOperacionalFilter,
            'aging' => $agingFilter,
            'q' => $qFilter,
            'only_overdue' => $onlyOverdue,
            'only_due_today' => $onlyDueToday,
            'only_broken_promises' => $onlyBrokenPromises,
            'only_no_action' => $onlyNoAction,
            'only_open_link_review' => $onlyOpenLinkReview,
            'only_critical' => $onlyCritical,
            'sort' => $sort,
            'direction' => $direction,
        ],
        'diagnostics' => [
            'query_ms' => $durationMs,
            'source' => 'memory_aggregate',
            'spans_processed' => count($logs),
        ],
    ]);
} catch (\InvalidArgumentException $e) {
    Http::json(['ok' => false, 'message' => $e->getMessage()], 400);
} catch (\Throwable $e) {
    Http::json(['ok' => false, 'message' => 'Erro interno na governança de cobrança: ' . $e->getMessage()], 500);
}
