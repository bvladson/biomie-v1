<?php

declare(strict_types=1);

/**
 * api/global_search.php — Busca global unificada (omnibox do topbar).
 *
 * Pesquisa, em uma unica chamada, as principais entidades canonicas de origem Omie
 * da empresa ativa: notas fiscais, pedidos, clientes, produtos e vendedores.
 * Tudo isolado por empresa via TenantContext::sqlFilter; notas ainda respeitam o
 * BiVisibilityScope (Licitacao oculta por padrao).
 *
 * Parametros:
 *   q      — termo de busca (min. 2 caracteres)
 *   limit  — itens por grupo (padrao 6, max 12)
 *
 * Resposta: { ok, q, total, groups: [ { type, label, icon, items: [...] } ] }
 * Cada item traz campos tipados; o frontend (global-search.js) mapeia para
 * data-biomie-action (drilldown) + href de fallback.
 */

use BIomie\Auth;
use BIomie\Database;
use BIomie\Http;
use BIomie\Support\BiVisibilityScope;
use BIomie\Support\SellerVisibilityScope;
use BIomie\Support\TenantContext;

require __DIR__ . '/bootstrap.php';

/** @var \BIomie\Config $config definido em api/bootstrap.php */
/** @var \BIomie\Logger $logger definido em api/bootstrap.php */

Auth::requireCapability('sales.view', true);

try {
    $q = trim((string) Http::query('q', ''));
    $perGroup = min(12, max(1, (int) Http::query('limit', '6')));

    if (mb_strlen($q) < 2) {
        Http::json(['ok' => true, 'q' => $q, 'total' => 0, 'groups' => []]);
    }

    $conn = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    if (!Database::isConfigured($config, $conn)) {
        Http::json(['ok' => false, 'error' => ['code' => 'DB_UNAVAILABLE', 'message' => 'Banco de dados nao configurado.']], 503);
    }

    $pdo = Database::connect($config, $conn);

    // VISIBILIDADE: a omnibox varre entidades comerciais. Grupos com dono de
    // vendedor (NFs, pedidos, títulos, o próprio roster) são restritos ao escopo
    // do usuário; um resultado de vendedor oculto não deve aparecer nem na busca.
    // Clientes e produtos são dimensão/catálogo (sem dono único) e ficam fora.
    // Cada grupo passa seu PRÓPRIO $params — sqlFilter usa binds únicos por
    // chamada, então não há colisão entre os grupos.
    $svUserId  = (int) (Auth::currentUser()['id'] ?? 0);
    $svCompany = (int) TenantContext::companyId();
    $sellerVis = new SellerVisibilityScope($pdo);

    // ── Termos de busca ──────────────────────────────────────────────────────
    $escaped   = str_replace(['%', '_'], ['\\%', '\\_'], $q);
    $like      = '%' . $escaped . '%';
    $digits    = preg_replace('/[^0-9]/', '', $q) ?? '';
    $likeDigit = $digits !== '' ? '%' . $digits . '%' : null;
    $numStr    = ltrim($q, '0');
    $isNumeric = ctype_digit($numStr) && $numStr !== '';
    $numVal    = $isNumeric ? (int) $numStr : null;

    // Chave NFe completa (44 dígitos) → match exato
    $chaveExact = strlen($digits) === 44 ? $digits : null;

    // Termo monetário ("1540", "1.540,00", "R$ 1.540,00") → busca por valor exato (±0,01)
    $moneyVal = null;
    $moneyRaw = trim(preg_replace('/^R\$\s*/i', '', $q) ?? $q);
    if (preg_match('/^\d{1,3}(\.\d{3})*(,\d{1,2})?$/', $moneyRaw) === 1 || preg_match('/^\d+(,\d{1,2})$/', $moneyRaw) === 1) {
        $moneyVal = (float) str_replace(',', '.', str_replace('.', '', $moneyRaw));
    } elseif (preg_match('/^\d+(\.\d{1,2})?$/', $moneyRaw) === 1) {
        $moneyVal = (float) $moneyRaw;
    }
    if ($moneyVal !== null && $moneyVal <= 0) {
        $moneyVal = null;
    }

    $groups = [];
    $total  = 0;
    $gsBlock = 'inicio'; // instrumentação: qual bloco estava executando se algo falhar

    $tableExists = static function (PDO $pdo, string $table): bool {
        try {
            $s = $pdo->prepare(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t LIMIT 1"
            );
            $s->execute([':t' => $table]);
            return (int) $s->fetchColumn() > 0;
        } catch (\Throwable) {
            return false;
        }
    };

    // ── 1. Notas fiscais ─────────────────────────────────────────────────────
    $gsBlock = 'notas';
    if ($tableExists($pdo, 'biomie_notas_fiscais_canonicas')) {
        $conds  = ['nf.numero_nf LIKE :nf_like_numero', 'nf.nome_cliente LIKE :nf_like_cliente', 'nf.chave_nfe LIKE :nf_like_chave'];
        $params = [':nf_like_numero' => $like, ':nf_like_cliente' => $like, ':nf_like_chave' => $like];
        if ($numVal !== null) { $conds[] = 'CAST(nf.numero_nf AS UNSIGNED) = :num'; $params[':num'] = $numVal; }
        if ($chaveExact !== null) { $conds[] = 'nf.chave_nfe = :chave'; $params[':chave'] = $chaveExact; }
        if ($moneyVal !== null) {
            $conds[] = 'ABS(nf.valor_total - :money) <= 0.01';
            $params[':money'] = $moneyVal;
        }

        $vis = BiVisibilityScope::getCondition($pdo, 'biomie_notas_fiscais_canonicas', 'nf');
        $svCond = $sellerVis->sqlFilter($svCompany, $svUserId, 'nf', $params);
        $sql = "SELECT nf.nota_key, nf.numero_nf, nf.serie_nf, nf.chave_nfe,
                       nf.nome_cliente, nf.valor_total, nf.data_emissao, nf.codigo_pedido_origem
                FROM biomie_notas_fiscais_canonicas nf
                WHERE " . TenantContext::sqlFilter('nf') . " AND {$vis} AND {$svCond}
                  AND (" . implode(' OR ', $conds) . ")
                ORDER BY nf.data_emissao DESC
                LIMIT {$perGroup}";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $items = array_map(static function (array $r): array {
            return [
                'nota_key'  => (string) ($r['nota_key'] ?? ''),
                'numero_nf' => (string) ($r['numero_nf'] ?? ''),
                'serie_nf'  => (string) ($r['serie_nf'] ?? ''),
                'chave_nfe' => (string) ($r['chave_nfe'] ?? ''),
                'cliente'   => (string) ($r['nome_cliente'] ?? ''),
                'pedido'    => (string) ($r['codigo_pedido_origem'] ?? ''),
                'valor'     => (float) ($r['valor_total'] ?? 0),
                'data'      => (string) ($r['data_emissao'] ?? ''),
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
        if ($items !== []) {
            $groups[] = ['type' => 'invoice', 'label' => 'Notas fiscais', 'icon' => 'receipt_long', 'items' => $items];
            $total   += count($items);
        }
    }

    // ── 2. Pedidos ───────────────────────────────────────────────────────────
    $gsBlock = 'pedidos';
    if ($tableExists($pdo, 'biomie_pedidos_canonicos')) {
        $conds  = ['p.codigo_pedido LIKE :order_like_code', 'p.nome_cliente LIKE :order_like_client'];
        $params = [':order_like_code' => $like, ':order_like_client' => $like];
        if ($numVal !== null) { $conds[] = 'CAST(p.codigo_pedido AS UNSIGNED) = :num'; $params[':num'] = $numVal; }

        $svCond = $sellerVis->sqlFilter($svCompany, $svUserId, 'p', $params);
        $sql = "SELECT p.codigo_pedido, p.nome_cliente, p.valor_total, p.data_emissao, p.status_comercial
                FROM biomie_pedidos_canonicos p
                WHERE " . TenantContext::sqlFilter('p') . " AND {$svCond}
                  AND (" . implode(' OR ', $conds) . ")
                ORDER BY p.data_emissao DESC
                LIMIT {$perGroup}";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $items = array_map(static function (array $r): array {
            return [
                'codigo'  => (string) ($r['codigo_pedido'] ?? ''),
                'cliente' => (string) ($r['nome_cliente'] ?? ''),
                'status'  => (string) ($r['status_comercial'] ?? ''),
                'valor'   => (float) ($r['valor_total'] ?? 0),
                'data'    => (string) ($r['data_emissao'] ?? ''),
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
        if ($items !== []) {
            $groups[] = ['type' => 'order', 'label' => 'Pedidos', 'icon' => 'shopping_cart', 'items' => $items];
            $total   += count($items);
        }
    }

    // ── 2b. Títulos financeiros (boletos/recebíveis) ─────────────────────────
    $gsBlock = 'titulos';
    if ($tableExists($pdo, 'biomie_titulos_financeiros_canonicos')) {
        $conds  = ['t.numero_documento LIKE :finance_like_doc', 't.nome_cliente LIKE :finance_like_client'];
        $params = [':finance_like_doc' => $like, ':finance_like_client' => $like];
        if ($chaveExact !== null) { $conds[] = 't.chave_nfe = :chave'; $params[':chave'] = $chaveExact; }
        if ($moneyVal !== null) {
            $conds[] = '(ABS(COALESCE(t.valor_liquido, 0) - :money) <= 0.01'
                . ' OR ABS(COALESCE(t.valor_pago, 0) - :money2) <= 0.01'
                . ' OR ABS(COALESCE(t.valor_aberto, 0) - :money3) <= 0.01)';
            $params[':money']  = $moneyVal;
            $params[':money2'] = $moneyVal;
            $params[':money3'] = $moneyVal;
        }

        try {
            $svCond = $sellerVis->sqlFilter($svCompany, $svUserId, 't', $params);
            $sql = "SELECT t.titulo_key, t.numero_documento, t.nome_cliente, t.status_titulo,
                           t.nota_key, t.chave_nfe, t.codigo_pedido_origem,
                           t.codigo_cliente_omie, t.documento_cliente, t.nome_vendedor,
                           COALESCE(NULLIF(t.valor_liquido, 0), t.valor_pago, 0) AS valor, t.data_vencimento
                    FROM biomie_titulos_financeiros_canonicos t
                    WHERE " . TenantContext::sqlFilter('t') . " AND {$svCond}
                      AND (" . implode(' OR ', $conds) . ")
                    ORDER BY t.data_vencimento DESC
                    LIMIT {$perGroup}";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $items = array_map(static function (array $r): array {
                return [
                    'titulo_key' => (string) ($r['titulo_key'] ?? ''),
                    'documento'  => (string) ($r['numero_documento'] ?? ''),
                    'cliente'    => (string) ($r['nome_cliente'] ?? ''),
                    'cliente_id' => (string) ($r['codigo_cliente_omie'] ?? ''),
                    'cliente_doc'=> (string) ($r['documento_cliente'] ?? ''),
                    'vendedor'   => (string) ($r['nome_vendedor'] ?? ''),
                    'nota_key'   => (string) ($r['nota_key'] ?? ''),
                    'chave_nfe'  => (string) ($r['chave_nfe'] ?? ''),
                    'pedido'     => (string) ($r['codigo_pedido_origem'] ?? ''),
                    'status'     => (string) ($r['status_titulo'] ?? ''),
                    'valor'      => (float) ($r['valor'] ?? 0),
                    'vencimento' => (string) ($r['data_vencimento'] ?? ''),
                ];
            }, $stmt->fetchAll(PDO::FETCH_ASSOC));
            if ($items !== []) {
                $groups[] = ['type' => 'finance', 'label' => 'Títulos financeiros', 'icon' => 'account_balance_wallet', 'items' => $items];
                $total   += count($items);
            }
        } catch (\Throwable) {
            // colunas podem variar entre instalações; grupo é opcional
        }
    }

    // ── 3. Clientes ──────────────────────────────────────────────────────────
    $gsBlock = 'clientes';
    if ($tableExists($pdo, 'biomie_clientes_dim')) {
        $conds  = ['c.nome_cliente LIKE :customer_like_name', 'c.nome_fantasia LIKE :customer_like_trade'];
        $params = [':customer_like_name' => $like, ':customer_like_trade' => $like];
        if ($likeDigit !== null) {
            $conds[] = "REPLACE(REPLACE(REPLACE(COALESCE(c.cpf_cnpj,''),'.',''),'-',''),'/','') LIKE :likedigit";
            $params[':likedigit'] = $likeDigit;
        }
        $sql = "SELECT c.codigo_cliente_omie, c.nome_cliente, c.nome_fantasia, c.cpf_cnpj
                FROM biomie_clientes_dim c
                WHERE " . TenantContext::sqlFilter('c') . "
                  AND (" . implode(' OR ', $conds) . ")
                ORDER BY c.nome_cliente
                LIMIT {$perGroup}";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $items = array_map(static function (array $r): array {
            return [
                'id'       => (string) ($r['codigo_cliente_omie'] ?? ''),
                'nome'     => (string) ($r['nome_cliente'] ?? ''),
                'fantasia' => (string) ($r['nome_fantasia'] ?? ''),
                'doc'      => (string) ($r['cpf_cnpj'] ?? ''),
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
        if ($items !== []) {
            $groups[] = ['type' => 'customer', 'label' => 'Clientes', 'icon' => 'groups', 'items' => $items];
            $total   += count($items);
        }
    }

    // ── 4. Produtos (itens de NF, escopo via join a NF) ──────────────────────
    $gsBlock = 'produtos';
    if ($tableExists($pdo, 'biomie_notas_fiscais_itens_canonicos')) {
        $sql = "SELECT i.codigo_produto AS code, MAX(i.descricao_produto) AS label, COUNT(*) AS cnt
                FROM biomie_notas_fiscais_itens_canonicos i
                INNER JOIN biomie_notas_fiscais_canonicas n
                        ON n.company_id = i.company_id AND n.nota_key = i.nota_key
                WHERE " . TenantContext::sqlFilter('n') . "
                  AND TRIM(COALESCE(i.codigo_produto, '')) <> ''
                  AND (i.descricao_produto LIKE :product_like_desc OR i.codigo_produto LIKE :product_like_code)
                GROUP BY i.codigo_produto
                ORDER BY cnt DESC
                LIMIT {$perGroup}";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':product_like_desc' => $like, ':product_like_code' => $like]);
        $items = array_map(static function (array $r): array {
            return [
                'code'  => (string) ($r['code'] ?? ''),
                'nome'  => (string) ($r['label'] ?? ''),
                'count' => (int) ($r['cnt'] ?? 0),
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
        if ($items !== []) {
            $groups[] = ['type' => 'product', 'label' => 'Produtos', 'icon' => 'inventory_2', 'items' => $items];
            $total   += count($items);
        }
    }

    // ── 5. Vendedores ────────────────────────────────────────────────────────
    $gsBlock = 'vendedores';
    if ($tableExists($pdo, 'biomie_vendedores_omie')) {
        $vendParams = [':like' => $like];
        $svCond = $sellerVis->sqlFilter($svCompany, $svUserId, 'v', $vendParams, 'codigo_omie');
        $sql = "SELECT v.codigo_omie AS id, v.nome AS nome
                FROM biomie_vendedores_omie v
                WHERE " . TenantContext::sqlFilter('v') . " AND {$svCond}
                  AND v.nome LIKE :like AND v.codigo_omie <> ''
                ORDER BY v.nome
                LIMIT {$perGroup}";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($vendParams);
        $items = array_map(static function (array $r): array {
            return ['id' => (string) ($r['id'] ?? ''), 'nome' => (string) ($r['nome'] ?? '')];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
        if ($items !== []) {
            $groups[] = ['type' => 'vendor', 'label' => 'Vendedores', 'icon' => 'badge', 'items' => $items];
            $total   += count($items);
        }
    }

    Http::json(['ok' => true, 'q' => $q, 'total' => $total, 'groups' => $groups]);
} catch (Throwable $e) {
    $logger->error('global_search falhou.', [
        'message' => $e->getMessage(),
        'block'   => $gsBlock ?? 'desconhecido',
        'q_len'   => isset($q) ? mb_strlen($q) : null,
    ]);
    Http::json([
        'ok'    => false,
        'error' => ['code' => 'GLOBAL_SEARCH_FAILED', 'message' => 'Nao foi possivel executar a busca agora.', 'details' => null],
    ], 500);
}
