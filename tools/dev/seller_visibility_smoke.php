<?php

declare(strict_types=1);

/**
 * SMOKE DA VISIBILIDADE COMERCIAL (Fase B/C) — matriz completa, com reversão.
 *
 * Prova, contra DADOS REAIS, que o escopo de vendedores se comporta como
 * especificado — sem depender de clicar no navegador. Cobre o que a checagem de
 * unidade não alcança: a configuração REAL gravada, lida pelo MESMO resolvedor
 * que os endpoints usam, na MESMA base.
 *
 * ─── SEGURANÇA ──────────────────────────────────────────────────────────────
 *  · Exige --confirm: não roda por acidente.
 *  · SALVA a configuração atual do usuário-alvo, aplica a de teste, e RESTAURA
 *    ao final — inclusive em caso de erro (finally). Estado final == inicial.
 *  · Só mexe nas tabelas da PRÓPRIA feature (biomie_user_seller_scope e
 *    biomie_user_visible_sellers). Não toca NF, pedido, título, agenda ou piloto.
 *  · O usuário-alvo fica restrito por alguns SEGUNDOS. Rode fora do horário de
 *    pico, ou use um usuário de teste dedicado.
 *
 * ─── USO ────────────────────────────────────────────────────────────────────
 *   php tools/dev/seller_visibility_smoke.php --user=3 --company=1 --confirm
 *   php tools/dev/seller_visibility_smoke.php --user=3 --company=1 --confirm \
 *        --other-company=2          # prova isolamento entre empresas
 *
 * Saída: linhas [OK]/[FALHA] + resumo. Exit 0 só se TUDO passar.
 */

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Config;
use BIomie\Database;
use BIomie\Support\SellerVisibilityScope;

$opts = getopt('', ['user:', 'company:', 'other-company:', 'confirm']);
$userId    = (int) ($opts['user'] ?? 0);
$companyId = (int) ($opts['company'] ?? 0);
$otherCid  = (int) ($opts['other-company'] ?? 0);

if (!isset($opts['confirm']) || $userId <= 0 || $companyId <= 0) {
    fwrite(STDERR, "Uso: php tools/dev/seller_visibility_smoke.php --user=ID --company=ID [--other-company=ID] --confirm\n"
        . "  Grava configuração TEMPORÁRIA de visibilidade e a REVERTE ao final.\n");
    exit(2);
}

$config = new Config();
$conn = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo  = Database::connect($config, $conn);

$results = [];
$assert = static function (string $name, bool $ok, string $detail = '') use (&$results): void {
    $results[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
    printf("  [%s] %s%s\n", $ok ? "\033[32mOK\033[0m" : "\033[31mFALHA\033[0m", $name, $detail !== '' ? "  ({$detail})" : '');
};

/** Estado atual (para restaurar). */
$snapshot = static function (PDO $pdo, int $cid, int $uid): array {
    $m = $pdo->prepare('SELECT visibility_mode FROM biomie_user_seller_scope WHERE company_id=:c AND user_id=:u');
    $m->execute([':c' => $cid, ':u' => $uid]);
    $mode = $m->fetchColumn();
    $s = $pdo->prepare('SELECT seller_id FROM biomie_user_visible_sellers WHERE company_id=:c AND user_id=:u ORDER BY seller_id');
    $s->execute([':c' => $cid, ':u' => $uid]);

    return ['mode' => $mode === false ? null : (string) $mode, 'sellers' => $s->fetchAll(PDO::FETCH_COLUMN)];
};

/** Aplica modo + lista (idempotente). */
$apply = static function (PDO $pdo, int $cid, int $uid, ?string $mode, array $sellers): void {
    $pdo->prepare('DELETE FROM biomie_user_visible_sellers WHERE company_id=:c AND user_id=:u')
        ->execute([':c' => $cid, ':u' => $uid]);
    if ($mode === null) {
        $pdo->prepare('DELETE FROM biomie_user_seller_scope WHERE company_id=:c AND user_id=:u')
            ->execute([':c' => $cid, ':u' => $uid]);

        return;
    }
    $pdo->prepare('INSERT INTO biomie_user_seller_scope (company_id,user_id,visibility_mode)
                   VALUES (:c,:u,:m) ON DUPLICATE KEY UPDATE visibility_mode=VALUES(visibility_mode)')
        ->execute([':c' => $cid, ':u' => $uid, ':m' => $mode]);
    if ($sellers !== []) {
        $ins = $pdo->prepare('INSERT INTO biomie_user_visible_sellers (company_id,user_id,seller_id) VALUES (:c,:u,:s)');
        foreach ($sellers as $sid) {
            $ins->execute([':c' => $cid, ':u' => $uid, ':s' => $sid]);
        }
    }
};

/** Resolvedor NOVO a cada asserção — o cache é por instância. */
$resolve = static fn (PDO $pdo): SellerVisibilityScope => new SellerVisibilityScope($pdo);

// Dois vendedores reais da empresa para o modo Personalizado.
$vs = $pdo->prepare("SELECT codigo_omie, nome FROM biomie_vendedores_omie
                      WHERE company_id=:c AND ativo=1 AND codigo_omie NOT IN ('','0')
                   ORDER BY nome LIMIT 3");
$vs->execute([':c' => $companyId]);
$vendors = $vs->fetchAll(PDO::FETCH_ASSOC);
if (count($vendors) < 3) {
    fwrite(STDERR, "Empresa {$companyId} precisa de ao menos 3 vendedores ativos para o smoke.\n");
    exit(2);
}
$allowA = (string) $vendors[0]['codigo_omie'];
$allowB = (string) $vendors[1]['codigo_omie'];
$denied = (string) $vendors[2]['codigo_omie'];

echo "\nSMOKE — Visibilidade Comercial\n";
printf("  usuário=%d empresa=%d | permitidos: %s (%s), %s (%s) | negado: %s (%s)\n\n",
    $userId, $companyId, $allowA, $vendors[0]['nome'], $allowB, $vendors[1]['nome'], $denied, $vendors[2]['nome']);

$before      = $snapshot($pdo, $companyId, $userId);
$beforeOther = $otherCid > 0 ? $snapshot($pdo, $otherCid, $userId) : null;

try {
    // ── 1. MODO PERSONALIZADO (selected) ────────────────────────────────────
    echo "1. Modo Personalizado (selected)\n";
    $apply($pdo, $companyId, $userId, 'selected', [$allowA, $allowB]);
    $s = $resolve($pdo);
    $ids = $s->allowedSellerIds($companyId, $userId);
    sort($ids);
    $exp = [$allowA, $allowB]; sort($exp);
    $assert('escopo = exatamente os 2 escolhidos', $ids === $exp, json_encode($ids));
    $assert('mode() reporta "selected"', $s->mode($companyId, $userId) === 'selected');
    $assert('canSee(permitido A) = true', $s->canSee($companyId, $userId, $allowA));
    $assert('canSee(permitido B) = true', $s->canSee($companyId, $userId, $allowB));
    $assert('🔴 canSee(vendedor OCULTO) = false', !$s->canSee($companyId, $userId, $denied), "oculto={$denied}");
    $assert('🔴 filtro fora do escopo não amplia (intersect)',
        $resolve($pdo)->intersect($companyId, $userId, [$denied]) === [], 'intersect([oculto])');
    $assert('filtro dentro do escopo restringe',
        $resolve($pdo)->intersect($companyId, $userId, [$allowB]) === [$allowB]);

    // ── 2. ISOLAMENTO ENTRE EMPRESAS ────────────────────────────────────────
    if ($otherCid > 0) {
        echo "\n2. Isolamento por empresa (empresa {$otherCid} sem config)\n";
        $o = $resolve($pdo)->allowedSellerIds($otherCid, $userId);
        $assert('🔴 config da empresa ' . $companyId . ' NÃO afeta a ' . $otherCid,
            $o === null, $o === null ? 'null (todos)' : json_encode($o));
    }

    // ── 3. LIMITES ──────────────────────────────────────────────────────────
    echo "\n3. Limites (fail-closed)\n";
    $apply($pdo, $companyId, $userId, 'selected', []);   // selected com lista vazia
    $assert('🔴 selected com lista VAZIA ⇒ zero (nunca todos)',
        $resolve($pdo)->allowedSellerIds($companyId, $userId) === []);

    $apply($pdo, $companyId, $userId, 'own', []);        // own sem vínculo
    $assert('🔴 own SEM vínculo ⇒ zero (nunca todos)',
        $resolve($pdo)->allowedSellerIds($companyId, $userId) === []);

    $apply($pdo, $companyId, $userId, 'all', []);
    $assert('modo all ⇒ null (todos da empresa)',
        $resolve($pdo)->allowedSellerIds($companyId, $userId) === null);

    $apply($pdo, $companyId, $userId, null, []);        // sem configuração
    $assert('sem configuração ⇒ default do resolvedor',
        $resolve($pdo)->allowedSellerIds($companyId, $userId) === null, 'null (sem vínculo ⇒ all)');
} finally {
    // ── RESTAURA o estado original, aconteça o que acontecer ────────────────
    $apply($pdo, $companyId, $userId, $before['mode'], $before['sellers']);
    if ($beforeOther !== null) {
        $apply($pdo, $otherCid, $userId, $beforeOther['mode'], $beforeOther['sellers']);
    }
    $after = $snapshot($pdo, $companyId, $userId);
    $restored = $after['mode'] === $before['mode'] && $after['sellers'] === $before['sellers'];
    echo "\n4. Reversão\n";
    printf("  [%s] estado restaurado ao original (modo=%s, %d vendedor(es))\n",
        $restored ? "\033[32mOK\033[0m" : "\033[31mFALHA\033[0m",
        $before['mode'] ?? '(sem config)', count($before['sellers']));
    $results[] = ['name' => 'reversão ao estado original', 'ok' => $restored, 'detail' => ''];
}

$failed = array_filter($results, static fn (array $r): bool => !$r['ok']);
printf("\n%s — %d/%d verificações\n\n",
    $failed === [] ? "\033[32m✔ SMOKE VERDE\033[0m" : "\033[31m✗ SMOKE VERMELHO\033[0m",
    count($results) - count($failed), count($results));

exit($failed === [] ? 0 : 1);
