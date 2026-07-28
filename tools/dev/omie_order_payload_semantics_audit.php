<?php

declare(strict_types=1);

/**
 * COM.R1.1 FASE — Investigação Bronze das Etapas Omie, Varejo/Revenda e Orçamentos Faturados
 *
 * Investiga os payloads bronze/raw do Omie para identificar os campos reais que
 * distinguem as etapas comerciais e sub-tipos de pedido (Varejo vs Revenda).
 *
 * Achados da FASE 0 que este script investiga:
 *   - etapa_omie armazena códigos numéricos (10/20/50/60/70/80)
 *   - etapa_omie=60 mapeada como "faturado", mas é Entrega Finalizada
 *   - "Pedidos Varejo" e "Pedidos Revenda" não estão na camada canônica
 *   - status_pedido_omie sempre vazio
 *   - 14 orçamentos (etapa 10) com NF vinculada
 *
 * Uso:
 *   php tools/dev/omie_order_payload_semantics_audit.php \
 *       [--start=YYYY-MM-DD] [--end=YYYY-MM-DD] \
 *       [--pretty] [--json] [--sample=20] \
 *       [--stage=10|20|50|60|70|80] [--search-key=texto]
 *
 * Read-only. Sem escrita no banco, sem migrações, sem alterações.
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Config;
use BIomie\Database;

// ── CLI args ───────────────────────────────────────────────────────────────────
$opts       = getopt('', ['start:', 'end:', 'pretty', 'json', 'sample:', 'stage:', 'search-key:']);
$start      = (string) ($opts['start']      ?? date('Y-01-01'));
$end        = (string) ($opts['end']        ?? date('Y-m-d'));
$pretty     = isset($opts['pretty']);
$jsonMode   = isset($opts['json']);
$sampleN    = max(1, min(50, (int) ($opts['sample']     ?? 10)));
$filterStage = isset($opts['stage'])      ? (string) $opts['stage']      : null;
$searchKey  = isset($opts['search-key'])  ? (string) $opts['search-key'] : null;

$t0 = microtime(true);

// ── Helpers ───────────────────────────────────────────────────────────────────
function sep(string $title = ''): void {
    echo PHP_EOL . str_repeat('─', 74) . PHP_EOL;
    if ($title !== '') echo "  {$title}" . PHP_EOL . str_repeat('─', 74) . PHP_EOL;
}
function row(string $lbl, mixed $v, int $w = 36): void {
    echo '  ' . str_pad($lbl . ':', $w) . $v . PHP_EOL;
}
function fmtN(int $n): string { return number_format($n, 0, ',', '.'); }
function fmtR(float $v): string { return 'R$ ' . number_format($v, 2, ',', '.'); }

/**
 * Recursively collect all leaf key paths and their values from a nested array.
 * @return array<string,string[]>  path => [value1, value2, ...]
 */
function collectLeaves(mixed $node, string $prefix = '', array &$out = []): array {
    if (!is_array($node)) {
        $val = is_string($node) ? $node : json_encode($node);
        $out[$prefix][] = (string) $val;
        return $out;
    }
    foreach ($node as $k => $v) {
        $path = $prefix !== '' ? "{$prefix}.{$k}" : (string) $k;
        if (is_array($v) && array_is_list($v) && isset($v[0]) && is_array($v[0])) {
            // Array of objects (e.g., det[] items) — only show first
            collectLeaves($v[0], "{$path}[0]", $out);
        } elseif (is_array($v)) {
            collectLeaves($v, $path, $out);
        } else {
            $val = is_bool($v) ? ($v ? 'true' : 'false') : (string) $v;
            $out[$path][] = $val;
        }
    }
    return $out;
}

/** Safe JSON decode, returns null on failure. */
function tryJson(string $json): ?array {
    try {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : null;
    } catch (\Throwable) {
        return null;
    }
}

/** Mask CPF/CNPJ for display. */
function maskDoc(string $doc): string {
    $doc = preg_replace('/\D/', '', $doc);
    if (strlen($doc) === 11) return substr($doc, 0, 3) . '.***.***-' . substr($doc, -2);
    if (strlen($doc) === 14) return substr($doc, 0, 2) . '.***.***/' . substr($doc, 8, 4) . '-' . substr($doc, -2);
    return $doc !== '' ? substr($doc, 0, 3) . '***' : '';
}

// ── Database ───────────────────────────────────────────────────────────────────
try {
    $config = new Config();
    $conn   = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    if (!Database::isConfigured($config, $conn)) {
        fwrite(STDERR, 'ERROR: Banco não configurado.' . PHP_EOL);
        exit(1);
    }
    $pdo = Database::connect($config, $conn);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (\Throwable $e) {
    fwrite(STDERR, 'DB: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

// Check tables exist
function tableExists(PDO $pdo, string $tbl): bool {
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t LIMIT 1");
    $s->execute([':t' => $tbl]);
    return (int) $s->fetchColumn() > 0;
}

$hasBronze   = tableExists($pdo, 'biomie_omie_bronze');
$hasCanonical = tableExists($pdo, 'biomie_pedidos_canonicos');
$hasNfs      = tableExists($pdo, 'biomie_notas_fiscais_canonicas');

// ── SECTION 1 — Bronze inventory ──────────────────────────────────────────────
$bronzeInventory = [];
if ($hasBronze) {
    // 1a: All entity types in bronze
    $s1a = $pdo->query(
        "SELECT entity_name, omie_method, domain_name,
                COUNT(*) AS cnt,
                MIN(ingested_at) AS first_at,
                MAX(ingested_at) AS last_at
         FROM biomie_omie_bronze
         GROUP BY entity_name, omie_method, domain_name
         ORDER BY cnt DESC
         LIMIT 40"
    );
    $bronzeInventory['entities'] = $s1a->fetchAll(PDO::FETCH_ASSOC);

    // 1b: Order-related bronze records
    $s1b = $pdo->prepare(
        "SELECT entity_name, omie_method, COUNT(*) AS cnt,
                MIN(ingested_at) AS first_at, MAX(ingested_at) AS last_at
         FROM biomie_omie_bronze
         WHERE entity_name IN ('order','pedido','pedido_venda','orcamento','order_event')
            OR omie_method LIKE '%Pedido%'
            OR omie_method LIKE '%Orcamento%'
            OR omie_method LIKE '%Etapa%'
         GROUP BY entity_name, omie_method
         ORDER BY cnt DESC"
    );
    $s1b->execute();
    $bronzeInventory['order_entities'] = $s1b->fetchAll(PDO::FETCH_ASSOC);
}

// ── SECTION 2 — Canonical stats per etapa ─────────────────────────────────────
$periodClause = "COALESCE(p.data_faturamento, p.data_emissao) BETWEEN :start AND :end";
$stageFilter  = $filterStage !== null ? " AND p.etapa_omie = :stage" : '';
$stageParams  = [':start' => $start, ':end' => $end];
if ($filterStage !== null) $stageParams[':stage'] = $filterStage;

$s2 = $pdo->prepare(
    "SELECT p.etapa_omie,
            p.status_comercial,
            COUNT(DISTINCT p.id) AS total_pedidos,
            SUM(p.valor_total) AS valor_total,
            COUNT(DISTINCT nf.nota_key) AS total_nfs,
            COUNT(DISTINCT CASE WHEN nf.status_fiscal = 'autorizada' THEN nf.nota_key END) AS nfs_autorizadas
     FROM biomie_pedidos_canonicos p
     LEFT JOIN biomie_notas_fiscais_canonicas nf ON nf.codigo_pedido_origem = p.codigo_pedido
     WHERE {$periodClause}{$stageFilter}
     GROUP BY p.etapa_omie, p.status_comercial
     ORDER BY total_pedidos DESC
     LIMIT 30"
);
$s2->execute($stageParams);
$canonicalByStage = $s2->fetchAll(PDO::FETCH_ASSOC);

// ── SECTION 3 — Bronze payload inspection per etapa ───────────────────────────
$payloadFields = [];   // stage => [path => [values]]
$stageCoverage = [];   // stage => ['total'=>N, 'with_bronze'=>N, 'payload_keys'=>[]]
$samplePayloads = []; // stage => [rows]

if ($hasBronze && $hasCanonical) {
    $stagesInspect = $filterStage !== null ? [$filterStage] : ['10','20','50','60','70','80'];

    foreach ($stagesInspect as $stage) {
        // Fetch sample canonical orders for this stage
        $sCanon = $pdo->prepare(
            "SELECT p.id, p.pedido_id_omie, p.codigo_pedido, p.etapa_omie,
                    p.status_comercial, p.nome_vendedor, p.nome_cliente,
                    p.documento_cliente,
                    p.codigo_integracao, p.status_pedido_omie,
                    p.data_emissao, p.data_faturamento, p.valor_total,
                    nf.numero_nf, nf.chave_nfe, nf.status_fiscal AS nf_status,
                    nf.data_emissao AS nf_data_emissao
             FROM biomie_pedidos_canonicos p
             LEFT JOIN biomie_notas_fiscais_canonicas nf
                   ON nf.codigo_pedido_origem = p.codigo_pedido
             WHERE {$periodClause}
               AND p.etapa_omie = :stage
             ORDER BY p.valor_total DESC
             LIMIT :lim"
        );
        $sCanon->bindValue(':start', $start,    PDO::PARAM_STR);
        $sCanon->bindValue(':end',   $end,       PDO::PARAM_STR);
        $sCanon->bindValue(':stage', $stage,     PDO::PARAM_STR);
        $sCanon->bindValue(':lim',   $sampleN,   PDO::PARAM_INT);
        $sCanon->execute();
        $canonRows = $sCanon->fetchAll(PDO::FETCH_ASSOC);

        if (!$canonRows) {
            $stageCoverage[$stage] = ['total' => 0, 'with_bronze' => 0, 'payload_keys' => []];
            continue;
        }

        $pedidoIds = array_column($canonRows, 'pedido_id_omie');
        $pedidoIds = array_filter($pedidoIds, fn($id) => $id !== null && $id > 0);

        // Find bronze records for these pedidos
        $bronzeRows = [];
        if ($pedidoIds && $hasBronze) {
            $placeholders = implode(',', array_fill(0, count($pedidoIds), '?'));
            $sBronze = $pdo->prepare(
                "SELECT b.natural_key, b.provider_key, b.entity_name, b.omie_method,
                        b.payload_json, b.ingested_at
                 FROM biomie_omie_bronze b
                 WHERE b.entity_name IN ('order','pedido_venda','pedido','orcamento','order_event')
                   AND (b.natural_key IN ({$placeholders})
                        OR b.provider_key IN ({$placeholders}))
                 ORDER BY b.ingested_at DESC
                 LIMIT 50"
            );
            $allIds = array_merge(array_map('strval', $pedidoIds), array_map('strval', $pedidoIds));
            $sBronze->execute($allIds);
            $bronzeRows = $sBronze->fetchAll(PDO::FETCH_ASSOC);
        }

        // Also try searching by codigo_pedido as natural_key
        $codigoPedidos = array_column($canonRows, 'codigo_pedido');
        $codigoPedidos = array_filter($codigoPedidos, fn($c) => $c !== '' && $c !== null);
        if ($codigoPedidos && $hasBronze && !$bronzeRows) {
            $placeholders2 = implode(',', array_fill(0, count($codigoPedidos), '?'));
            $sBronze2 = $pdo->prepare(
                "SELECT b.natural_key, b.provider_key, b.entity_name, b.omie_method,
                        b.payload_json, b.ingested_at
                 FROM biomie_omie_bronze b
                 WHERE b.entity_name IN ('order','pedido_venda','pedido','orcamento','order_event')
                   AND b.natural_key IN ({$placeholders2})
                 ORDER BY b.ingested_at DESC
                 LIMIT 50"
            );
            $sBronze2->execute(array_values($codigoPedidos));
            $bronzeRows = array_merge($bronzeRows, $sBronze2->fetchAll(PDO::FETCH_ASSOC));
        }

        // Parse payload fields from bronze records
        $allLeaves = [];
        foreach ($bronzeRows as $br) {
            $payload = tryJson($br['payload_json'] ?? '');
            if ($payload === null) continue;
            collectLeaves($payload, '', $allLeaves);
        }

        // Aggregate leaf paths
        $aggregated = [];
        foreach ($allLeaves as $path => $vals) {
            // Normalize path
            $vals = array_unique(array_filter($vals, fn($v) => $v !== '' && $v !== 'null'));
            if (!$vals) continue;
            $aggregated[$path] = [
                'count'    => count($vals),
                'examples' => array_slice(array_values($vals), 0, 3),
            ];
        }
        ksort($aggregated);

        $payloadFields[$stage] = $aggregated;
        $stageCoverage[$stage] = [
            'total'           => count($canonRows),
            'with_bronze'     => count($bronzeRows),
            'bronze_entities' => array_unique(array_column($bronzeRows, 'entity_name')),
            'payload_keys'    => count($aggregated),
        ];
        $samplePayloads[$stage] = $canonRows;
    }
}

// ── SECTION 4 — Candidate field detection ─────────────────────────────────────
/**
 * Fields that might carry: stage label, business unit (varejo/revenda), tipo, canal.
 * We search all payload keys for patterns matching these concepts.
 */
$CANDIDATE_PATTERNS = [
    'stage_label'   => ['etapa_faturamento', 'descricao_etapa', 'cNomePos', 'cNomeEtapa', 'cEtapaFat',
                        'descricao', 'nome_etapa', 'label', 'situacao_desc', 'status_desc'],
    'stage_code'    => ['cEtapa', 'codigo_etapa', 'codigo_etapa_faturamento', 'etapa_faturamento_codigo'],
    'tipo_pedido'   => ['tipo_pedido', 'cTipoPedido', 'tipo_venda', 'canal', 'cCanal', 'categoria',
                        'codigo_categoria', 'cCategoria', 'origem', 'cOrigem', 'operacao', 'cOperacao'],
    'varejo_revenda'=> ['varejo', 'revenda', 'revendedor', 'canal_venda', 'tipo_cliente', 'perfil_cliente'],
    'user_track'    => ['uInc', 'uAlt', 'incluido_por', 'usuario_inclusao', 'criado_por', 'alterado_por'],
    'obs'           => ['observacoes', 'observacao', 'informacoes_adicionais', 'obs', 'tags'],
];

$candidateFields = [];
foreach ($payloadFields as $stage => $fields) {
    foreach ($CANDIDATE_PATTERNS as $category => $patterns) {
        foreach ($fields as $path => $info) {
            $pathLower = mb_strtolower($path);
            foreach ($patterns as $pat) {
                if (str_contains($pathLower, mb_strtolower($pat))) {
                    $candidateFields[$category][$path] = $candidateFields[$category][$path] ?? [
                        'path'     => $path,
                        'stages'   => [],
                        'examples' => [],
                        'count'    => 0,
                    ];
                    $candidateFields[$category][$path]['stages'][] = $stage;
                    $candidateFields[$category][$path]['stages'] = array_unique($candidateFields[$category][$path]['stages']);
                    $candidateFields[$category][$path]['examples'] = array_unique(array_merge(
                        $candidateFields[$category][$path]['examples'],
                        $info['examples']
                    ));
                    $candidateFields[$category][$path]['count'] += $info['count'];
                }
            }
        }
    }
}

// ── SECTION 5 — Varejo/Revenda search in payloads ────────────────────────────
$VAREJO_REVENDA_TERMS = ['varejo', 'revenda', 'revendedor', 'canal', 'tipo_pedido', 'tipo_venda', 'b2b', 'b2c', 'atacado'];
$vaRejeMatches = [];

foreach ($payloadFields as $stage => $fields) {
    foreach ($fields as $path => $info) {
        foreach ($info['examples'] as $val) {
            $valLower = mb_strtolower($val);
            foreach ($VAREJO_REVENDA_TERMS as $term) {
                if (str_contains($valLower, $term) || str_contains(mb_strtolower($path), $term)) {
                    $vaRejeMatches[] = [
                        'stage'   => $stage,
                        'path'    => $path,
                        'value'   => $val,
                        'term'    => $term,
                        'count'   => $info['count'],
                    ];
                }
            }
        }
    }
}

// ── SECTION 6 — Orçamentos (etapa 10) com NF vinculada ───────────────────────
$quoteWithNf = [];
if ($hasCanonical && $hasNfs) {
    $s6 = $pdo->prepare(
        "SELECT p.pedido_id_omie, p.codigo_pedido, p.etapa_omie, p.status_comercial,
                p.nome_vendedor, p.nome_cliente, p.documento_cliente,
                p.data_emissao AS pedido_date, p.data_faturamento,
                p.valor_total,
                nf.numero_nf, nf.chave_nfe, nf.status_fiscal,
                nf.data_emissao AS nf_emissao, nf.data_competencia,
                p.sincronizado_em AS canonical_synced_at
         FROM biomie_pedidos_canonicos p
         INNER JOIN biomie_notas_fiscais_canonicas nf ON nf.codigo_pedido_origem = p.codigo_pedido
         WHERE COALESCE(p.data_faturamento, p.data_emissao) BETWEEN :start AND :end
           AND p.etapa_omie = '10'
         ORDER BY p.valor_total DESC
         LIMIT 30"
    );
    $s6->execute([':start' => $start, ':end' => $end]);
    $rawQnf = $s6->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rawQnf as $r) {
        // Check bronze history for this pedido — has it ever had a different etapa?
        $bronzeHistory = [];
        if ($hasBronze && $r['pedido_id_omie']) {
            $sHist = $pdo->prepare(
                "SELECT b.ingested_at, b.entity_name,
                        JSON_UNQUOTE(JSON_EXTRACT(b.payload_json, '$.cabecalho.etapa'))   AS etapa_hist,
                        JSON_UNQUOTE(JSON_EXTRACT(b.payload_json, '$.cEtapa'))            AS cEtapa_hist,
                        JSON_UNQUOTE(JSON_EXTRACT(b.payload_json, '$.status_pedido.cEtapa')) AS sp_etapa_hist
                 FROM biomie_omie_bronze b
                 WHERE b.natural_key = :nk
                    OR b.provider_key = :pk
                 ORDER BY b.ingested_at ASC
                 LIMIT 10"
            );
            $nk = (string) $r['pedido_id_omie'];
            $sHist->execute([':nk' => $nk, ':pk' => $nk]);
            $bronzeHistory = $sHist->fetchAll(PDO::FETCH_ASSOC);
        }

        $etapas = array_filter(array_unique(array_merge(
            array_column($bronzeHistory, 'etapa_hist'),
            array_column($bronzeHistory, 'cEtapa_hist'),
            array_column($bronzeHistory, 'sp_etapa_hist'),
        )), fn($v) => $v !== null && $v !== '' && $v !== 'null');

        // Classify the anomaly
        $nfDate   = $r['nf_emissao']    ?? '';
        $pedDate  = $r['pedido_date']   ?? $r['data_faturamento'] ?? '';
        $synced   = $r['canonical_synced_at'] ?? '';

        if (count($etapas) > 1) {
            $class = 'snapshot_stale';
            $reason = 'bronze_history_shows_stage_changed: [' . implode('→', $etapas) . ']';
        } elseif ($nfDate !== '' && $pedDate !== '' && $nfDate > $pedDate) {
            $class = 'converted_after_quote';
            $reason = 'nf_emissao(' . $nfDate . ') > pedido_date(' . $pedDate . ') — pedido faturado depois do orçamento';
        } elseif ($nfDate !== '' && $pedDate === '') {
            $class = 'canonical_mapping_bug';
            $reason = 'NF existe mas pedido sem data — possível erro de mapeamento canônico';
        } elseif (!$bronzeHistory) {
            $class = 'missing_stage_update';
            $reason = 'sem bronze history — não é possível verificar se etapa foi atualizada';
        } else {
            $class = 'unknown';
            $reason = 'etapa 10 mantida mesmo com NF — possível sync stale ou regra de snapshot';
        }

        $quoteWithNf[] = [
            'pedido_id'      => (int) $r['pedido_id_omie'],
            'codigo_pedido'  => (string) $r['codigo_pedido'],
            'cliente'        => mb_substr((string) $r['nome_cliente'], 0, 40),
            'doc_cliente'    => maskDoc((string) $r['documento_cliente']),
            'vendedor'       => mb_substr((string) $r['nome_vendedor'], 0, 30),
            'pedido_date'    => (string) $r['pedido_date'],
            'nf_numero'      => (string) $r['numero_nf'],
            'nf_status'      => (string) $r['status_fiscal'],
            'nf_emissao'     => (string) $r['nf_emissao'],
            'valor_total'    => (float) $r['valor_total'],
            'etapa_atual'    => (string) $r['etapa_omie'],
            'stage_history'  => array_values($etapas),
            'synced_at'      => $synced,
            'classification' => $class,
            'reason'         => $reason,
            'bronze_rows'    => count($bronzeHistory),
        ];
    }
}

// ── SECTION 7 — Etapa 60 analysis ─────────────────────────────────────────────
$etapa60Analysis = [];
if ($hasCanonical) {
    // 7a: etapa 60 stats
    $s7a = $pdo->prepare(
        "SELECT COUNT(*) AS total,
                SUM(p.valor_total) AS valor_total,
                SUM(CASE WHEN nf.nota_key IS NOT NULL THEN 1 ELSE 0 END) AS com_nf,
                SUM(CASE WHEN nf.status_fiscal = 'autorizada' THEN 1 ELSE 0 END) AS nfs_autorizadas,
                SUM(CASE WHEN nf.nota_key IS NULL THEN 1 ELSE 0 END) AS sem_nf,
                MIN(p.data_faturamento) AS min_data,
                MAX(p.data_faturamento) AS max_data
         FROM biomie_pedidos_canonicos p
         LEFT JOIN biomie_notas_fiscais_canonicas nf ON nf.codigo_pedido_origem = p.codigo_pedido
         WHERE {$periodClause}
           AND p.etapa_omie = '60'"
    );
    $s7a->execute([':start' => $start, ':end' => $end]);
    $etapa60Analysis['stats'] = $s7a->fetch(PDO::FETCH_ASSOC);

    // 7b: Check overlap with etapa 50 (both marked as faturado)
    $s7b = $pdo->prepare(
        "SELECT COUNT(*) AS total_faturado_50,
                SUM(p.valor_total) AS valor_50
         FROM biomie_pedidos_canonicos p
         WHERE {$periodClause}
           AND p.etapa_omie = '50'"
    );
    $s7b->execute([':start' => $start, ':end' => $end]);
    $etapa60Analysis['etapa_50_stats'] = $s7b->fetch(PDO::FETCH_ASSOC);

    // 7c: Sample etapa 60 with NF
    $s7c = $pdo->prepare(
        "SELECT p.codigo_pedido, p.nome_vendedor, p.nome_cliente,
                p.data_emissao, p.data_faturamento, p.valor_total,
                nf.numero_nf, nf.status_fiscal, nf.data_emissao AS nf_emissao
         FROM biomie_pedidos_canonicos p
         LEFT JOIN biomie_notas_fiscais_canonicas nf ON nf.codigo_pedido_origem = p.codigo_pedido
         WHERE {$periodClause}
           AND p.etapa_omie = '60'
         ORDER BY p.valor_total DESC
         LIMIT :lim"
    );
    $s7c->bindValue(':start', $start,  PDO::PARAM_STR);
    $s7c->bindValue(':end',   $end,    PDO::PARAM_STR);
    $s7c->bindValue(':lim',   $sampleN, PDO::PARAM_INT);
    $s7c->execute();
    $etapa60Analysis['samples'] = $s7c->fetchAll(PDO::FETCH_ASSOC);

    // 7d: Revenue impact — is etapa 60 double-counting with etapa 50?
    $s7d = $pdo->prepare(
        "SELECT p.etapa_omie,
                COUNT(DISTINCT p.id) AS pedidos,
                SUM(p.valor_total) AS valor_pedidos,
                COUNT(DISTINCT nf.nota_key) AS nfs,
                SUM(CASE WHEN nf.status_fiscal='autorizada' THEN nf.valor_mercadorias ELSE 0 END) AS valor_nfs_aut
         FROM biomie_pedidos_canonicos p
         LEFT JOIN biomie_notas_fiscais_canonicas nf ON nf.codigo_pedido_origem = p.codigo_pedido
         WHERE {$periodClause}
           AND p.etapa_omie IN ('50','60')
         GROUP BY p.etapa_omie"
    );
    $s7d->execute([':start' => $start, ':end' => $end]);
    $etapa60Analysis['vs_etapa_50'] = $s7d->fetchAll(PDO::FETCH_ASSOC);
}

// ── SECTION 8 — Stage mapping proposal ───────────────────────────────────────
$STAGE_MAP_PROPOSAL = [
    '10' => [
        'label'                   => 'Orçamento',
        'proposed_biomie_scope'   => 'orcamento',
        'should_count_as_quote'   => true,
        'should_count_as_portfolio' => false,
        'should_count_as_realized'  => false,
        'should_count_as_delivery'  => false,
        'should_count_as_cancelled' => false,
        'should_count_as_returned'  => false,
        'reason' => 'Proposta/intenção comercial. Não entra no realizado. Entra no funil.',
        'current_biomie_status_comercial' => 'outro',
        'current_treatment'               => 'INCORRETO — tratado como "outro" mas deveria ser "orcamento" semântico',
    ],
    '20' => [
        'label'                   => 'Pedido / Autorizado',
        'proposed_biomie_scope'   => 'carteira_aceita',
        'should_count_as_quote'   => false,
        'should_count_as_portfolio' => true,
        'should_count_as_realized'  => false,
        'should_count_as_delivery'  => false,
        'should_count_as_cancelled' => false,
        'should_count_as_returned'  => false,
        'reason' => 'Pedido aceito aguardando faturamento. Entra na carteira, não no realizado.',
        'current_biomie_status_comercial' => 'autorizado',
        'current_treatment'               => 'OK — mapeado como "autorizado"',
    ],
    '50' => [
        'label'                   => 'Faturado',
        'proposed_biomie_scope'   => 'faturado',
        'should_count_as_quote'   => false,
        'should_count_as_portfolio' => false,
        'should_count_as_realized'  => true,
        'should_count_as_delivery'  => false,
        'should_count_as_cancelled' => false,
        'should_count_as_returned'  => false,
        'reason' => 'NF emitida. Entra no realizado quando há NF autorizada correspondente.',
        'current_biomie_status_comercial' => 'faturado',
        'current_treatment'               => 'OK — mapeado como "faturado"',
    ],
    '60' => [
        'label'                   => 'Entrega Finalizada',
        'proposed_biomie_scope'   => 'entrega_finalizada',
        'should_count_as_quote'   => false,
        'should_count_as_portfolio' => false,
        'should_count_as_realized'  => false,
        'should_count_as_delivery'  => true,
        'should_count_as_cancelled' => false,
        'should_count_as_returned'  => false,
        'reason' => 'Pós-venda/operacional. NF já emitida em etapa 50. NÃO deve gerar nova receita. '
            . 'DIVERGÊNCIA ATUAL: BIomie trata como "faturado" via classifyStatus (50-69 → faturado).',
        'current_biomie_status_comercial' => 'faturado',
        'current_treatment'               => 'DIVERGENTE — deveria ser entrega_finalizada (pós-venda)',
    ],
    '70' => [
        'label'                   => 'Cancelado',
        'proposed_biomie_scope'   => 'cancelado',
        'should_count_as_quote'   => false,
        'should_count_as_portfolio' => false,
        'should_count_as_realized'  => false,
        'should_count_as_delivery'  => false,
        'should_count_as_cancelled' => true,
        'should_count_as_returned'  => false,
        'reason' => 'Pedido cancelado. Excluído de todos os indicadores comerciais.',
        'current_biomie_status_comercial' => 'cancelado',
        'current_treatment'               => 'OK — mapeado como "cancelado"',
    ],
    '80' => [
        'label'                   => 'Devolvido',
        'proposed_biomie_scope'   => 'devolvido',
        'should_count_as_quote'   => false,
        'should_count_as_portfolio' => false,
        'should_count_as_realized'  => false,
        'should_count_as_delivery'  => false,
        'should_count_as_cancelled' => false,
        'should_count_as_returned'  => true,
        'reason' => 'Pedido devolvido. Excluído do realizado e carteira.',
        'current_biomie_status_comercial' => 'cancelado',
        'current_treatment'               => 'PARCIALMENTE CORRETO — mapeado como cancelado, mas deveria ser devolvido',
    ],
];

// ── SECTION 9 — Business unit detection summary ───────────────────────────────
$hasVarejoRevenda     = count($vaRejeMatches) > 0;
$varejoRevConf        = 0;
$varejoRevField       = '';
$varejoRevRec         = 'Não encontrado nos payloads bronze analisados.';

$hasStageLabel = false;
$stageLabelField = '';
foreach ($candidateFields['stage_label'] ?? [] as $path => $info) {
    if (count($info['examples']) > 0) {
        $hasStageLabel = true;
        $stageLabelField = $path;
        break;
    }
}

if ($hasVarejoRevenda) {
    $varejoRevConf = 60;
    foreach ($vaRejeMatches as $m) {
        if (str_contains(mb_strtolower($m['path']), 'varejo') || str_contains(mb_strtolower($m['path']), 'revenda')) {
            $varejoRevConf = 90;
            $varejoRevField = $m['path'];
            $varejoRevRec   = "Campo '{$m['path']}' contém '{$m['value']}' — confirmar se é enum Varejo/Revenda.";
            break;
        }
    }
}

// ── elapsed ────────────────────────────────────────────────────────────────────
$elapsed = round(microtime(true) - $t0, 2);

// ── JSON output ────────────────────────────────────────────────────────────────
if ($jsonMode) {
    echo json_encode([
        'omie_order_payload_semantics_audit' => [
            'version'          => 'COM.R1.1',
            'period'           => ['start' => $start, 'end' => $end],
            'generated_at'     => date('Y-m-d H:i:s'),
            'elapsed_s'        => $elapsed,
            'summary' => [
                'canonical_by_stage'      => $canonicalByStage,
                'bronze_entity_types'     => $bronzeInventory['entities'] ?? [],
                'stage_coverage'          => $stageCoverage,
                'has_varejo_revenda_field'=> $hasVarejoRevenda,
                'varejo_revenda_field'    => $varejoRevField,
                'varejo_revenda_confidence' => $varejoRevConf,
                'has_stage_label_field'   => $hasStageLabel,
                'stage_label_field'       => $stageLabelField,
                'quote_with_nf_count'     => count($quoteWithNf),
            ],
            'candidate_fields'            => $candidateFields,
            'varejo_revenda_matches'      => $vaRejeMatches,
            'quote_with_invoice_cases'    => $quoteWithNf,
            'etapa_60_analysis'           => $etapa60Analysis,
            'stage_mapping_proposal'      => $STAGE_MAP_PROPOSAL,
            'business_unit_detection' => [
                'has_varejo_revenda_field' => $hasVarejoRevenda,
                'field_path'              => $varejoRevField,
                'confidence_score'        => $varejoRevConf,
                'recommendation'          => $varejoRevRec,
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

// ── Pretty output ──────────────────────────────────────────────────────────────
$hr = str_repeat('═', 74);
echo PHP_EOL . $hr . PHP_EOL;
echo '  COM.R1.1 — Investigação Bronze: Etapas Omie / Varejo-Revenda / Orçamentos+NF' . PHP_EOL;
echo '  Período : ' . $start . ' → ' . $end;
if ($filterStage) echo '  |  Stage filter: ' . $filterStage;
echo PHP_EOL . $hr . PHP_EOL . PHP_EOL;

// ── 1 — Bronze inventory ───────────────────────────────────────────────────────
sep('1 — Inventário de Fontes Bronze (biomie_omie_bronze)');
if (!$hasBronze) {
    echo PHP_EOL . '  AVISO: tabela biomie_omie_bronze não encontrada.' . PHP_EOL;
} else {
    $allEntities = $bronzeInventory['entities'] ?? [];
    if (!$allEntities) {
        echo PHP_EOL . '  Bronze vazia ou sem registros.' . PHP_EOL;
    } else {
        echo PHP_EOL;
        echo '  ' . str_pad('entity_name', 22) . str_pad('omie_method', 26) . str_pad('domain', 14) . 'count' . PHP_EOL;
        echo '  ' . str_repeat('-', 72) . PHP_EOL;
        foreach ($allEntities as $e) {
            echo '  ' . str_pad((string)$e['entity_name'], 22)
                . str_pad((string)$e['omie_method'], 26)
                . str_pad((string)$e['domain_name'], 14)
                . fmtN((int)$e['cnt'])
                . '  [' . substr((string)$e['first_at'], 0, 10) . ' → ' . substr((string)$e['last_at'], 0, 10) . ']'
                . PHP_EOL;
        }
    }
    $orderEntities = $bronzeInventory['order_entities'] ?? [];
    if ($orderEntities) {
        echo PHP_EOL . '  Entidades relacionadas a pedidos/orçamentos:' . PHP_EOL;
        foreach ($orderEntities as $e) {
            echo '    [' . $e['entity_name'] . '] ' . $e['omie_method'] . ' → ' . fmtN((int)$e['cnt']) . ' registros' . PHP_EOL;
        }
    }
}

// ── 2 — Canonical by stage ────────────────────────────────────────────────────
sep('2 — Pedidos Canônicos por etapa_omie no período');
echo PHP_EOL;
echo '  ' . str_pad('etapa', 8) . str_pad('status_comercial', 18) . str_pad('pedidos', 10)
    . str_pad('valor_total', 18) . str_pad('NFs', 8) . 'NFs_aut' . PHP_EOL;
echo '  ' . str_repeat('-', 68) . PHP_EOL;
foreach ($canonicalByStage as $r) {
    echo '  ' . str_pad((string)$r['etapa_omie'], 8)
        . str_pad((string)$r['status_comercial'], 18)
        . str_pad(fmtN((int)$r['total_pedidos']), 10)
        . str_pad(fmtR((float)$r['valor_total']), 18)
        . str_pad(fmtN((int)$r['total_nfs']), 8)
        . fmtN((int)$r['nfs_autorizadas']) . PHP_EOL;
}

// ── 3 — Bronze coverage per stage ────────────────────────────────────────────
sep('3 — Cobertura Bronze por Etapa e Campos do Payload');
foreach ($stageCoverage as $stage => $cov) {
    echo PHP_EOL . "  ■ etapa_omie = {$stage}" . PHP_EOL;
    row('    Pedidos canônicos analisados', (string) $cov['total']);
    row('    Registros bronze encontrados', (string) $cov['with_bronze']);
    row('    Chaves de payload distintas', (string) ($cov['payload_keys'] ?? 0));
    if (isset($cov['bronze_entities']) && $cov['bronze_entities']) {
        row('    entity_name(s) no bronze', implode(', ', $cov['bronze_entities']));
    }
}

// ── 4 — Candidate fields ──────────────────────────────────────────────────────
sep('4 — Campos Candidatos no Payload Omie');
if (!$candidateFields) {
    echo PHP_EOL . '  Nenhum campo candidato encontrado. Bronze pode estar vazia ou sem registros para o período.' . PHP_EOL;
} else {
    foreach ($candidateFields as $category => $fields) {
        echo PHP_EOL . '  [ ' . strtoupper($category) . ' ]' . PHP_EOL;
        foreach ($fields as $path => $info) {
            $exs = implode(' | ', array_map(fn($v) => '"' . mb_substr($v, 0, 40) . '"', $info['examples']));
            echo '    ' . str_pad($path, 40) . ' stages:[' . implode(',', $info['stages']) . ']  ex: ' . $exs . PHP_EOL;
        }
    }
}

// ── 5 — Varejo/Revenda ────────────────────────────────────────────────────────
sep('5 — Busca Varejo / Revenda nos Payloads Bronze');
echo PHP_EOL;
echo '  Encontrado : ' . ($hasVarejoRevenda ? 'SIM' : 'NÃO') . PHP_EOL;
echo '  Confiança  : ' . $varejoRevConf . '/100' . PHP_EOL;
if ($varejoRevField) echo '  Campo      : ' . $varejoRevField . PHP_EOL;
echo '  Recomend.  : ' . $varejoRevRec . PHP_EOL;
if ($vaRejeMatches) {
    echo PHP_EOL . '  Ocorrências:' . PHP_EOL;
    foreach (array_slice($vaRejeMatches, 0, 10) as $m) {
        echo '    etapa=' . $m['stage'] . '  path=' . $m['path'] . '  valor="' . mb_substr($m['value'], 0, 40) . '"  (termo: ' . $m['term'] . ')' . PHP_EOL;
    }
}

if (!$hasVarejoRevenda) {
    echo PHP_EOL;
    echo '  CONCLUSÃO: O campo que distingue Varejo vs Revenda NÃO está presente nos payloads' . PHP_EOL;
    echo '  bronze analisados. Possíveis causas:' . PHP_EOL;
    echo '    a) O Kanban "Pedidos Varejo / Pedidos Revenda" usa etapas de funil configuráveis' . PHP_EOL;
    echo '       no módulo Omie Vendas — distintas do campo cabecalho.etapa (código numérico).' . PHP_EOL;
    echo '    b) O bronze atual só guarda o payload de ListarPedidos, que não inclui o rótulo' . PHP_EOL;
    echo '       da etapa de funil — apenas o código numérico.' . PHP_EOL;
    echo '    c) É necessário chamar a API ListarEtapasFaturamento para obter o mapeamento' . PHP_EOL;
    echo '       código → rótulo ("10" → "Orçamento - PD", "20" → "Pedidos Varejo", etc.).' . PHP_EOL;
    echo '    d) Alternativa: o campo cabecalho.etapa_faturamento pode conter o rótulo, mas' . PHP_EOL;
    echo '       o sync atual não o extrai para o canônico.' . PHP_EOL;
}

// ── 6 — Orçamentos com NF ─────────────────────────────────────────────────────
sep('6 — Orçamentos (etapa 10) com NF Vinculada');
echo PHP_EOL . '  Total: ' . count($quoteWithNf) . ' caso(s)' . PHP_EOL . PHP_EOL;

$classCounts = [];
foreach ($quoteWithNf as $q) {
    $classCounts[$q['classification']] = ($classCounts[$q['classification']] ?? 0) + 1;
}
foreach ($classCounts as $cls => $cnt) {
    echo '  ' . str_pad($cls, 30) . $cnt . ' caso(s)' . PHP_EOL;
}

echo PHP_EOL;
foreach ($quoteWithNf as $q) {
    echo '  Ped.' . str_pad((string)$q['codigo_pedido'], 10)
        . str_pad(mb_substr($q['vendedor'], 0, 20), 22)
        . str_pad(mb_substr($q['cliente'], 0, 25), 27)
        . str_pad(fmtR($q['valor_total']), 16)
        . PHP_EOL;
    echo '    pedido:' . ($q['pedido_date'] ?: '-') . '  NF:' . $q['nf_numero'] . '[' . $q['nf_status'] . ']@' . $q['nf_emissao']
        . '  etapa_atual:' . $q['etapa_atual']
        . '  bronze_rows:' . $q['bronze_rows']
        . PHP_EOL;
    echo '    → [' . $q['classification'] . '] ' . $q['reason'] . PHP_EOL;
    echo PHP_EOL;
}

// ── 7 — Etapa 60 ──────────────────────────────────────────────────────────────
sep('7 — Análise da Etapa 60 (Entrega Finalizada)');
echo PHP_EOL;
$stats60 = $etapa60Analysis['stats'] ?? null;
if ($stats60) {
    row('  Total pedidos etapa 60', fmtN((int)$stats60['total']));
    row('  Valor total', fmtR((float)$stats60['valor_total']));
    row('  Com NF vinculada', fmtN((int)$stats60['com_nf']));
    row('  NFs autorizadas', fmtN((int)$stats60['nfs_autorizadas']));
    row('  Sem NF vinculada', fmtN((int)$stats60['sem_nf']));
    row('  Período (data_faturamento)', ($stats60['min_data'] ?? '-') . ' → ' . ($stats60['max_data'] ?? '-'));
}

echo PHP_EOL . '  Comparação etapa 50 (Faturado) vs 60 (Entrega Finalizada):' . PHP_EOL;
foreach ($etapa60Analysis['vs_etapa_50'] ?? [] as $r) {
    echo '  etapa=' . $r['etapa_omie']
        . '  pedidos=' . fmtN((int)$r['pedidos'])
        . '  valor_pedidos=' . fmtR((float)$r['valor_pedidos'])
        . '  NFs=' . fmtN((int)$r['nfs'])
        . '  valor_NFs_aut=' . fmtR((float)$r['valor_nfs_aut'])
        . PHP_EOL;
}

echo PHP_EOL;
echo '  DIAGNÓSTICO ETAPA 60:' . PHP_EOL;
echo '  ┌────────────────────────────────────────────────────────────────────┐' . PHP_EOL;
echo '  │ O BIomie atual mapeia etapa 60 como "faturado" via classifyStatus │' . PHP_EOL;
echo '  │ (código >= 50 && <= 69 → faturado). Isso é INCORRETO semanticamente│' . PHP_EOL;
echo '  │ porque etapa 60 = Entrega Finalizada (pós-venda), não faturamento.│' . PHP_EOL;
echo '  │                                                                    │' . PHP_EOL;
echo '  │ RISCO: se pedido avança de etapa 50 → 60, o valor pode ser        │' . PHP_EOL;
echo '  │ contabilizado duas vezes se a snapshot do canônico atualizar.     │' . PHP_EOL;
echo '  │                                                                    │' . PHP_EOL;
echo '  │ PROPOSTA: etapa 60 → entrega_finalizada (pos_venda)               │' . PHP_EOL;
echo '  │ NÃO deve entrar no realizado. Apenas confirma entrega.            │' . PHP_EOL;
echo '  └────────────────────────────────────────────────────────────────────┘' . PHP_EOL;

if (isset($etapa60Analysis['samples'])) {
    echo PHP_EOL . '  Amostras etapa 60:' . PHP_EOL;
    foreach (array_slice($etapa60Analysis['samples'], 0, 5) as $s) {
        echo '    Ped.' . str_pad((string)$s['codigo_pedido'], 10)
            . str_pad(mb_substr((string)$s['nome_vendedor'], 0, 22), 24)
            . str_pad(fmtR((float)$s['valor_total']), 16)
            . ($s['numero_nf'] ? 'NF ' . $s['numero_nf'] . '[' . $s['status_fiscal'] . ']' : 'sem NF')
            . PHP_EOL;
    }
}

// ── 8 — Stage mapping proposal ────────────────────────────────────────────────
sep('8 — Proposta de Mapeamento Semântico Oficial');
echo PHP_EOL;
foreach ($STAGE_MAP_PROPOSAL as $code => $prop) {
    $ok = str_contains($prop['current_treatment'], 'OK') ? '[OK]    ' : '[AJUSTE]';
    echo '  etapa ' . $code . ' — ' . $prop['label'] . PHP_EOL;
    echo '    biomie_scope    : ' . $prop['proposed_biomie_scope'] . PHP_EOL;
    echo '    status_atual    : ' . $ok . ' ' . $prop['current_treatment'] . PHP_EOL;
    echo '    realizado?      : ' . ($prop['should_count_as_realized'] ? 'SIM' : 'não') . PHP_EOL;
    echo '    carteira?       : ' . ($prop['should_count_as_portfolio'] ? 'SIM' : 'não') . PHP_EOL;
    echo '    orçamento?      : ' . ($prop['should_count_as_quote'] ? 'SIM' : 'não') . PHP_EOL;
    echo '    entrega?        : ' . ($prop['should_count_as_delivery'] ? 'SIM' : 'não') . PHP_EOL;
    echo '    cancelado?      : ' . ($prop['should_count_as_cancelled'] ? 'SIM' : 'não') . PHP_EOL;
    echo PHP_EOL;
}

// ── 9 — Payload keys cross-stage ──────────────────────────────────────────────
if ($payloadFields && !$filterStage) {
    sep('9 — Chaves de Payload por Etapa (cabecalho.* e infoCadastro.*)');
    $relevantPaths = ['cabecalho', 'infoCadastro', 'status_pedido', 'faturamento', 'cEtapa'];
    foreach ($payloadFields as $stage => $fields) {
        $filtered = array_filter($fields, function($path) use ($relevantPaths) {
            foreach ($relevantPaths as $rp) {
                if (str_starts_with($path, $rp) || str_contains($path, 'etapa') || str_contains($path, 'tipo') || str_contains($path, 'canal') || str_contains($path, 'varejo') || str_contains($path, 'revenda')) return true;
            }
            return false;
        }, ARRAY_FILTER_USE_KEY);
        if (!$filtered) continue;
        echo PHP_EOL . '  etapa ' . $stage . ':' . PHP_EOL;
        foreach ($filtered as $path => $info) {
            $exs = implode(' | ', array_map(fn($v) => '"' . mb_substr($v, 0, 30) . '"', $info['examples']));
            echo '    ' . str_pad($path, 42) . $exs . PHP_EOL;
        }
    }
}

// ── Footer ────────────────────────────────────────────────────────────────────
echo PHP_EOL . $hr . PHP_EOL;
echo '  COM.R1.1 concluído em ' . $elapsed . 's' . PHP_EOL;
echo '  Bronze: ' . ($hasBronze ? 'disponível' : 'AUSENTE') . '  |  ';
echo 'Canônico pedidos: ' . ($hasCanonical ? 'disponível' : 'AUSENTE') . '  |  ';
echo 'Canônico NFs: ' . ($hasNfs ? 'disponível' : 'AUSENTE') . PHP_EOL;
echo $hr . PHP_EOL . PHP_EOL;
