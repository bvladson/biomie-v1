<?php

declare(strict_types=1);

/**
 * AGENDA DE ORÇAMENTOS — base compartilhada dos endpoints (Fase 5).
 *
 * Não é um endpoint: é incluído por api/quote_agenda_*.php depois do bootstrap.
 *
 * REGRAS COMUNS
 *  · Isolamento por TenantContext/company_id em TODA consulta.
 *  · Nenhum endpoint consulta o Omie — só banco.
 *  · Respostas autenticadas por empresa ⇒ `Cache-Control: private, no-store`
 *    (RFC 9111: no-store impede armazenamento; private veda cache compartilhado).
 *  · Dados derivados do Omie SEMPRE acompanhados do bloco `freshness` lido na
 *    MESMA visão consistente — a UI nunca mostra número sem saber se confia nele.
 *  · Sem ETag: uma cobrança muda a representação sem mudar o snapshot do Omie,
 *    então snapshot_hash sozinho não identifica a representação selecionada
 *    (RFC 9110). Enquanto não houver validador completo, não emitimos ETag.
 *  · FEATURE FLAG: esconder a página NÃO basta — quem tem a capability poderia
 *    chamar a API direto. TODO endpoint recusa quando o módulo está desativado.
 */

use BIomie\Auth;
use BIomie\Commercial\CommercialFilterPreferenceStore;
use BIomie\Commercial\CommercialRequestScope;
use BIomie\Commercial\CommercialScopeDeniedException;
use BIomie\Database;
use BIomie\Http;
use BIomie\QuoteAgenda\QuoteAgendaAvailability;
use BIomie\QuoteAgenda\QuoteAgendaReadService;
use BIomie\QuoteAgenda\QuoteFollowupChargeService;
use BIomie\QuoteAgenda\SellerBindingResolver;
use BIomie\Support\SellerVisibilityScope;
use BIomie\Support\TenantContext;
use BIomie\Support\VendorScopeFilter;

/**
 * Recusa a requisição quando o módulo está desligado.
 *
 * Chamado por TODOS os endpoints, logo após o Auth: a capability autoriza o
 * PAPEL, a flag autoriza o MÓDULO — são controles independentes.
 */
function quoteAgendaRequireEnabled(): void
{
    $availability = quoteAgendaAvailability();
    if (!$availability['enabled']) {
        $status = match ($availability['reason']) {
            'availability_unavailable' => 503,
            'tenant_unavailable' => 409,
            default => 403,
        };

        quoteAgendaRespondRaw([
            'ok' => false,
            'error' => $availability['error'] ?? 'feature_disabled',
            'message' => $availability['message'],
            'feature' => [
                'reason' => $availability['reason'],
                'global_mode' => $availability['global_mode'],
            ],
        ], $status);
    }
}

/**
 * Estado da Agenda para a empresa da sessao. Uma unica consulta por request.
 *
 * @return array{
 *   enabled:bool,
 *   reason:string,
 *   error:string|null,
 *   message:string,
 *   global_mode:string,
 *   company_policy_enabled:bool|null
 * }
 */
function quoteAgendaAvailability(): array
{
    static $state = null;
    if (is_array($state)) {
        return $state;
    }

    /** @var \BIomie\Config $config */
    $config = $GLOBALS['config'];
    $state = QuoteAgendaAvailability::forCompany(
        $config,
        quoteAgendaPdo(),
        quoteAgendaCompanyId(),
    );

    return $state;
}

function quoteAgendaPdo(): PDO
{
    /** @var \BIomie\Config $config */
    $config = $GLOBALS['config'];
    $conn = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    if (!Database::isConfigured($config, $conn)) {
        Http::json(['ok' => false, 'error' => 'database_unavailable',
            'message' => 'Banco de dados não configurado.'], 503);
    }

    return Database::connect($config, $conn);
}

function quoteAgendaCompanyId(): int
{
    $cid = (int) TenantContext::companyId();
    if ($cid <= 0) {
        Http::json(['ok' => false, 'error' => 'tenant_unavailable',
            'message' => 'Empresa não identificada na sessão.'], 409);
    }

    return $cid;
}

function quoteAgendaReadService(): QuoteAgendaReadService
{
    return new QuoteAgendaReadService(quoteAgendaPdo(), quoteAgendaCompanyId());
}

function quoteAgendaChargeService(): QuoteFollowupChargeService
{
    return new QuoteFollowupChargeService(quoteAgendaPdo(), quoteAgendaCompanyId());
}

/**
 * Escopo de vendedores do usuário logado na empresa ativa.
 *
 * @return list<string>|null null = todos da empresa · [] = nenhum
 */
function quoteAgendaSellerScope(): ?array
{
    static $resolved = false;
    /** @var list<string>|null $resolvedScope */
    static $resolvedScope = null;
    if ($resolved) {
        return $resolvedScope;
    }

    $companyId = quoteAgendaCompanyId();
    $userId = (int) (Auth::currentUser()['id'] ?? 0);

    // O perfil `.self` nunca herda o fallback "sem vínculo = todos" usado para
    // gestores. A identidade do vendedor é obrigatória e vazio significa zero.
    if (
        !Auth::hasCapability('sales.quotes.followup.view')
        && Auth::hasCapability('sales.quotes.followup.self')
    ) {
        $resolvedScope = (new SellerBindingResolver(quoteAgendaPdo()))
            ->sellerIdsFor($userId, $companyId);
        $resolved = true;
        return $resolvedScope;
    }

    $resolvedScope = (new SellerVisibilityScope(quoteAgendaPdo()))->allowedSellerIds(
        $companyId,
        $userId
    );
    $resolved = true;
    return $resolvedScope;
}

function quoteAgendaIsSelfOnly(): bool
{
    return !Auth::hasCapability('sales.quotes.followup.view')
        && Auth::hasCapability('sales.quotes.followup.self');
}

/**
 * Filtros aceitos — sempre sobre o acompanhamento, nunca sobre o canônico.
 *
 * CHOKE-POINT DE VISIBILIDADE: todo endpoint de leitura da Agenda passa por
 * aqui, então o escopo de vendedores é aplicado UMA vez, no servidor. Esconder
 * vendedor no dropdown não é controle — quem monta a URL na mão passaria por
 * cima. Aqui não passa.
 *
 * O filtro do usuário só RESTRINGE: pedir um vendedor fora do escopo é 403, não
 * "lista vazia" — a diferença entre "não existe" e "não é seu" já é informação,
 * e negar explicitamente evita que a UI trate o vazio como ausência de dados.
 *
 * @return array<string, string|list<string>>
 */
function quoteAgendaFilters(): array
{
    $f = [];
    $requested = [];
    $requestSource = '';
    try {
        // As chaves múltiplas prevalecem, mas uma chave vazia não apaga o
        // fallback legado. Isso permite a transição gradual dos clientes.
        foreach (['seller_ids', 'vendor_ids', 'seller_id', 'vendor_id'] as $key) {
            if (!array_key_exists($key, $_GET)) {
                continue;
            }
            $candidate = VendorScopeFilter::normalizeRequestedIds($_GET[$key]);
            if ($candidate !== []) {
                $requested = $candidate;
                $requestSource = $key;
                break;
            }
        }
    } catch (\InvalidArgumentException $e) {
        quoteAgendaRespondRaw([
            'ok' => false,
            'error' => 'invalid_seller_filter',
            'message' => $e->getMessage(),
        ], 400);
    }

    $scope = quoteAgendaSellerScope();
    $unauthorized = $scope === null
        ? []
        : array_values(array_diff($requested, $scope));
    if ($unauthorized !== []) {
        quoteAgendaRespondRaw([
            'ok' => false,
            'error' => 'seller_not_authorized',
            'message' => 'Um ou mais vendedores não estão autorizados para este usuário nesta empresa.',
        ], 403);
    }

    if ($requested !== []) {
        if ($requestSource === 'seller_id' && count($requested) === 1) {
            // Contrato escalar antigo: conserva seller_id e mantém a segunda
            // barreira do escopo no read model quando ele é restrito.
            $f['seller_id'] = $requested[0];
            if ($scope !== null) {
                $f['seller_ids'] = $scope;
            }
        } else {
            // A validação integral acima prova que a lista toda é subconjunto
            // do escopo. Aplicar a própria lista produz a interseção exata.
            $f['seller_ids'] = $requested;
        }
    } elseif ($scope !== null) {
        // `null` NÃO entra no array: ausência da chave = sem restrição. Uma
        // lista vazia entra e, no read model, vira `1 = 0`.
        $f['seller_ids'] = $scope;
    }

    // FILTRO COMERCIAL INTELIGENTE — propagado do Dashboard via a preferência
    // compartilhada 'commercial'. Choke-point: vale p/ summary, calendar, day,
    // detail e vendor de uma vez.
    //
    //  · CANAL: só entra quando há estreitamento real (varejo/licitação);
    //    "todas" não adiciona canal (preserva o LEFT JOIN).
    //  · VENDEDOR: sem esta parte, o card "Orçamentos" do Dashboard (que semeia
    //    pela preferência) e esta tela mostrariam NÚMEROS DIFERENTES para o mesmo
    //    recorte — o usuário filtra por um vendedor no Dashboard e a Agenda
    //    continua somando todo mundo. A URL continua vencendo a preferência, e a
    //    interseção com $scope mantém a autorização intacta.
    try {
        $commScope = CommercialRequestScope::fromRequest(
            quoteAgendaPdo(),
            CommercialFilterPreferenceStore::PAGE_COMMERCIAL
        );
        if ($commScope->isChannelNarrowed() && $commScope->resolved !== null) {
            $f['channels'] = $commScope->resolved->effectiveChannels;
        }

        if ($requested === []) {
            $prefSellers = $commScope->vendorScopeSeed();   // INTENÇÃO, não escopo
            if ($prefSellers !== []) {
                $f['seller_ids'] = $scope === null
                    ? $prefSellers
                    : array_values(array_intersect($scope, $prefSellers));
            } elseif ($commScope->forceEmptySellers()) {
                // Preferência explícita "nenhum" (ou sanitizada a zero) ⇒ zero linhas.
                $f['seller_ids'] = [];
            }
        }
    } catch (CommercialScopeDeniedException $denied) {
        quoteAgendaRespondRaw([
            'ok' => false,
            'error' => 'channel_not_authorized',
            'message' => $denied->getMessage(),
        ], 403);
    }

    return $f;
}

/**
 * Recusa uma ESCRITA sobre acompanhamento fora do escopo de vendedores.
 *
 * Ler e cobrar são autorizações distintas, mas ambas se apoiam no mesmo escopo:
 * quem não pode ver a carteira também não pode registrar cobrança nela. Como o
 * alvo vem no corpo da request, a checagem tem que ser feita no servidor antes
 * de qualquer efeito.
 *
 * Alvo inexistente e alvo fora do escopo recebem a MESMA resposta: distinguir
 * os dois revelaria quais pedidos existem na empresa.
 */
function quoteAgendaAssertWritable(string $omieOrderId, int $followupId): void
{
    $scope = quoteAgendaSellerScope();
    if ($scope === null) {
        return;                       // sem restrição de vendedor
    }

    $params = ['cid' => quoteAgendaCompanyId()];
    $ph = [];
    foreach (array_values($scope) as $i => $sid) {
        $ph[] = ":ws{$i}";
        $params["ws{$i}"] = (string) $sid;
    }

    $found = false;
    if ($ph !== []) {
        $ref = $followupId > 0 ? 'id = :ref' : 'omie_order_id = :ref';
        $params['ref'] = $followupId > 0 ? $followupId : $omieOrderId;

        $st = quoteAgendaPdo()->prepare(
            'SELECT 1 FROM biomie_sales_quote_followup_schedule
              WHERE company_id = :cid AND ' . $ref . '
                AND seller_id IN (' . implode(', ', $ph) . ') LIMIT 1'
        );
        $st->execute($params);
        $found = $st->fetchColumn() !== false;
    }

    if (!$found) {
        quoteAgendaRespondRaw([
            'ok' => false,
            'error' => 'seller_not_authorized',
            'message' => 'Este orçamento não pertence a um vendedor autorizado para você.',
        ], 403);
    }
}

/**
 * Envelope padrão: data + freshness + generated_at, com no-store.
 *
 * @param array{data:mixed,freshness:array<string,mixed>,generated_at:string} $payload
 */
function quoteAgendaRespond(array $payload, int $status = 200): never
{
    $freshness = $payload['freshness'];
    if (quoteAgendaSellerScope() !== null) {
        // Estes totais e o hash descrevem a empresa inteira, não a carteira do
        // vendedor. Datas e estado de atualização continuam úteis e seguros.
        unset(
            $freshness['snapshot_hash'],
            $freshness['live_total'],
            $freshness['eligible_total']
        );
    }

    Http::json([
        'ok' => true,
        'data' => $payload['data'],
        'freshness' => $freshness,
        'generated_at' => $payload['generated_at'],
    ], $status, ['Cache-Control' => 'private, no-store']);
}

/**
 * @param array<string, mixed> $body
 * @param array<string, string> $headers
 */
function quoteAgendaRespondRaw(array $body, int $status = 200, array $headers = []): never
{
    Http::json($body, $status, $headers + ['Cache-Control' => 'private, no-store']);
}
