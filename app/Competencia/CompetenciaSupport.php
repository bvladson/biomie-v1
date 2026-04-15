<?php

declare(strict_types=1);

namespace BIomie\Competencia;

use DateTimeImmutable;

final class CompetenciaSupport
{
    public function withHistoricalMeta(array $payload, string $competencia, ?string $lastSync): array
    {
        $payload['meta'] = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        $payload['meta']['snapshot_competencia'] = $competencia;
        $payload['meta']['snapshot_last_sync'] = $lastSync;
        $payload['meta']['served_from'] = 'historical-read-model';
        $payload['source'] = 'historical_snapshot';
        $payload['mode'] = (string) ($payload['mode'] ?? 'historical_snapshot');

        return $payload;
    }

    public function normalizeVendorKey(string $name): string
    {
        $normalized = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        if (!is_string($normalized) || $normalized === '') {
            $normalized = $name;
        }

        return trim(mb_strtolower($normalized));
    }

    public function periodLabel(string $start, string $end): string
    {
        $startDate = DateTimeImmutable::createFromFormat('Y-m-d', $start);
        $endDate = DateTimeImmutable::createFromFormat('Y-m-d', $end);
        if (!$startDate instanceof DateTimeImmutable || !$endDate instanceof DateTimeImmutable) {
            return $start . ' ate ' . $end;
        }

        return $startDate->format('d/m/Y') . ' ate ' . $endDate->format('d/m/Y');
    }

    public function screenColumn(string $screen): string
    {
        return match ($screen) {
            'overview' => 'overview_payload',
            'vendors' => 'vendors_payload',
            'flight_plan' => 'flight_plan_payload',
            'targets' => 'targets_payload',
            default => throw new \InvalidArgumentException('Tela de snapshot desconhecida: ' . $screen),
        };
    }

    public function encodeJson(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            throw new \RuntimeException('Falha ao serializar snapshot de competencia.');
        }

        return $json;
    }
}
