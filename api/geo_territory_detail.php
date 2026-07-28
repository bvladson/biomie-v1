<?php

declare(strict_types=1);

/**
 * api/geo_territory_detail.php — GEO.R1 Fase 7
 *
 * Detalhe de um território clicado no mapa.
 *
 * Parâmetros:
 *   layer          = fortaleza_bairros|grande_fortaleza_bairros|rmf_municipios|ceara_municipios|ceara_regioes
 *   territory_key  = chave do território (bairro_norm, ibge_code, regiao_id)
 *   start, end     = YYYY-MM-DD
 *   scope          = orc_pd|remessa|licitacao|todas
 *   tab            = resumo|nfs|clientes|vendedores|produtos|diagnostico
 */

use BIomie\Auth;
use BIomie\Database;
use BIomie\Geo\GeoMapLayerRegistry;
use BIomie\Geo\GeoTerritoryResolver;
use BIomie\Http;
use BIomie\RequestContext;
use BIomie\Support\CommercialMartScope;
use BIomie\Support\DocumentMask;
use BIomie\Support\MapFilterSupport;
use BIomie\Support\NeighborhoodNormalizer;

require __DIR__ . '/bootstrap.php';

Auth::requireModule('geo', true);

try {
    $layer        = trim((string) ($_GET['layer']         ?? GeoMapLayerRegistry::defaultLayer()));
    $territoryKey = trim((string) ($_GET['territory_key'] ?? ''));
    $tab          = trim((string) ($_GET['tab']           ?? 'resumo'));
    $scope        = in_array((string)($_GET['scope'] ?? 'orc_pd'), ['orc_pd','remessa','licitacao','todas'], true)
        ? (string) ($_GET['scope'] ?? 'orc_pd')
        : 'orc_pd';

    if (!GeoMapLayerRegistry::isValid($layer)) {
        Http::json(['ok' => false, 'error' => 'invalid_layer', 'message' => "Camada inválida: {$layer}"], 400);
    }
    if ($territoryKey === '') {
        Http::json(['ok' => false, 'error' => 'missing_territory_key', 'message' => 'territory_key é obrigatório.'], 400);
    }

    $f     = MapFilterSupport::fromRequest($_GET);
    $start = $f['date_from'];
    $end   = $f['date_to'];

    $config = new \BIomie\Config();
    $conn   = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo    = Database::connect($config, $conn);

    if (!\BIomie\Http::tableExists($pdo, 'mart_compradores_por_geo')) {
        Http::json(['ok' => false, 'error' => 'mart_missing'], 503);
    }

    $layerDef    = GeoMapLayerRegistry::get($layer);
    $scopeLabel  = $layerDef['scope_label'] ?? 'território';
    $canViewDocs = Auth::canViewFullDocuments();

    $startComp = substr($start, 0, 7);
    $endComp   = substr($end, 0, 7);

    // Monta filtro WHERE baseado no layer
    [$whereExtra, $params] = buildTerritoryWhere($layer, $territoryKey, $startComp, $endComp, $pdo);
    $tenantFilter = CommercialMartScope::condition($pdo, 'mart_compradores_por_geo');
    $whereExtra = " AND {$tenantFilter}" . $whereExtra;

    // ── Resumo ────────────────────────────────────────────────────────────────
    if ($tab === 'resumo') {
        $stmt = $pdo->prepare("
            SELECT
                COALESCE(SUM(faturamento), 0)  AS receita,
                COALESCE(SUM(qtd_notas), 0)    AS nfs,
                COUNT(DISTINCT CONCAT(company_id, ':', COALESCE(CAST(codigo_cliente_omie AS CHAR), documento, cliente_nome))) AS clientes,
                MAX(ultima_compra)             AS ultima_nf,
                COUNT(DISTINCT bairro_norm)    AS bairros_distintos,
                COUNT(DISTINCT cidade_norm)    AS cidades_distintas
            FROM mart_compradores_por_geo
            WHERE competencia BETWEEN :start_comp AND :end_comp {$whereExtra}
        ");
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $receita = (float)($row['receita'] ?? 0);
        $nfs     = (int)  ($row['nfs']     ?? 0);
        $clientes = (int) ($row['clientes'] ?? 0);

        // Status comercial
        $status = $receita > 50000 ? 'forte' : ($receita > 10000 ? 'moderado' : ($receita > 0 ? 'fraco' : 'vazio'));

        // Participação no total do período
        $totalStmt = $pdo->prepare(
            "SELECT COALESCE(SUM(faturamento),0)
             FROM mart_compradores_por_geo
             WHERE {$tenantFilter}
               AND competencia BETWEEN :sc AND :ec"
        );
        $totalStmt->execute([':sc' => $startComp, ':ec' => $endComp]);
        $totalReceita = (float)$totalStmt->fetchColumn();
        $sharePct = $totalReceita > 0 ? round($receita / $totalReceita * 100, 1) : 0.0;

        // Label amigável do território
        $territoryLabel = buildTerritoryLabel($layer, $territoryKey, $pdo);

        Http::json([
            'ok'              => true,
            'layer'           => $layer,
            'territory_key'   => $territoryKey,
            'territory_label' => $territoryLabel,
            'scope_label'     => $scopeLabel,
            'tab'             => 'resumo',
            'period'          => ['start' => $start, 'end' => $end],
            'scope'           => $scope,
            'summary' => [
                'receita'          => round($receita, 2),
                'nfs'              => $nfs,
                'clientes'         => $clientes,
                'ticket_medio'     => $nfs > 0 ? round($receita / $nfs, 2) : 0.0,
                'ultima_nf'        => ($row['ultima_nf'] ?? null) ?: null,
                'share_pct'        => $sharePct,
                'status_comercial' => $status,
                'status_label'     => match($status) {
                    'forte'    => 'Território com alta movimentação comercial.',
                    'moderado' => 'Território com movimentação moderada.',
                    'fraco'    => 'Território com movimentação fraca.',
                    default    => 'Sem vendas registradas neste período.',
                },
            ],
            'meta' => ['request_id' => RequestContext::id(), 'generated_at' => date(DATE_ATOM), 'source' => 'mart_compradores_por_geo'],
        ]);
    }

    // ── Top clientes ──────────────────────────────────────────────────────────
    if ($tab === 'clientes') {
        $stmt = $pdo->prepare("
            SELECT
                cliente_nome,
                MAX(codigo_cliente_omie) AS codigo_cliente_omie,
                documento,
                COALESCE(NULLIF(bairro_norm,''), 'nao_informado') AS bairro,
                cidade_norm,
                SUM(qtd_notas)   AS notas,
                SUM(faturamento) AS receita,
                MAX(ultima_compra) AS ultima_compra
            FROM mart_compradores_por_geo
            WHERE competencia BETWEEN :start_comp AND :end_comp {$whereExtra}
            GROUP BY company_id, cliente_nome, documento, bairro_norm, cidade_norm
            ORDER BY receita DESC LIMIT 20
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $clientes = array_map(function($r) use ($canViewDocs) {
            return [
                'nome'           => (string)($r['cliente_nome'] ?? ''),
                'documento'      => $canViewDocs
                    ? (string)($r['documento'] ?? '')
                    : DocumentMask::maskDocument((string)($r['documento'] ?? ''), ''),
                'codigo_omie'    => (string)($r['codigo_cliente_omie'] ?? ''),
                'bairro'         => NeighborhoodNormalizer::displayName((string)($r['bairro'] ?? '')),
                'cidade'         => NeighborhoodNormalizer::displayName((string)($r['cidade_norm'] ?? '')),
                'notas'          => (int)($r['notas'] ?? 0),
                'receita'        => (float)($r['receita'] ?? 0),
                'ultima_compra'  => ($r['ultima_compra'] ?? null) ?: null,
            ];
        }, $rows);

        Http::json([
            'ok' => true, 'layer' => $layer, 'territory_key' => $territoryKey,
            'tab' => 'clientes', 'clientes' => $clientes,
            'meta' => ['request_id' => RequestContext::id()],
        ]);
    }

    // ── Top vendedores ────────────────────────────────────────────────────────
    if ($tab === 'vendedores') {
        $hasVendedor = (bool)$pdo->query(
            "SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mart_compradores_por_geo' AND COLUMN_NAME='nome_vendedor' LIMIT 1"
        )->fetchColumn();

        $vendedores = [];
        if ($hasVendedor) {
            $stmt = $pdo->prepare("
                SELECT
                    COALESCE(nome_vendedor,'Sem vendedor') AS vendedor,
                    COUNT(DISTINCT CONCAT(company_id, ':', COALESCE(CAST(codigo_cliente_omie AS CHAR), documento, cliente_nome))) AS clientes,
                    SUM(qtd_notas)   AS notas,
                    SUM(faturamento) AS receita
                FROM mart_compradores_por_geo
                WHERE competencia BETWEEN :start_comp AND :end_comp {$whereExtra}
                GROUP BY nome_vendedor ORDER BY receita DESC LIMIT 15
            ");
            $stmt->execute($params);
            $vendedores = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        Http::json([
            'ok' => true, 'layer' => $layer, 'territory_key' => $territoryKey,
            'tab' => 'vendedores', 'vendedores' => $vendedores,
            'meta' => ['request_id' => RequestContext::id()],
        ]);
    }

    // ── Diagnóstico ───────────────────────────────────────────────────────────
    if ($tab === 'diagnostico') {
        $totalStmt = $pdo->prepare(
            "SELECT COUNT(*), COALESCE(SUM(faturamento),0) FROM mart_compradores_por_geo WHERE competencia BETWEEN :sc AND :ec {$whereExtra}"
        );
        $totalStmt->execute($params);
        $diagRow = $totalStmt->fetch(PDO::FETCH_NUM) ?: [0, 0.0];

        Http::json([
            'ok'            => true,
            'layer'         => $layer,
            'territory_key' => $territoryKey,
            'tab'           => 'diagnostico',
            'diagnostics'   => [
                'mart_rows_for_territory' => (int)($diagRow[0] ?? 0),
                'mart_receita'            => (float)($diagRow[1] ?? 0),
                'layer_def'               => $layerDef,
                'territory_key_used'      => $territoryKey,
                'where_clause_type'       => $layer,
                'source_table'            => 'mart_compradores_por_geo',
                'period'                  => ['start' => $start, 'end' => $end, 'start_comp' => $startComp, 'end_comp' => $endComp],
            ],
            'meta' => ['request_id' => RequestContext::id()],
        ]);
    }

    // Fallback — tab não reconhecida
    Http::json(['ok' => false, 'error' => 'unknown_tab', 'message' => "Tab desconhecida: {$tab}. Válidas: resumo, clientes, vendedores, diagnostico."], 400);

} catch (\Throwable $e) {
    global $logger;
    $logger?->error('geo_territory_detail error', ['msg' => $e->getMessage()]);
    Http::json(['ok' => false, 'error' => 'api_error', 'message' => 'Erro ao carregar detalhe do território.', 'request_id' => RequestContext::id()], 500);
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Monta o trecho WHERE e os parâmetros para filtrar pelo território na camada.
 *
 * @return array{0: string, 1: array<string, string>}
 */
function buildTerritoryWhere(string $layer, string $tKey, string $startComp, string $endComp, PDO $pdo): array
{
    $params = [':start_comp' => $startComp, ':end_comp' => $endComp];

    switch ($layer) {
        case 'fortaleza_bairros':
            $params[':bairro'] = $tKey;
            $where = " AND LOWER(cidade_norm) = 'fortaleza' AND bairro_norm = :bairro";
            break;

        case 'grande_fortaleza_bairros':
            // tKey pode ser "ibge::bairro_norm" ou "mun::ibge"
            if (str_starts_with($tKey, 'mun::')) {
                $ibge = str_replace('mun::', '', $tKey);
                $params[':ibge'] = $ibge;
                $where = " AND EXISTS (SELECT 1 FROM biomie_geo_municipios_ce m WHERE LOWER(g.cidade_norm)=m.nome_norm AND m.ibge_code=:ibge)";
                // fallback se tabela inexistente
                $where = " AND 1=1";
            } else {
                [$ibge, $bairro] = explode('::', $tKey, 2) + ['', ''];
                $params[':bairro'] = $bairro;
                $where = " AND bairro_norm = :bairro";
            }
            break;

        case 'rmf_municipios':
            // tKey = ibge_code
            $params[':ibge_norm'] = $tKey;
            // Match via biomie_geo_municipios_ce se disponível
            $hasTable = (bool)$pdo->query(
                "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='biomie_geo_municipios_ce' LIMIT 1"
            )->fetchColumn();
            if ($hasTable) {
                $nome = $pdo->prepare("SELECT nome_norm FROM biomie_geo_municipios_ce WHERE ibge_code=:c LIMIT 1");
                $nome->execute([':c' => $tKey]);
                $nomeNorm = $nome->fetchColumn();
                if ($nomeNorm) {
                    $params[':cidade'] = $nomeNorm;
                    $where = " AND LOWER(cidade_norm) = :cidade";
                    break;
                }
            }
            $where = " AND 1=1";
            break;

        case 'ceara_municipios':
            $hasTable = (bool)$pdo->query(
                "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='biomie_geo_municipios_ce' LIMIT 1"
            )->fetchColumn();
            if ($hasTable) {
                $nome = $pdo->prepare("SELECT nome_norm FROM biomie_geo_municipios_ce WHERE ibge_code=:c LIMIT 1");
                $nome->execute([':c' => $tKey]);
                $nomeNorm = $nome->fetchColumn();
                if ($nomeNorm) {
                    $params[':cidade'] = $nomeNorm;
                    $where = " AND LOWER(cidade_norm) = :cidade";
                    break;
                }
            }
            $where = " AND 1=1";
            break;

        case 'ceara_regioes':
            // tKey = regiao_id
            $hasTable = (bool)$pdo->query(
                "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='biomie_geo_municipios_ce' LIMIT 1"
            )->fetchColumn();
            if ($hasTable) {
                $where = " AND EXISTS (SELECT 1 FROM biomie_geo_municipios_ce m WHERE LOWER(mart_compradores_por_geo.cidade_norm)=m.nome_norm AND CAST(m.regiao_planejamento_id AS CHAR)=:regiao)";
                $params[':regiao'] = $tKey;
            } else {
                $where = " AND 1=1";
            }
            break;

        default:
            $where = " AND 1=1";
            break;
    }

    // Replace bare table name in EXISTS clause to use correct alias
    $where = str_replace('mart_compradores_por_geo.cidade_norm', 'cidade_norm', $where);

    return [$where, $params];
}

/** Label amigável do território dado seu layer + key. */
function buildTerritoryLabel(string $layer, string $tKey, PDO $pdo): string
{
    if (in_array($layer, ['rmf_municipios','ceara_municipios'], true)) {
        try {
            $hasTable = (bool)$pdo->query(
                "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='biomie_geo_municipios_ce' LIMIT 1"
            )->fetchColumn();
            if ($hasTable) {
                $stmt = $pdo->prepare("SELECT nome FROM biomie_geo_municipios_ce WHERE ibge_code=:c LIMIT 1");
                $stmt->execute([':c' => $tKey]);
                $nome = $stmt->fetchColumn();
                if ($nome) return (string)$nome;
            }
        } catch (\Throwable) {}
    }

    if ($layer === 'ceara_regioes') {
        try {
            $hasTable = (bool)$pdo->query(
                "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='biomie_geo_regioes_ce' LIMIT 1"
            )->fetchColumn();
            if ($hasTable) {
                $stmt = $pdo->prepare("SELECT nome FROM biomie_geo_regioes_ce WHERE id=:i LIMIT 1");
                $stmt->execute([':i' => (int)$tKey]);
                $nome = $stmt->fetchColumn();
                if ($nome) return (string)$nome;
            }
        } catch (\Throwable) {}
    }

    return \BIomie\Support\NeighborhoodNormalizer::displayName($tKey);
}
