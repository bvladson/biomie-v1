<?php

declare(strict_types=1);

/**
 * tools/dev/finance_receivable_trace_audit.php — FIN.R4.1
 *
 * Diagnóstico read-only de rastreabilidade: NF × boleto × vendedor × método de pagamento.
 * Não altera, não materializa, não cria bridges.
 *
 * Usage:
 *   php tools/dev/finance_receivable_trace_audit.php --start=YYYY-MM-DD --end=YYYY-MM-DD --pretty
 *   php tools/dev/finance_receivable_trace_audit.php --start=2026-05-01 --end=2026-05-31 --json
 *   php tools/dev/finance_receivable_trace_audit.php --only-open --only-overdue --include-examples --pretty
 *   php tools/dev/finance_receivable_trace_audit.php --payment-method=boleto --pretty
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\Support\FinanceReceivableTraceBuilder;

Bootstrap::init();

$opts            = getOpts($argv);
$json            = isset($opts['json']);
$pretty          = isset($opts['pretty']) || !$json;
$start           = (string) ($opts['start'] ?? date('Y-m-01'));
$end             = (string) ($opts['end'] ?? date('Y-m-t'));
$onlyOpen        = isset($opts['only-open']);
$onlyOverdue     = isset($opts['only-overdue']);
$paymentFilter   = trim((string) ($opts['payment-method'] ?? 'all'));
$includeExamples = isset($opts['include-examples']) || $pretty;

try {
    validateDate($start, 'start');
    validateDate($end, 'end');
    if ($start > $end) {
        throw new InvalidArgumentException('start deve ser <= end');
    }

    $config = new Config();
    $conn   = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo    = Database::connect($config, $conn);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ── Inventário de colunas ─────────────────────────────────────────────────
    $columnInventory = [];
    $tables = [
        'biomie_titulos_financeiros_canonicos',
        'biomie_notas_fiscais_canonicas',
        'biomie_bridge_nf_titulo_items',
        'biomie_bridge_nf_titulo_summary',
        'mart_financeiro_titulo',
        'mart_bridge_pedido_nfe_titulo',
    ];

    foreach ($tables as $table) {
        $exists = (bool) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($table)
        )->fetchColumn();

        if (!$exists) {
            $columnInventory[$table] = ['exists' => false, 'columns' => []];
            continue;
        }

        $cols = $pdo->query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($table) . "
             ORDER BY ORDINAL_POSITION"
        )->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $columnInventory[$table] = ['exists' => true, 'columns' => $cols];
    }

    // ── Builder para dados agregados ──────────────────────────────────────────
    $filters = [];
    if ($onlyOpen) $filters['status'] = 'open';
    if ($onlyOverdue) $filters['status'] = 'overdue';
    if ($paymentFilter !== 'all') $filters['payment_method'] = $paymentFilter;

    $builder = new FinanceReceivableTraceBuilder($pdo);
    $payload = $builder->build($start, $end, $filters);
    $summary = $payload['summary'];

    // ── Cobertura de vínculo (via SQL direto para granularidade) ─────────────
    $coverage  = [];
    $hasBridge = $columnInventory['biomie_bridge_nf_titulo_items']['exists'];
    $hasNf     = $columnInventory['biomie_notas_fiscais_canonicas']['exists'];
    $today     = date('Y-m-d');

    $titlesStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM biomie_titulos_financeiros_canonicos WHERE data_competencia BETWEEN :s AND :e"
    );
    $titlesStmt->execute(['s' => $start, 'e' => $end]);
    $coverage['total_titulos_periodo'] = (int) $titlesStmt->fetchColumn();

    $openStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM biomie_titulos_financeiros_canonicos
         WHERE data_competencia BETWEEN :s AND :e AND valor_aberto > 0
           AND status_titulo NOT IN ('liquidado','recebido','pago')"
    );
    $openStmt->execute(['s' => $start, 'e' => $end]);
    $coverage['titulos_abertos'] = (int) $openStmt->fetchColumn();

    $overdueStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM biomie_titulos_financeiros_canonicos
         WHERE data_competencia BETWEEN :s AND :e AND valor_aberto > 0
           AND data_vencimento < CURDATE()
           AND status_titulo NOT IN ('liquidado','recebido','pago')"
    );
    $overdueStmt->execute(['s' => $start, 'e' => $end]);
    $coverage['titulos_vencidos'] = (int) $overdueStmt->fetchColumn();

    $dueStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM biomie_titulos_financeiros_canonicos
         WHERE data_competencia BETWEEN :s AND :e AND valor_aberto > 0
           AND data_vencimento >= CURDATE()
           AND status_titulo NOT IN ('liquidado','recebido','pago')"
    );
    $dueStmt->execute(['s' => $start, 'e' => $end]);
    $coverage['titulos_a_vencer'] = (int) $dueStmt->fetchColumn();

    $withNfDirectStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM biomie_titulos_financeiros_canonicos
         WHERE data_competencia BETWEEN :s AND :e AND nota_key IS NOT NULL AND nota_key <> ''"
    );
    $withNfDirectStmt->execute(['s' => $start, 'e' => $end]);
    $coverage['titulos_com_nota_key_direto'] = (int) $withNfDirectStmt->fetchColumn();

    if ($hasBridge) {
        $bridgeStmt = $pdo->prepare(
            "SELECT COUNT(DISTINCT t.titulo_key) FROM biomie_titulos_financeiros_canonicos t
             JOIN biomie_bridge_nf_titulo_items bi ON bi.titulo_key = t.titulo_key
             WHERE t.data_competencia BETWEEN :s AND :e"
        );
        $bridgeStmt->execute(['s' => $start, 'e' => $end]);
        $coverage['titulos_com_nf_via_bridge'] = (int) $bridgeStmt->fetchColumn();
    } else {
        $coverage['titulos_com_nf_via_bridge'] = null;
    }

    $withoutNfStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM biomie_titulos_financeiros_canonicos t
         WHERE t.data_competencia BETWEEN :s AND :e
           AND (t.nota_key IS NULL OR t.nota_key = '')" .
        ($hasBridge ? " AND t.titulo_key NOT IN (SELECT bi.titulo_key FROM biomie_bridge_nf_titulo_items bi)" : '')
    );
    $withoutNfStmt->execute(['s' => $start, 'e' => $end]);
    $coverage['titulos_sem_nf_vinculada'] = (int) $withoutNfStmt->fetchColumn();

    $coverage['titulos_com_vendedor']    = $summary['open_titles'] - $summary['titles_without_vendor'];
    $coverage['titulos_sem_vendedor']    = $summary['titles_without_vendor'];
    $coverage['valor_aberto_sem_vendedor'] = 0.0;

    // Calcular valor aberto sem vendedor via rows do payload
    foreach ($payload['nf_rows'] as $row) {
        if ($row['is_open'] && ($row['vendor_missing'] ?? false)) {
            $coverage['valor_aberto_sem_vendedor'] += (float) $row['valor_aberto'];
        }
    }
    $coverage['valor_aberto_sem_vendedor'] = round($coverage['valor_aberto_sem_vendedor'], 2);

    // ── Detecção de boleto ────────────────────────────────────────────────────
    $boletoDetection = [
        'boleto_detection_rule'   => 'normalizePaymentMethod(): (1) especie_documento [BOL/DUP]; (2) keywords forma_pagamento; (3) bol_sn da conta corrente (bronze Omie)',
        'boleto_titles_total'     => 0,
        'boleto_open_total'       => 0,
        'boleto_overdue_total'    => 0,
        'boleto_with_nf_total'    => 0,
        'boleto_without_nf_total' => 0,
        'boleto_with_vendor_total'   => 0,
        'boleto_without_vendor_total'=> 0,
    ];

    foreach ($payload['nf_rows'] as $row) {
        if (!$row['is_boleto']) continue;
        $boletoDetection['boleto_titles_total']++;
        if ($row['is_open']) {
            $boletoDetection['boleto_open_total']++;
            if ($row['nota_key'] !== '') $boletoDetection['boleto_with_nf_total']++;
            else $boletoDetection['boleto_without_nf_total']++;
            if (!$row['vendor_missing']) $boletoDetection['boleto_with_vendor_total']++;
            else $boletoDetection['boleto_without_vendor_total']++;
        }
        if ($row['is_overdue']) $boletoDetection['boleto_overdue_total']++;
    }

    // ── Cobertura de método de pagamento (FIN.R4.2) ───────────────────────────
    $methodCoverage = buildMethodCoverage($pdo, $payload['nf_rows'], $start, $end);

    // ── Métodos de pagamento ──────────────────────────────────────────────────
    $paymentMethods = [
        'ranking_by_value'    => $payload['payment_method_ranking'],
        'ranking_by_quantity' => [],
    ];

    $byQty = $payload['payment_method_ranking'];
    usort($byQty, static fn (array $a, array $b): int => ((int) $b['titles_count'] <=> (int) $a['titles_count']));
    $paymentMethods['ranking_by_quantity'] = $byQty;

    // ── Exemplos (se --include-examples) ─────────────────────────────────────
    $examples = [];
    if ($includeExamples) {
        $allRows = $payload['nf_rows'];

        $examples['nf_com_boleto_aberto'] = array_slice(
            array_values(array_filter($allRows, static fn (array $r): bool =>
                $r['is_open'] && $r['is_boleto'] && $r['nota_key'] !== '')),
            0, 5
        );
        $examples['titulo_aberto_sem_nf'] = array_slice(
            array_values(array_filter($allRows, static fn (array $r): bool =>
                $r['is_open'] && $r['nota_key'] === '')),
            0, 5
        );
        $examples['titulo_aberto_sem_vendedor'] = array_slice(
            array_values(array_filter($allRows, static fn (array $r): bool =>
                $r['is_open'] && ($r['vendor_missing'] ?? false))),
            0, 5
        );
        $examples['boleto_vencido_com_cliente'] = array_slice(
            array_values(array_filter($allRows, static fn (array $r): bool =>
                $r['is_overdue'] && $r['is_boleto'] && $r['client_key'] !== '__sem_cliente__')),
            0, 5
        );
        $examples['boleto_vencido_sem_cliente'] = array_slice(
            array_values(array_filter($allRows, static fn (array $r): bool =>
                $r['is_overdue'] && $r['is_boleto'] && $r['client_key'] === '__sem_cliente__')),
            0, 5
        );
        $examples['metodo_nao_informado'] = array_slice(
            array_values(array_filter($allRows, static fn (array $r): bool =>
                $r['normalized_payment_method'] === 'nao_informado')),
            0, 5
        );
    }

    $report = [
        'ok'      => true,
        'status'  => 'OK',
        'period'  => ['start' => $start, 'end' => $end, 'reference_date' => $today],
        'coverage' => array_merge($summary, $coverage),
        'payment_methods' => $paymentMethods,
        'boleto_detection' => $boletoDetection,
        'method_coverage'  => $methodCoverage,
        'column_inventory' => $columnInventory,
        'examples'  => $includeExamples ? $examples : ['note' => 'use --include-examples'],
        'diagnostics' => $payload['diagnostics'],
    ];
} catch (Throwable $exception) {
    $report = [
        'ok'     => false,
        'status' => 'CRITICAL',
        'period' => ['start' => $start ?? '-', 'end' => $end ?? '-'],
        'error'  => $exception->getMessage(),
    ];
}

if ($json) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(($report['ok'] ?? false) ? 0 : 1);
}

printPretty($report);
exit(($report['ok'] ?? false) ? 0 : 1);

// ── Helpers ───────────────────────────────────────────────────────────────────

/** @return array<string,mixed> */
function buildMethodCoverage(PDO $pdo, array $rows, string $start, string $end): array
{
    $total = 0;
    $withForma = $withEspecie = $withBolSn = $withPix = $naoInformado = 0;
    $methodDist = [];

    foreach ($rows as $row) {
        $total++;
        if (($row['raw_payment_method'] ?? '') !== '') $withForma++;
        if (($row['raw_document_species'] ?? '') !== '') $withEspecie++;
        $method = (string) ($row['normalized_payment_method'] ?? 'nao_informado');
        $methodDist[$method] = ($methodDist[$method] ?? 0) + 1;
        if ($method === 'nao_informado') $naoInformado++;
    }

    // Contar títulos com conta corrente configurada (bol_sn/pix_sn via bronze)
    $bronzeExists = false;
    try {
        $bronzeExists = (bool) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='biomie_omie_bronze'"
        )->fetchColumn();
    } catch (Throwable) {}

    $accountBoleto = $accountPix = $accountConfigured = 0;
    if ($bronzeExists) {
        $stmt = $pdo->query(
            "SELECT COALESCE(t.codigo_conta_corrente_omie,'') cc,
                    COALESCE(b.payload_json,'{}') payload
             FROM biomie_titulos_financeiros_canonicos t
             LEFT JOIN biomie_omie_bronze b
               ON b.omie_method = 'ListarContasCorrentes'
               AND CAST(JSON_UNQUOTE(JSON_EXTRACT(b.payload_json,'$.nCodCC')) AS CHAR) = t.codigo_conta_corrente_omie
             WHERE t.data_competencia BETWEEN " . $pdo->quote($start) . " AND " . $pdo->quote($end) . "
             GROUP BY t.codigo_conta_corrente_omie, b.payload_json"
        );
        if ($stmt) {
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $accRow) {
                $p = json_decode((string) $accRow['payload'], true);
                if (!is_array($p)) continue;
                if (strtolower(trim((string) ($p['bol_sn'] ?? ''))) === 's') $accountBoleto++;
                if (strtolower(trim((string) ($p['pix_sn'] ?? ''))) === 's') $accountPix++;
                if (($p['bol_sn'] ?? '') !== '' || ($p['pix_sn'] ?? '') !== '') $accountConfigured++;
            }
        }
    }

    // Canonical field stats
    $canonStmt = $pdo->prepare(
        "SELECT
            SUM(CASE WHEN forma_pagamento IS NOT NULL AND forma_pagamento != '' THEN 1 ELSE 0 END) wf,
            SUM(CASE WHEN especie_documento IS NOT NULL AND especie_documento != '' THEN 1 ELSE 0 END) we,
            COUNT(*) tot
         FROM biomie_titulos_financeiros_canonicos
         WHERE data_competencia BETWEEN :s AND :e"
    );
    $canonStmt->execute(['s' => $start, 'e' => $end]);
    $canon = $canonStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    arsort($methodDist);

    return [
        'total_rows'              => $total,
        'with_forma_pagamento_raw'  => (int) ($canon['wf'] ?? 0),
        'with_especie_documento_raw'=> (int) ($canon['we'] ?? 0),
        'canonical_total'          => (int) ($canon['tot'] ?? 0),
        'method_distribution'      => $methodDist,
        'nao_informado_count'      => $naoInformado,
        'nao_informado_pct'        => $total > 0 ? round($naoInformado / $total * 100, 1) : 0,
        'account_configured_bol'   => $accountBoleto,
        'account_configured_pix'   => $accountPix,
        'enrichment_source'        => 'ListarContasReceber (sync) + bol_sn/pix_sn (bronze conta corrente)',
        'enrichment_status'        => (int) ($canon['wf'] ?? 0) === 0
            ? 'PENDING — reprocessar sincronização financeira para popular forma_pagamento via ListarContasReceber'
            : 'OK — forma_pagamento preenchida; detecção via especie_documento e conta corrente ativa',
    ];
}

function getOpts(array $argv): array
{
    $out = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (!str_starts_with($arg, '--')) continue;
        [$key, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, true);
        $out[$key] = $value;
    }
    return $out;
}

function validateDate(string $value, string $label): void
{
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    if (!$dt || $dt->format('Y-m-d') !== $value) {
        throw new InvalidArgumentException("{$label} deve estar em YYYY-MM-DD.");
    }
}

function printPretty(array $report): void
{
    $hr = str_repeat('─', 74);
    echo PHP_EOL . '═══ Finance Receivable Trace Audit — FIN.R4.1+R4.2 ═══' . PHP_EOL;
    echo 'Status: ' . ($report['status'] ?? 'UNKNOWN') . PHP_EOL;
    $p = $report['period'] ?? [];
    echo 'Período: ' . ($p['start'] ?? '-') . ' → ' . ($p['end'] ?? '-') . PHP_EOL . PHP_EOL;

    if (!($report['ok'] ?? false)) {
        echo 'Erro: ' . ($report['error'] ?? 'desconhecido') . PHP_EOL;
        return;
    }

    echo $hr . PHP_EOL . 'COBERTURA GERAL' . PHP_EOL . $hr . PHP_EOL;
    $coverage = $report['coverage'] ?? [];
    $fields = [
        'total_titulos_periodo'          => 'Total de títulos no período',
        'titulos_abertos'                => 'Títulos em aberto',
        'titulos_vencidos'               => 'Títulos vencidos',
        'titulos_a_vencer'               => 'Títulos a vencer',
        'titulos_com_nota_key_direto'    => 'Títulos com nota_key direto',
        'titulos_com_nf_via_bridge'      => 'Títulos com NF via bridge',
        'titulos_sem_nf_vinculada'       => 'Títulos sem NF vinculada',
        'titles_with_nf'                 => 'Títulos abertos com NF',
        'titles_without_nf'              => 'Títulos abertos sem NF',
        'titles_without_vendor'          => 'Títulos abertos sem vendedor',
        'valor_aberto_sem_vendedor'      => 'Valor aberto sem vendedor (R$)',
        'nfs_with_open_boleto'           => 'NFs com boleto em aberto',
        'boleto_open_total'              => 'Boletos em aberto',
        'boleto_overdue_total'           => 'Boletos vencidos',
        'max_delay_days'                 => 'Maior atraso (dias)',
        'top_payment_method'             => 'Método de pagamento principal',
    ];
    foreach ($fields as $key => $label) {
        if (!array_key_exists($key, $coverage)) continue;
        $val = $coverage[$key];
        $formatted = is_float($val) ? 'R$ ' . number_format($val, 2, ',', '.') : (string) ($val ?? 'N/A');
        echo '  ' . str_pad($label, 42) . ': ' . $formatted . PHP_EOL;
    }

    echo PHP_EOL . $hr . PHP_EOL . 'DETECÇÃO DE BOLETO' . PHP_EOL . $hr . PHP_EOL;
    $bd = $report['boleto_detection'] ?? [];
    echo '  Regra         : ' . ($bd['boleto_detection_rule'] ?? '-') . PHP_EOL;
    echo '  Total boletos : ' . ($bd['boleto_titles_total'] ?? 0) . PHP_EOL;
    echo '  Boleto aberto : ' . ($bd['boleto_open_total'] ?? 0) . PHP_EOL;
    echo '  Boleto vencido: ' . ($bd['boleto_overdue_total'] ?? 0) . PHP_EOL;
    echo '  Com NF        : ' . ($bd['boleto_with_nf_total'] ?? 0) . PHP_EOL;
    echo '  Sem NF        : ' . ($bd['boleto_without_nf_total'] ?? 0) . PHP_EOL;
    echo '  Com vendedor  : ' . ($bd['boleto_with_vendor_total'] ?? 0) . PHP_EOL;
    echo '  Sem vendedor  : ' . ($bd['boleto_without_vendor_total'] ?? 0) . PHP_EOL;

    echo PHP_EOL . $hr . PHP_EOL . 'TOP 10 MÉTODOS DE PAGAMENTO (por valor total)' . PHP_EOL . $hr . PHP_EOL;
    $ranking = $report['payment_methods']['ranking_by_value'] ?? [];
    foreach (array_slice($ranking, 0, 10) as $i => $m) {
        echo '  ' . ($i + 1) . '. ' . str_pad($m['method_label'] ?? '-', 20)
            . ' | Total: R$ ' . number_format((float) ($m['total_value'] ?? 0), 2, ',', '.')
            . ' | Aberto: R$ ' . number_format((float) ($m['open_value'] ?? 0), 2, ',', '.')
            . ' | Títulos: ' . ($m['titles_count'] ?? 0)
            . PHP_EOL;
    }

    // ── FIN.R4.2 Method Coverage
    $mc = $report['method_coverage'] ?? [];
    if ($mc !== []) {
        echo PHP_EOL . $hr . PHP_EOL . 'COBERTURA DE MÉTODO DE PAGAMENTO (FIN.R4.2)' . PHP_EOL . $hr . PHP_EOL;
        $tot = (int) ($mc['canonical_total'] ?? 0);
        $wf  = (int) ($mc['with_forma_pagamento_raw'] ?? 0);
        $we  = (int) ($mc['with_especie_documento_raw'] ?? 0);
        $ni  = (int) ($mc['nao_informado_count'] ?? 0);
        $niPct = (float) ($mc['nao_informado_pct'] ?? 0);
        echo sprintf("  Total títulos no período         : %d\n", $tot);
        echo sprintf("  forma_pagamento preenchido (raw) : %d (%.1f%%)\n", $wf, $tot > 0 ? round($wf / $tot * 100, 1) : 0);
        echo sprintf("  especie_documento preenchido     : %d (%.1f%%)\n", $we, $tot > 0 ? round($we / $tot * 100, 1) : 0);
        echo sprintf("  Método nao_informado             : %d (%.1f%%)\n", $ni, $niPct);
        echo sprintf("  Contas config. boleto (bol_sn=S) : %d\n", $mc['account_configured_bol'] ?? 0);
        echo sprintf("  Contas config. PIX (pix_sn=S)   : %d\n", $mc['account_configured_pix'] ?? 0);
        echo PHP_EOL . '  Distribuição de métodos detectados:' . PHP_EOL;
        foreach ($mc['method_distribution'] ?? [] as $m => $cnt) {
            echo sprintf("    %-25s : %d\n", $m, $cnt);
        }
        echo PHP_EOL . '  Status enriquecimento: ' . ($mc['enrichment_status'] ?? '-') . PHP_EOL;
        echo '  Fonte: ' . ($mc['enrichment_source'] ?? '-') . PHP_EOL;
    }

    echo PHP_EOL . $hr . PHP_EOL . 'INVENTÁRIO DE TABELAS' . PHP_EOL . $hr . PHP_EOL;
    foreach ($report['column_inventory'] ?? [] as $table => $info) {
        $exists = $info['exists'] ? 'OK' : 'AUSENTE';
        $cols   = $info['exists'] ? count($info['columns']) . ' colunas' : '';
        echo '  [' . $exists . '] ' . $table . ($cols ? " ({$cols})" : '') . PHP_EOL;
    }

    $examples = $report['examples'] ?? [];
    if (isset($examples['nf_com_boleto_aberto'])) {
        echo PHP_EOL . $hr . PHP_EOL . 'EXEMPLOS: NFs com boleto aberto' . PHP_EOL . $hr . PHP_EOL;
        foreach ($examples['nf_com_boleto_aberto'] as $ex) {
            echo '  NF: ' . ($ex['nota_key'] ?? '-') . ' | Cliente: ' . ($ex['cliente_nome'] ?? '-')
                . ' | Vendedor: ' . ($ex['vendedor_nome'] ?? '-')
                . ' | Aberto: R$ ' . number_format((float) ($ex['valor_aberto'] ?? 0), 2, ',', '.')
                . PHP_EOL;
        }
    }
}
