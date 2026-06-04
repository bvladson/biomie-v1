<?php

/**
 * api/geo_neighborhood_detail.php — Geo 360 / detalhe territorial.
 *
 * Compatibilidade:
 *   - chamada antiga: neighborhood_key, neighborhood, city
 *   - chamada nova: territory_type, cidade_key, bairro_key, territory_label, layer
 *
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
    $today = date('Y-m-d');
    $monthStart = date('Y-m-01');

    $neighborhoodKey = trim((string) Http::query('neighborhood_key'));
    $bairroKey = trim((string) Http::query('bairro_key', $neighborhoodKey));
    $cidadeKey = trim((string) Http::query('cidade_key', Http::query('city_key', '')));
    $city = trim((string) Http::query('city', 'Fortaleza'));
    $neighborhood = trim((string) Http::query('neighborhood', $neighborhoodKey));
    $territoryLabel = trim((string) Http::query('territory_label', $neighborhood));
    $territoryType = trim((string) Http::query('territory_type', ''));
    $layer = trim((string) Http::query('layer', ''));
    $joinKey = trim((string) Http::query('join_key', ''));

    $cityKeyFromText = geo360_normalize_key($city);
    if ($cidadeKey === '' && $cityKeyFromText !== '') {
        $cidadeKey = $cityKeyFromText;
    }

    if ($territoryType === '') {
        $legacyKey = geo360_normalize_key($neighborhoodKey);
        if ($cityKeyFromText !== '' && $legacyKey !== '' && $cityKeyFromText === $legacyKey && $cityKeyFromText !== 'fortaleza') {
            $territoryType = 'ceara_municipio';
            $cidadeKey = $cityKeyFromText;
            $bairroKey = '';
        } else {
            $territoryType = $cityKeyFromText === 'fortaleza' ? 'fortaleza_bairro' : 'metro_bairro';
            if ($bairroKey === '') {
                $bairroKey = $neighborhoodKey;
            }
        }
    }

    $isNeighborhood = in_array($territoryType, ['fortaleza_bairro', 'metro_bairro'], true);
    if ($isNeighborhood && trim($bairroKey) === '') {
        Http::json([
            'ok' => false,
            'message' => 'Parametro bairro_key ou neighborhood_key e obrigatorio para detalhe de bairro.',
            'request_id' => RequestContext::id(),
        ], 400);
    }
    if (!$isNeighborhood && trim($cidadeKey) === '' && !in_array($territoryType, ['sem_geo', 'sem_bairro'], true)) {
        $cidadeKey = geo360_normalize_key($neighborhoodKey);
    }
    if (!$isNeighborhood && trim($cidadeKey) === '' && !in_array($territoryType, ['sem_geo', 'sem_bairro'], true)) {
        Http::json([
            'ok' => false,
            'message' => 'Parametro cidade_key e obrigatorio para detalhe de municipio.',
            'request_id' => RequestContext::id(),
        ], 400);
    }

    if ($territoryLabel === '') {
        $territoryLabel = $neighborhood !== '' ? $neighborhood : ($city !== '' ? $city : ($bairroKey ?: $cidadeKey));
    }

    $start = trim((string) Http::query('start', Http::query('date_from', '')));
    $end = trim((string) Http::query('end', Http::query('date_to', '')));
    $limitRaw = (int) Http::query('limit', 50);
    $limit = max(1, min($limitRaw, 200));
    $tab = trim((string) Http::query('tab'));
    $tab = $tab !== '' ? $tab : null;

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

    $territory = [
        'territory_type' => $territoryType,
        'cidade_key' => geo360_normalize_key($cidadeKey),
        'bairro_key' => geo360_normalize_key($bairroKey),
        'territory_label' => $territoryLabel,
        'layer' => $layer,
        'join_key' => $joinKey,
    ];

    $repo = new Geo360ReadRepository($pdo);
    $service = new Geo360ReadService($repo);

    $payload = $service->build(
        $territory['bairro_key'] !== '' ? $territory['bairro_key'] : $territory['cidade_key'],
        $territoryLabel,
        $city,
        $start,
        $end,
        $limit,
        $tab,
        $territory
    );

    $payload['meta'] = [
        'request_id' => RequestContext::id(),
        'generated_at' => date(DATE_ATOM),
        'tab' => $tab,
        'limit' => $limit,
    ];

    Http::json($payload);
} catch (\Throwable $exception) {
    global $logger;
    $logger?->error('geo_neighborhood_detail api error', ['exception' => $exception->getMessage()]);

    Http::json([
        'ok' => false,
        'error' => 'api_error',
        'message' => 'Nao foi possivel carregar o detalhe territorial agora.',
        'request_id' => RequestContext::id(),
    ], 500);
}

function geo360_normalize_key(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($converted)) {
            $value = $converted;
        }
    }
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?: '';
    return trim(preg_replace('/_+/', '_', $value) ?: '', '_');
}
