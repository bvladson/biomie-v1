<?php

declare(strict_types=1);

namespace BIomie\Support;

use BIomie\QuoteAgenda\SellerBindingResolver;
use PDO;

/**
 * VISIBILIDADE COMERCIAL — quais vendedores um usuário pode ver NUMA empresa.
 *
 * É uma primitiva TRANSVERSAL, no mesmo nível de {@see TenantContext::sqlFilter}
 * e do BiVisibilityScope: o tenant responde "de qual empresa", este responde "de
 * quais vendedores". Toda leitura comercial nova deve passar por aqui.
 *
 * NÃO CONFUNDIR COM IDENTIDADE. `biomie_quote_seller_binding` responde "QUEM EU
 * SOU" — tem UNIQUE (company_id, seller_id), um código pertence a um usuário.
 * Visibilidade é "QUEM EU POSSO VER": três gestores podem ver o mesmo vendedor.
 * São relações distintas e por isso moram em tabelas distintas.
 *
 * MODOS
 *  · all      — todos os vendedores da empresa  ⇒ allowedSellerIds() = null
 *  · own      — só os códigos que o usuário representa (vínculo de identidade)
 *  · selected — lista escolhida pelo administrador (biomie_user_visible_sellers)
 *
 * FONTE DO MODO: a linha em `biomie_user_seller_scope` (empresa, usuário). Se
 * ela EXISTE, o modo é explícito e vence tudo. Se NÃO existe, cai no default.
 *
 * DEFAULT SEM CONFIGURAÇÃO (decidido em 2026-07-23): quem tem vínculo de
 * vendedor cai em `own`; os demais em `all`. Assim a introdução do escopo não
 * cega ninguém, e a proteção que a visão do vendedor JÁ tinha não regride.
 *
 * VAZIO EXPLÍCITO É VAZIO: modo `own` sem vínculo, ou `selected` sem nenhum
 * vendedor marcado, resolvem para `[]` (nada) — nunca degradam para `all`. Foi o
 * admin quem restringiu; interpretar como "todos" seria desfazer a decisão dele.
 *
 * NULL ≠ LISTA VAZIA — a distinção é a trava de segurança:
 *   null → sem restrição (todos da empresa)
 *   []   → NENHUM vendedor autorizado ⇒ zero linhas, nunca "todos"
 * Quem consome precisa tratar os dois casos; confundi-los é uma escalada de
 * privilégio silenciosa.
 *
 * O isolamento por empresa continua sendo do TenantContext: este escopo é uma
 * restrição ADICIONAL, nunca um substituto.
 */
final class SellerVisibilityScope
{
    public const MODE_ALL      = 'all';
    public const MODE_OWN      = 'own';
    public const MODE_SELECTED = 'selected';

    /**
     * Cache por request: o mesmo par (empresa, usuário) é resolvido várias vezes por página.
     *
     * @var array<string,list<string>|null>
     */
    private array $cache = [];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Códigos Omie que o usuário pode ver nesta empresa.
     *
     * @return list<string>|null null = todos da empresa · [] = nenhum
     */
    public function allowedSellerIds(int $companyId, int $userId): ?array
    {
        // Sem usuário ou sem empresa identificados NÃO é "sem restrição" — é
        // ausência de contexto, e o modo seguro de falhar é não mostrar nada.
        if ($companyId <= 0 || $userId <= 0) {
            return [];
        }

        $key = $companyId . ':' . $userId;
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        return $this->cache[$key] = $this->resolve($companyId, $userId);
    }

    /**
     * Resolve o escopo sem cache. O modo explícito (linha em user_seller_scope)
     * vence; na ausência dele, aplica o default all/own.
     *
     * @return list<string>|null
     */
    private function resolve(int $companyId, int $userId): ?array
    {
        $mode = $this->storedMode($companyId, $userId);

        // Sem linha ⇒ default: quem tem vínculo de vendedor vê a própria
        // carteira; os demais veem tudo. Ninguém fica cego no dia do deploy.
        if ($mode === null) {
            $own = $this->ownSellerIds($companyId, $userId);
            return $own !== [] ? $own : null;
        }

        return match ($mode) {
            self::MODE_ALL      => null,
            self::MODE_OWN      => $this->ownSellerIds($companyId, $userId),
            self::MODE_SELECTED => $this->selectedSellerIds($companyId, $userId),
            default             => [],   // ENUM inesperado: fail-closed, nunca "todos"
        };
    }

    /**
     * Modo persistido para (empresa, usuário), ou null se não há configuração.
     *
     * Degrada para null (⇒ default) se a tabela ainda não existe — a migration
     * da Fase B pode não ter rodado num ambiente, e isso não pode virar erro 500
     * numa tela comercial. Fail-OPEN aqui é seguro porque o default já é o
     * comportamento pré-visibilidade; a trava fail-closed mora nos modos.
     */
    private function storedMode(int $companyId, int $userId): ?string
    {
        try {
            $st = $this->pdo->prepare(
                'SELECT visibility_mode FROM biomie_user_seller_scope
                  WHERE company_id = :c AND user_id = :u'
            );
            $st->execute(['c' => $companyId, 'u' => $userId]);
            $mode = $st->fetchColumn();
        } catch (\Throwable) {
            return null;
        }

        return $mode === false ? null : (string) $mode;
    }

    /**
     * Códigos do vínculo de identidade (modo own).
     *
     * @return list<string>
     */
    private function ownSellerIds(int $companyId, int $userId): array
    {
        return (new SellerBindingResolver($this->pdo))->sellerIdsFor($userId, $companyId);
    }

    /**
     * Códigos escolhidos pelo admin (modo selected). Lista vazia é vazio real —
     * o admin marcou "selected" e não escolheu ninguém, então não vê nada.
     *
     * @return list<string>
     */
    private function selectedSellerIds(int $companyId, int $userId): array
    {
        try {
            $st = $this->pdo->prepare(
                'SELECT seller_id FROM biomie_user_visible_sellers
                  WHERE company_id = :c AND user_id = :u ORDER BY seller_id'
            );
            $st->execute(['c' => $companyId, 'u' => $userId]);
            $ids = $st->fetchAll(PDO::FETCH_COLUMN);
        } catch (\Throwable) {
            // Tabela ausente com modo=selected persistido: o modo diz "restrinja",
            // então o modo seguro de falhar é NÃO mostrar nada — jamais tudo.
            return [];
        }

        // seller_id '0'/vazio nunca representa pessoa: filtrado (igual ao binding).
        return array_values(array_filter(
            array_map('strval', $ids),
            static fn (string $s): bool => $s !== '' && $s !== '0'
        ));
    }

    /**
     * Modo EFETIVO — para diagnóstico e para a UI explicar o que está sendo
     * visto. Reflete o modo persistido; na ausência de linha, deriva o default.
     */
    public function mode(int $companyId, int $userId): string
    {
        if ($companyId <= 0 || $userId <= 0) {
            return self::MODE_SELECTED;   // sem contexto ⇒ escopo vazio (ver resolve)
        }

        $stored = $this->storedMode($companyId, $userId);
        if ($stored !== null) {
            return $stored;
        }

        // Default: own se tem vínculo, all caso contrário.
        return $this->ownSellerIds($companyId, $userId) !== []
            ? self::MODE_OWN
            : self::MODE_ALL;
    }

    /**
     * O usuário pode ver ESTE vendedor?
     *
     * Usado para validar `seller_id`/`vendor_id` vindos da request: o filtro do
     * usuário pode RESTRINGIR o escopo, nunca AMPLIAR.
     */
    public function canSee(int $companyId, int $userId, string $sellerId): bool
    {
        $allowed = $this->allowedSellerIds($companyId, $userId);
        if ($allowed === null) {
            return true;
        }

        return in_array($sellerId, $allowed, true);
    }

    /**
     * Predicado SQL de visibilidade — a primitiva da ONDA transversal (Fase C).
     *
     * Espelha {@see \BIomie\Support\TenantContext::sqlFilter}: devolve um trecho
     * de WHERE pronto para colar, isolando a query aos vendedores autorizados.
     * Os placeholders entram em $params por referência (nomes únicos por chamada
     * para não colidir quando há mais de um filtro na mesma query).
     *
     *   null (todos)   ⇒ '1=1'  (sem restrição — não polui o WHERE com nada)
     *   [] (nenhum)    ⇒ '1=0'  (zero linhas — a trava fail-closed)
     *   [a,b,...]      ⇒ 'alias.coluna IN (:svs_0, :svs_1, ...)'
     *
     * @param array<string,mixed> $params  recebe os binds por referência
     */
    public function sqlFilter(
        int $companyId,
        int $userId,
        string $alias,
        array &$params,
        string $column = 'codigo_vendedor_omie'
    ): string {
        $allowed = $this->allowedSellerIds($companyId, $userId);
        if ($allowed === null) {
            return '1=1';
        }
        if ($allowed === []) {
            return '1=0';
        }

        $prefix = $alias !== '' ? rtrim($alias, '.') . '.' : '';
        // Sufixo único por chamada: várias aplicações na mesma query não colidem.
        static $seq = 0;
        $ph = [];
        foreach (array_values($allowed) as $sid) {
            $name = 'svs_' . $seq++;
            $ph[] = ':' . $name;
            $params[$name] = $sid;
        }

        return $prefix . $column . ' IN (' . implode(', ', $ph) . ')';
    }

    /**
     * Interseção entre o escopo autorizado e um filtro pedido pelo usuário.
     *
     * Devolve o que deve ir para `seller_ids` no read model. `$requested` vazio
     * significa "sem filtro do usuário" — o resultado é o escopo puro.
     *
     * @param  list<string> $requested
     * @return list<string>|null null = todos da empresa · [] = nenhum
     */
    public function intersect(int $companyId, int $userId, array $requested): ?array
    {
        $allowed = $this->allowedSellerIds($companyId, $userId);
        if ($requested === []) {
            return $allowed;
        }
        if ($allowed === null) {
            return array_values($requested);
        }

        return array_values(array_intersect($allowed, $requested));
    }
}
