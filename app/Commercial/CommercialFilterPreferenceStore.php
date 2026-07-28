<?php

declare(strict_types=1);

namespace BIomie\Commercial;

use PDO;
use Throwable;

/**
 * PERSISTÊNCIA da INTENÇÃO de filtro comercial — server-side, por recorte.
 *
 * Guarda o que o usuário PEDIU (canais e vendedores), não o que ficou EFETIVO: o
 * efetivo é recalculado a cada requisição contra a autorização atual (ver
 * {@see CommercialFilterResolver}). Assim a preferência nunca "congela" um acesso
 * que o admin depois removeu — no máximo ela é sanitizada na leitura.
 *
 * A distinção null ≠ [] é a mesma trava de segurança do SellerVisibilityScope,
 * agora sobrevivendo à sessão. É por isso que este Store devolve/aceita structs
 * explícitos em vez de arrays soltos:
 *   channels: null = todos os autorizados · list = subconjunto pedido
 *   sellerIds: null = todos os autorizados · [] = nenhum · list = específicos
 *
 * FAIL-OPEN na LEITURA (tabela ausente ⇒ null = "sem preferência"): um ambiente
 * onde a migration ainda não rodou não pode derrubar o dashboard. É seguro porque
 * "sem preferência" cai no default, que já é o comportamento pré-feature. A trava
 * fail-closed mora na autorização (resolver), não aqui.
 */
final class CommercialFilterPreferenceStore
{
    /**
     * Páginas conhecidas — allowlist para não persistir chave arbitrária vinda do cliente.
     *
     * `PAGE_COMMERCIAL` é a chave GLOBAL do filtro comercial: uma só seleção de
     * canal/vendedor vale para TODAS as superfícies comerciais (Dashboard, NF do
     * Período, Agenda, Plano de Voo). É assim que o filtro "propaga" — definir num
     * lugar reflete em todos. A coluna `page` continua flexível para, no futuro,
     * permitir recorte por página se algum fluxo exigir memória própria.
     */
    public const PAGE_COMMERCIAL = 'commercial';
    /** @deprecated alias de compatibilidade — use PAGE_COMMERCIAL. */
    public const PAGE_COMMERCIAL_DASHBOARD = self::PAGE_COMMERCIAL;
    public const PAGES = [self::PAGE_COMMERCIAL];

    private const TABLE = 'biomie_user_commercial_filters';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function isKnownPage(string $page): bool
    {
        return in_array($page, self::PAGES, true);
    }

    /**
     * Preferência salva para (empresa, usuário, página, recorte), ou null se não há.
     *
     * @return array{channels: list<string>|null, seller_ids: list<string>|null, updated_at: string}|null
     */
    public function load(int $companyId, int $userId, string $page, string $businessScope): ?array
    {
        if ($companyId <= 0 || $userId <= 0 || !self::isKnownPage($page)) {
            return null;
        }

        try {
            $st = $this->pdo->prepare(
                'SELECT channels_json, seller_ids_json, updated_at FROM ' . self::TABLE . '
                  WHERE company_id = :c AND user_id = :u AND page = :p AND business_scope = :b'
            );
            $st->execute(['c' => $companyId, 'u' => $userId, 'p' => $page, 'b' => $businessScope]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return null; // tabela ausente / indisponível ⇒ sem preferência (default)
        }

        if ($row === false) {
            return null;
        }

        return [
            'channels' => self::decodeList($row['channels_json'] ?? null),
            'seller_ids' => self::decodeList($row['seller_ids_json'] ?? null),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    /**
     * Salva (upsert) a INTENÇÃO. Só deve ser chamado DEPOIS de o backend validar
     * o pedido contra a autorização (ver commercial_filter_pref.php): guardar algo
     * proibido seria semear um estado que a leitura teria de sanitizar toda vez.
     *
     * @param list<string>|null $channels  null = todos os autorizados
     * @param list<string>|null $sellerIds null = todos autorizados · [] = nenhum
     */
    public function save(
        int $companyId,
        int $userId,
        string $page,
        string $businessScope,
        ?array $channels,
        ?array $sellerIds,
        ?int $updatedBy = null
    ): void {
        if ($companyId <= 0 || $userId <= 0) {
            throw new \InvalidArgumentException('Contexto inválido para salvar preferência.');
        }
        if (!self::isKnownPage($page)) {
            throw new \InvalidArgumentException('Página desconhecida para preferência comercial.');
        }

        $st = $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE . '
                (company_id, user_id, page, business_scope, channels_json, seller_ids_json, updated_by)
             VALUES (:c, :u, :p, :b, :ch, :se, :by_i)
             ON DUPLICATE KEY UPDATE
                channels_json = VALUES(channels_json),
                seller_ids_json = VALUES(seller_ids_json),
                updated_by = VALUES(updated_by)'
        );
        $st->execute([
            'c' => $companyId,
            'u' => $userId,
            'p' => $page,
            'b' => $businessScope,
            'ch' => self::encodeList($channels),
            'se' => self::encodeList($sellerIds),
            'by_i' => $updatedBy,
        ]);
    }

    /** Remove a preferência (volta ao default). */
    public function clear(int $companyId, int $userId, string $page, string $businessScope): void
    {
        if ($companyId <= 0 || $userId <= 0 || !self::isKnownPage($page)) {
            return;
        }
        try {
            $st = $this->pdo->prepare(
                'DELETE FROM ' . self::TABLE . '
                  WHERE company_id = :c AND user_id = :u AND page = :p AND business_scope = :b'
            );
            $st->execute(['c' => $companyId, 'u' => $userId, 'p' => $page, 'b' => $businessScope]);
        } catch (Throwable) {
            // best-effort: se a tabela sumiu, não há o que limpar.
        }
    }

    /**
     * NULL preservado como null (todos); '[]' como []; caso contrário a lista.
     * Entrada corrompida vira null (default) em vez de derrubar a página.
     *
     * @return list<string>|null
     */
    private static function decodeList(mixed $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        $s = trim((string) $raw);
        if ($s === '') {
            return null;
        }
        try {
            $decoded = json_decode($s, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
        if (!is_array($decoded)) {
            return null;
        }

        return array_values(array_unique(array_filter(
            array_map(static fn ($v): string => trim((string) $v), $decoded),
            static fn (string $v): bool => $v !== ''
        )));
    }

    /**
     * null → NULL na coluna (todos); lista (inclusive []) → JSON.
     *
     * @param list<string>|null $list
     */
    private static function encodeList(?array $list): ?string
    {
        if ($list === null) {
            return null;
        }
        $clean = array_values(array_unique(array_filter(
            array_map(static fn ($v): string => trim((string) $v), $list),
            static fn (string $v): bool => $v !== ''
        )));

        return json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
