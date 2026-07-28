<?php

/**
 * api/vendor_detail.php — Fonte oficial do drawer Vendor 360.
 *
 * Usa exclusivamente dados locais (canonical + marts):
 *   - biomie_notas_fiscais_canonicas   → invoices, realized
 *   - biomie_notas_fiscais_itens_canonicos → products
 *   - mart_bridge_pedido_nfe_titulo    → linked_orders, wallet_orders
 *   - biomie_sales_targets_monthly     → targets
 *
 * Não chama a API Omie.
 */

declare(strict_types=1);

use BIomie\Auth;
use BIomie\CanonicalRepository;
use BIomie\Database;
use BIomie\Http;
use BIomie\RequestContext;
use BIomie\TargetsRepository;

require __DIR__ . '/bootstrap.php';

Auth::requireCapability('sales.view', true);

try {
    $range     = Http::resolveDateRangeOrCompetencia();
    $vendorRef = rawurldecode(trim((string) Http::query('vendor_id')));

    if ($vendorRef === '') {
        Http::json(['ok' => false, 'message' => 'vendor_id e obrigatorio.', 'request_id' => RequestContext::id()], 400);
        return;
    }

    Http::validateDateRange($range['start'], $range['end']);
    $start = $range['start'];
    $end   = $range['end'];

    $conn = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    if (!Database::isConfigured($config, $conn)) {
        Http::json(['ok' => false, 'message' => 'Banco de dados nao configurado.', 'request_id' => RequestContext::id()], 503);
        return;
    }

    $pdo  = Database::connect($config, $conn);

    // VISIBILIDADE: sales.view abre o drawer; NÃO autoriza escolher qualquer
    // vendedor pela URL. Fora do escopo do usuário ⇒ 403 (mesmo furo fechado em
    // quote_agenda_vendor / flight_plan_vendor_detail).
    $svUserId  = (int) (\BIomie\Auth::currentUser()['id'] ?? 0);
    $svCompany = (int) \BIomie\Support\TenantContext::companyId();
    if (!(new \BIomie\Support\SellerVisibilityScope($pdo))->canSee($svCompany, $svUserId, $vendorRef)) {
        Http::json([
            'ok' => false,
            'error' => 'seller_not_authorized',
            'message' => 'Vendedor não autorizado para este usuário nesta empresa.',
            'request_id' => RequestContext::id(),
        ], 403);
    }

    $repo = new CanonicalRepository($pdo, $logger);

    if (!$repo->isAvailable()) {
        Http::json(['ok' => false, 'message' => 'Camada canonical indisponivel.', 'request_id' => RequestContext::id()], 503);
        return;
    }

    require_once __DIR__ . '/../app/Modules/Sales/Application/Vendors/VendorIdentityResolver.php';
    require_once __DIR__ . '/../app/Modules/Sales/Application/Vendors/Vendor360ReadService.php';
    require_once __DIR__ . '/../app/Modules/Sales/Infrastructure/Vendor360ReadRepository.php';

    $vendorMap = $repo->getVendorMap();
    $resolver  = new \BIomie\Modules\Sales\Application\Vendors\VendorIdentityResolver($vendorMap);
    $identity  = $resolver->resolve($vendorRef);

    $targetsRepository = null;
    try {
        $targetsRepository = new TargetsRepository($pdo, $logger);
    } catch (Throwable $e) {
        $logger->warning('Vendor Detail: metas indisponiveis.', [
            'message'    => $e->getMessage(),
            'request_id' => RequestContext::id(),
        ]);
    }

    $saturdayWeight = (float) $config->get('flight_plan.saturday_weight', 0.33);

    $service = new \BIomie\Modules\Sales\Application\Vendors\Vendor360ReadService($repo, $resolver, $pdo);
    $payload = $service->buildPayload($start, $end, $identity, $targetsRepository, $saturdayWeight, RequestContext::id());

    // ── CARTEIRA do vendedor — MESMA regra do Dashboard, calculada no servidor ─
    //
    // Por que aqui e não dentro do Vendor360ReadService: aquele serviço é
    // compartilhado por outros consumidores; enfiar a regra lá teria efeito
    // colateral amplo. Aqui é aditivo e isolado.
    //
    // REGRA (idêntica a commercial_dashboard.php): é CARTEIRA quando existe NF
    // autorizada do MESMO cliente ANTES do início do período — ou seja, o cliente
    // já estava na base e voltou a comprar. NOVOS é o complemento exato, então
    // carteira + novos = faturado do vendedor, sempre.
    //
    // Antes, o drawer aproximava isso no navegador ("comprou 2x dentro do
    // período"), o que SUBESTIMAVA a carteira: quem comprou em maio e voltou uma
    // única vez em julho não era contado. Número aproximado em tela de vendedor
    // mina a confiança — por isso passou a ser servidor, exato.
    $payload['carteira'] = ['disponivel' => false];
    try {
        $nfTable = 'biomie_notas_fiscais_canonicas';
        $baseSql = \BIomie\Support\FiscalValueBasis::preComputedBaseSql('nf');
        $visCond = \BIomie\Support\BiVisibilityScope::getCondition($pdo, $nfTable, 'nf');

        $clienteId = "CASE
            WHEN nf.codigo_cliente_omie IS NOT NULL AND nf.codigo_cliente_omie > 0
                THEN CONCAT(nf.company_id, ':id:', nf.codigo_cliente_omie)
            WHEN NULLIF(TRIM(COALESCE(nf.documento_cliente, '')), '') IS NOT NULL
                THEN CONCAT(nf.company_id, ':doc:', TRIM(COALESCE(nf.documento_cliente, '')))
            WHEN NULLIF(TRIM(COALESCE(nf.nome_cliente, '')), '') IS NOT NULL
                THEN CONCAT(nf.company_id, ':name:', TRIM(COALESCE(nf.nome_cliente, '')))
            ELSE NULL END";

        $recorrente = "EXISTS (
            SELECT 1 FROM {$nfTable} prev
             WHERE prev.company_id = nf.company_id
               AND prev.status_fiscal = 'autorizada'
               AND prev.operation_scope = 'commercial'
               AND prev.data_ref < :cart_start
               AND (
                    (nf.codigo_cliente_omie IS NOT NULL AND nf.codigo_cliente_omie > 0
                         AND prev.codigo_cliente_omie = nf.codigo_cliente_omie)
                 OR ((nf.codigo_cliente_omie IS NULL OR nf.codigo_cliente_omie = 0)
                         AND NULLIF(TRIM(COALESCE(nf.documento_cliente, '')), '') IS NOT NULL
                         AND TRIM(COALESCE(prev.documento_cliente, '')) = TRIM(COALESCE(nf.documento_cliente, '')))
                 OR ((nf.codigo_cliente_omie IS NULL OR nf.codigo_cliente_omie = 0)
                         AND NULLIF(TRIM(COALESCE(nf.documento_cliente, '')), '') IS NULL
                         AND NULLIF(TRIM(COALESCE(nf.nome_cliente, '')), '') IS NOT NULL
                         AND TRIM(COALESCE(prev.nome_cliente, '')) = TRIM(COALESCE(nf.nome_cliente, '')))
               )
        )";

        $baseWhere = \BIomie\Support\TenantContext::sqlFilter('nf')
            . " AND nf.status_fiscal = 'autorizada'"
            . " AND nf.operation_scope = 'commercial'"
            . " AND nf.data_ref >= :cart_ini AND nf.data_ref < DATE_ADD(:cart_fim, INTERVAL 1 DAY)"
            . " AND {$visCond}"
            . ' AND nf.codigo_vendedor_omie = :cart_vendor';

        // TABELA DERIVADA: o EXISTS aparece UMA única vez. Repeti-lo nas três
        // agregações faria o mesmo placeholder (:cart_start) surgir 3x na
        // instrução — e prepares nativos do PDO recusam nome repetido
        // (HY093 "Invalid parameter number"). Calcular o flag uma vez e agregar
        // sobre ele também evita reavaliar a subconsulta por coluna.
        $stmt = $pdo->prepare(
            "SELECT
                COUNT(*) AS nfs_total,
                COALESCE(SUM(t.valor), 0) AS valor_total,
                COUNT(DISTINCT t.cid) AS clientes_total,
                COALESCE(SUM(t.rec), 0) AS nfs_carteira,
                COALESCE(SUM(CASE WHEN t.rec = 1 THEN t.valor ELSE 0 END), 0) AS valor_carteira,
                COUNT(DISTINCT CASE WHEN t.rec = 1 THEN t.cid END) AS clientes_carteira
             FROM (
                SELECT {$baseSql} AS valor,
                       {$clienteId} AS cid,
                       CASE WHEN {$recorrente} THEN 1 ELSE 0 END AS rec
                  FROM {$nfTable} nf
                 WHERE {$baseWhere}
             ) t"
        );
        $stmt->execute([
            'cart_start'  => $start,
            'cart_ini'    => $start,
            'cart_fim'    => $end,
            'cart_vendor' => $vendorRef,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $valorTotal    = (float) ($row['valor_total'] ?? 0);
        $valorCarteira = (float) ($row['valor_carteira'] ?? 0);
        $nfsTotal      = (int)   ($row['nfs_total'] ?? 0);
        $nfsCarteira   = (int)   ($row['nfs_carteira'] ?? 0);

        $payload['carteira'] = [
            'disponivel'        => true,
            'valor'             => round($valorCarteira, 2),
            'nfs'               => $nfsCarteira,
            'clientes'          => (int) ($row['clientes_carteira'] ?? 0),
            'pct'               => $valorTotal > 0 ? round(($valorCarteira / $valorTotal) * 100, 1) : 0.0,
            // Complemento EXATO — nunca recalculado por outra via, para fechar.
            'novos_valor'       => round(max(0.0, $valorTotal - $valorCarteira), 2),
            'novos_nfs'         => max(0, $nfsTotal - $nfsCarteira),
            'faturado_total'    => round($valorTotal, 2),
            'clientes_total'    => (int) ($row['clientes_total'] ?? 0),
            'regra'             => 'Cliente com NF autorizada anterior ao início do período (mesma regra do painel).',
        ];
    } catch (Throwable $carteiraError) {
        // Degradar é aceitável; em SILÊNCIO não — zero por falha seria
        // indistinguível de zero real (lição do card de orçamentos).
        $logger->warning('vendor_detail: carteira (recorrentes) indisponivel.', [
            'message'    => $carteiraError->getMessage(),
            'vendor'     => $vendorRef,
            'request_id' => RequestContext::id(),
        ]);
    }

    Http::json($payload);

} catch (InvalidArgumentException $e) {
    Http::json(['ok' => false, 'message' => $e->getMessage(), 'request_id' => RequestContext::id()], 400);
} catch (Throwable $e) {
    $logger->error('Falha no vendor_detail.', ['message' => $e->getMessage(), 'request_id' => RequestContext::id()]);
    Http::json(['ok' => false, 'message' => 'Nao foi possivel carregar os dados do vendedor.', 'request_id' => RequestContext::id()], 500);
}
