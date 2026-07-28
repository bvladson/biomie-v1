<?php

declare(strict_types=1);

/**
 * GUARD — sombreamento de propriedade de formulário por controle nomeado.
 *
 * ─── O BUG QUE ISTO IMPEDE DE VOLTAR ────────────────────────────────────────
 * Em 2026-07-24, criar usuário no Admin navegava para
 *   /biomie/[object HTMLInputElement]?ctx=…   → 404, usuário NÃO criado.
 *
 * Causa: no HTML, um controle NOMEADO sobrescreve a propriedade homônima do
 * formulário. Os forms do Admin usam <input name="action" value="create_user">,
 * então `form.action` devolvia o ELEMENTO em vez da URL. Ao ser interpolado numa
 * URL, virava a string "[object HTMLInputElement]" — que resolve como caminho
 * relativo, passa no teste de mesma origem e vira o destino do submit.
 *
 * A defesa correta é ler o ATRIBUTO (getAttribute('action')), imune ao
 * sombreamento. Este guard é ESTÁTICO porque o projeto não tem runner de JS:
 * varre o fonte e falha se alguém voltar a LER `form.action` como valor.
 *
 * Cobre também o minificado — foi ele que continuou servindo o bug depois de o
 * fonte já estar corrigido (o minify precisa rodar no deploy).
 *
 *   php tests/run_form_action_shadowing_guard.php
 */

require_once __DIR__ . '/TestSupport.php';

$checks = [];
$check = static function (string $name, bool $ok, mixed $detail = null) use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};

$root = dirname(__DIR__);

/** Forms que usam <input name="action"> — os expostos ao sombreamento. */
$shadowedForms = [];
foreach (glob($root . '/views/admin/tabs/*.php') ?: [] as $file) {
    $src = (string) file_get_contents($file);
    if (str_contains($src, 'name="action"')) {
        $shadowedForms[] = basename($file);
    }
}
$check('há forms com <input name="action"> (o risco é real, não teórico)',
    $shadowedForms !== [], implode(', ', $shadowedForms));

// ── O fonte não pode LER form.action como valor ─────────────────────────────
$js = (string) file_get_contents($root . '/assets/js/tenant-tab-context.js');

// Ocorrências fora de comentário: remove // … e /* … */ antes de procurar.
$code = preg_replace('~/\*.*?\*/~s', '', $js) ?? $js;
$code = preg_replace('~^\s*//.*$~m', '', $code) ?? $code;

$check('🔴 fonte NÃO lê `form.action` como valor',
    preg_match('~\bform\.action\s*(\|\||\)|;|,)~', $code) !== 1);
$check('🔴 fonte NÃO atribui a `form.action`',
    preg_match('~\bform\.action\s*=~', $code) !== 1);
$check('fonte usa getAttribute(\'action\')',
    str_contains($code, "getAttribute('action')") || str_contains($code, 'getAttribute("action")'));
$check('fonte usa setAttribute(\'action\', …)',
    str_contains($code, "setAttribute('action'") || str_contains($code, 'setAttribute("action"'));

// ── O MINIFICADO precisa refletir o fonte (é ele que é servido) ─────────────
$minPath = $root . '/assets/js/tenant-tab-context.min.js';
$check('minificado existe', is_file($minPath));
if (is_file($minPath)) {
    $min = (string) file_get_contents($minPath);
    $check('🔴 minificado NÃO contém o padrão bugado `.action||…location`',
        preg_match('~\.action\s*\|\|\s*\w+\.location~', $min) !== 1);
    $check('minificado contém getAttribute("action") — minify rodou após o fix',
        str_contains($min, 'getAttribute("action")') || str_contains($min, "getAttribute('action')"));
    $check('minificado mais novo (ou igual) que o fonte',
        filemtime($minPath) >= filemtime($root . '/assets/js/tenant-tab-context.js'),
        'min=' . date('c', filemtime($minPath)));
}

// ── Nenhum outro JS pode reintroduzir o padrão ──────────────────────────────
$offenders = [];
foreach (glob($root . '/assets/js/*.js') ?: [] as $file) {
    if (str_contains($file, '.min.')) {
        continue;
    }
    $s = preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents($file)) ?? '';
    $s = preg_replace('~^\s*//.*$~m', '', $s) ?? $s;
    if (preg_match('~\bform\.action\b~', $s) === 1) {
        $offenders[] = basename($file);
    }
}
$check('🔴 nenhum outro JS lê form.action', $offenders === [], implode(', ', $offenders));

$passed = count(array_filter($checks, static fn (array $r): bool => $r['ok']));
$result = [
    'ok' => $passed === count($checks),
    'total' => count($checks),
    'passed' => $passed,
    'failed' => count($checks) - $passed,
    'checks' => $checks,
];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
exit($result['ok'] ? 0 : 1);
