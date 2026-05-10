<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Semantic;

/**
 * Exports the MetricRegistry as structured data.
 *
 * Does NOT write files — callers are responsible for persistence.
 * Use toArray() for programmatic access, toJson() for API responses,
 * toMarkdown() for documentation generation.
 */
final class MetricCatalogExporter
{
    public function __construct(
        private readonly MetricRegistry $registry,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $kpis = [];
        foreach ($this->registry->all() as $def) {
            $kpis[] = $def->toArray();
        }

        return [
            'generated_at' => date('c'),
            'totals'       => [
                'kpis'       => count($this->registry->all()),
                'active'     => count($this->registry->active()),
                'draft'      => count($this->registry->draft()),
                'deprecated' => count($this->registry->deprecated()),
                'entities'   => count($this->registry->allEntities()),
                'dimensions' => count($this->registry->allDimensions()),
                'measures'   => count($this->registry->allMeasures()),
            ],
            'kpis'       => $kpis,
            'entities'   => array_map(fn ($e) => $e->toArray(), $this->registry->allEntities()),
            'dimensions' => array_map(fn ($d) => $d->toArray(), $this->registry->allDimensions()),
            'measures'   => array_map(fn ($m) => $m->toArray(), $this->registry->allMeasures()),
        ];
    }

    public function toJson(int $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE): string
    {
        return json_encode($this->toArray(), $flags) ?: '{}';
    }

    public function toMarkdown(): string
    {
        $lines   = [];
        $lines[] = '# BIomie Metric Catalog';
        $lines[] = '';
        $lines[] = sprintf('_Generated: %s_', date('Y-m-d H:i:s'));
        $lines[] = '';

        $all        = $this->registry->all();
        $active     = $this->registry->active();
        $draft      = $this->registry->draft();
        $deprecated = $this->registry->deprecated();

        $lines[] = sprintf(
            '**Total KPIs:** %d (active: %d, draft: %d, deprecated: %d)',
            count($all), count($active), count($draft), count($deprecated),
        );
        $lines[] = '';

        // Group by domain
        $byDomain = [];
        foreach ($all as $def) {
            $byDomain[$def->domain][] = $def;
        }
        ksort($byDomain);

        foreach ($byDomain as $domain => $kpis) {
            $lines[] = sprintf('## Domain: %s', ucfirst($domain));
            $lines[] = '';
            $lines[] = '| ID | Name | Status | Source | Table | Date | Permission |';
            $lines[] = '|---|---|---|---|---|---|---|';
            foreach ($kpis as $def) {
                $name   = str_replace('|', '\\|', $def->name);
                $table  = $def->sourceTable ?: '—';
                $date   = $def->officialDate ?: '—';
                $lines[] = sprintf(
                    '| `%s` | %s | %s | %s | `%s` | `%s` | `%s` |',
                    $def->id, $name, $def->status, $def->sourceOfTruth, $table, $date, $def->permission,
                );
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }
}
