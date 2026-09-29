<?php

declare(strict_types=1);

namespace BIomie\Support;

final class VendorAliasResolver
{
    public const CHANNEL_REV = 'revenda';
    public const CHANNEL_VAR = 'varejo';
    public const CHANNEL_REPRESENTANTE = 'representante';
    public const CHANNEL_LICITACAO = 'licitacao';
    public const CHANNEL_OUTRO = 'outro';

    public const WARN_AMBIGUOUS_CHANNEL = 'ambiguous_caio_channel';
    public const WARN_NO_CHANNEL = 'no_channel_suffix';
    public const WARN_CROSS_CHANNEL = 'cross_channel_conflict';
    public const WARN_MANUAL_APPROVAL = 'alias_requires_manual_approval';

    private const SUFFIX_MAP = [
        ' - rev' => self::CHANNEL_REV,
        '-rev' => self::CHANNEL_REV,
        ' rev' => self::CHANNEL_REV,
        ' - revenda' => self::CHANNEL_REV,
        ' revenda' => self::CHANNEL_REV,
        ' - var' => self::CHANNEL_VAR,
        '-var' => self::CHANNEL_VAR,
        ' var' => self::CHANNEL_VAR,
        ' - varejo' => self::CHANNEL_VAR,
        ' varejo' => self::CHANNEL_VAR,
        ' - representante' => self::CHANNEL_REPRESENTANTE,
        ' representante' => self::CHANNEL_REPRESENTANTE,
        ' - rep' => self::CHANNEL_REPRESENTANTE,
        ' rep' => self::CHANNEL_REPRESENTANTE,
    ];

    /**
     * @return array{
     *   raw_vendor_name:string,
     *   canonical_vendor_name:string,
     *   vendor_channel:string,
     *   base_name:string,
     *   alias_confidence:int,
     *   warnings:list<string>,
     *   resolved_by:string,
     *   alias_candidate:bool,
     *   requires_manual_approval:bool,
     *   channel_match:bool,
     *   suggested_alias:?string
     * }
     */
    public function resolve(string $vendorName): array
    {
        $raw = trim($vendorName);
        $normalized = $this->normalize($raw);

        if ($raw === '' || $this->isGeneric($normalized)) {
            return $this->buildResult($raw, $raw, self::CHANNEL_OUTRO, '', 0, [], 'generic', false, false, false, null);
        }

        $channel = $this->detectChannel($normalized);
        $baseName = $this->stripChannel($normalized);

        if ($channel !== self::CHANNEL_OUTRO) {
            $canonical = $this->buildCanonicalName($baseName, $channel);
            $candidate = $this->manualAliasCandidate($baseName, $channel);
            $warnings = $candidate !== null ? [self::WARN_MANUAL_APPROVAL] : [];

            return $this->buildResult(
                $raw,
                $canonical,
                $channel,
                $baseName,
                $candidate !== null ? 85 : 100,
                $warnings,
                $candidate !== null ? 'channel_detected_alias_candidate' : 'channel_detected',
                $candidate !== null,
                $candidate !== null,
                $candidate !== null,
                $candidate
            );
        }

        $warnings = [$this->mightBeMultiChannel($baseName) ? self::WARN_AMBIGUOUS_CHANNEL : self::WARN_NO_CHANNEL];

        return $this->buildResult($raw, $raw, self::CHANNEL_OUTRO, $baseName, 60, $warnings, 'no_channel_suffix', false, false, false, null);
    }

    public function areEquivalent(string $a, string $b): bool
    {
        $na = $this->normalize($a);
        $nb = $this->normalize($b);
        $ca = $this->detectChannel($na);
        $cb = $this->detectChannel($nb);

        if ($ca !== $cb) {
            return false;
        }

        return $this->stripChannel($na) === $this->stripChannel($nb);
    }

    public function isCrossChannelConflict(string $a, string $b): bool
    {
        $na = $this->normalize($a);
        $nb = $this->normalize($b);
        $ca = $this->detectChannel($na);
        $cb = $this->detectChannel($nb);

        if ($ca === $cb || $ca === self::CHANNEL_OUTRO || $cb === self::CHANNEL_OUTRO) {
            return false;
        }

        return $this->stripChannel($na) === $this->stripChannel($nb);
    }

    public function detectChannel(string $normalized): string
    {
        foreach (self::SUFFIX_MAP as $suffix => $channel) {
            if (str_ends_with($normalized, $suffix)) {
                return $channel;
            }
        }

        // Vendedor operacional "Licitação" (igualdade normalizada, ver LicitacaoSeller).
        // Espaços ignorados: o iconv//TRANSLIT de normalize() rende "licitac ao"
        // em algumas plataformas (Windows) e "licitacao" em outras (glibc).
        return LicitacaoSeller::isLicitacaoName(str_replace(' ', '', $normalized))
            ? self::CHANNEL_LICITACAO
            : self::CHANNEL_OUTRO;
    }

    public function stripChannel(string $normalized): string
    {
        foreach (self::SUFFIX_MAP as $suffix => $channel) {
            if (str_ends_with($normalized, $suffix)) {
                return trim(substr($normalized, 0, strlen($normalized) - strlen($suffix)));
            }
        }

        return $normalized;
    }

    public function normalize(string $value): string
    {
        $n = trim(mb_strtolower($value));
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $n);
        if (is_string($converted) && $converted !== '') {
            $n = $converted;
        }

        $n = strtr($n, [
            'Ã¡' => 'a', 'Ã ' => 'a', 'Ã£' => 'a', 'Ã¢' => 'a', 'Ã¤' => 'a',
            'Ã©' => 'e', 'Ã¨' => 'e', 'Ãª' => 'e', 'Ã«' => 'e',
            'Ã­' => 'i', 'Ã¬' => 'i', 'Ã®' => 'i', 'Ã¯' => 'i',
            'Ã³' => 'o', 'Ã²' => 'o', 'Ãµ' => 'o', 'Ã´' => 'o', 'Ã¶' => 'o',
            'Ãº' => 'u', 'Ã¹' => 'u', 'Ã»' => 'u', 'Ã¼' => 'u',
            'Ã§' => 'c',
        ]);

        $n = (string) preg_replace('/[^a-z0-9\s\-\.]/', ' ', $n);
        $n = (string) preg_replace('/\s+/', ' ', $n);

        return trim($n);
    }

    private function isGeneric(string $normalized): bool
    {
        return in_array($normalized, [
            '',
            'sem vendedor',
            'sem vendedor definido',
            'nao informado',
            'enviado via api',
            's vendedor',
            's/ vendedor',
            's/vendedor',
        ], true);
    }

    private function mightBeMultiChannel(string $baseName): bool
    {
        $normalized = $this->normalize($baseName);
        return $normalized === 'caio' || (strlen($normalized) <= 10 && !str_contains($normalized, ' '));
    }

    private function manualAliasCandidate(string $baseName, string $channel): ?string
    {
        $base = $this->normalize($baseName);

        if ($base === 'caio bruno' && $channel === self::CHANNEL_REV) {
            return 'CAIO - REV';
        }

        if ($base === 'caio bruno' && $channel === self::CHANNEL_VAR) {
            return 'CAIO - VAR';
        }

        return null;
    }

    private function buildCanonicalName(string $baseName, string $channel): string
    {
        $suffix = match ($channel) {
            self::CHANNEL_REV => 'REV',
            self::CHANNEL_VAR => 'VAR',
            self::CHANNEL_REPRESENTANTE => 'REP',
            self::CHANNEL_LICITACAO => 'LIC',
            default => '',
        };
        $base = mb_strtoupper(trim($baseName));

        return $suffix !== '' ? "{$base} - {$suffix}" : $base;
    }

    /**
     * @param list<string> $warnings
     * @return array{
     *   raw_vendor_name:string,
     *   canonical_vendor_name:string,
     *   vendor_channel:string,
     *   base_name:string,
     *   alias_confidence:int,
     *   warnings:list<string>,
     *   resolved_by:string,
     *   alias_candidate:bool,
     *   requires_manual_approval:bool,
     *   channel_match:bool,
     *   suggested_alias:?string
     * }
     */
    private function buildResult(
        string $raw,
        string $canonical,
        string $channel,
        string $baseName,
        int $confidence,
        array $warnings,
        string $resolvedBy,
        bool $aliasCandidate,
        bool $requiresManualApproval,
        bool $channelMatch,
        ?string $suggestedAlias
    ): array {
        return [
            'raw_vendor_name' => $raw,
            'canonical_vendor_name' => $canonical,
            'vendor_channel' => $channel,
            'base_name' => $baseName,
            'alias_confidence' => $confidence,
            'warnings' => $warnings,
            'resolved_by' => $resolvedBy,
            'alias_candidate' => $aliasCandidate,
            'requires_manual_approval' => $requiresManualApproval,
            'channel_match' => $channelMatch,
            'suggested_alias' => $suggestedAlias,
        ];
    }
}
