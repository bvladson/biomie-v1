<?php

declare(strict_types=1);

namespace BIomie\Commercial;

use BIomie\SchemaCapabilities;
use BIomie\Support\TenantContext;
use PDO;

/**
 * ESCOPO COMERCIAL DA REQUISIÇÃO — a cola que faz o filtro PROPAGAR.
 *
 * Resolve, uma vez por requisição, o filtro comercial GLOBAL (canal + vendedor +
 * preferência salva) e entrega às várias superfícies (Dashboard, NF do Período,
 * Agenda, Plano de Voo) exatamente o MESMO recorte — sem cada uma reinterpretar.
 * O contexto (TenantContext) define o universo; isto só refina dentro dele.
 *
 * Reúne o que o {@see CommercialFilterResolver} decide num formato pronto p/ SQL:
 *   · channelRefineSql(alias)  — 'alias.business_channel IN (...)' quando o usuário
 *                                estreitou o canal (senão nada; o BiVisibilityScope
 *                                já cobre o universo do contexto).
 *   · vendorScopeSeed()        — códigos p/ semear o VendorScopeFilter quando a URL
 *                                não pediu vendedor (a preferência age como filtro).
 *   · forceEmptySellers()      — preferência "nenhum" (ou sanitizada a zero) ⇒ 0 linhas.
 *
 * SÓ atua em contexto de UMA empresa (o consolidado mantém o comportamento atual).
 * Pedido de URL fora do autorizado ⇒ {@see CommercialScopeDeniedException} (403),
 * exatamente como no Dashboard.
 */
final class CommercialRequestScope
{
    private function __construct(
        public readonly ?int $companyId,
        public readonly int $userId,
        public readonly string $businessScope,
        public readonly ?ResolvedCommercialFilter $resolved,
        private readonly bool $narrowedChannels,
    ) {
    }

    /**
     * Constrói a partir do HTTP corrente (usa $_GET: channel, vendor_ids/vendor_id)
     * + TenantContext. Não lança para consolidado (fica inativo).
     *
     * @throws CommercialScopeDeniedException  pedido explícito (URL) proibido
     */
    public static function fromRequest(PDO $pdo, string $page): self
    {
        $companyId = TenantContext::companyIdOrNull();
        $userId = (int) (($_SESSION['biomie_auth']['user_id'] ?? 0));
        if ($userId <= 0) {
            $userId = (int) (\BIomie\Auth::currentUser()['id'] ?? 0);
        }
        $businessScope = TenantContext::isBooted() ? TenantContext::businessScope() : '';

        if ($companyId === null || $userId <= 0) {
            return new self($companyId, $userId, $businessScope, null, false);
        }

        $channelPresent = array_key_exists('channel', $_GET);
        $channels = self::parseChannelParam($_GET['channel'] ?? []);
        $sellerPresent = array_key_exists('vendor_ids', $_GET) || array_key_exists('vendor_id', $_GET);
        $sellerIds = self::parseVendorParam();

        $resolved = CommercialFilterResolver::fromPdo($pdo)->resolve(
            $companyId,
            $userId,
            $page,
            $businessScope,
            [
                'channel_present' => $channelPresent,
                'channels' => $channels,
                'seller_present' => $sellerPresent,
                'seller_ids' => $sellerIds,
            ],
        );

        return new self(
            $companyId,
            $userId,
            $businessScope,
            $resolved,
            $resolved->requestedChannels !== null,
        );
    }

    public function isActive(): bool
    {
        return $this->resolved !== null && $this->companyId !== null;
    }

    /** O usuário estreitou o canal (é um refino real, não "todas")? */
    public function isChannelNarrowed(): bool
    {
        return $this->isActive() && $this->narrowedChannels;
    }

    /**
     * Predicado de refino de canal para colar no WHERE (SEM AND).
     *
     * Só produz filtro quando há estreitamento real E a coluna existe na tabela.
     * Caso contrário devolve '1=1' (o BiVisibilityScope já cobre o universo).
     * Canais efetivos vazios ⇒ '1=0' (zero linhas). Valores vêm de allowlist.
     */
    public function channelRefineSql(PDO $pdo, string $table, string $alias): string
    {
        if (!$this->isChannelNarrowed() || $this->resolved === null) {
            return '1=1';
        }
        if (!SchemaCapabilities::columnExists($pdo, $table, 'business_channel')) {
            return '1=1';
        }
        $valid = array_values(array_filter(
            $this->resolved->effectiveChannels,
            static fn (string $c): bool => in_array($c, CommercialChannelScope::CHANNELS, true)
        ));
        if ($valid === []) {
            return '1=0';
        }
        $prefix = $alias !== '' ? rtrim($alias, '.') . '.' : '';
        $list = implode(',', array_map(static fn (string $c): string => "'" . $c . "'", $valid));

        return $prefix . 'business_channel IN (' . $list . ')';
    }

    /**
     * Códigos p/ semear o VendorScopeFilter (a preferência agindo como filtro).
     * Vazio = sem pedido do usuário (o VendorScopeFilter aplica o escopo pleno).
     *
     * USA A INTENÇÃO, não o escopo: o escopo de visibilidade já é aplicado
     * incondicionalmente pelo VendorScopeFilter. Semear o escopo criaria um
     * "filtro fantasma" — o usuário restrito passaria a se comportar como se
     * tivesse escolhido vendedores sem ter escolhido (ver
     * tests/run_commercial_filter_seed_contract.php).
     *
     * @return list<string>
     */
    public function vendorScopeSeed(): array
    {
        $requested = $this->resolved?->requestedSellerIds ?? null;

        return is_array($requested) ? array_values($requested) : [];
    }

    /** Preferência "nenhum vendedor" (ou sanitizada a zero) ⇒ a query deve dar 0 linhas. */
    public function forceEmptySellers(): bool
    {
        return $this->resolved?->requestedSellerIds === [];
    }

    public function sanitized(): bool
    {
        return $this->resolved?->sanitized() ?? false;
    }

    /**
     * Bloco meta para a resposta (filter_hash + canal + sanitização), alinhado ao
     * que o Dashboard devolve, para a UI tratar todas as superfícies igual.
     *
     * @param  array<string,mixed> $refine
     * @return array<string,mixed>
     */
    public function meta(string $start, string $end, array $refine = []): array
    {
        if ($this->resolved === null || $this->companyId === null) {
            return [
                'filter_hash' => null,
                'business_scope' => $this->businessScope,
                'company_id' => $this->companyId,
                'source' => 'default',
                'sanitized' => false,
                'channel_scope' => null,
            ];
        }
        $scope = $this->resolved->toEffectiveScope($this->companyId, $start, $end, $refine);

        return [
            'filter_hash' => $scope->filterHash(),
            'business_scope' => $this->businessScope,
            'company_id' => $this->companyId,
            'source' => $this->resolved->source,
            'sanitized' => $this->resolved->sanitized(),
            'channel_scope' => $this->resolved->channelScopeMeta(),
            'sanitization' => [
                'channels_dropped' => $this->resolved->droppedChannels,
                'sellers_dropped' => $this->resolved->droppedSellers,
            ],
        ];
    }

    /**
     * 'all'/'todas' = sentinela de "Todas as operações" (presente, sem canal
     * específico). Removidos aqui p/ não caírem no allowlist como canal inválido.
     *
     * @param  mixed $raw
     * @return list<string>
     */
    private static function parseChannelParam(mixed $raw): array
    {
        $items = is_array($raw) ? $raw : explode(',', (string) $raw);
        $out = [];
        foreach ($items as $c) {
            $c = strtolower(trim((string) $c));
            if ($c !== '' && $c !== 'all' && $c !== 'todas') {
                $out[] = $c;
            }
        }

        return array_values(array_unique($out));
    }

    /** @return list<string> */
    private static function parseVendorParam(): array
    {
        $raw = $_GET['vendor_ids'] ?? ($_GET['vendor_id'] ?? '');
        $items = is_array($raw) ? $raw : explode(',', (string) $raw);
        $out = [];
        foreach ($items as $v) {
            $v = trim((string) $v);
            if ($v !== '' && $v !== '0') {
                $out[] = $v;
            }
        }

        return array_values(array_unique($out));
    }
}
