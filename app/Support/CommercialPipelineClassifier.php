<?php

declare(strict_types=1);

namespace BIomie\Support;

/**
 * COM.R1.2 — Classificador oficial de etapas comerciais Omie.
 *
 * Recebe campos normalizados de um pedido/bridge/payload e retorna
 * a classificação semântica completa, auditável e sem dependência de banco.
 *
 * IMPORTANTE — Restrição de schema:
 *   O campo status_comercial na biomie_pedidos_canonicos usa ENUM de 4 valores:
 *   ('faturado','autorizado','cancelado','outro').
 *   Esta classe NÃO escreve no banco — retorna a classificação semântica estendida
 *   para uso em diagnósticos, dashboards e ferramentas de análise.
 *
 * Mapeamento oficial Omie (confirmado por investigação bronze COM.R1.1):
 *   10 = Orçamento          → orcamento
 *   20 = Pedido/Autorizado  → carteira_aceita
 *   50 = Faturado           → faturado
 *   60 = Entrega Finalizada → entrega_finalizada  (NÃO é novo faturamento)
 *   70 = Cancelado          → cancelado
 *   80 = Devolvido          → devolvido
 */
final class CommercialPipelineClassifier
{
    // ── Scope constants ──────────────────────────────────────────────────────
    public const SCOPE_ORCAMENTO           = 'orcamento';
    public const SCOPE_CARTEIRA_ACEITA     = 'carteira_aceita';
    public const SCOPE_CARTEIRA_VAREJO     = 'carteira_varejo';
    public const SCOPE_CARTEIRA_REVENDA    = 'carteira_revenda';
    public const SCOPE_FATURADO            = 'faturado';
    public const SCOPE_ENTREGA_FINALIZADA  = 'entrega_finalizada';
    public const SCOPE_CANCELADO           = 'cancelado';
    public const SCOPE_DEVOLVIDO           = 'devolvido';
    public const SCOPE_DESCONHECIDO        = 'desconhecido';
    // COM.R1 — escopos de canal separados (não entram no Orç-PD padrão)
    public const SCOPE_LICITACAO           = 'licitacao';
    public const SCOPE_REMESSA             = 'remessa';

    // Pipeline bucket constants
    public const BUCKET_FUNIL    = 'funil';
    public const BUCKET_CARTEIRA = 'carteira';
    public const BUCKET_REALIZADO = 'realizado';
    public const BUCKET_POS_VENDA = 'pos_venda';
    public const BUCKET_EXCLUIDO  = 'excluido';

    /**
     * Mapa oficial de etapas numéricas Omie.
     * Confirmado por investigação do payload bronze (COM.R1.1).
     */
    private const ETAPA_MAP = [
        10 => ['scope' => self::SCOPE_ORCAMENTO,          'label' => 'Orçamento',          'confidence' => 95],
        20 => ['scope' => self::SCOPE_CARTEIRA_ACEITA,    'label' => 'Pedido/Autorizado',  'confidence' => 92],
        50 => ['scope' => self::SCOPE_FATURADO,           'label' => 'Faturado',           'confidence' => 95],
        60 => ['scope' => self::SCOPE_ENTREGA_FINALIZADA, 'label' => 'Entrega Finalizada', 'confidence' => 92],
        70 => ['scope' => self::SCOPE_CANCELADO,          'label' => 'Cancelado',          'confidence' => 95],
        80 => ['scope' => self::SCOPE_DEVOLVIDO,          'label' => 'Devolvido',          'confidence' => 92],
    ];

    /**
     * Classifica um pedido/linha normalizada e retorna mapa semântico completo.
     *
     * Campos esperados em $input (todos opcionais, usa fallback graceful):
     *   etapa_omie           string  — código numérico Omie ("10","20","50","60","70","80")
     *   etapa_label          string  — rótulo textual (se disponível via ListarEtapasFaturamento)
     *   status_comercial     string  — valor ENUM do canônico ('faturado','autorizado','cancelado','outro')
     *   info_faturado        string  — infoCadastro.faturado ("S"/"N")
     *   info_autorizado      string  — infoCadastro.autorizado ("S"/"N")
     *   info_cancelado       string  — infoCadastro.cancelado ("S"/"N")
     *   info_devolvido       string  — infoCadastro.devolvido ("S"/"N")
     *   info_denegado        string  — infoCadastro.denegado ("S"/"N")
     *   has_authorized_nf    bool    — existe NF autorizada no período?
     *   nf_numero            string  — número da NF vinculada
     *   nf_status            string  — 'autorizada'/'cancelada'
     *   nome_vendedor        string  — para detecção de Licitação
     *   codigo_integracao    string  — código de integração
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function classify(array $input): array
    {
        $etapaOmie      = trim((string) ($input['etapa_omie']       ?? ''));
        $etapaLabel     = trim((string) ($input['etapa_label']      ?? ''));
        $statusComercial = trim((string) ($input['status_comercial'] ?? ''));
        $infoFat        = $this->isS($input['info_faturado']   ?? '');
        $infoAut        = $this->isS($input['info_autorizado'] ?? '');
        $infoCan        = $this->isS($input['info_cancelado']  ?? '');
        $infoDev        = $this->isS($input['info_devolvido']  ?? '');
        $infoDen        = $this->isS($input['info_denegado']   ?? '');
        $hasNf          = (bool) ($input['has_authorized_nf']  ?? false);
        $nfNumero       = trim((string) ($input['nf_numero']        ?? ''));
        $nfStatus       = trim((string) ($input['nf_status']        ?? ''));
        $nomeVendedor   = mb_strtoupper(trim((string) ($input['nome_vendedor'] ?? '')));

        // ── 1. Licitação (by vendor name — BiVisibilityScope pattern)
        if (str_contains($nomeVendedor, 'LICITAC') || str_contains($nomeVendedor, 'LICITAÇ')) {
            return $this->build(
                $etapaOmie, 'Licitação', 'licitacao', self::BUCKET_EXCLUIDO,
                false, false, false, false, false, false, false,
                90, 'licitacao_vendor_name_pattern'
            );
        }

        // ── 2. Devolvido (flags trumps etapa code)
        if ($infoDev || $statusComercial === 'devolvido') {
            return $this->build(
                $etapaOmie, $etapaLabel ?: 'Devolvido', self::SCOPE_DEVOLVIDO, self::BUCKET_EXCLUIDO,
                false, false, false, false, false, true, false,
                92, 'info_devolvido_flag_S_or_status_comercial'
            );
        }

        // ── 3. Cancelado (flags)
        if ($infoCan || $infoDen || $statusComercial === 'cancelado') {
            return $this->build(
                $etapaOmie, $etapaLabel ?: 'Cancelado', self::SCOPE_CANCELADO, self::BUCKET_EXCLUIDO,
                false, false, false, false, true, false, false,
                92, 'info_cancelado_or_denegado_flag_S_or_status_comercial'
            );
        }

        // ── 4. Numeric etapa — primary classification path
        if ($etapaOmie !== '' && ctype_digit($etapaOmie)) {
            $code = (int) $etapaOmie;

            // Explicit map for exact known codes
            if (isset(self::ETAPA_MAP[$code])) {
                $entry = self::ETAPA_MAP[$code];
                $label = $etapaLabel !== '' ? $etapaLabel : $entry['label'];

                switch ($code) {
                    case 10:
                        $warning = ($hasNf || $nfNumero !== '') ? 'quote_with_invoice' : null;
                        return $this->build(
                            $etapaOmie, $label, self::SCOPE_ORCAMENTO, self::BUCKET_FUNIL,
                            true, false, false, false, false, false, false,
                            $entry['confidence'], 'numeric_etapa_10_orcamento',
                            $warning, $warning ? ['has_authorized_nf' => $hasNf, 'nf_numero' => $nfNumero] : []
                        );

                    case 20:
                        // Could be Varejo or Revenda — needs ListarEtapasFaturamento to distinguish
                        $subScope = $this->detectSubScope($etapaLabel, $input);
                        $scope = match ($subScope) {
                            'varejo'  => self::SCOPE_CARTEIRA_VAREJO,
                            'revenda' => self::SCOPE_CARTEIRA_REVENDA,
                            default   => self::SCOPE_CARTEIRA_ACEITA,
                        };
                        return $this->build(
                            $etapaOmie, $label, $scope, self::BUCKET_CARTEIRA,
                            false, true, $infoAut, false, false, false, false,
                            $entry['confidence'], 'numeric_etapa_20_carteira_aceita'
                        );

                    case 50:
                        return $this->build(
                            $etapaOmie, $label, self::SCOPE_FATURADO, self::BUCKET_REALIZADO,
                            false, false, $infoAut, true, false, false, false,
                            $entry['confidence'], 'numeric_etapa_50_faturado'
                        );

                    case 60:
                        // CRITICAL: etapa 60 = Entrega Finalizada, NOT new revenue.
                        // infoCadastro.faturado=S here means the NF was already emitted at etapa 50.
                        return $this->build(
                            $etapaOmie, $label, self::SCOPE_ENTREGA_FINALIZADA, self::BUCKET_POS_VENDA,
                            false, false, false, $infoFat, false, false, true,
                            $entry['confidence'],
                            'numeric_etapa_60_entrega_finalizada_NOT_new_revenue'
                        );

                    case 70:
                        return $this->build(
                            $etapaOmie, $label, self::SCOPE_CANCELADO, self::BUCKET_EXCLUIDO,
                            false, false, false, false, true, false, false,
                            $entry['confidence'], 'numeric_etapa_70_cancelado'
                        );

                    case 80:
                        return $this->build(
                            $etapaOmie, $label, self::SCOPE_DEVOLVIDO, self::BUCKET_EXCLUIDO,
                            false, false, false, false, false, true, false,
                            $entry['confidence'], 'numeric_etapa_80_devolvido'
                        );
                }
            }

            // Range-based fallback for non-mapped codes
            if ($code >= 50 && $code < 60) {
                return $this->build(
                    $etapaOmie, $etapaLabel ?: "Faturado (código {$code})", self::SCOPE_FATURADO, self::BUCKET_REALIZADO,
                    false, false, $infoAut, true, false, false, false,
                    80, "numeric_etapa_range_50_59"
                );
            }
            if ($code >= 60 && $code <= 69) {
                return $this->build(
                    $etapaOmie, $etapaLabel ?: "Pós-venda (código {$code})", self::SCOPE_ENTREGA_FINALIZADA, self::BUCKET_POS_VENDA,
                    false, false, false, $infoFat, false, false, true,
                    75, "numeric_etapa_range_60_69_pos_venda"
                );
            }
            if ($code >= 20 && $code <= 49) {
                return $this->build(
                    $etapaOmie, $etapaLabel ?: "Pedido (código {$code})", self::SCOPE_CARTEIRA_ACEITA, self::BUCKET_CARTEIRA,
                    false, true, $infoAut, false, false, false, false,
                    78, "numeric_etapa_range_20_49_carteira"
                );
            }
            if ($code >= 70) {
                $scope = $infoDev ? self::SCOPE_DEVOLVIDO : self::SCOPE_CANCELADO;
                return $this->build(
                    $etapaOmie, $etapaLabel ?: "Cancelado (código {$code})", $scope, self::BUCKET_EXCLUIDO,
                    false, false, false, false, !$infoDev, $infoDev, false,
                    80, "numeric_etapa_gte_70_cancelado_or_devolvido"
                );
            }
            if ($code <= 19) {
                return $this->build(
                    $etapaOmie, $etapaLabel ?: "Orçamento (código {$code})", self::SCOPE_ORCAMENTO, self::BUCKET_FUNIL,
                    true, false, false, false, false, false, false,
                    82, "numeric_etapa_lte_19_orcamento"
                );
            }
        }

        // ── 5. Text-based classification (non-numeric etapa)
        if ($etapaLabel !== '' || $etapaOmie !== '') {
            $text = mb_strtolower($etapaLabel !== '' ? $etapaLabel : $etapaOmie);
            return $this->classifyByText($etapaOmie, $etapaLabel, $text, $input, $infoAut, $infoFat);
        }

        // ── 6. infoCadastro flags as last resort
        if ($infoFat && $infoAut) {
            return $this->build(
                $etapaOmie, 'Faturado (por flags)', self::SCOPE_FATURADO, self::BUCKET_REALIZADO,
                false, false, true, true, false, false, false,
                65, 'info_faturado_and_autorizado_flags_fallback'
            );
        }
        if ($infoAut) {
            return $this->build(
                $etapaOmie, 'Autorizado (por flags)', self::SCOPE_CARTEIRA_ACEITA, self::BUCKET_CARTEIRA,
                false, true, true, false, false, false, false,
                60, 'info_autorizado_flag_fallback'
            );
        }

        // ── 7. status_comercial ENUM fallback
        return match ($statusComercial) {
            'faturado'   => $this->build($etapaOmie, 'Faturado', self::SCOPE_FATURADO, self::BUCKET_REALIZADO, false, false, false, true, false, false, false, 50, 'status_comercial_faturado_fallback'),
            'autorizado' => $this->build($etapaOmie, 'Autorizado', self::SCOPE_CARTEIRA_ACEITA, self::BUCKET_CARTEIRA, false, true, true, false, false, false, false, 50, 'status_comercial_autorizado_fallback'),
            'cancelado'  => $this->build($etapaOmie, 'Cancelado', self::SCOPE_CANCELADO, self::BUCKET_EXCLUIDO, false, false, false, false, true, false, false, 50, 'status_comercial_cancelado_fallback'),
            default      => $this->build($etapaOmie, '(desconhecido)', self::SCOPE_DESCONHECIDO, self::BUCKET_FUNIL, false, false, false, false, false, false, false, 0, 'no_classification_found'),
        };
    }

    /**
     * Shorthand to classify directly from a canonical order row.
     * Maps the canonical field names to the classifier input.
     *
     * @param array<string,mixed> $canonicalRow  — row from biomie_pedidos_canonicos
     * @param array<string,mixed> $bronzePayload — raw Omie JSON payload (optional, for infoCadastro)
     * @param array<string,mixed> $nfRow         — joined NF row (optional)
     * @return array<string,mixed>
     */
    public function classifyFromCanonical(
        array $canonicalRow,
        array $bronzePayload = [],
        array $nfRow = []
    ): array {
        $infoCadastro = $bronzePayload['infoCadastro'] ?? [];
        return $this->classify([
            'etapa_omie'      => (string) ($canonicalRow['etapa_omie']       ?? ''),
            'status_comercial'=> (string) ($canonicalRow['status_comercial']  ?? ''),
            'nome_vendedor'   => (string) ($canonicalRow['nome_vendedor']     ?? ''),
            'codigo_integracao' => (string) ($canonicalRow['codigo_integracao'] ?? ''),
            'info_faturado'   => (string) ($infoCadastro['faturado']   ?? $canonicalRow['info_faturado']   ?? ''),
            'info_autorizado' => (string) ($infoCadastro['autorizado'] ?? $canonicalRow['info_autorizado'] ?? ''),
            'info_cancelado'  => (string) ($infoCadastro['cancelado']  ?? $canonicalRow['info_cancelado']  ?? ''),
            'info_devolvido'  => (string) ($infoCadastro['devolvido']  ?? $canonicalRow['info_devolvido']  ?? ''),
            'info_denegado'   => (string) ($infoCadastro['denegado']   ?? $canonicalRow['info_denegado']   ?? ''),
            'has_authorized_nf' => !empty($nfRow['nota_key']) && ($nfRow['status_fiscal'] ?? '') === 'autorizada',
            'nf_numero'       => (string) ($nfRow['numero_nf']    ?? ''),
            'nf_status'       => (string) ($nfRow['status_fiscal'] ?? ''),
        ]);
    }

    /**
     * Classifies a numeric etapa code only — lightweight, no context needed.
     * Returns [scope, label, should_count_as_realized, should_count_as_portfolio, reason].
     *
     * @return array{scope:string,label:string,realized:bool,portfolio:bool,reason:string}
     */
    public function classifyEtapa(string $etapaOmie): array
    {
        if ($etapaOmie === '' || !ctype_digit($etapaOmie)) {
            return ['scope' => self::SCOPE_DESCONHECIDO, 'label' => '(vazio)', 'realized' => false, 'portfolio' => false, 'reason' => 'empty_or_non_numeric'];
        }
        $code = (int) $etapaOmie;
        return match (true) {
            $code === 10            => ['scope' => self::SCOPE_ORCAMENTO,          'label' => 'Orçamento',          'realized' => false, 'portfolio' => false, 'reason' => 'etapa_10'],
            $code >= 20 && $code <= 49 => ['scope' => self::SCOPE_CARTEIRA_ACEITA, 'label' => 'Pedido/Autorizado',  'realized' => false, 'portfolio' => true,  'reason' => "etapa_{$code}_range_20_49"],
            $code >= 50 && $code < 60  => ['scope' => self::SCOPE_FATURADO,        'label' => 'Faturado',           'realized' => true,  'portfolio' => false, 'reason' => "etapa_{$code}"],
            $code === 60            => ['scope' => self::SCOPE_ENTREGA_FINALIZADA, 'label' => 'Entrega Finalizada', 'realized' => false, 'portfolio' => false, 'reason' => 'etapa_60_pos_venda'],
            $code > 60 && $code <= 69  => ['scope' => self::SCOPE_ENTREGA_FINALIZADA,'label'=> "Pós-venda ({$code})",'realized' => false, 'portfolio' => false, 'reason' => "etapa_{$code}_range_61_69"],
            $code === 70            => ['scope' => self::SCOPE_CANCELADO,          'label' => 'Cancelado',          'realized' => false, 'portfolio' => false, 'reason' => 'etapa_70'],
            $code === 80            => ['scope' => self::SCOPE_DEVOLVIDO,          'label' => 'Devolvido',          'realized' => false, 'portfolio' => false, 'reason' => 'etapa_80'],
            $code > 80              => ['scope' => self::SCOPE_CANCELADO,          'label' => "Cancelado ({$code})",'realized' => false, 'portfolio' => false, 'reason' => "etapa_{$code}_gte_80"],
            default                 => ['scope' => self::SCOPE_DESCONHECIDO,       'label' => "Código {$code}",     'realized' => false, 'portfolio' => false, 'reason' => 'unknown_code'],
        };
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    private function isS(mixed $value): bool
    {
        return strtoupper(trim((string) $value)) === 'S';
    }

    private function detectSubScope(string $label, array $input): string
    {
        $lbl = mb_strtolower($label);
        if (str_contains($lbl, 'varejo')) return 'varejo';
        if (str_contains($lbl, 'revenda') || str_contains($lbl, 'revendedor')) return 'revenda';
        $integ = mb_strtolower((string) ($input['codigo_integracao'] ?? ''));
        if (str_contains($integ, 'varejo')) return 'varejo';
        if (str_contains($integ, 'revenda')) return 'revenda';
        return 'aceito';
    }

    private function classifyByText(
        string $etapaOmie, string $etapaLabel, string $text,
        array $input, bool $infoAut, bool $infoFat
    ): array {
        if (str_contains($text, 'entrega finalizada') || str_contains($text, 'entregue') || str_contains($text, 'finalizado')) {
            return $this->build($etapaOmie, $etapaLabel ?: 'Entrega Finalizada', self::SCOPE_ENTREGA_FINALIZADA, self::BUCKET_POS_VENDA, false, false, false, $infoFat, false, false, true, 82, 'text_entrega_finalizada');
        }
        if (str_contains($text, 'faturado') || str_contains($text, 'faturada')) {
            return $this->build($etapaOmie, $etapaLabel ?: 'Faturado', self::SCOPE_FATURADO, self::BUCKET_REALIZADO, false, false, $infoAut, true, false, false, false, 82, 'text_faturado');
        }
        if (str_contains($text, 'para faturar') || str_contains($text, 'aguardando faturamento')) {
            return $this->build($etapaOmie, $etapaLabel ?: 'Para Faturar', self::SCOPE_CARTEIRA_ACEITA, self::BUCKET_CARTEIRA, false, true, $infoAut, false, false, false, false, 80, 'text_para_faturar');
        }
        if (str_contains($text, 'revenda') || str_contains($text, 'revendedor')) {
            return $this->build($etapaOmie, $etapaLabel ?: 'Pedidos Revenda', self::SCOPE_CARTEIRA_REVENDA, self::BUCKET_CARTEIRA, false, true, $infoAut, false, false, false, false, 82, 'text_revenda');
        }
        if (str_contains($text, 'varejo')) {
            return $this->build($etapaOmie, $etapaLabel ?: 'Pedidos Varejo', self::SCOPE_CARTEIRA_VAREJO, self::BUCKET_CARTEIRA, false, true, $infoAut, false, false, false, false, 82, 'text_varejo');
        }
        if (str_contains($text, 'pedido') || str_contains($text, 'aprovado') || str_contains($text, 'autorizado')) {
            return $this->build($etapaOmie, $etapaLabel ?: 'Pedido/Autorizado', self::SCOPE_CARTEIRA_ACEITA, self::BUCKET_CARTEIRA, false, true, $infoAut, false, false, false, false, 75, 'text_pedido_autorizado');
        }
        if (str_contains($text, 'orçamento') || str_contains($text, 'orcamento') || str_contains($text, 'proposta')) {
            return $this->build($etapaOmie, $etapaLabel ?: 'Orçamento', self::SCOPE_ORCAMENTO, self::BUCKET_FUNIL, true, false, false, false, false, false, false, 80, 'text_orcamento');
        }
        if (str_contains($text, 'cancelado') || str_contains($text, 'denegado')) {
            return $this->build($etapaOmie, $etapaLabel ?: 'Cancelado', self::SCOPE_CANCELADO, self::BUCKET_EXCLUIDO, false, false, false, false, true, false, false, 80, 'text_cancelado');
        }
        if (str_contains($text, 'devolvido') || str_contains($text, 'devolução')) {
            return $this->build($etapaOmie, $etapaLabel ?: 'Devolvido', self::SCOPE_DEVOLVIDO, self::BUCKET_EXCLUIDO, false, false, false, false, false, true, false, 80, 'text_devolvido');
        }
        return $this->build($etapaOmie, $etapaLabel ?: '(texto não reconhecido)', self::SCOPE_DESCONHECIDO, self::BUCKET_FUNIL, false, false, false, false, false, false, false, 20, 'text_unrecognized');
    }

    /**
     * @param array<string,mixed> $extraData
     * @return array<string,mixed>
     */
    private function build(
        string $etapaOmie,
        string $etapaLabel,
        string $scope,
        string $bucket,
        bool $isQuote,
        bool $isPortfolio,
        bool $isAuthorized,
        bool $isInvoiced,
        bool $isCancelled,
        bool $isReturned,
        bool $isDeliveryDone,
        int $confidence,
        string $reason,
        ?string $warning = null,
        array $extraData = []
    ): array {
        $result = [
            'etapa_omie'                    => $etapaOmie,
            'etapa_label'                   => $etapaLabel,
            'commercial_scope'              => $scope,
            'pipeline_bucket'               => $bucket,
            'is_quote'                      => $isQuote,
            'is_portfolio'                  => $isPortfolio,
            'is_authorized_order'           => $isAuthorized,
            'is_invoiced'                   => $isInvoiced,
            'is_delivery_done'              => $isDeliveryDone,
            'is_cancelled'                  => $isCancelled,
            'is_returned'                   => $isReturned,
            'should_count_as_realized_by_stage' => $scope === self::SCOPE_FATURADO,
            'should_count_as_realized_by_nf'    => false, // NF authorization checked separately
            'should_count_as_portfolio'         => $isPortfolio && !$isCancelled && !$isReturned,
            'should_count_as_quote'             => $isQuote,
            'should_count_as_delivery'          => $isDeliveryDone,
            'confidence_score'              => $confidence,
            'reason'                        => $reason,
        ];
        if ($warning !== null) {
            $result['warning'] = $warning;
        }
        if ($extraData) {
            $result['extra'] = $extraData;
        }
        return $result;
    }
}
