<?php

declare(strict_types=1);

/**
 * api/product_action_intelligence.php — PROD.R2
 *
 * Inteligência de produto para ação comercial.
 * Transforma dados canônicos em recomendações acionáveis.
 *
 * Modos (mode):
 *   summary      — resumo executivo de oportunidades no período
 *   opportunities — lista paginada de produtos com score e recomendação
 *   product      — análise completa de um produto (requer product_code)
 *   repurchase   — candidatos de recompra de um produto (requer product_code)
 *   vendors      — aderência vendedor × produto
 *   drawer       — payload completo para o drawer de ação comercial
 *
 * Parâmetros comuns:
 *   start, end        — período (YYYY-MM-DD)
 *   mode              — um dos modos acima
 *   product_code      — obrigatório para mode=product/repurchase/drawer
 *   vendor_code       — opcional para mode=vendors
 *   limit             — máx itens (default 50, max 200)
 *   offset            — paginação (default 0)
 *   order             — receita|score|dias_sem_venda|clientes (default: score)
 *   debug             — 1 para retornar diagnósticos extras
 *
 * Permissão: sales.view
 * Read-only. Sem chamadas à API Omie em tempo real.
 * Sem CSV/manual. Sem SELECT *.
 * Datas: COALESCE(data_competencia, data_emissao).
 * Estoque: não afirma saldo físico. Usa document_ledger quando oficial indisponível.
 */

require __DIR__ . '/bootstrap.php';
\BIomie\Auth::requireCapability('sales.view', true);

use BIomie\Database;
use BIomie\Http;
use BIomie\Product\ProductActionIntelligenceBuilder;
use BIomie\RequestContext;
use BIomie\Support\ProductCommercialIdentityResolver;

header('Content-Type: application/json; charset=utf-8');

// ── Whitelist validation ──────────────────────────────────────────────────────

$VALID_MODES  = ['summary', 'opportunities', 'product', 'repurchase', 'vendors', 'drawer'];
$VALID_ORDERS = ['receita', 'score', 'dias_sem_venda', 'clientes', 'qtd'];

$today      = date('Y-m-d');
$yearStart  = date('Y-01-01');
$rawStart   = trim((string) Http::query('start', $yearStart));
$rawEnd     = trim((string) Http::query('end',   $today));
$mode       = trim((string) Http::query('mode',  'summary'));
$prodCode   = trim((string) Http::query('product_code', ''));
$vendorCode = trim((string) Http::query('vendor_code',  '')) ?: null;
$limitRaw   = max(1, min(200, (int) Http::query('limit',  '50')));
$offsetRaw  = max(0, (int) Http::query('offset', '0'));
$orderRaw   = trim((string) Http::query('order',  'score'));
$debug      = Http::query('debug', '') === '1';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawStart)) $rawStart = $yearStart;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawEnd))   $rawEnd   = $today;
if ($rawEnd < $rawStart) $rawEnd = $rawStart;

if (!in_array($mode,  $VALID_MODES,  true)) $mode  = 'summary';
if (!in_array($orderRaw, $VALID_ORDERS, true)) $orderRaw = 'score';

try {
    $conn    = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo     = Database::connect($config, $conn);
    $builder = new ProductActionIntelligenceBuilder($pdo, new ProductCommercialIdentityResolver($pdo));

    $meta = [
        'request_id'   => RequestContext::id(),
        'generated_at' => date(DATE_ATOM),
        'mode'         => $mode,
        'period'       => ['start' => $rawStart, 'end' => $rawEnd],
        'source'       => 'omie_canonical',
        'confidence'   => 'operational',
    ];

    // ── mode=summary ─────────────────────────────────────────────────────────
    if ($mode === 'summary') {
        $summary = $builder->buildExecutiveSummary($rawStart, $rawEnd);
        Http::json(array_merge(['ok' => true], $summary, ['meta' => $meta]));
    }

    // ── mode=opportunities ────────────────────────────────────────────────────
    if ($mode === 'opportunities') {
        $opps = $builder->buildProductOpportunities($rawStart, $rawEnd, 200);

        // Apply ordering
        usort($opps, static function (array $a, array $b) use ($orderRaw): int {
            return match ($orderRaw) {
                'receita'       => $b['receita_periodo']  <=> $a['receita_periodo'],
                'dias_sem_venda' => $a['dias_sem_venda']  <=> $b['dias_sem_venda'],
                'clientes'      => $b['clientes_periodo'] <=> $a['clientes_periodo'],
                'qtd'           => $b['qtd_periodo']      <=> $a['qtd_periodo'],
                default         => $b['score_acao']       <=> $a['score_acao'],
            };
        });

        $total   = count($opps);
        $paged   = array_values(array_slice($opps, $offsetRaw, $limitRaw));

        Http::json([
            'ok'            => true,
            'total'         => $total,
            'offset'        => $offsetRaw,
            'limit'         => $limitRaw,
            'order'         => $orderRaw,
            'opportunities' => $paged,
            'meta'          => $meta,
        ]);
    }

    // ── mode=product ──────────────────────────────────────────────────────────
    if ($mode === 'product') {
        if ($prodCode === '') {
            Http::json(['ok' => false, 'message' => 'product_code obrigatório para mode=product', 'request_id' => RequestContext::id()], 400);
        }

        $opps = $builder->buildProductOpportunities($rawStart, $rawEnd, 500);
        $found = null;
        foreach ($opps as $o) {
            if ($o['product_code'] === $prodCode) { $found = $o; break; }
        }

        if ($found === null) {
            $found = [
                'product_code' => $prodCode,
                'product_name' => $prodCode,
                'receita_periodo' => 0, 'qtd_periodo' => 0, 'nfs_periodo' => 0,
                'clientes_periodo' => 0, 'meses_ativos' => 0, 'ultima_venda' => null,
                'dias_sem_venda' => 0, 'score_acao' => 0,
                'categoria' => 'Sem dados no período',
                'acao_sugerida' => 'Ampliar período de análise',
            ];
        }

        Http::json([
            'ok'            => true,
            'product'       => $found,
            'repurchase'    => $builder->buildRepurchaseCandidates($prodCode, $rawStart, $rawEnd, 20),
            'meta'          => $meta,
        ]);
    }

    // ── mode=repurchase ───────────────────────────────────────────────────────
    if ($mode === 'repurchase') {
        if ($prodCode === '') {
            Http::json(['ok' => false, 'message' => 'product_code obrigatório para mode=repurchase', 'request_id' => RequestContext::id()], 400);
        }

        $candidates = $builder->buildRepurchaseCandidates($prodCode, $rawStart, $rawEnd, $limitRaw);
        Http::json([
            'ok'         => true,
            'product_code' => $prodCode,
            'total'      => count($candidates),
            'candidates' => $candidates,
            'meta'       => $meta,
        ]);
    }

    // ── mode=vendors ──────────────────────────────────────────────────────────
    if ($mode === 'vendors') {
        $affinity = $builder->buildVendorProductAffinity($rawStart, $rawEnd, $vendorCode ?: null);
        Http::json([
            'ok'      => true,
            'total'   => count($affinity),
            'vendors' => $affinity,
            'meta'    => $meta,
        ]);
    }

    // ── mode=drawer ───────────────────────────────────────────────────────────
    if ($mode === 'drawer') {
        if ($prodCode === '') {
            Http::json(['ok' => false, 'message' => 'product_code obrigatório para mode=drawer', 'request_id' => RequestContext::id()], 400);
        }

        $opps = $builder->buildProductOpportunities($rawStart, $rawEnd, 500);
        $found = null;
        foreach ($opps as $o) {
            if ($o['product_code'] === $prodCode) { $found = $o; break; }
        }

        $repurchase = $builder->buildRepurchaseCandidates($prodCode, $rawStart, $rawEnd, 20);
        $affinity   = $builder->buildVendorProductAffinity($rawStart, $rawEnd);

        // Filter vendor affinity to this product
        $productVendors = [];
        foreach ($affinity as $v) {
            foreach ($v['todos_produtos'] ?? [] as $p) {
                if ($p['product_code'] === $prodCode) {
                    $productVendors[] = [
                        'vendor_id'              => $v['vendor_id'],
                        'vendor_name'            => $v['vendor_name'],
                        'receita'                => $p['receita'],
                        'qtd'                    => $p['qtd'],
                        'nfs'                    => $p['nfs'],
                        'clientes'               => $p['clientes'],
                        'classificacao'          => $p['classificacao'],
                        'participacao_vendedor_pct' => $p['participacao_vendedor_pct'],
                        'share_produto'          => $p['share_produto'],
                    ];
                    break;
                }
            }
        }
        usort($productVendors, fn($a, $b) => $b['receita'] <=> $a['receita']);

        Http::json([
            'ok'              => true,
            'product'         => $found,
            'repurchase'      => $repurchase,
            'product_vendors' => $productVendors,
            'meta'            => $meta,
        ]);
    }

    Http::json(['ok' => false, 'message' => 'mode inválido', 'request_id' => RequestContext::id()], 400);

} catch (\Throwable $e) {
    Http::json([
        'ok'         => false,
        'message'    => 'Erro interno ao carregar inteligência de produto.',
        'request_id' => RequestContext::id(),
    ], 500);
}
