<?php

declare(strict_types=1);

/**
 * Performance comercial — Leitura canônica.
 *
 * Mesmo contrato de resposta que api/vendors.php, mas lê da tabela
 * biomie_pedidos_canonicos em vez de chamar a API do Omie ao vivo.
 *
 * Se a tabela canônica não existir ou estiver vazia, redireciona
 * transparente para o fluxo antigo (api/vendors.php).
 */

use BIomie\CanonicalRepository;
use BIomie\Commercial\TargetLadder;
use BIomie\CommercialPlanning;
use BIomie\Database;
use BIomie\Http;
use BIomie\Support;
use BIomie\Support\ActiveVendorResolver;
use BIomie\Support\CustomerOwnershipResolver;
use BIomie\Support\SalesGoalPeriodCalculator;
use BIomie\Support\VendorAliasResolver;

require __DIR__ . '/bootstrap.php';
\BIomie\Auth::requireCapability('sales.view', true);

try {
    $range = Http::resolveDateRange();
    Http::validateDateRange($range['start'], $range['end']);
    $start = $range['start'];
    $end   = $range['end'];

    // ── COM.R1.5 — active_only (default true) + channel filter ───────────────
    $activeOnly    = Http::boolQuery('active_only', true) && !Http::boolQuery('include_inactive', false);
    $inactiveOnly  = Http::boolQuery('inactive_only', false);
    $channelFilter = trim(Http::query('channel', ''));
    $allowedChannels = ['varejo', 'revenda', 'representante', 'licitacao', 'outro'];
    if (!in_array($channelFilter, $allowedChannels, true)) $channelFilter = '';

    // ── Verificar se a camada canônica está disponível ────────────────────────
    $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $canonical = null;

    if (Database::isConfigured($config, $connection)) {
        try {
            $pdo = Database::connect($config, $connection);
            $repo = new CanonicalRepository($pdo, $logger);
            if ($repo->isAvailable()) {
                $canonical = $repo;
            }
        } catch (\Throwable $e) {
            $logger->warning('Canonical vendors: banco indisponível, usando fallback.', ['message' => $e->getMessage()]);
        }
    }

    // ── Fallback: delegar para o fluxo antigo ────────────────────────────────
    if ($canonical === null) {
        $snapshot = $analytics->getSnapshot($start, $end, Http::boolQuery('force', false));
        Http::json([
            'ok' => true,
            'mode' => $snapshot['mode'],
            'source' => 'legacy',
            'period' => $snapshot['period'],
            'summary' => $snapshot['summary'],
            'summary_status' => $snapshot['summary_status'] ?? [],
            'vendors' => $snapshot['vendor_ranking'],
            'vendor_status_matrix' => $snapshot['vendor_status_matrix'] ?? [],
            'daily_status' => $snapshot['daily_status'] ?? [],
            'funnel' => $snapshot['funnel'] ?? [],
            'alerts_operational' => $snapshot['alerts_operational'] ?? [],
            'meta' => $snapshot['meta'],
        ]);
    }

    // ── Leitura canônica ─────────────────────────────────────────────────────

    $summary = $canonical->summarize($start, $end);
    $vendorRows = $canonical->aggregateByVendor($start, $end);
    $dailyRows = $canonical->dailyByStatus($start, $end);
    $lastSync = $canonical->lastSyncAt($start, $end);

    $billedVal = (float) ($summary['billed_value'] ?? 0);
    $billedQty = (int) ($summary['billed_orders'] ?? 0);
    $authVal = (float) ($summary['authorized_value'] ?? 0);
    $authQty = (int) ($summary['authorized_orders'] ?? 0);
    $cancelVal = (float) ($summary['cancelled_value'] ?? 0);
    $cancelQty = (int) ($summary['cancelled_orders'] ?? 0);

    $convRate = ($authQty + $billedQty) > 0 ? round(($billedQty / ($authQty + $billedQty)) * 100, 2) : 0;
    $cancelRate = ($authQty + $cancelQty) > 0 ? round(($cancelQty / ($authQty + $cancelQty)) * 100, 2) : 0;

    $summaryStatus = [
        'billed_value' => Support::money($billedVal),
        'billed_orders' => $billedQty,
        'authorized_value' => Support::money($authVal),
        'authorized_orders' => $authQty,
        'cancelled_value' => Support::money($cancelVal),
        'cancelled_orders' => $cancelQty,
        'backlog_value' => Support::money($authVal),
        'conversion_rate' => $convRate,
        'cancel_rate' => $cancelRate,
    ];

    // ── Montar vendor_status_matrix (mesmo formato do SalesAnalyticsService) ─
    $vendorMatrix = [];
    $byVendor = [];

    foreach ($vendorRows as $row) {
        $vId = (string) ($row['codigo_vendedor_omie'] ?? '');
        $vName = (string) ($row['nome_vendedor'] ?? 'Sem vendedor');
        $status = (string) ($row['status_comercial'] ?? 'outro');
        $key = $vId ?: 'name:' . mb_strtolower($vName);

        if (!isset($byVendor[$key])) {
            $byVendor[$key] = [
                'vendor_id' => $vId,
                'vendor_name' => $vName,
                'billed_value' => 0.0, 'billed_orders' => 0,
                'authorized_value' => 0.0, 'authorized_orders' => 0,
                'cancelled_value' => 0.0, 'cancelled_orders' => 0,
            ];
        }

        $total = (float) ($row['total'] ?? 0);
        $pedidos = (int) ($row['pedidos'] ?? 0);

        if ($status === 'faturado') {
            $byVendor[$key]['billed_value'] += $total;
            $byVendor[$key]['billed_orders'] += $pedidos;
        } elseif ($status === 'autorizado') {
            $byVendor[$key]['authorized_value'] += $total;
            $byVendor[$key]['authorized_orders'] += $pedidos;
        } elseif ($status === 'cancelado') {
            $byVendor[$key]['cancelled_value'] += $total;
            $byVendor[$key]['cancelled_orders'] += $pedidos;
        }
    }

    foreach ($byVendor as $v) {
        $vBilledVal = $v['billed_value'];
        $vBilledQty = $v['billed_orders'];
        $vAuthQty = $v['authorized_orders'];
        $vCancelQty = $v['cancelled_orders'];

        $vendorMatrix[] = [
            'vendor_id' => $v['vendor_id'],
            'vendor_name' => $v['vendor_name'],
            'billed_value' => Support::money($vBilledVal),
            'billed_orders' => $vBilledQty,
            'authorized_value' => Support::money($v['authorized_value']),
            'authorized_orders' => $vAuthQty,
            'cancelled_value' => Support::money($v['cancelled_value']),
            'cancelled_orders' => $vCancelQty,
            'backlog_value' => Support::money($v['authorized_value']),
            'conversion_rate' => ($vAuthQty + $vBilledQty) > 0 ? round(($vBilledQty / ($vAuthQty + $vBilledQty)) * 100, 2) : 0,
            'cancel_rate' => ($vAuthQty + $vCancelQty) > 0 ? round(($vCancelQty / ($vAuthQty + $vCancelQty)) * 100, 2) : 0,
            'avg_ticket_billed' => $vBilledQty > 0 ? Support::money($vBilledVal / $vBilledQty) : 0,
            'avg_days_to_bill' => 0,
            'share_billed' => $billedVal > 0 ? round(($vBilledVal / $billedVal) * 100, 2) : 0,
        ];
    }
    // ── COM.R1.5 — Active vendor status + channel resolution ─────────────────
    $aliasRes  = new VendorAliasResolver();
    $activeRes = new ActiveVendorResolver($pdo, $aliasRes);
    $activeRes->ensureLoaded();
    $activeSummary = $activeRes->getSummary();

    // ── COM.R1.4 — Meta proporcional + NF realized + conflitos de carteirização ─

    // 1. Load monthly targets from biomie_sales_targets_monthly
    $targetsRows = [];
    try {
        $targRepo = new \BIomie\TargetsRepository($pdo, $logger);
        if ($targRepo->isAvailable()) {
            $months      = CommercialPlanning::monthsInRange($start, $end);
            $targetsMap  = $targRepo->getTargetsForMonths($months);
            $targetsRows = array_values($targetsMap);
        }
    } catch (\Throwable) {}

    // 2. Build monthly target map keyed by year_month for team total
    $teamMonthlyTargets = [];
    foreach ($targetsRows as $tr) {
        $ym  = $tr['year_month'];
        $amt = $tr['target_amount'];
        if ($ym !== '') $teamMonthlyTargets[$ym] = ($teamMonthlyTargets[$ym] ?? 0.0) + $amt;
    }

    // 3. Compute team-level proportional goal using calendar-day calculator
    $goalCalc  = new SalesGoalPeriodCalculator();
    $teamGoal  = $goalCalc->calculate($start, $end, $teamMonthlyTargets);
    $teamMetaProporcional = $teamGoal['meta_total_periodo'];

    // 4. Compute NF-based realized per vendor (biomie_notas_fiscais_canonicas)
    $nfRealizedByVendor = [];
    $nfRealizedTotal    = 0.0;
    try {
        $hasNfTable = (function(PDO $p): bool { try { $s = $p->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='biomie_notas_fiscais_canonicas' LIMIT 1"); $s->execute(); return (int)$s->fetchColumn() > 0; } catch(\Throwable) { return false; } })($pdo);
        if ($hasNfTable) {
            $nfDateExpr   = "COALESCE(data_competencia, data_emissao)";
            $tenantFilter = \BIomie\Support\TenantContext::sqlFilter();
            $sNfReal = $pdo->prepare(
                "SELECT nome_vendedor, SUM(valor_mercadorias) AS valor_merc
                 FROM biomie_notas_fiscais_canonicas
                 WHERE {$nfDateExpr} BETWEEN :start AND :end
                   AND {$tenantFilter}
                   AND status_fiscal = 'autorizada'
                 GROUP BY nome_vendedor"
            );
            $sNfReal->execute([':start' => $start, ':end' => $end]);
            foreach ($sNfReal->fetchAll(PDO::FETCH_ASSOC) as $nfRow) {
                $vn = trim((string) ($nfRow['nome_vendedor'] ?? ''));
                $vm = (float) ($nfRow['valor_merc'] ?? 0);
                $nfRealizedByVendor[$vn] = ($nfRealizedByVendor[$vn] ?? 0.0) + $vm;
                $nfRealizedTotal += $vm;
            }
        }
    } catch (\Throwable) {}

    // 5. Compute conflict counts per vendor from dim
    $vendorConflictCounts  = [];  // vendor_name => ['conflicts'=>N, 'value'=>F]
    $vendorInvadedCounts   = [];  // owner_vendor_name => count
    $totalConflictsGlobal  = 0;
    $totalConflictValue    = 0.0;
    try {
        $hasDimTable = (function(PDO $p): bool { try { $s = $p->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='biomie_clientes_dim' LIMIT 1"); $s->execute(); return (int)$s->fetchColumn() > 0; } catch(\Throwable) { return false; } })($pdo);
        if ($hasNfTable && $hasDimTable) {
            $nfDateExpr     = "COALESCE(nf.data_competencia, nf.data_emissao)";
            $tenantFilterNf = \BIomie\Support\TenantContext::sqlFilter('nf');
            $sConf = $pdo->prepare(
                "SELECT nf.nome_vendedor        AS sale_vendor,
                        cd.vendedor_responsavel_nome AS owner_vendor,
                        COUNT(DISTINCT nf.nota_key) AS qtd_nfs,
                        SUM(nf.valor_mercadorias)   AS valor
                 FROM biomie_notas_fiscais_canonicas nf
                 INNER JOIN biomie_clientes_dim cd
                   ON cd.codigo_cliente_omie = nf.codigo_cliente_omie
                  AND cd.company_id = nf.company_id
                 WHERE {$nfDateExpr} BETWEEN :start AND :end
                   AND {$tenantFilterNf}
                   AND nf.status_fiscal = 'autorizada'
                   AND cd.vendedor_responsavel_nome IS NOT NULL
                   AND cd.vendedor_responsavel_nome != ''
                   AND cd.vendedor_responsavel_nome != 'Sem vendedor'
                 GROUP BY nf.nome_vendedor, cd.vendedor_responsavel_nome"
            );
            $sConf->execute([':start' => $start, ':end' => $end]);
            $conflRows = $sConf->fetchAll(PDO::FETCH_ASSOC);
            $ownerRes  = new CustomerOwnershipResolver();
            foreach ($conflRows as $cr) {
                $sv   = trim((string) ($cr['sale_vendor']  ?? ''));
                $ov   = trim((string) ($cr['owner_vendor'] ?? ''));
                $val  = (float) ($cr['valor'] ?? 0);
                if ($ownerRes->isEmptyVendor($sv) || $ownerRes->isEmptyVendor($ov)) continue;
                if (!$ownerRes->sameVendor($sv, $ov)) {
                    // conflict: sold by different vendor
                    $vendorConflictCounts[$sv] = $vendorConflictCounts[$sv] ?? ['conflicts' => 0, 'value' => 0.0];
                    $vendorConflictCounts[$sv]['conflicts']++;
                    $vendorConflictCounts[$sv]['value'] += $val;
                    $vendorInvadedCounts[$ov] = ($vendorInvadedCounts[$ov] ?? 0) + 1;
                    $totalConflictsGlobal++;
                    $totalConflictValue += $val;
                }
            }
        }
    } catch (\Throwable) {}

    // 6. Enrich vendorMatrix with target_period, attainment_pct, nf_realized, conflicts, active/channel
    foreach ($vendorMatrix as &$vm) {
        $vName = (string) ($vm['vendor_name'] ?? '');
        // Target proporcional (calendar days)
        $vGoal   = $goalCalc->calculateForVendor($start, $end, (string)($vm['vendor_id'] ?? ''), $vName, $targetsRows);
        $vTarget = $vGoal['meta_total_periodo'];
        $vm['target_period']         = round($vTarget, 2);
        // NF-based realized
        $vNfReal = $nfRealizedByVendor[$vName] ?? 0.0;
        $vm['realized_by_nf']        = round($vNfReal, 2);
        // Attainment based on NF realized vs proportional target
        $vm['attainment_pct'] = $vTarget > 0 ? round($vNfReal / $vTarget * 100, 2) : 0.0;
        // Risk status based on attainment
        $vm['risk_status'] = match (true) {
            $vTarget <= 0                  => 'no_target',
            $vm['attainment_pct'] >= 100   => 'on_track',
            $vm['attainment_pct'] >= 85    => 'at_risk',
            $vm['attainment_pct'] >= 60    => 'below_target',
            default                        => 'critical',
        };
        // Metas múltiplas — níveis proporcionais + nível atual/próximo (opcional)
        $vmVendorId = (string) ($vm['vendor_id'] ?? '');
        $tierMonthly = []; // tier_index => [ym => valor]
        foreach ($targetsRows as $tRow) {
            $tVid   = trim($tRow['vendor_id']);
            $tVname = mb_strtolower(trim($tRow['vendor_name']));
            $tYm    = $tRow['year_month'];
            $tMatch = ($vmVendorId !== '' && $tVid === $vmVendorId)
                || ($vName !== '' && mb_strtolower($vName) === $tVname);
            if (!$tMatch || $tYm === '') continue;
            foreach (array_values($tRow['tiers']) as $ti => $tv) {
                $tierMonthly[$ti][$tYm] = ($tierMonthly[$ti][$tYm] ?? 0.0) + (float) $tv;
            }
        }
        if ($tierMonthly !== [] && $vTarget > 0) {
            ksort($tierMonthly);
            $levels = [round($vTarget, 2)];
            foreach ($tierMonthly as $tierMap) {
                $tierPeriod = $goalCalc->calculate($start, $end, $tierMap)['meta_total_periodo'];
                if ($tierPeriod > end($levels)) $levels[] = round($tierPeriod, 2);
            }
            $vm = array_merge($vm, TargetLadder::evaluate($levels, $vNfReal));
            $vm['base_gap'] = round(max($vTarget - $vNfReal, 0), 2);
            $vm['gap_to_target'] = $vm['active_target'] !== null
                ? round(max((float) $vm['active_target'] - $vNfReal, 0), 2)
                : 0.0;
        }
        // Conflict counts
        $vConf = $vendorConflictCounts[$vName] ?? null;
        $vm['conflicts_out_of_portfolio'] = $vConf ? (int)$vConf['conflicts'] : 0;
        $vm['conflicts_value_out']        = $vConf ? round($vConf['value'], 2) : 0.0;
        $vm['clients_invaded']            = $vendorInvadedCounts[$vName] ?? 0;
        // vendor_ref for drawer
        $vm['vendor_ref'] = (string)($vm['vendor_id'] ?? '');
        // COM.R1.5 — active status + channel
        $vStatus = $activeRes->resolve((string) $vm['vendor_id'], $vName);
        $vm['is_active']         = $vStatus['is_active'];
        $vm['active_status_confidence'] = $vStatus['active_status_confidence'] ?? $vStatus['confidence'] ?? 0;
        $vm['active_status_warning']    = $vStatus['active_status_warning'] ?? '';
        $vm['active_status_warnings']   = $vStatus['warnings'] ?? [];
        $vm['ghost_vendor']      = $vStatus['ghost_vendor'] ?? false;
        $vm['vendor_channel']    = $vStatus['vendor_channel'];
        $vm['canonical_name']    = $vStatus['canonical_name'] ?: $vName;
        $vm['display_name']      = $vName;
        $vm['inactive_badge']    = $vStatus['inactive_badge'];
        $vm['channel_badge']     = $vStatus['channel_badge'];
        $vm['status_source']     = $vStatus['source'] ?? '';
        $vm['suggested_alias']   = $vStatus['suggested_alias'] ?? null;
        $vm['requires_manual_approval'] = $vStatus['requires_manual_approval'] ?? false;
    }
    unset($vm);

    // COM.R1.5 — Apply active_only / inactive_only filter and channel filter
    if ($inactiveOnly) {
        $vendorMatrix = array_values(array_filter($vendorMatrix, fn($v) => (int)($v['active_status_confidence'] ?? 0) >= 80 && !($v['is_active'] ?? true)));
    } elseif ($activeOnly) {
        $vendorMatrix = array_values(array_filter($vendorMatrix, fn($v) => (int)($v['active_status_confidence'] ?? 0) < 80 || (bool)($v['is_active'] ?? true)));
    }
    if ($channelFilter !== '') {
        $vendorMatrix = array_values(array_filter($vendorMatrix, fn($v) => ($v['vendor_channel'] ?? '') === $channelFilter));
    }

    // Update team summary with NF-based realized and proportional target
    $summaryCompat['target']        = round($teamMetaProporcional, 2);
    $summaryCompat['realized_by_nf']= round($nfRealizedTotal, 2);
    $summaryCompat['goal_progress'] = $teamMetaProporcional > 0
        ? round($nfRealizedTotal / $teamMetaProporcional * 100, 2)
        : 0.0;

    usort($vendorMatrix, fn($a, $b) => $b['billed_value'] <=> $a['billed_value']);

    // ── Montar daily_status ──────────────────────────────────────────────────
    $dailyStatus = [];
    foreach (Support::eachDate($start, $end) as $date) {
        $dailyStatus[$date] = [
            'date' => $date,
            'billed_value' => 0.0, 'billed_orders' => 0,
            'authorized_value' => 0.0, 'authorized_orders' => 0,
            'cancelled_value' => 0.0, 'cancelled_orders' => 0,
        ];
    }
    foreach ($dailyRows as $row) {
        $dia = $row['dia'] ?? '';
        $status = $row['status_comercial'] ?? '';
        if (!isset($dailyStatus[$dia])) continue;

        $val = (float) ($row['total'] ?? 0);
        $qty = (int) ($row['pedidos'] ?? 0);

        if ($status === 'faturado') {
            $dailyStatus[$dia]['billed_value'] += $val;
            $dailyStatus[$dia]['billed_orders'] += $qty;
        } elseif ($status === 'autorizado') {
            $dailyStatus[$dia]['authorized_value'] += $val;
            $dailyStatus[$dia]['authorized_orders'] += $qty;
        } elseif ($status === 'cancelado') {
            $dailyStatus[$dia]['cancelled_value'] += $val;
            $dailyStatus[$dia]['cancelled_orders'] += $qty;
        }
    }

    // ── Alertas operacionais ─────────────────────────────────────────────────
    $alertsOp = [];
    if (!empty($activeSummary['active_status_mapping_gap'])) {
        $alertsOp[] = [
            'level' => 'warning',
            'title' => 'Status de vendedores em validacao',
            'message' => 'Ha divergencia entre cadastro Omie e BIomie.',
        ];
    }
    foreach ($vendorMatrix as $v) {
        if ($v['cancel_rate'] > 15 && ($v['cancelled_orders'] + $v['authorized_orders']) >= 3) {
            $alertsOp[] = [
                'level' => 'danger',
                'title' => $v['vendor_name'] . ': cancelamento de ' . $v['cancel_rate'] . '%',
                'message' => $v['cancelled_orders'] . ' cancelado(s) de ' . ($v['cancelled_orders'] + $v['authorized_orders']) . ' pedido(s).',
            ];
        }
    }

    // ── Summary compatível com o cockpit ─────────────────────────────────────
    $revenueOrders = $billedQty + $authQty;
    $grossRevenue = $billedVal + $authVal;

    $summaryCompat = [
        'revenue' => Support::money($grossRevenue),
        'gross_revenue' => Support::money($grossRevenue),
        'merchandise_total' => 0,
        'discounts_total' => 0,
        'orders' => $revenueOrders,
        'avg_ticket' => $revenueOrders > 0 ? Support::money($grossRevenue / $revenueOrders) : 0,
        'avg_ticket_billed' => $billedQty > 0 ? Support::money($billedVal / $billedQty) : 0,
        'active_vendors' => count(array_filter($vendorMatrix, fn($v) => $v['vendor_name'] !== 'Sem vendedor')),
        'target' => 0,
        'forecast' => 0,
        'goal_progress' => 0,
    ];

    // ── FILTRO COMERCIAL — vendedores selecionados no mega funil ──────────────
    //
    // Aplicado na SAÍDA, não no SQL: esta tela nasce de agregações de serviço
    // (summarize/aggregateByVendor) compartilhadas com outros consumidores, e
    // reescrevê-las traria risco desproporcional. Recortar o conjunto e
    // RECALCULAR os totais a partir dele dá o mesmo resultado, sem tocar nas
    // consultas. A autorização segue intacta: só é possível estreitar dentro do
    // que o VendorScopeFilter/SellerVisibilityScope já permitiram montar.
    $selectedVendorIds = \BIomie\Support\VendorScopeFilter::requestedIds();
    $vendorFilterApplied = false;
    if ($selectedVendorIds !== []) {
        $selectedSet = array_flip($selectedVendorIds);
        $filteredMatrix = array_values(array_filter(
            $vendorMatrix,
            static fn (array $v): bool => isset($selectedSet[(string) ($v['vendor_id'] ?? '')])
        ));
        // Só aplica se sobrou alguém: uma seleção que não casa com ninguém desta
        // empresa não pode zerar a tela inteira em silêncio.
        if ($filteredMatrix !== []) {
            $vendorFilterApplied = true;
            $vendorMatrix = $filteredMatrix;

            $fBilledVal = 0.0; $fBilledQty = 0; $fAuthVal = 0.0; $fAuthQty = 0;
            $fCancelVal = 0.0; $fCancelQty = 0;
            foreach ($vendorMatrix as $v) {
                $fBilledVal += (float) ($v['billed_value'] ?? 0);
                $fBilledQty += (int)   ($v['billed_orders'] ?? 0);
                $fAuthVal   += (float) ($v['authorized_value'] ?? 0);
                $fAuthQty   += (int)   ($v['authorized_orders'] ?? 0);
                $fCancelVal += (float) ($v['cancelled_value'] ?? 0);
                $fCancelQty += (int)   ($v['cancelled_orders'] ?? 0);
            }
            // share_billed passa a ser relativo ao recorte (senão os % não somam 100).
            foreach ($vendorMatrix as &$vRef) {
                $vRef['share_billed'] = $fBilledVal > 0
                    ? round(((float) ($vRef['billed_value'] ?? 0) / $fBilledVal) * 100, 2)
                    : 0;
            }
            unset($vRef);

            $summaryStatus['billed_value']      = Support::money($fBilledVal);
            $summaryStatus['billed_orders']     = $fBilledQty;
            $summaryStatus['authorized_value']  = Support::money($fAuthVal);
            $summaryStatus['authorized_orders'] = $fAuthQty;
            $summaryStatus['cancelled_value']   = Support::money($fCancelVal);
            $summaryStatus['cancelled_orders']  = $fCancelQty;
            $summaryStatus['backlog_value']     = Support::money($fAuthVal);
            $summaryStatus['conversion_rate']   = ($fAuthQty + $fBilledQty) > 0
                ? round(($fBilledQty / ($fAuthQty + $fBilledQty)) * 100, 2) : 0;
            $summaryStatus['cancel_rate']       = ($fAuthQty + $fCancelQty) > 0
                ? round(($fCancelQty / ($fAuthQty + $fCancelQty)) * 100, 2) : 0;

            $summaryCompat['total_revenue']    = Support::money($fBilledVal);
            $summaryCompat['total_orders']     = $fBilledQty;
            $summaryCompat['avg_ticket_billed'] = $fBilledQty > 0
                ? Support::money($fBilledVal / $fBilledQty) : 0;
            $summaryCompat['active_vendors']   = count($vendorMatrix);

            $funnelAuth   = $fAuthVal + $fBilledVal;
            $funnelBilled = $fBilledVal;
            $funnelCancel = $fCancelVal;
        }
    }

    Http::json([
        'ok' => true,
        'mode' => 'canonical',
        'source' => 'canonical',
        'period' => [
            'start' => $start,
            'end' => $end,
            'label' => date('d/m/Y', strtotime($start)) . ' até ' . date('d/m/Y', strtotime($end)),
        ],
        'summary' => $summaryCompat,
        'summary_status' => $summaryStatus,
        'vendor_filter' => [
            'applied' => $vendorFilterApplied,
            'vendor_ids' => $selectedVendorIds,
            'count' => count($selectedVendorIds),
        ],
        'vendors' => $vendorMatrix,
        'vendor_status_matrix' => $vendorMatrix,
        'daily_status' => array_values(array_map(fn($d) => [
            'date' => $d['date'],
            'billed_value' => Support::money($d['billed_value']),
            'billed_orders' => $d['billed_orders'],
            'authorized_value' => Support::money($d['authorized_value']),
            'authorized_orders' => $d['authorized_orders'],
            'cancelled_value' => Support::money($d['cancelled_value']),
            'cancelled_orders' => $d['cancelled_orders'],
        ], $dailyStatus)),
        'funnel' => [
            // Segue o recorte quando há filtro de vendedor (variáveis definidas
            // no bloco acima); sem filtro, mantém os totais da empresa.
            'authorized' => Support::money($funnelAuth ?? ($authVal + $billedVal)),
            'billed' => Support::money($funnelBilled ?? $billedVal),
            'cancelled' => Support::money($funnelCancel ?? $cancelVal),
            'received' => 0.0,
        ],
        'alerts_operational' => $alertsOp,
        'meta' => [
            'generated_at' => date(DATE_ATOM),
            'served_from'  => 'canonical-db',
            'last_sync'    => $lastSync,
            'active_filter' => $activeOnly ? 'active_only_when_confident' : ($inactiveOnly ? 'inactive_only_confident' : 'all'),
            'vendor_roster' => $activeSummary,
        ],
        'goal_period' => [
            'meta_proporcional_total'   => round($teamMetaProporcional, 2),
            'realizado_por_nf'          => round($nfRealizedTotal, 2),
            'dias_periodo'              => $teamGoal['dias_periodo'],
            'breakdown_por_mes'         => $teamGoal['breakdown'],
            'calculator'                => 'SalesGoalPeriodCalculator (dias corridos)',
        ],
        'ownership_summary' => [
            'total_conflicts_out'   => $totalConflictsGlobal,
            'conflict_value_out'    => round($totalConflictValue, 2),
            'note'                  => 'Vendas onde o vendedor da NF ≠ dono da carteira (biomie_clientes_dim)',
        ],
    ]);
} catch (\InvalidArgumentException $e) {
    Http::json(['ok' => false, 'message' => $e->getMessage()], 400);
} catch (Throwable $e) {
    $logger->error('Falha em api/canonical_vendors.', [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
    Http::json(['ok' => false, 'message' => 'Erro interno ao carregar dados canônicos.'], 500);
}
