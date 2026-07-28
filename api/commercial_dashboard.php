<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Database;
use BIomie\Http;
use BIomie\SchemaCapabilities;
use BIomie\TargetsRepository;
use BIomie\Commercial\CommercialChannelScope;
use BIomie\Commercial\CommercialFilterPreferenceStore;
use BIomie\Commercial\CommercialFilterResolver;
use BIomie\Commercial\CommercialPeriodScopeContext;
use BIomie\Commercial\CommercialScopeDeniedException;
use BIomie\QuoteAgenda\QuoteAgendaAvailability;
use BIomie\QuoteAgenda\SellerBindingResolver;
use BIomie\Support\BiVisibilityScope;
use BIomie\Support\Filters\FilterNormalizer;
use BIomie\Support\Filters\Pagination;
use BIomie\Support\Filters\Presets\DashboardFilters;
use BIomie\Support\FiscalValueBasis;
use BIomie\Support\SellerVisibilityScope;
use BIomie\Support\TenantContext;
use BIomie\Support\VendorScopeFilter;

require __DIR__ . '/bootstrap.php';
Auth::requireCapability('sales.view', true);

/** @var \BIomie\Logger $dashboardLogger */
$dashboardLogger = $logger;

set_time_limit(60);

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    Http::methodNotAllowed(['GET']);
}

try {
    $range = Http::resolveDateRange();
    Http::validateDateRange($range['start'], $range['end']);

    $start = $range['start'];
    $end   = $range['end'];

    // ── Params ───────────────────────────────────────────────────────────────
    $sortWl    = DashboardFilters::sortWhitelist('nf');
    $rawSort   = FilterNormalizer::text((string) (Http::query('sort') ?? 'emissao'), 40);
    $sortExpr  = $sortWl->resolve($rawSort);
    $sortDir   = FilterNormalizer::direction((string) (Http::query('direction') ?? 'desc'));
    $page      = max(1, (int) (Http::query('page') ?? 1));
    $perPage   = min(200, max(1, (int) (Http::query('per_page') ?? 50)));

    $fVendedor = FilterNormalizer::text((string) (Http::query('vendedor') ?? ''), 100);
    $fVendorIds = VendorScopeFilter::requestedIds();
    $fVendorId = count($fVendorIds) === 1 ? $fVendorIds[0] : '';
    $fCliente  = FilterNormalizer::text((string) (Http::query('cliente') ?? ''), 100);
    $fProduto  = FilterNormalizer::text((string) (Http::query('produto') ?? ''), 100);
    $fQuick    = FilterNormalizer::text((string) (Http::query('quick') ?? ''), 30);

    // ── Connect ──────────────────────────────────────────────────────────────
    $connection = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo        = Database::connect($config, $connection);
    $companyIds = array_values(array_unique(array_filter(
        array_map('intval', TenantContext::allowedCompanyIds()),
        static fn (int $companyId): bool => $companyId > 0
    )));
    if ($companyIds === []) {
        throw new \RuntimeException('Contexto de empresas vazio.');
    }
    $userId     = (int) (Auth::currentUser()['id'] ?? 0);

    // O filtro pedido pelo navegador sempre INTERSECTA o escopo concedido ao
    // usuario. Digitar ou forjar um vendor_id nunca pode ampliar a visibilidade.
    $sellerVisibility = new SellerVisibilityScope($pdo);
    $sellerSelfOnly = !Auth::hasCapability('sales.quotes.followup.view')
        && Auth::hasCapability('sales.quotes.followup.self');
    $sellerBinding = $sellerSelfOnly ? new SellerBindingResolver($pdo) : null;
    $sellerScopesByCompany = [];
    $sellerScopeRows = [];
    foreach ($companyIds as $scopeCompanyId) {
        if ($sellerSelfOnly) {
            // Perfil vendedor nunca usa o fallback "sem vinculo = todos". A
            // identidade precisa estar cadastrada; vazio significa zero linhas.
            $scopeMode = SellerVisibilityScope::MODE_OWN;
            $scopeIds = $sellerBinding?->sellerIdsFor($userId, $scopeCompanyId) ?? [];
        } else {
            $scopeMode = $sellerVisibility->mode($scopeCompanyId, $userId);
            $scopeIds = $sellerVisibility->allowedSellerIds($scopeCompanyId, $userId);
        }
        if ($scopeIds !== null) {
            $scopeIds = array_values(array_unique(array_filter(
                array_map(
                    static fn (mixed $sellerId): string => trim((string) $sellerId),
                    $scopeIds
                ),
                static fn (string $sellerId): bool => $sellerId !== '' && $sellerId !== '0'
            )));
        }
        $sellerScopesByCompany[$scopeCompanyId] = $scopeIds;
        $sellerScopeRows[] = [
            'company_id' => $scopeCompanyId,
            'mode' => $scopeMode,
            'restricted' => $scopeIds !== null,
            'allowed_count' => $scopeIds === null ? null : count($scopeIds),
        ];
    }
    $sellerScopeModes = array_values(array_unique(array_column($sellerScopeRows, 'mode')));
    $sellerScopeMode = count($sellerScopeModes) === 1 ? $sellerScopeModes[0] : 'mixed';
    $restrictedScopeRows = array_values(array_filter(
        $sellerScopeRows,
        static fn (array $scope): bool => (bool) $scope['restricted']
    ));
    $sellerScopeRestricted = $restrictedScopeRows !== [];
    $sellerScopeAllRestricted = count($restrictedScopeRows) === count($sellerScopeRows);
    $sellerScopeAllowedCount = $sellerScopeAllRestricted
        ? array_sum(array_map(
            static fn (array $scope): int => (int) ($scope['allowed_count'] ?? 0),
            $sellerScopeRows
        ))
        : null;

    // ── Filtro Comercial Inteligente ─────────────────────────────────────────
    // O CONTEXTO já definiu o universo (empresa + canais via BiVisibilityScope).
    // Aqui o refino: canal (varejo/licitação) e a preferência salva por recorte.
    // Só vale em contexto de UMA empresa; consolidado mantém o comportamento atual.
    $table   = 'biomie_notas_fiscais_canonicas';
    $singleCompanyId   = TenantContext::companyIdOrNull();
    $businessScope     = TenantContext::businessScope();
    $hasChannelColNf   = SchemaCapabilities::columnExists($pdo, $table, 'business_channel');

    $channelParamPresent = array_key_exists('channel', $_GET);
    $rawChannel = $_GET['channel'] ?? [];
    $requestedChannelList = [];
    foreach ((is_array($rawChannel) ? $rawChannel : explode(',', (string) $rawChannel)) as $c) {
        $c = strtolower(trim((string) $c));
        // 'all'/'todas' = sentinela de "Todas as operações": presente porém sem
        // canal específico ⇒ todos os autorizados (não é um canal do allowlist).
        if ($c !== '' && $c !== 'all' && $c !== 'todas') {
            $requestedChannelList[] = $c;
        }
    }
    $sellerParamPresent = array_key_exists('vendor_ids', $_GET) || array_key_exists('vendor_id', $_GET);

    /** @var list<string>|null $channelRefineChannels canais a aplicar como refino; null = sem refino */
    $channelRefineChannels = null;
    $forceEmptySellers = false;
    $resolvedFilter = null;

    if ($singleCompanyId !== null) {
        try {
            // REAPROVEITA a instância de $sellerVisibility já usada acima. Além de
            // poupar as consultas de escopo (o objeto tem cache por par empresa×
            // usuário), garante que o filtro e o bloco seller_scope enxerguem o
            // MESMO retrato de permissões — duas instâncias poderiam divergir se
            // algo mudasse entre as duas leituras.
            $resolvedFilter = (new CommercialFilterResolver(
                $sellerVisibility,
                new CommercialChannelScope($pdo),
                new CommercialFilterPreferenceStore($pdo),
            ))->resolve(
                $singleCompanyId,
                $userId,
                CommercialFilterPreferenceStore::PAGE_COMMERCIAL_DASHBOARD,
                $businessScope,
                [
                    'channel_present' => $channelParamPresent,
                    'channels' => $requestedChannelList,
                    'seller_present' => $sellerParamPresent,
                    'seller_ids' => $fVendorIds,
                ],
            );
        } catch (CommercialScopeDeniedException $denied) {
            // Pedido explícito fora do autorizado: 403 visível, nunca "vazio".
            Http::json(['ok' => false, 'message' => $denied->getMessage()], 403);
        }

        // Seed de vendedores pela preferência (só quando a URL não pediu nada).
        //
        // USA A INTENÇÃO (requestedSellerIds), NUNCA o escopo (effectiveSellerIds):
        // o escopo de visibilidade já é aplicado incondicionalmente pelo
        // VendorScopeFilter abaixo. Semear o escopo criaria um "filtro fantasma"
        // para usuários restritos — e o pior efeito era silencioso: com
        // $fVendorIds preenchido, a busca por NOME de vendedor (abaixo) deixava
        // de ser aplicada. Intenção null = usuário não pediu nada.
        if (!$sellerParamPresent && $resolvedFilter !== null) {
            $prefSellers = $resolvedFilter->requestedSellerIds;
            if (is_array($prefSellers) && $prefSellers !== []) {
                $fVendorIds = array_values($prefSellers);
                $fVendorId  = count($fVendorIds) === 1 ? $fVendorIds[0] : '';
            } elseif ($prefSellers === []) {
                $forceEmptySellers = true;   // preferência explícita "nenhum" (ou sanitizada a zero)
            }
        }

        // Refino de canal só quando é um estreitamento real (requestedChannels != null).
        if (
            $hasChannelColNf
            && $resolvedFilter !== null
            && $resolvedFilter->requestedChannels !== null
        ) {
            $refine = array_values(array_intersect(
                $resolvedFilter->effectiveChannels,
                CommercialChannelScope::CHANNELS
            ));
            $channelRefineChannels = $refine !== [] ? $refine : ['__none__'];
        }
    }

    /** SQL IN() a partir de canais já validados contra a allowlist. */
    $channelInSql = static function (array $channels): string {
        $valid = array_values(array_filter(
            $channels,
            static fn (string $c): bool => in_array($c, CommercialChannelScope::CHANNELS, true)
        ));
        if ($valid === []) {
            return "''";   // nenhum canal válido ⇒ zero linhas
        }

        return implode(',', array_map(static fn (string $c): string => "'" . $c . "'", $valid));
    };

    $t0 = microtime(true);

    // ── WHERE conditions ─────────────────────────────────────────────────────
    $visCond = BiVisibilityScope::getCondition($pdo, $table, 'nf');
    $baseSql = FiscalValueBasis::preComputedBaseSql('nf');
    // Must stay aligned with FiscalReadRepository::summarizeInvoicesCommercial.
    // Older rows can legitimately lack documento_cliente, so document-only
    // counting made the dashboard report zero clients despite real revenue.
    $clientIdentitySql = "CASE
        WHEN nf.codigo_cliente_omie IS NOT NULL AND nf.codigo_cliente_omie > 0
            THEN CONCAT(nf.company_id, ':id:', nf.codigo_cliente_omie)
        WHEN NULLIF(TRIM(COALESCE(nf.documento_cliente, '')), '') IS NOT NULL
            THEN CONCAT(nf.company_id, ':doc:', TRIM(COALESCE(nf.documento_cliente, '')))
        WHEN NULLIF(TRIM(COALESCE(nf.nome_cliente, '')), '') IS NOT NULL
            THEN CONCAT(nf.company_id, ':name:', TRIM(COALESCE(nf.nome_cliente, '')))
        ELSE NULL
    END";

    $where  = [
        TenantContext::sqlFilter('nf'),
        "nf.status_fiscal = 'autorizada'",
        "nf.operation_scope = 'commercial'",
        "nf.data_ref >= :start",
        "nf.data_ref < DATE_ADD(:end, INTERVAL 1 DAY)",
        $visCond,
    ];
    $params = [':start' => $start, ':end' => $end];

    $where[] = VendorScopeFilter::conditionForContext(
        $pdo,
        $userId,
        $companyIds,
        $fVendorIds,
        'nf',
        $params,
        'codigo_vendedor_omie',
        'company_id',
        $sellerSelfOnly
    );

    // Preferência "nenhum vendedor" (ou sanitizada a zero) ⇒ zero linhas.
    if ($forceEmptySellers) {
        $where[] = '1=0';
    }
    // Refino de canal (varejo/licitação) por cima do universo do BiVisibilityScope.
    if ($channelRefineChannels !== null) {
        $where[] = 'nf.business_channel IN (' . $channelInSql($channelRefineChannels) . ')';
    }

    if ($fVendorIds === [] && $fVendedor !== '') {
        $where[]             = 'nf.nome_vendedor LIKE :vendedor';
        $params[':vendedor'] = '%' . $fVendedor . '%';
    }
    if ($fCliente !== '') {
        $where[]            = 'nf.nome_cliente LIKE :cliente';
        $params[':cliente'] = '%' . $fCliente . '%';
    }
    if ($fProduto !== '') {
        $where[]            = 'EXISTS (SELECT 1 FROM biomie_notas_fiscais_itens_canonicos i'
                            . ' WHERE i.company_id = nf.company_id AND i.nota_key = nf.nota_key'
                            . ' AND i.descricao_produto LIKE :produto)';
        $params[':produto'] = '%' . $fProduto . '%';
    }

    // Quick filters (non-commissionable components / linked order)
    if ($fQuick === 'com_componentes') {
        $where[] = '(COALESCE(nf.valor_frete,0) > 0'
                 . ' OR COALESCE(nf.valor_outras_despesas,0) > 0'
                 . ' OR COALESCE(nf.valor_seguro,0) > 0)';
    } elseif ($fQuick === 'sem_pedido') {
        $where[] = '(nf.codigo_pedido_origem IS NULL OR nf.codigo_pedido_origem = \'\')';
    }

    $whereStr = implode(' AND ', $where);

    // Breakdown por canal: SUM condicional na MESMA query e MESMO WHERE dos KPIs
    // ⇒ varejo + licitação = total, sempre (business_channel é um valor por NF;
    // o universo já está restrito a esses canais). Sem coluna, não há breakdown.
    $breakdownSelect = $hasChannelColNf
        ? ",
            COALESCE(SUM(CASE WHEN nf.business_channel = 'licitacao' THEN {$baseSql} ELSE 0 END), 0) AS base_licitacao,
            COALESCE(SUM(CASE WHEN nf.business_channel = 'varejo' THEN {$baseSql} ELSE 0 END), 0) AS base_varejo,
            SUM(CASE WHEN nf.business_channel = 'licitacao' THEN 1 ELSE 0 END) AS nfs_licitacao,
            SUM(CASE WHEN nf.business_channel = 'varejo' THEN 1 ELSE 0 END) AS nfs_varejo"
        : '';

    // ── KPIs ─────────────────────────────────────────────────────────────────
    $stmtKpi = $pdo->prepare(
        "SELECT
            COUNT(*) AS nfs,
            COUNT(DISTINCT {$clientIdentitySql}) AS clientes,
            COALESCE(SUM({$baseSql}), 0) AS base_comercial_nf,
            COALESCE(SUM(nf.valor_frete), 0) AS frete,
            COALESCE(SUM(nf.valor_outras_despesas), 0) AS outras_despesas,
            COALESCE(SUM(nf.valor_seguro), 0) AS seguro{$breakdownSelect}
         FROM {$table} nf
         WHERE {$whereStr}"
    );
    $stmtKpi->execute($params);
    $kpiRow = $stmtKpi->fetch(\PDO::FETCH_ASSOC) ?: [];

    $baseComercial    = (float) ($kpiRow['base_comercial_nf'] ?? 0);
    $nfsCount         = (int)   ($kpiRow['nfs'] ?? 0);
    $clientesCount    = (int)   ($kpiRow['clientes'] ?? 0);
    $freteTotal       = (float) ($kpiRow['frete'] ?? 0);
    $outrasTotal      = (float) ($kpiRow['outras_despesas'] ?? 0);
    $seguroTotal      = (float) ($kpiRow['seguro'] ?? 0);
    $ticketMedio      = $nfsCount > 0 ? $baseComercial / $nfsCount : 0.0;
    $naoComissionavel = $freteTotal + $outrasTotal + $seguroTotal;

    // Breakdown por canal (para a UI mostrar a composição sem recontar).
    $baseLicitacao = $hasChannelColNf ? (float) ($kpiRow['base_licitacao'] ?? 0) : null;
    $baseVarejo    = $hasChannelColNf ? (float) ($kpiRow['base_varejo'] ?? 0) : null;
    $nfsLicitacao  = $hasChannelColNf ? (int) ($kpiRow['nfs_licitacao'] ?? 0) : null;
    $nfsVarejo     = $hasChannelColNf ? (int) ($kpiRow['nfs_varejo'] ?? 0) : null;

    // Cancelamentos usam exatamente o mesmo tenant, periodo, escopo de
    // vendedor e filtros da leitura faturada; muda apenas o status fiscal.
    $cancelWhere = $where;
    $cancelWhere[1] = "nf.status_fiscal = 'cancelada'";
    $stmtCancelled = $pdo->prepare(
        "SELECT COUNT(*) AS nfs, COALESCE(SUM({$baseSql}), 0) AS base_comercial_nf
         FROM {$table} nf
         WHERE " . implode(' AND ', $cancelWhere)
    );
    $stmtCancelled->execute($params);
    $cancelRow   = $stmtCancelled->fetch(\PDO::FETCH_ASSOC) ?: [];
    $cancelNfs   = (int) ($cancelRow['nfs'] ?? 0);
    $cancelValue = (float) ($cancelRow['base_comercial_nf'] ?? 0);

    // ── CARTEIRA — venda para cliente RECORRENTE ─────────────────────────────
    //
    // DEFINIÇÃO (2026-07-27, decidida pelo negócio): Carteira é a receita vinda
    // de cliente que JÁ COMPROU ANTES — ele estava na base e voltou a comprar.
    // Substitui a definição anterior ("pedido autorizado ainda não faturado"),
    // que media outra coisa (etapa do funil) e foi descontinuada.
    //
    // O complemento é NOVOS (primeira compra). Por construção:
    //     carteira + novos = faturado total  ⇒ sem dupla contagem.
    //
    // "Recorrente" = existe NF autorizada do MESMO cliente ANTES do início do
    // período. A identidade do cliente segue a mesma precedência do KPI de
    // clientes (código Omie → documento → nome), para que os dois números falem
    // do mesmo universo. Carteirização deixou de ser conceito à parte: quem é da
    // carteira é exatamente quem já era cliente.
    $recurringSql = "EXISTS (
        SELECT 1 FROM {$table} prev
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

    $carteiraValue = 0.0; $carteiraNfs = 0; $carteiraClientes = 0;
    try {
        $stmtCarteira = $pdo->prepare(
            "SELECT COUNT(*) AS nfs,
                    COALESCE(SUM({$baseSql}), 0) AS valor,
                    COUNT(DISTINCT {$clientIdentitySql}) AS clientes
               FROM {$table} nf
              WHERE {$whereStr} AND {$recurringSql}"
        );
        // Placeholder PRÓPRIO para o recorte histórico: o PDO com prepares
        // nativos recusa o mesmo nome duas vezes na mesma instrução, e :start já
        // é usado no WHERE principal.
        $carteiraParams = $params;
        $carteiraParams[':cart_start'] = $start;
        $stmtCarteira->execute($carteiraParams);
        $cRow = $stmtCarteira->fetch(\PDO::FETCH_ASSOC) ?: [];
        $carteiraNfs      = (int)   ($cRow['nfs'] ?? 0);
        $carteiraValue    = (float) ($cRow['valor'] ?? 0);
        $carteiraClientes = (int)   ($cRow['clientes'] ?? 0);
    } catch (\Throwable $carteiraError) {
        // Falha aqui NÃO pode virar "R$ 0,00" mudo (lição do card de orçamentos).
        $dashboardLogger->warning('commercial_dashboard: carteira (recorrentes) indisponível', [
            'message' => $carteiraError->getMessage(),
        ]);
        $carteiraNfs = -1;   // -1 sinaliza indisponível, distinto de zero real
    }
    // Novos = complemento exato do faturado (nunca recalculado por outra via,
    // justamente para a soma fechar sempre).
    $novosValue = $carteiraNfs >= 0 ? max(0.0, $baseComercial - $carteiraValue) : 0.0;
    $novosNfs   = $carteiraNfs >= 0 ? max(0, $nfsCount - $carteiraNfs) : 0;

    // ── Orçamentos (etapa 10) ────────────────────────────────────────────────
    $walletQuotesFailed = null;      // motivo da degradação (null = tudo certo)
    $quotesSellerIdsUsed = null;     // escopo de vendedor REALMENTE usado nos orçamentos
    $walletValue = 0.0; $walletOrders = 0;
    $quotesValue = 0.0; $quotesOrders = 0;
    $quotesOverdue = 0; $quotesCritical = 0; $quotesToday = 0;
    try {
        $pedTbl = 'biomie_pedidos_canonicos';
        $pdParams = [':pdstart' => $start, ':pdend' => $end];
        $pdWhere  = TenantContext::sqlFilter('p')
            . " AND p.operation_scope = 'commercial'"
            . " AND p.data_ref >= :pdstart"
            . " AND p.data_ref < DATE_ADD(:pdend, INTERVAL 1 DAY)"
            . ' AND ' . BiVisibilityScope::getCondition($pdo, $pedTbl, 'p');

        $pdWhere .= ' AND ' . VendorScopeFilter::conditionForContext(
            $pdo,
            $userId,
            $companyIds,
            $fVendorIds,
            'p',
            $pdParams,
            'codigo_vendedor_omie',
            'company_id',
            $sellerSelfOnly
        );

        // Mesmo refino de canal/vendedor da leitura faturada, para não divergir.
        if ($forceEmptySellers) {
            $pdWhere .= ' AND 1=0';
        }
        if (
            $channelRefineChannels !== null
            && SchemaCapabilities::columnExists($pdo, $pedTbl, 'business_channel')
        ) {
            $pdWhere .= ' AND p.business_channel IN (' . $channelInSql($channelRefineChannels) . ')';
        }

        if ($fVendorIds === [] && $fVendedor !== '') {
            $pdWhere             .= ' AND p.nome_vendedor LIKE :pdvend';
            $pdParams[':pdvend']  = '%' . $fVendedor . '%';
        }
        if ($fCliente !== '') {
            $pdWhere .= ' AND p.nome_cliente LIKE :pdclient';
            $pdParams[':pdclient'] = '%' . $fCliente . '%';
        }
        $stmtWallet = $pdo->prepare(
            "SELECT COUNT(*) AS cnt, COALESCE(SUM(valor_mercadorias), 0) AS val
             FROM {$pedTbl} p
             WHERE p.status_comercial = 'autorizado' AND {$pdWhere} LIMIT 1"
        );
        $stmtWallet->execute($pdParams);
        $wRow = $stmtWallet->fetch(\PDO::FETCH_ASSOC) ?: [];
        $walletValue  = (float) ($wRow['val'] ?? 0);
        $walletOrders = (int)   ($wRow['cnt'] ?? 0);

        // Orçamentos EM ABERTO — FONTE ÚNICA: QuoteAgendaReadService::summary().
        // O MESMO modelo que alimenta a miniagenda da home e a tela da Agenda,
        // para que os três exibam exatamente a mesma quantidade e valor.
        //
        // Ponto-no-tempo, SEM período: "quanto está aberto agora". O filtro de
        // data do dashboard NÃO entra aqui — um orçamento cuja próxima cobrança
        // cai fora do mês continua aberto (bug de 2026-07-22: card 56 × mini 55).
        // Não usa o canônico por etapa_omie: ele não captura orçamento com
        // previsão futura (blind spot do sync por janela) e dava ~0.
        if (Auth::hasAnyCapability([
            'sales.quotes.followup.view',
            'sales.quotes.followup.self',
        ])) {
            $agendaConfig = new \BIomie\Config();
            foreach ($companyIds as $cidQuotes) {
                $agendaAvailability = QuoteAgendaAvailability::forCompany(
                    $agendaConfig,
                    $pdo,
                    $cidQuotes
                );
                if (!$agendaAvailability['enabled']) {
                    continue;
                }

                $quotesFilters = [];
                $quotesRequested = [];
                if ($fVendorIds !== []) {
                    $quotesRequested = $fVendorIds;
                } elseif ($fVendedor !== '') {
                    // O read model escopa por CÓDIGO do vendedor; o dashboard
                    // traduz nome → códigos dentro de cada empresa.
                    $vStmt = $pdo->prepare(
                        'SELECT codigo_omie FROM biomie_vendedores_omie
                          WHERE company_id = :c AND nome LIKE :n AND codigo_omie <> \'\''
                    );
                    $vStmt->execute([':c' => $cidQuotes, ':n' => '%' . $fVendedor . '%']);
                    $quotesRequested = array_map(
                        'strval',
                        $vStmt->fetchAll(\PDO::FETCH_COLUMN)
                    );
                }

                // A seleção sempre intersecta o escopo DA EMPRESA corrente.
                // Isso impede que um código permitido em A libere o homônimo B.
                //
                // NÃO USE `?? []` AQUI: o escopo null significa "sem restrição"
                // (vê todos) e é um valor LEGÍTIMO do array. O `??` dispara
                // também para null, convertendo "vê todos" em "não vê ninguém" —
                // a interseção logo abaixo zerava e o card Orçamentos ficava em
                // R$ 0,00 para todo usuário irrestrito que filtrasse vendedores.
                // array_key_exists preserva a distinção null ≠ [].
                $companySellerScope = array_key_exists($cidQuotes, $sellerScopesByCompany)
                    ? $sellerScopesByCompany[$cidQuotes]
                    : [];
                if ($fVendorIds === [] && $fVendedor !== '' && $quotesRequested === []) {
                    $quotesScope = [];
                } elseif ($quotesRequested === []) {
                    $quotesScope = $companySellerScope;
                } elseif ($companySellerScope === null) {
                    $quotesScope = array_values($quotesRequested);
                } else {
                    $quotesScope = array_values(array_intersect(
                        $companySellerScope,
                        $quotesRequested
                    ));
                }
                if ($quotesScope !== null) {
                    $quotesFilters['seller_ids'] = $quotesScope;
                }
                // Mesmo refino de canal do faturado: o card de orçamentos segue o
                // toggle de operação (só quando há estreitamento real).
                if ($channelRefineChannels !== null) {
                    $quotesFilters['channels'] = $channelRefineChannels;
                }

                $quotesSellerIdsUsed = $quotesFilters['seller_ids'] ?? 'sem-restricao';
                $quotesSummary = (new \BIomie\QuoteAgenda\QuoteAgendaReadService(
                    $pdo,
                    $cidQuotes
                ))->summary($quotesFilters);
                $quotesKpis = $quotesSummary['kpis']['open'] ?? [];
                $quotesValue += (float) ($quotesKpis['total_value'] ?? 0);
                $quotesOrders += (int) ($quotesKpis['count'] ?? 0);
                $quotesOverdue += (int) ($quotesSummary['kpis']['overdue']['count'] ?? 0);
                $quotesCritical += (int) ($quotesSummary['kpis']['critical']['count'] ?? 0);
                $quotesToday += (int) ($quotesSummary['kpis']['charge_today']['count'] ?? 0);
            }
        }
    } catch (\Throwable $walletQuotesError) {
        // Degradar para 0 é aceitável (a base de pedidos pode estar indisponível),
        // MAS EM SILÊNCIO NÃO: um catch cego aqui transformava qualquer falha num
        // "R$ 0,00" indistinguível de "não há orçamentos" — o usuário via zero e
        // não havia rastro nenhum para investigar. Agora fica registrado e o
        // bloco diagnostics da resposta sinaliza a degradação.
        $walletQuotesFailed = $walletQuotesError->getMessage();
        $dashboardLogger->warning('commercial_dashboard: carteira/orçamentos indisponíveis', [
            'message' => $walletQuotesFailed,
            'file' => $walletQuotesError->getFile() . ':' . $walletQuotesError->getLine(),
            'vendor_ids' => $fVendorIds,
            'company_ids' => $companyIds,
        ]);
    }

    // ── Series diária ────────────────────────────────────────────────────────
    // Meta proporcional e ritmo do recorte atual. A meta somente e comparavel
    // quando cliente/produto/filtro rapido nao fragmentam o realizado.
    $targetSummary = [
        'available'      => false,
        'comparable'     => false,
        'period_target'  => 0.0,
        'realized'       => round($baseComercial, 2),
        'gap'            => 0.0,
        'attainment_pct' => null,
        'expected_pct'   => null,
        'pace_pct'       => null,
        'status'         => 'SEM_META',
        'status_label'   => 'Meta nao cadastrada',
        'months'         => [],
    ];
    try {
        $periodContext = new CommercialPeriodScopeContext($start, $end);
        $targetRows = [];
        foreach ($companyIds as $targetCompanyId) {
            $companyTargetRows = (new TargetsRepository(
                pdo: $pdo,
                logger: $dashboardLogger,
                tenantCompanyId: $targetCompanyId
            ))->getTargetsForMonths(
                $periodContext->monthsCovered(),
                count($fVendorIds) === 1 ? $fVendorId : null
            );
            foreach ($companyTargetRows as $targetRow) {
                $targetRow['company_id'] = $targetCompanyId;
                $targetRows[] = $targetRow;
            }
        }
        $monthlyTargets = [];
        foreach ($targetRows as $targetRow) {
            $targetCompanyId = (int) ($targetRow['company_id'] ?? 0);
            $targetVendorId = trim((string) $targetRow['vendor_id']);
            $targetVendorName = trim((string) $targetRow['vendor_name']);
            // MESMO cuidado do bloco de orçamentos: `?? []` converteria o escopo
            // null ("vê todos") em [] ("vê ninguém"), e o teste abaixo passaria a
            // descartar TODA meta — o painel mostrava "Meta não cadastrada" mesmo
            // com metas ativas. array_key_exists preserva null ≠ [].
            $companySellerScope = array_key_exists($targetCompanyId, $sellerScopesByCompany)
                ? $sellerScopesByCompany[$targetCompanyId]
                : [];
            if (
                $companySellerScope !== null
                && !in_array($targetVendorId, $companySellerScope, true)
            ) {
                continue;
            }
            if ($fVendorIds !== [] && !in_array($targetVendorId, $fVendorIds, true)) {
                continue;
            }
            if (
                $fVendorIds === []
                && $fVendedor !== ''
                && stripos($targetVendorName, $fVendedor) === false
            ) {
                continue;
            }

            $month = (string) $targetRow['year_month'];
            if ($month !== '') {
                $monthlyTargets[$month] = ($monthlyTargets[$month] ?? 0.0)
                    + (float) $targetRow['target_amount'];
            }
        }

        $periodTarget = $periodContext->proportionalTarget($monthlyTargets);
        $isComparable = $fCliente === '' && $fProduto === '' && $fQuick === '';
        $attainmentPct = $isComparable && $periodTarget > 0.0
            ? round(($baseComercial / $periodTarget) * 100, 1)
            : null;

        $today = date('Y-m-d');
        $elapsedDays = $today < $start
            ? 0
            : ($today > $end
                ? $periodContext->daysPeriod()
                : (int) ((strtotime($today) - strtotime($start)) / 86400) + 1);
        $expectedPct = round(
            ($elapsedDays / max(1, $periodContext->daysPeriod())) * 100,
            1
        );
        $pacePct = $attainmentPct !== null && $expectedPct > 0.0
            ? round(($attainmentPct / $expectedPct) * 100, 1)
            : null;

        $status = 'SEM_META';
        $statusLabel = 'Meta nao cadastrada';
        if ($periodTarget > 0.0 && !$isComparable) {
            $status = 'RECORTE_NAO_COMPARAVEL';
            $statusLabel = 'Meta fora deste recorte';
        } elseif ($periodTarget > 0.0 && $pacePct !== null) {
            if ($attainmentPct >= 100.0 || $pacePct >= 95.0) {
                $status = 'NO_RITMO';
                $statusLabel = $attainmentPct >= 100.0 ? 'Meta atingida' : 'No ritmo';
            } elseif ($pacePct >= 75.0) {
                $status = 'ATENCAO';
                $statusLabel = 'Atencao ao ritmo';
            } else {
                $status = 'RISCO';
                $statusLabel = 'Risco de meta';
            }
        }

        $targetSummary = [
            'available'      => $periodTarget > 0.0,
            'comparable'     => $isComparable,
            'period_target'  => round($periodTarget, 2),
            'realized'       => round($baseComercial, 2),
            'gap'            => $isComparable ? round(max(0.0, $periodTarget - $baseComercial), 2) : 0.0,
            'attainment_pct' => $attainmentPct,
            'expected_pct'   => $expectedPct,
            'pace_pct'       => $pacePct,
            'status'         => $status,
            'status_label'   => $statusLabel,
            'months'         => $monthlyTargets,
        ];
    } catch (\Throwable $targetError) {
        $dashboardLogger->warning('commercial_dashboard target summary unavailable', [
            'message' => $targetError->getMessage(),
        ]);
    }

    $stmtDia = $pdo->prepare(
        "SELECT
            nf.data_ref AS dia,
            COUNT(*) AS nfs,
            COALESCE(SUM({$baseSql}), 0) AS base_comercial
         FROM {$table} nf
         WHERE {$whereStr}
         GROUP BY dia
         ORDER BY dia"
    );
    $stmtDia->execute($params);
    $seriesDia = $stmtDia->fetchAll(\PDO::FETCH_ASSOC);

    // ── Series mensal ────────────────────────────────────────────────────────
    $stmtMes = $pdo->prepare(
        "SELECT
            DATE_FORMAT(nf.data_ref, '%Y-%m') AS mes,
            COUNT(*) AS nfs,
            COALESCE(SUM({$baseSql}), 0) AS base_comercial
         FROM {$table} nf
         WHERE {$whereStr}
         GROUP BY mes
         ORDER BY mes"
    );
    $stmtMes->execute($params);
    $seriesMes = $stmtMes->fetchAll(\PDO::FETCH_ASSOC);

    // ── Ranking vendedores (top 10) ───────────────────────────────────────────
    $stmtVnd = $pdo->prepare(
        "SELECT
            nf.codigo_vendedor_omie,
            nf.nome_vendedor,
            COUNT(*) AS nfs,
            COUNT(DISTINCT {$clientIdentitySql}) AS clientes,
            COALESCE(SUM({$baseSql}), 0) AS base_comercial
         FROM {$table} nf
         WHERE {$whereStr}
         GROUP BY nf.codigo_vendedor_omie, nf.nome_vendedor
         ORDER BY base_comercial DESC
         LIMIT 10"
    );
    $stmtVnd->execute($params);
    $topVendedores = $stmtVnd->fetchAll(\PDO::FETCH_ASSOC);

    // ── Ranking clientes (top 10) ─────────────────────────────────────────────
    $stmtCli = $pdo->prepare(
        "SELECT
            nf.nome_cliente,
            COUNT(*) AS nfs,
            COALESCE(SUM({$baseSql}), 0) AS base_comercial,
            MAX(nf.data_ref) AS ultima_compra
         FROM {$table} nf
         WHERE {$whereStr}
         GROUP BY nf.nome_cliente
         ORDER BY base_comercial DESC
         LIMIT 10"
    );
    $stmtCli->execute($params);
    $topClientes = $stmtCli->fetchAll(\PDO::FETCH_ASSOC);

    // ── Ranking produtos (top 10, via itens) ─────────────────────────────────
    $stmtProd = $pdo->prepare(
        "SELECT
            i.descricao_produto AS produto,
            i.codigo_produto AS codigo,
            COUNT(DISTINCT CONCAT(i.company_id, ':', i.nota_key)) AS nfs,
            SUM(i.quantidade) AS quantidade,
            COALESCE(SUM(i.valor_total), 0) AS receita
         FROM biomie_notas_fiscais_itens_canonicos i
         JOIN {$table} nf
           ON nf.company_id = i.company_id
          AND nf.nota_key = i.nota_key
         WHERE {$whereStr}
         GROUP BY i.codigo_produto, i.descricao_produto
         ORDER BY receita DESC
         LIMIT 10"
    );
    $stmtProd->execute($params);
    $topProdutos = $stmtProd->fetchAll(\PDO::FETCH_ASSOC);

    // ── Top NFs (paginado) ────────────────────────────────────────────────────
    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM {$table} nf WHERE {$whereStr}");
    $stmtCount->execute($params);
    $totalNfs = (int) $stmtCount->fetchColumn();

    $pagination = new Pagination($totalNfs, $page, $perPage);

    $stmtNfs = $pdo->prepare(
        "SELECT
            nf.nota_key,
            nf.nota_id_omie,
            nf.chave_nfe,
            nf.numero_nf,
            nf.data_emissao,
            nf.nome_cliente,
            nf.nome_vendedor,
            nf.codigo_pedido_origem,
            nf.valor_total,
            nf.valor_frete,
            nf.valor_outras_despesas,
            nf.valor_seguro,
            COALESCE(nf.valor_base_comercial_nf, {$baseSql}) AS base_comercial_nf
         FROM {$table} nf
         WHERE {$whereStr}
         ORDER BY {$sortExpr} {$sortDir}
         LIMIT :limit OFFSET :offset"
    );
    $stmtNfs->bindValue(':limit',  $pagination->perPage, \PDO::PARAM_INT);
    $stmtNfs->bindValue(':offset', $pagination->offset,  \PDO::PARAM_INT);
    foreach ($params as $k => $v) {
        $stmtNfs->bindValue($k, $v);
    }
    $stmtNfs->execute();
    $topNfs = $stmtNfs->fetchAll(\PDO::FETCH_ASSOC);

    $queryMs = round((microtime(true) - $t0) * 1000, 1);

    // ── Meta do Filtro Comercial Inteligente ──────────────────────────────────
    // filter_hash + escopo efetivo: a UI só pinta a resposta cujo hash bate com o
    // pedido atual (guarda contra resposta atrasada) e mostra canal/sanitização.
    $filterMeta = [
        'filter_hash'    => null,
        'business_scope' => $businessScope,
        'company_id'     => $singleCompanyId,
        'source'         => $resolvedFilter?->source ?? 'default',
        'sanitized'      => (bool) ($resolvedFilter?->sanitized() ?? false),
        'channel_scope'  => null,
        'sanitization'   => [
            'channels_dropped' => $resolvedFilter?->droppedChannels ?? [],
            'sellers_dropped'  => $resolvedFilter?->droppedSellers ?? [],
        ],
    ];
    if ($resolvedFilter !== null && $singleCompanyId !== null) {
        $effectiveScope = $resolvedFilter->toEffectiveScope($singleCompanyId, $start, $end, [
            'client'        => $fCliente !== '' ? $fCliente : null,
            'product'       => $fProduto !== '' ? $fProduto : null,
            'with_freight'  => $fQuick === 'com_componentes',
            'without_order' => $fQuick === 'sem_pedido',
        ]);
        $filterMeta['filter_hash']   = $effectiveScope->filterHash();
        $filterMeta['channel_scope'] = $resolvedFilter->channelScopeMeta();
    }

    Http::json([
        'ok'   => true,
        'kpis' => [
            'base_comercial_nf'  => round($baseComercial, 2),
            'nfs'                => $nfsCount,
            'clientes'           => $clientesCount,
            'ticket_medio'       => round($ticketMedio, 2),
            'frete'              => round($freteTotal, 2),
            'outras_despesas'    => round($outrasTotal, 2),
            'seguro'             => round($seguroTotal, 2),
            'nao_comissionavel'  => round($naoComissionavel, 2),
            'cancelled_value'    => round($cancelValue, 2),
            'cancelled_orders'   => $cancelNfs,
            // CARTEIRA = receita de cliente recorrente (já comprou antes).
            // wallet_* mantém o NOME da chave por compatibilidade com a UI, mas
            // agora carrega a definição nova; carteira_* é o nome canônico.
            'wallet_value'       => round($carteiraValue, 2),
            'wallet_orders'      => $carteiraNfs > 0 ? $carteiraNfs : 0,
            'carteira_value'     => round($carteiraValue, 2),
            'carteira_nfs'       => max(0, $carteiraNfs),
            'carteira_clientes'  => $carteiraClientes,
            'carteira_pct'       => $baseComercial > 0
                ? round(($carteiraValue / $baseComercial) * 100, 1) : 0,
            'carteira_disponivel' => $carteiraNfs >= 0,
            'novos_value'        => round($novosValue, 2),
            'novos_nfs'          => $novosNfs,
            'novos_pct'          => $baseComercial > 0
                ? round(($novosValue / $baseComercial) * 100, 1) : 0,
            'quotes_value'       => round($quotesValue, 2),
            'quotes_orders'      => $quotesOrders,
            'quotes_overdue'     => $quotesOverdue,
            'quotes_critical'    => $quotesCritical,
            'quotes_today'       => $quotesToday,
            'breakdown'          => [
                'available' => $hasChannelColNf,
                'licitacao' => [
                    'value' => $baseLicitacao === null ? null : round($baseLicitacao, 2),
                    'nfs'   => $nfsLicitacao,
                ],
                'varejo'    => [
                    'value' => $baseVarejo === null ? null : round($baseVarejo, 2),
                    'nfs'   => $nfsVarejo,
                ],
            ],
        ],
        'meta'   => $filterMeta,
        'target' => $targetSummary,
        'seller_scope' => [
            'mode'                => $sellerScopeMode,
            'restricted'          => $sellerScopeRestricted,
            'allowed_count'       => $sellerScopeAllowedCount,
            'companies'           => $sellerScopeRows,
            'requested_vendor_id' => $fVendorId,
            'requested_vendor_ids' => $fVendorIds,
        ],
        'series' => [
            'evolucao_diaria'  => array_map(static fn (array $r): array => [
                'dia'            => (string) ($r['dia'] ?? ''),
                'nfs'            => (int)    $r['nfs'],
                'base_comercial' => round((float) $r['base_comercial'], 2),
            ], $seriesDia),
            'evolucao_mensal'  => array_map(static fn (array $r): array => [
                'mes'            => (string) ($r['mes'] ?? ''),
                'nfs'            => (int)    $r['nfs'],
                'base_comercial' => round((float) $r['base_comercial'], 2),
            ], $seriesMes),
        ],
        'rankings' => [
            'top_vendedores' => array_map(static fn (array $r): array => [
                'vendor_id'      => (string) ($r['codigo_vendedor_omie'] ?? ''),
                'codigo'         => (string) ($r['codigo_vendedor_omie'] ?? ''),
                'nome'           => (string) ($r['nome_vendedor'] ?? ''),
                'nfs'            => (int)    $r['nfs'],
                'clientes'       => (int)    $r['clientes'],
                'base_comercial' => round((float) $r['base_comercial'], 2),
            ], $topVendedores),
            'top_clientes' => array_map(static fn (array $r): array => [
                'nome'           => (string) ($r['nome_cliente'] ?? ''),
                'nfs'            => (int)    $r['nfs'],
                'base_comercial' => round((float) $r['base_comercial'], 2),
                'ultima_compra'  => (string) ($r['ultima_compra'] ?? ''),
            ], $topClientes),
            'top_produtos' => array_map(static fn (array $r): array => [
                'produto'        => (string) ($r['produto'] ?? ''),
                'codigo'         => (string) ($r['codigo'] ?? ''),
                'nfs'            => (int)    $r['nfs'],
                'quantidade'     => (float)  $r['quantidade'],
                'receita'        => round((float) $r['receita'], 2),
            ], $topProdutos),
            'top_nfs' => array_map(static fn (array $r): array => [
                'nota_key'            => (string) ($r['nota_key'] ?? ''),
                'nota_id_omie'        => (string) ($r['nota_id_omie'] ?? ''),
                'chave_nfe'           => (string) ($r['chave_nfe'] ?? ''),
                'numero_nf'           => (string) ($r['numero_nf'] ?? ''),
                'data_emissao'        => (string) ($r['data_emissao'] ?? ''),
                'nome_cliente'        => (string) ($r['nome_cliente'] ?? ''),
                'nome_vendedor'       => (string) ($r['nome_vendedor'] ?? ''),
                'codigo_pedido'       => (string) ($r['codigo_pedido_origem'] ?? ''),
                'base_comercial_nf'   => round((float) ($r['base_comercial_nf'] ?? 0), 2),
                'valor_frete'         => round((float) ($r['valor_frete'] ?? 0), 2),
                'valor_outras'        => round((float) ($r['valor_outras_despesas'] ?? 0), 2),
                'valor_seguro'        => round((float) ($r['valor_seguro'] ?? 0), 2),
            ], $topNfs),
        ],
        'pagination' => $pagination->toArray(),
        'filters'    => [
            'vendor_id' => $fVendorId,
            'vendor_ids' => $fVendorIds,
            'vendedor'  => $fVendedor,
            'cliente'   => $fCliente,
            'produto'   => $fProduto,
            'quick'     => $fQuick,
            'channel'   => $resolvedFilter?->effectiveChannels ?? [],
            'sort'      => $rawSort,
            'direction' => $sortDir,
            'page'      => $page,
            'per_page'  => $perPage,
        ],
        'diagnostics' => [
            'query_ms'   => $queryMs,
            'source'     => 'canonical_nf',
            'base'       => 'Base Comercial NF',
            'visibility' => 'BiVisibilityScope',
            'seller_scope_mode' => $sellerScopeMode,
            // Zero por FALHA é diferente de zero por ausência de dados. Sem este
            // sinal, um erro na leitura de carteira/orçamentos aparecia como
            // "R$ 0,00" legítimo e ninguém tinha como distinguir.
            'wallet_quotes_degraded' => $walletQuotesFailed !== null,
            'wallet_quotes_error' => $walletQuotesFailed,
            'quotes_seller_ids' => $quotesSellerIdsUsed,
        ],
    ]);
} catch (\InvalidArgumentException $e) {
    Http::json(['ok' => false, 'message' => $e->getMessage()], 400);
} catch (\Throwable $e) {
    $dashboardLogger->warning('commercial_dashboard.php failed', ['message' => $e->getMessage()]);
    Http::json(['ok' => false, 'message' => 'Erro interno no painel comercial.'], 500);
}
