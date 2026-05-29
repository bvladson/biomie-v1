<?php

declare(strict_types=1);

namespace BIomie\Support;

/**
 * COM.R2 FASE 1 — Apresentador Visual de Canal Comercial.
 *
 * Recebe dados classificados (CommercialPipelineClassifier, VendorAliasResolver,
 * CustomerOwnershipResolver) e retorna objetos prontos para renderização UI.
 *
 * Read-only. Sem acesso ao banco.
 */
final class CommercialChannelPresenter
{
    // ── Channel keys ─────────────────────────────────────────────────────────
    public const CHANNEL_VAREJO        = 'varejo';
    public const CHANNEL_REVENDA       = 'revenda';
    public const CHANNEL_LICITACAO     = 'licitacao';
    public const CHANNEL_REMESSA       = 'remessa';
    public const CHANNEL_REPRESENTANTE = 'representante';
    public const CHANNEL_OUTRO         = 'outro';

    // ── Scope keys ───────────────────────────────────────────────────────────
    public const SCOPE_DEFAULT       = 'orc_pd';  // Orçamento-PD padrão comercial
    public const SCOPE_LICITACAO     = 'licitacao';
    public const SCOPE_REMESSA       = 'remessa';
    public const SCOPE_TODOS         = 'todos';

    // ── Channel label map ─────────────────────────────────────────────────────
    private const CHANNEL_LABELS = [
        self::CHANNEL_VAREJO        => 'Varejo',
        self::CHANNEL_REVENDA       => 'Revenda',
        self::CHANNEL_LICITACAO     => 'Licitação',
        self::CHANNEL_REMESSA       => 'Remessa',
        self::CHANNEL_REPRESENTANTE => 'Representante',
        self::CHANNEL_OUTRO         => 'Outro',
    ];

    private const CHANNEL_BADGE_CODES = [
        self::CHANNEL_VAREJO        => 'VAR',
        self::CHANNEL_REVENDA       => 'REV',
        self::CHANNEL_LICITACAO     => 'LIC',
        self::CHANNEL_REMESSA       => 'REM',
        self::CHANNEL_REPRESENTANTE => 'REP',
        self::CHANNEL_OUTRO         => '',
    ];

    // CSS class names expected in mag-design-system.css
    private const CHANNEL_BADGE_CLASSES = [
        self::CHANNEL_VAREJO        => 'mag-badge mag-badge--channel-var',
        self::CHANNEL_REVENDA       => 'mag-badge mag-badge--channel-rev',
        self::CHANNEL_LICITACAO     => 'mag-badge mag-badge--channel-lic',
        self::CHANNEL_REMESSA       => 'mag-badge mag-badge--channel-rem',
        self::CHANNEL_REPRESENTANTE => 'mag-badge mag-badge--channel-rep',
        self::CHANNEL_OUTRO         => 'mag-badge mag-badge--source-empty',
    ];

    /**
     * Build a full visual presentation object from classification data.
     *
     * @param array<string,mixed> $input Keys:
     *   vendor_name          string
     *   vendor_channel       string   — from VendorAliasResolver
     *   commercial_scope     string   — from CommercialPipelineClassifier
     *   pipeline_bucket      string
     *   is_licitacao         bool
     *   is_remessa           bool
     *   is_quote             bool
     *   is_portfolio         bool
     *   is_invoiced          bool
     *   is_delivery_done     bool
     *   ownership_status     string   — 'own_portfolio'|'out_of_portfolio'|'no_owner'|'inactive_review'
     *   conflict_status      string   — from CustomerOwnershipResolver conflict_type
     *
     * @return array<string,mixed>
     */
    public function present(array $input): array
    {
        $vendorChannel  = (string) ($input['vendor_channel']   ?? self::CHANNEL_OUTRO);
        $scope          = (string) ($input['commercial_scope'] ?? '');
        $isLicitacao    = (bool)   ($input['is_licitacao']     ?? false);
        $isRemessa      = (bool)   ($input['is_remessa']       ?? false);
        $isQuote        = (bool)   ($input['is_quote']         ?? false);
        $isPortfolio    = (bool)   ($input['is_portfolio']     ?? false);
        $isInvoiced     = (bool)   ($input['is_invoiced']      ?? false);
        $isDelivered    = (bool)   ($input['is_delivery_done'] ?? false);
        $ownerStatus    = (string) ($input['ownership_status'] ?? '');
        $conflictStatus = (string) ($input['conflict_status']  ?? '');

        // Resolve channel_key from vendor_channel + scope flags
        $channelKey = $this->resolveChannelKey($vendorChannel, $isLicitacao, $isRemessa);

        // Determine status label and class
        [$statusLabel, $statusClass] = $this->resolveStatusLabel($scope, $isQuote, $isPortfolio, $isInvoiced, $isDelivered);

        // Should show in default commercial view (Orç-PD)?
        $showInDefault = !$isLicitacao && !$isRemessa;

        // Visual group
        $visualGroup = $this->resolveVisualGroup($channelKey, $isQuote, $isPortfolio, $isInvoiced, $isDelivered);

        // Warning flags
        $warnings = $this->buildWarnings($conflictStatus, $ownerStatus, $isLicitacao, $isRemessa, $isDelivered);

        return [
            'channel_key'                      => $channelKey,
            'channel_label'                    => self::CHANNEL_LABELS[$channelKey] ?? ucfirst($channelKey),
            'channel_badge_code'               => self::CHANNEL_BADGE_CODES[$channelKey] ?? '',
            'channel_badge_class'              => self::CHANNEL_BADGE_CLASSES[$channelKey] ?? '',
            'status_label'                     => $statusLabel,
            'status_badge_class'               => $statusClass,
            'should_show_in_default_commercial_view' => $showInDefault,
            'visual_group'                     => $visualGroup,
            'warning_flags'                    => $warnings,
            'tooltip'                          => $this->buildTooltip($channelKey, $statusLabel, $warnings),
        ];
    }

    /**
     * Build a compact HTML badge string for a vendor channel.
     */
    public function channelBadgeHtml(string $vendorChannel, bool $isLicitacao = false, bool $isRemessa = false): string
    {
        $key  = $this->resolveChannelKey($vendorChannel, $isLicitacao, $isRemessa);
        $code = self::CHANNEL_BADGE_CODES[$key] ?? '';
        if ($code === '') return '';
        $cls  = self::CHANNEL_BADGE_CLASSES[$key];
        $lbl  = self::CHANNEL_LABELS[$key];
        return '<span class="' . htmlspecialchars($cls, ENT_QUOTES) . '" title="Canal: ' . htmlspecialchars($lbl, ENT_QUOTES) . '">' . htmlspecialchars($code, ENT_QUOTES) . '</span>';
    }

    /**
     * Returns available scope filter options for a given context.
     *
     * @return list<array{key:string,label:string,default:bool,warning:string}>
     */
    public function scopeFilterOptions(): array
    {
        return [
            ['key' => self::SCOPE_DEFAULT,   'label' => 'Orç-PD',    'default' => true,  'warning' => ''],
            ['key' => self::CHANNEL_VAREJO,  'label' => 'Varejo',    'default' => false, 'warning' => ''],
            ['key' => self::CHANNEL_REVENDA, 'label' => 'Revenda',   'default' => false, 'warning' => ''],
            ['key' => self::CHANNEL_LICITACAO, 'label' => 'Licitação','default' => false, 'warning' => ''],
            ['key' => self::CHANNEL_REMESSA, 'label' => 'Remessa',   'default' => false, 'warning' => ''],
            ['key' => self::SCOPE_TODOS,     'label' => 'Todos',     'default' => false, 'warning' => 'Visão combinada: inclui escopos separados. Use com cuidado.'],
        ];
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function resolveChannelKey(string $vendorChannel, bool $isLicitacao, bool $isRemessa): string
    {
        if ($isLicitacao) return self::CHANNEL_LICITACAO;
        if ($isRemessa)   return self::CHANNEL_REMESSA;
        // Normalize incoming channel from VendorAliasResolver
        $vc = strtolower(trim($vendorChannel));
        return match (true) {
            $vc === 'varejo'        => self::CHANNEL_VAREJO,
            $vc === 'revenda'       => self::CHANNEL_REVENDA,
            $vc === 'licitacao'     => self::CHANNEL_LICITACAO,
            $vc === 'remessa'       => self::CHANNEL_REMESSA,
            $vc === 'representante' => self::CHANNEL_REPRESENTANTE,
            default                 => self::CHANNEL_OUTRO,
        };
    }

    /** @return array{0:string,1:string} */
    private function resolveStatusLabel(
        string $scope,
        bool $isQuote,
        bool $isPortfolio,
        bool $isInvoiced,
        bool $isDelivered
    ): array {
        if ($isDelivered)  return ['Entrega finalizada', 'mag-badge mag-badge--source-cache'];
        if ($isInvoiced)   return ['Faturado por NF',    'mag-badge mag-badge--source-canonical'];
        if ($isPortfolio)  return ['Carteira aceita',    'mag-badge mag-badge--source-gold'];
        if ($isQuote)      return ['Orçamento',          'mag-badge mag-badge--source-fallback'];
        return [ucfirst($scope ?: 'Desconhecido'), 'mag-badge mag-badge--source-empty'];
    }

    private function resolveVisualGroup(
        string $channelKey,
        bool $isQuote,
        bool $isPortfolio,
        bool $isInvoiced,
        bool $isDelivered
    ): string {
        if ($channelKey === self::CHANNEL_LICITACAO) return 'escopos_separados';
        if ($channelKey === self::CHANNEL_REMESSA)   return 'escopos_separados';
        if ($isDelivered)  return 'pos_venda';
        if ($isInvoiced)   return 'realizado';
        if ($isPortfolio)  return 'carteira';
        if ($isQuote)      return 'funil';
        return 'outro';
    }

    /** @return list<string> */
    private function buildWarnings(
        string $conflictStatus,
        string $ownerStatus,
        bool $isLicitacao,
        bool $isRemessa,
        bool $isDelivered
    ): array {
        $w = [];
        if ($isLicitacao) $w[] = 'Licitação: excluída do Orç-PD padrão';
        if ($isRemessa)   $w[] = 'Remessa: escopo operacional separado';
        if ($isDelivered) $w[] = 'Entrega finalizada: não gera nova receita';
        if ($conflictStatus === 'sold_by_other_vendor')   $w[] = 'Venda fora da carteira';
        if ($conflictStatus === 'cross_channel_conflict') $w[] = 'Conflito de canal (REV vs VAR)';
        if ($ownerStatus === 'inactive_review')           $w[] = 'Dono do cliente inativo';
        return $w;
    }

    private function buildTooltip(string $channelKey, string $statusLabel, array $warnings): string
    {
        $parts = [$statusLabel . ' · Canal: ' . (self::CHANNEL_LABELS[$channelKey] ?? ucfirst($channelKey))];
        foreach ($warnings as $w) $parts[] = '⚠ ' . $w;
        return implode(' | ', $parts);
    }
}
