<?php

/**
 * api/geo_neighborhood_detail.php — Geo 360 / Detalhe do Bairro.
 *
 * Retorna resumo completo de um bairro: compradores, CNAEs, NFs autorizadas,
 * vendedores, evolução temporal — tudo de dados locais (nunca chama API Omie).
 *
 * Parâmetros:
 *   neighborhood_key  — obrigatório; chave normalizada do bairro
 *   neighborhood      — nome de exibição (opcional, fallback para key)
 *   city              — cidade (default: Fortaleza)
 *   start             — YYYY-MM-DD (default: 1º dia do mês atual)
 *   end               — YYYY-MM-DD (default: hoje)
 *   limit             — máx. linhas por seção (default 50, máx 200)
 *   tab               — carregamento sob demanda: resumo|compradores|cnaes|nfs|vendedores|evolucao|diagnostico
 *
 * Permissão: módulo geo
 * Nunca chama API Omie em tempo de tela.
 */

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Database;
use BIomie\Http;
use BIomie\Modules\Sales\Application\Geo\Geo360ReadService;
use BIomie\Modules\Sales\Infrastructure\Geo360ReadRepository;
use BIomie\RequestContext;

require __DIR__ . '/bootstrap.php';

Auth::requireModule('geo', true);

try {
    $neighborhoodKey = trim((string) Http::query('neighborhood_key'));
    if ($neighborhoodKey === '') {
        Http::json(['ok' => false, 'message' => 'Parametro neighborhood_key e obrigatorio.', 'request_id' => RequestContext::id()], 400);
    }

    $today      = date('Y-m-d');
    $monthStart = date('Y-m-01');
    $neighborhood = trim((string) Http::query('neighborhood', $neighborhoodKey));
    $city         = trim((string) Http::query('city', 'Fortaleza'));
    $start        = trim((string) Http::query('start'));
    $end          = trim((string) Http::query('end'));
    $limitRaw     = (int) Http::query('limit', 50);
    $limit        = max(1, min($limitRaw, 200));
    $tab          = trim((string) Http::query('tab'));
    $tab          = $tab !== '' ? $tab : null;

    if ($start === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
        $start = $monthStart;
    }
    if ($end === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
        $end = $today;
    }
    if ($start > $end) {
        $start = $monthStart;
    }

    $connectionData = Database::getGeoConnection($config);
    $pdo = $connectionData[0];

    if (!Http::tableExists($pdo, 'mart_compradores_por_geo')) {
        Http::jsonConflictMissingTable('mart_compradores_por_geo');
    }

    $repo    = new Geo360ReadRepository($pdo);
    $service = new Geo360ReadService($repo);

    $payload = $service->build($neighborhoodKey, $neighborhood, $city, $start, $end, $limit, $tab);

    $payload['meta'] = [
        'request_id'   => RequestContext::id(),
        'generated_at' => date(DATE_ATOM),
        'tab'          => $tab,
        'limit'        => $limit,
    ];

    Http::json($payload);
} catch (\Throwable $exception) {
    global $logger;
    $logger?->error('geo_neighborhood_detail api error', ['exception' => $exception->getMessage()]);

    Http::json([
        'ok'         => false,
        'error'      => 'api_error',
        'message'    => 'Nao foi possivel carregar o detalhe do bairro agora.',
        'request_id' => RequestContext::id(),
    ], 500);
}
