<?php

declare(strict_types=1);

namespace BIomie\Modules\Sales\Application\Vendors;

class VendorIdentityResolver
{
    private array $vendorMap;

    public function __construct(array $vendorMap = [])
    {
        $this->vendorMap = $vendorMap;
    }

    /**
     * @return array{resolved:bool, resolved_by:string, vendor_id:string, vendor_name:string, aliases:array<string>, warnings:array<string>, is_generic:bool}
     */
    public function resolve(string $vendorRef): array
    {
        $ref = trim($vendorRef);
        if ($ref === '') {
            return $this->buildGeneric();
        }

        $normalized = $this->normalize($ref);

        if ($this->isGeneric($normalized)) {
            return $this->buildGeneric();
        }

        // 1. Exact ID match
        if (isset($this->vendorMap[$ref])) {
            return $this->buildMatch('vendor_id', $ref, $this->vendorMap[$ref]['nome'] ?? $ref);
        }

        // 2. Exact Name or Normalized match
        foreach ($this->vendorMap as $id => $v) {
            $name = trim((string) ($v['nome'] ?? ''));
            if ($name === $ref) {
                return $this->buildMatch('exact_name', (string)$id, $name);
            }
            if ($this->normalize($name) === $normalized) {
                return $this->buildMatch('normalized_name', (string)$id, $name);
            }
        }

        // 3. Alias match — channel-aware: only match within same channel (REV ≠ VAR)
        $refChannel = $this->extractChannel($normalized);
        $refBase    = $this->stripChannel($normalized);
        if ($refBase !== '') {
            foreach ($this->vendorMap as $id => $v) {
                $name      = trim((string) ($v['nome'] ?? ''));
                $normName  = $this->normalize($name);
                if ($this->extractChannel($normName) !== $refChannel) continue;
                if ($this->stripChannel($normName) === $refBase) {
                    return $this->buildMatch('alias', (string)$id, $name, [$refBase]);
                }
            }
        }

        // 3b. Ambiguous lookup: ref has no channel suffix but vendorMap has multiple channel variants
        if ($refChannel === '') {
            $candidates = [];
            foreach ($this->vendorMap as $id => $v) {
                $name     = trim((string) ($v['nome'] ?? ''));
                $normName = $this->normalize($name);
                if ($this->stripChannel($normName) === $refBase && $this->extractChannel($normName) !== '') {
                    $candidates[] = ['id' => (string)$id, 'name' => $name];
                }
            }
            if (count($candidates) === 1) {
                return $this->buildMatch('alias_no_channel', $candidates[0]['id'], $candidates[0]['name'], [$refBase]);
            }
            if (count($candidates) > 1) {
                return [
                    'resolved'    => false,
                    'resolved_by' => 'ambiguous_channel',
                    'vendor_id'   => $ref,
                    'vendor_name' => $ref,
                    'aliases'     => array_column($candidates, 'name'),
                    'warnings'    => ['ambiguous_caio_channel'],
                    'is_generic'  => false,
                ];
            }
        }

        // Fallback: Not found in map but might have data in mart (e.g., from orders)
        return [
            'resolved' => true,
            'resolved_by' => 'fallback',
            'vendor_id' => $ref,
            'vendor_name' => $ref,
            'aliases' => [],
            'warnings' => ['Vendedor não consta no cadastro canônico atual.'],
            'is_generic' => false,
        ];
    }

    private function isGeneric(string $normalized): bool
    {
        return in_array($normalized, [
            's/ vendedor',
            's/vendedor',
            'sem vendedor',
            'nao identificado',
            '__sem_vendedor__'
        ], true);
    }

    private function buildGeneric(): array
    {
        return [
            'resolved' => true,
            'resolved_by' => 'generic_bucket',
            'vendor_id' => '__SEM_VENDEDOR__',
            'vendor_name' => 'Sem vendedor',
            'aliases' => [],
            'warnings' => [],
            'is_generic' => true,
        ];
    }

    private function buildMatch(string $resolvedBy, string $id, string $name, array $aliases = []): array
    {
        return [
            'resolved' => true,
            'resolved_by' => $resolvedBy,
            'vendor_id' => $id,
            'vendor_name' => $name,
            'aliases' => $aliases,
            'warnings' => [],
            'is_generic' => false,
        ];
    }

    public function normalize(string $value): string
    {
        $decoded = str_contains($value, '%') ? rawurldecode($value) : $value;
        $normalized = trim(mb_strtolower($decoded));
        // Remove accents
        $normalized = preg_replace('/[áàãâä]/u', 'a', $normalized) ?? $normalized;
        $normalized = preg_replace('/[éèêë]/u', 'e', $normalized) ?? $normalized;
        $normalized = preg_replace('/[íìîï]/u', 'i', $normalized) ?? $normalized;
        $normalized = preg_replace('/[óòõôö]/u', 'o', $normalized) ?? $normalized;
        $normalized = preg_replace('/[úùûü]/u', 'u', $normalized) ?? $normalized;
        $normalized = preg_replace('/[ç]/u', 'c', $normalized) ?? $normalized;
        return preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
    }

    // Returns the channel suffix found in the normalized name ('rev', 'var', 'representante', 'licitacao', '')
    public function extractChannel(string $normalized): string
    {
        $channelMap = [
            ' - rev'          => 'rev',
            ' rev'            => 'rev',
            ' - revenda'      => 'rev',
            ' revenda'        => 'rev',
            ' - var'          => 'var',
            ' var'            => 'var',
            ' - varejo'       => 'var',
            ' varejo'         => 'var',
            ' - representante'=> 'representante',
            ' representante'  => 'representante',
        ];
        foreach ($channelMap as $suffix => $channel) {
            if (str_ends_with($normalized, $suffix)) return $channel;
        }
        if (str_contains($normalized, 'licitac')) return 'licitacao';
        return '';
    }

    // Strips the channel suffix from a normalized name, returning only the base name
    public function stripChannel(string $normalized): string
    {
        $suffixes = [
            ' - rev', ' rev', ' - revenda', ' revenda',
            ' - var', ' var', ' - varejo', ' varejo',
            ' - representante', ' representante',
        ];
        foreach ($suffixes as $s) {
            if (str_ends_with($normalized, $s)) {
                return trim(substr($normalized, 0, strlen($normalized) - strlen($s)));
            }
        }
        return $normalized;
    }

    private function cleanAlias(string $normalized): string
    {
        // Legacy method kept for compatibility; no longer strips channel suffixes.
        return trim(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);
    }
}
