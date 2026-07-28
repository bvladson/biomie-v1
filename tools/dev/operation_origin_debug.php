<?php

declare(strict_types=1);

/**
 * Fase H.3 — T3: Auditoria de proveniência de origem Omie por NF.
 *
 * Para cada NF canonical (autorizada), busca o payload bronze e determina:
 *   - CFOP do primeiro item
 *   - nIdPedido vinculado
 *   - operação fiscal (finNFe, natOp)
 *   - escopo atual vs escopo recomendado (baseado em CFOP)
 *   - se aparece como conflito
 *
 * Não chama Omie em tempo real. Usa biomie_omie_bronze + canonical.
 *
 * Uso:
 *   php tools/dev/operation_origin_debug.php
 *   php tools/dev/operation_origin_debug.php --json
 *   php tools/dev/operation_origin_debug.php --nf=00001286
 *   php tools/dev/operation_origin_debug.php --cfop=5.915
 *   php tools/dev/operation_origin_debug.php --scope=commercial   (filtrar por scope atual)
 *   php tools/dev/operation_origin_debug.php --conflict           (só NFs com scope divergente)
 */

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Database;
use BIomie\Support\OmieOperationScope;

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
Bootstrap::init();

$opts       = getopt('', ['json', 'nf:', 'cfop:', 'scope:', 'conflict']);
$isJson     = isset($opts['json']);
$filterNf   = isset($opts['nf'])    ? trim((string) $opts['nf'])    : null;
$filterCfop = isset($opts['cfop'])  ? trim((string) $opts['cfop'])  : null;
$filterScope = isset($opts['scope']) ? trim((string) $opts['scope']) : null;
$onlyConflict = isset($opts['conflict']);

$config     = Config::fromEnvironment();
$connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
if (!Database::isConfigured($config, $connection)) {
    fwrite(STDERR, "ERRO: Banco nao configurado.\n");
    exit(2);
}
$pdo = Database::connect($config, $connection);
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

// ── Build CFOP lookup from bronze (nIdNF → CFOP, nIdPedido, endpoint) ──────────

$bronzeMap = []; // nota_key → ['cfop', 'nid_pedido', 'fin_nfe', 'nat_op', 'endpoint']

$stmtBronze = $pdo->query("
    SELECT DISTINCT
        CONCAT('omie:', JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.compl.nIdNF'))) AS nota_key,
        JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.det[0].prod.CFOP'))              AS cfop,
        JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.compl.nIdPedido'))               AS nid_pedido,
        JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.ide.finNFe'))                    AS fin_nfe,
        COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.ide.natOp')), '')       AS nat_op,
        endpoint
    FROM biomie_omie_bronze
    WHERE domain_name = 'invoices'
      AND JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.compl.nIdNF')) IS NOT NULL
");
foreach ($stmtBronze->fetchAll(\PDO::FETCH_ASSOC) as $row) {
    $bronzeMap[(string)$row['nota_key']] = $row;
}

// ── Fetch canonical NFs ────────────────────────────────────────────────────────

$whereClause = "WHERE status_fiscal = 'autorizada'";
$params = [];
if ($filterNf !== null) {
    $whereClause .= ' AND numero_nf = :nf';
    $params[':nf'] = $filterNf;
}
if ($filterScope !== null) {
    $whereClause .= ' AND operation_scope = :scope';
    $params[':scope'] = $filterScope;
}

$stmtCanon = $pdo->prepare("
    SELECT nota_key, numero_nf, serie_nf, pedido_id_omie,
           operation_scope, operation_scope_rule, operation_scope_confidence,
           omie_operation_name, valor_total, data_emissao, nome_cliente
    FROM biomie_notas_fiscais_canonicas
    $whereClause
    ORDER BY data_emissao DESC, numero_nf DESC
");
$stmtCanon->execute($params);
$canonNfs = $stmtCanon->fetchAll(\PDO::FETCH_ASSOC);

// ── Build report rows ──────────────────────────────────────────────────────────

$rows = [];
$stats = [
    'total'              => 0,
    'found_in_bronze'    => 0,
    'cfop_commercial'    => 0,
    'cfop_remittance'    => 0,
    'cfop_devolucao'     => 0,
    'cfop_unknown'       => 0,
    'cfop_not_found'     => 0,
    'conflicts'          => 0,
    'scopes_before'      => [],
    'scopes_after'       => [],
];

foreach ($canonNfs as $nf) {
    $stats['total']++;
    $notaKey   = (string) $nf['nota_key'];
    $bronze    = $bronzeMap[$notaKey] ?? null;

    $cfop         = $bronze['cfop']       ?? null;
    $nidPedido    = $bronze['nid_pedido'] ?? null;
    $finNfe       = $bronze['fin_nfe']    ?? null;
    $natOp        = $bronze['nat_op']     ?? '';
    $endpoint     = $bronze['endpoint']   ?? null;

    if ($bronze !== null) {
        $stats['found_in_bronze']++;
    }

    // Derive recommended scope
    $recommendedLineage = null;
    if ($bronze !== null) {
        $fakeInvoice = [
            'ide' => ['natOp' => $natOp, 'finNFe' => $finNfe],
            'det' => [['prod' => ['CFOP' => $cfop]]],
        ];
        $recommendedLineage = OmieOperationScope::classifyInvoice($fakeInvoice);
    }

    $recommendedScope      = $recommendedLineage['scope']      ?? 'no_bronze';
    $recommendedConfidence = $recommendedLineage['confidence'] ?? '';
    $recommendedRule       = $recommendedLineage['rule']       ?? '';

    $currentScope = (string) $nf['operation_scope'];
    $conflict     = ($bronze !== null) && ($recommendedScope !== $currentScope);

    if ($filterCfop !== null && $cfop !== $filterCfop) {
        continue;
    }
    if ($onlyConflict && !$conflict) {
        continue;
    }

    // Stats
    $stats['scopes_before'][$currentScope] = ($stats['scopes_before'][$currentScope] ?? 0) + 1;
    $stats['scopes_after'][$recommendedScope] = ($stats['scopes_after'][$recommendedScope] ?? 0) + 1;

    if ($conflict) {
        $stats['conflicts']++;
    }

    if ($cfop !== null) {
        $cfopGroup = match (true) {
            in_array($cfop, OmieOperationScope::remittanceCfops(), true) => 'cfop_remittance',
            in_array($cfop, OmieOperationScope::commercialCfops(), true) => 'cfop_commercial',
            in_array($cfop, OmieOperationScope::devolucaoCfops(), true)  => 'cfop_devolucao',
            default => 'cfop_unknown',
        };
        $stats[$cfopGroup]++;
    } else {
        $stats['cfop_not_found']++;
    }

    $rows[] = [
        'nota_key'               => $notaKey,
        'numero_nf'              => $nf['numero_nf'],
        'serie'                  => $nf['serie_nf'],
        'data_emissao'           => $nf['data_emissao'],
        'cliente'                => $nf['nome_cliente'],
        'valor_total'            => (float) $nf['valor_total'],
        'pedido_id_omie'         => $nf['pedido_id_omie'],
        'bronze_nid_pedido'      => $nidPedido,
        'cfop'                   => $cfop,
        'fin_nfe'                => $finNfe,
        'nat_op'                 => $natOp ?: null,
        'endpoint'               => $endpoint,
        'current_scope'          => $currentScope,
        'current_rule'           => $nf['operation_scope_rule'],
        'current_confidence'     => $nf['operation_scope_confidence'],
        'recommended_scope'      => $recommendedScope,
        'recommended_rule'       => $recommendedRule,
        'recommended_confidence' => $recommendedConfidence,
        'conflict'               => $conflict,
    ];
}

// ── Output ─────────────────────────────────────────────────────────────────────

if ($isJson) {
    echo json_encode([
        'stats'  => $stats,
        'rows'   => $rows,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

// Text output
echo "\n=== operation_origin_debug (Fase H.3) ===\n";

if ($filterNf)     { echo "Filtro NF: $filterNf\n"; }
if ($filterCfop)   { echo "Filtro CFOP: $filterCfop\n"; }
if ($filterScope)  { echo "Filtro scope: $filterScope\n"; }
if ($onlyConflict) { echo "Filtro: somente conflitos\n"; }

echo "\nESTATISTICAS:\n";
printf("  Total NFs:            %d\n", $stats['total']);
printf("  Encontradas no bronze: %d\n", $stats['found_in_bronze']);
printf("  CFOP commercial:      %d\n", $stats['cfop_commercial']);
printf("  CFOP remittance:      %d\n", $stats['cfop_remittance']);
printf("  CFOP devolucao:       %d\n", $stats['cfop_devolucao']);
printf("  CFOP desconhecido:    %d\n", $stats['cfop_unknown']);
printf("  Sem CFOP (sem bronze): %d\n", $stats['cfop_not_found']);
printf("  Conflitos:            %d\n", $stats['conflicts']);

echo "\n  Escopo atual:\n";
foreach ($stats['scopes_before'] as $s => $n) {
    printf("    %-15s  %d\n", $s, $n);
}
echo "\n  Escopo recomendado:\n";
foreach ($stats['scopes_after'] as $s => $n) {
    printf("    %-15s  %d\n", $s, $n);
}

if ($rows !== []) {
    echo "\nDETALHES:\n";
    printf("%-20s %-8s %-5s %-7s %-6s %-18s %-18s %s\n",
        'nota_key', 'numero_nf', 'cfop', 'finNFe', 'valor', 'current_scope→rule', 'rec_scope→rule', 'conflict');
    foreach ($rows as $r) {
        $currentLabel    = $r['current_scope'] . '/' . ($r['current_rule'] ?? '?');
        $recommendLabel  = $r['recommended_scope'] . '/' . $r['recommended_rule'];
        $conflictIcon    = $r['conflict'] ? '<<< CONFLICT' : '';
        printf("%-20s %-8s %-5s %-7s %6.2f %-30s %-30s %s\n",
            substr((string)$r['nota_key'], 0, 20),
            $r['numero_nf'],
            (string) $r['cfop'],
            (string) $r['fin_nfe'],
            $r['valor_total'],
            substr($currentLabel, 0, 30),
            substr($recommendLabel, 0, 30),
            $conflictIcon
        );
    }
}

echo "\n";
