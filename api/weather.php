<?php

declare(strict_types=1);

use BIomie\Http;

require __DIR__ . '/bootstrap.php';

try {
    $label = trim((string) $config->get('weather.label', 'Fortaleza, CE'));
    $latitude = (float) $config->get('weather.latitude', -3.7319);
    $longitude = (float) $config->get('weather.longitude', -38.5267);
    $timezone = trim((string) $config->get('weather.timezone', (string) $config->get('app.timezone', 'America/Fortaleza')));
    $ttlSeconds = max(60, (int) $config->get('weather.cache_ttl_seconds', 600));

    $cacheKey = sprintf(
        'weather:%s:%s:%s',
        preg_replace('/[^a-zA-Z0-9_-]/', '_', $label),
        str_replace('.', '_', number_format($latitude, 4, '.', '')),
        str_replace('.', '_', number_format($longitude, 4, '.', ''))
    );

    $cached = $cache->get($cacheKey, $ttlSeconds);
    if ($cached !== null) {
        Http::json($cached);
    }

    $query = http_build_query([
        'latitude' => $latitude,
        'longitude' => $longitude,
        'timezone' => $timezone !== '' ? $timezone : 'auto',
        'current' => 'temperature_2m,relative_humidity_2m,apparent_temperature,precipitation,weather_code,wind_speed_10m,is_day',
        'daily' => 'temperature_2m_max,temperature_2m_min,precipitation_probability_max,sunrise,sunset',
        'forecast_days' => 1,
    ]);

    $url = 'https://api.open-meteo.com/v1/forecast?' . $query;
    $payload = fetchWeatherSnapshot($url, $label, $timezone !== '' ? $timezone : 'auto');
    $cache->put($cacheKey, $payload);

    Http::json($payload);
} catch (Throwable $exception) {
    $cacheKey = $cacheKey ?? null;
    $stale = $cacheKey ? $cache->read($cacheKey) : null;
    if (is_array($stale)) {
        $stale['meta'] = is_array($stale['meta'] ?? null) ? $stale['meta'] : [];
        $stale['meta']['served_from'] = 'weather-cache-stale';
        $stale['meta']['warning'] = 'Clima exibido a partir do cache local.';
        Http::json($stale);
    }

    $logger->warning('Falha ao consultar clima em tempo real.', [
        'message' => $exception->getMessage(),
    ]);

    Http::json([
        'ok' => false,
        'message' => 'Nao foi possivel carregar o clima agora.',
    ], 503);
}

/**
 * @return array<string,mixed>
 */
function fetchWeatherSnapshot(string $url, string $label, string $timezone): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('curl_init indisponivel para consulta de clima.');
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'User-Agent: BIomie-MAG/1.0',
        ],
    ]);

    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if (!is_string($raw) || $raw === '') {
        throw new RuntimeException($error !== '' ? $error : 'Resposta vazia da API de clima.');
    }
    if ($status >= 400) {
        throw new RuntimeException('API de clima retornou HTTP ' . $status . '.');
    }

    $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('Resposta invalida da API de clima.');
    }

    $current = is_array($decoded['current'] ?? null) ? $decoded['current'] : [];
    $daily = is_array($decoded['daily'] ?? null) ? $decoded['daily'] : [];

    return [
        'ok' => true,
        'location' => [
            'label' => $label,
            'latitude' => (float) ($decoded['latitude'] ?? 0),
            'longitude' => (float) ($decoded['longitude'] ?? 0),
            'timezone' => (string) ($decoded['timezone'] ?? $timezone),
        ],
        'current' => [
            'time' => (string) ($current['time'] ?? date(DATE_ATOM)),
            'temperature' => (float) ($current['temperature_2m'] ?? 0),
            'apparent_temperature' => (float) ($current['apparent_temperature'] ?? 0),
            'humidity' => (int) ($current['relative_humidity_2m'] ?? 0),
            'wind_speed' => (float) ($current['wind_speed_10m'] ?? 0),
            'precipitation' => (float) ($current['precipitation'] ?? 0),
            'weather_code' => (int) ($current['weather_code'] ?? -1),
            'is_day' => (int) ($current['is_day'] ?? 1) === 1,
        ],
        'today' => [
            'temperature_max' => (float) (($daily['temperature_2m_max'][0] ?? 0)),
            'temperature_min' => (float) (($daily['temperature_2m_min'][0] ?? 0)),
            'precipitation_probability_max' => (int) (($daily['precipitation_probability_max'][0] ?? 0)),
            'sunrise' => (string) (($daily['sunrise'][0] ?? '')),
            'sunset' => (string) (($daily['sunset'][0] ?? '')),
        ],
        'meta' => [
            'generated_at' => date(DATE_ATOM),
            'served_from' => 'weather-live',
        ],
    ];
}
