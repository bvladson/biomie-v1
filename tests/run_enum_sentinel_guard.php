<?php

declare(strict_types=1);

/**
 * GUARD — sentinela de ENUM inválido ('') escapando para um mart.
 *
 * ─── O BUG QUE ISTO IMPEDE DE VOLTAR ────────────────────────────────────────
 * Em 2026-08-04 a materialização dos gold marts falhava 40 vezes seguidas:
 *
 *   SQLSTATE[01000] 1265: Data truncated for column 'tipo_pessoa' at row 30
 *   → MartTransactionRunner: rollback
 *
 * `biomie_clientes_dim` tinha 109 linhas (só na empresa 1; 118 grupos no total)
 * com `tipo_pessoa = ''`. Vazio numa coluna ENUM é o **sentinela de valor
 * inválido do MySQL**: índice 0, gravado quando algum dia entrou um valor fora
 * da enumeração. Ele NÃO é NULL — então `COALESCE(MAX(x), 'desconhecido')`
 * deixava passar, e o destino recusava sob STRICT_TRANS_TABLES, derrubando a
 * transação inteira do mart.
 *
 * A defesa é `NULLIF(x, '')` ANTES do COALESCE. Mapear para 'desconhecido' é
 * normalização e não mascaramento: 'desconhecido' é membro legítimo da
 * enumeração e significa exatamente isso. Truncar com SUBSTRING ou converter
 * para vazio faria o mart voltar a rodar escondendo classificação errada.
 *
 * Este guard é ESTÁTICO: varre os builders atrás de COALESCE sobre colunas ENUM
 * sem o NULLIF. Não substitui a materialização real — mas pega a regressão no
 * commit, que é quando custa barato.
 *
 *   php tests/run_enum_sentinel_guard.php
 */

require_once __DIR__ . '/TestSupport.php';

$checks = [];
$check = static function (string $name, bool $ok, mixed $detail = null) use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};

$root = dirname(__DIR__);

/** Colunas que são ENUM no banco e, por isso, carregam o sentinela ''. */
$colunasEnum = ['tipo_pessoa'];

$builders = glob($root . '/app/DataPlatform/Gold/Builders/*.php') ?: [];
$check('há builders para varrer', $builders !== [], count($builders) . ' arquivos');

$ofensores = [];
$protegidos = [];

foreach ($builders as $file) {
    $src = (string) file_get_contents($file);
    foreach ($colunasEnum as $coluna) {
        // Todo COALESCE que agrega essa coluna precisa passar pelo NULLIF.
        $padrao = '~COALESCE\(\s*(?:NULLIF\(\s*)?(?:MAX|MIN)\(\s*[\w.]*' . preg_quote($coluna, '~') . '~i';
        if (preg_match_all($padrao, $src, $m) === 0) {
            continue;
        }
        foreach ($m[0] as $trecho) {
            $rotulo = basename($file) . ':' . $coluna;
            if (stripos($trecho, 'NULLIF') === false) {
                $ofensores[] = $rotulo . ' → ' . trim($trecho);
            } else {
                $protegidos[] = $rotulo;
            }
        }
    }
}

$check(
    'o guard encontrou o padrão que deve proteger',
    $protegidos !== [] || $ofensores !== [],
    'protegidos=' . count($protegidos) . ' ofensores=' . count($ofensores),
);

$check(
    '🔴 nenhum COALESCE sobre coluna ENUM sem NULLIF(.., \'\')',
    $ofensores === [],
    implode(' | ', $ofensores),
);

// O CustomerPeriodMartBuilder é o que quebrou — ele tem que estar coberto.
$customer = $root . '/app/DataPlatform/Gold/Builders/CustomerPeriodMartBuilder.php';
if (is_file($customer)) {
    $src = (string) file_get_contents($customer);
    $comNullif = preg_match_all("~COALESCE\(NULLIF\(MAX\(d\.tipo_pessoa\), ''\)~", $src);
    $check(
        'CustomerPeriodMartBuilder protege as 3 ocorrências de tipo_pessoa',
        $comNullif === 3,
        "encontradas: {$comNullif} (esperado 3)",
    );
    $check(
        'nenhum SUBSTRING mascarando tipo_pessoa',
        stripos($src, 'SUBSTRING(tipo_pessoa') === false
            && stripos($src, 'LEFT(tipo_pessoa') === false,
    );
}

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
