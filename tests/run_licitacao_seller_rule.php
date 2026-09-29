<?php

declare(strict_types=1);

/**
 * REGRA DE CANAL POR VENDEDOR (29/09/2026) — a pergunta é "qual vendedor está
 * registrado nessa operação no Omie?", nunca "quem é o cliente?".
 *
 *   vendedor "Licitação" (código do cadastro Omie ou nome normalizado) => licitação
 *   qualquer outro vendedor                                           => varejo
 *   override manual vence tudo; cliente do grupo => intra-grupo (não é venda)
 *
 * Os casos 1 e 2 existem para que ninguém reintroduza "instituto/órgão público =
 * licitação": cliente público vendido pelo ROBERTO é VAREJO.
 *
 * Parte A: caminho real da escrita (resolveInvoiceVisibility) com as regras que
 *          a migração 20260929130000 cria. Sem banco.
 * Parte B: a própria migração, up() e down(), no banco de TESTE (guardado).
 *
 *   php tests/run_licitacao_seller_rule.php
 */

require_once __DIR__ . '/TestSupport.php';

use BIomie\Canonical\CanonicalWriteRepository;
use BIomie\Support\ChannelClassifierService;
use BIomie\Support\LicitacaoSeller;

$checks = [];
$check = static function (string $name, bool $ok, mixed $detail = null) use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};

const GRUPO = '26537584000394,40914338000173,49410629000107,26537584000122,49410635000156';
const COD_LICITACAO_ERGO = '7467611512';

// ── Parte A ─────────────────────────────────────────────────────────────────
$repoRef = new ReflectionClass(CanonicalWriteRepository::class);
$resolve = $repoRef->getMethod('resolveInvoiceVisibility');
$manual = $repoRef->getMethod('existingManualInvoiceVisibility');

$repo = (static function () use ($repoRef): CanonicalWriteRepository {
    $clsRef = new ReflectionClass(ChannelClassifierService::class);
    $classificador = $clsRef->newInstanceWithoutConstructor();
    // Exatamente o conjunto ativo que a migração deixa (Ergo).
    $clsRef->getProperty('rules')->setValue($classificador, [
        ['id' => 49, 'rule_type' => 'cliente_cnpj_list', 'match_value' => GRUPO, 'target_channel' => 'outros', 'confidence' => 'alta'],
        ['id' => 101, 'rule_type' => 'vendedor_id_list', 'match_value' => COD_LICITACAO_ERGO, 'target_channel' => 'licitacao', 'confidence' => 'alta'],
        ['id' => 102, 'rule_type' => 'vendedor_nome_igual', 'match_value' => 'Licitação', 'target_channel' => 'licitacao', 'confidence' => 'alta'],
        ['id' => 44, 'rule_type' => 'default', 'match_value' => '', 'target_channel' => 'varejo', 'confidence' => 'alta'],
    ]);
    $clsRef->getProperty('rulesLoaded')->setValue($classificador, true);
    $repo = $repoRef->newInstanceWithoutConstructor();
    $repoRef->getProperty('vendorScopeRules')->setValue($repo, []);
    $repoRef->getProperty('channelClassifier')->setValue($repo, $classificador);
    return $repo;
})();

$nf = static fn (string $vendedor, string $cliente, string $doc = '12.345.678/0001-90', string $codigo = '', string $obs = ''): array => [
    'business_channel' => null,
    'codigo_vendedor_omie' => $codigo,
    'nome_vendedor' => $vendedor,
    'nome_cliente' => $cliente,
    'observacoes' => $obs,
    'documento_cliente' => $doc,
    'projeto_id_omie' => '',
];
$canal = static fn (array $r): string => $r['channel'] . '/' . $r['source'];

// Os quatro casos obrigatórios.
$r = $resolve->invoke($repo, $nf('ROBERTO SIQ.', 'INSTITUTO DE PREVIDENCIA DO ESTADO'));
$check('🔴 cliente público + vendedor ROBERTO => varejo', $r['channel'] === 'varejo' && $r['source'] === LicitacaoSeller::SOURCE_REGULAR, $canal($r));
$r = $resolve->invoke($repo, $nf('Licitação', 'INSTITUTO DE PESOS E MEDIDAS'));
$check('🔴 cliente público + vendedor LICITAÇÃO => licitação', $r['channel'] === 'licitacao' && $r['source'] === LicitacaoSeller::SOURCE_LICITACAO, $canal($r));
$r = $resolve->invoke($repo, $nf('Licitação', 'MOVEIS SAARA LTDA'));
$check('cliente privado + vendedor LICITAÇÃO => licitação', $r['channel'] === 'licitacao', $canal($r));
$r = $resolve->invoke($repo, $nf('MARLENE', 'LETICIA MACEDO FALCAO'));
$check('cliente privado + vendedor MARLENE => varejo', $r['channel'] === 'varejo', $canal($r));

// Casos reais da prévia de 29/09.
$r = $resolve->invoke($repo, $nf('Sem vendedor', 'INSTITUTO MIRANTE DE CULTURA'));
$check('Expert NF 1: "Sem vendedor" + instituto => varejo (sem override)', $r['channel'] === 'varejo', $canal($r));
$r = $resolve->invoke($repo, $nf('NUBIA OLIVEIRA', 'INSTITUTO DE PESOS E MEDIDAS'));
$check('Ergo NF 270: NUBIA + IPEM => varejo', $r['channel'] === 'varejo', $canal($r));

// Normalização do nome: igualdade, não substring.
foreach (['LICITAÇÃO', 'Licitação', 'licitacao', 'LICITACAO', ' Licitação ', "Licitação\t", 'LICITAÇÃO  '] as $variante) {
    $r = $resolve->invoke($repo, $nf($variante, 'CLIENTE'));
    $check('nome ' . json_encode($variante, JSON_UNESCAPED_UNICODE) . ' => licitação', $r['channel'] === 'licitacao', $canal($r));
}
foreach (['Licitante', 'Licitações', 'João Licitação', 'LICITAÇÃO 2', 'Pregão'] as $parecido) {
    $r = $resolve->invoke($repo, $nf($parecido, 'CLIENTE'));
    $check('nome parecido ' . json_encode($parecido, JSON_UNESCAPED_UNICODE) . ' => varejo', $r['channel'] === 'varejo', $canal($r));
}

// Código do vendedor vence renomeação no Omie.
$r = $resolve->invoke($repo, $nf('Vendas Governo', 'PREFEITURA', '12.345.678/0001-90', COD_LICITACAO_ERGO));
$check('🔴 código do vendedor "Licitação" com nome trocado => licitação',
    $r['channel'] === 'licitacao' && (int) $r['rule_id'] === 101, $canal($r) . ' #' . $r['rule_id']);

// Observação não classifica mais (regras pedido_obs_like desativadas).
$r = $resolve->invoke($repo, $nf('ROBERTO SIQ.', 'PREFEITURA', '12.345.678/0001-90', '', 'Pregão eletrônico 12/2026, empenho 55'));
$check('observação com "pregão/empenho" + ROBERTO => varejo', $r['channel'] === 'varejo', $canal($r));

// Intra-grupo vem antes: venda para empresa do grupo não é receita.
$r = $resolve->invoke($repo, $nf('Licitação', 'EXPERT MOVEIS', '26.537.584/0003-94'));
$check('cliente do grupo + vendedor Licitação => intra-grupo (fora dos números)',
    $r['channel'] === 'outros' && (int) $r['visible'] === 0 && $r['source'] === LicitacaoSeller::SOURCE_INTRA_GRUPO, $canal($r));

// Override manual vence tudo.
$m = $manual->invoke($repo, [
    'channel_confidence' => 'manual', 'business_channel' => 'licitacao', 'business_unit_scope' => 'licitacao',
    'is_biomie_commercial_visible' => 0, 'channel_rule_id' => null,
    'visibility_rule_source' => LicitacaoSeller::SOURCE_MANUAL . ': decidido por vlad',
]);
$check('override manual preservado com a origem manual_override',
    is_array($m) && $m['channel'] === 'licitacao' && $m['confidence'] === 'manual'
    && str_starts_with((string) $m['source'], LicitacaoSeller::SOURCE_MANUAL), $m);

// Os outros leitores de "é licitação?" seguem a mesma regra.
$check('BiVisibilityScope::isLicitationName usa igualdade',
    \BIomie\Support\BiVisibilityScope::isLicitationName(' licitação ')
    && !\BIomie\Support\BiVisibilityScope::isLicitationName('Licitante'));
$alias = new \BIomie\Support\VendorAliasResolver();
$check('VendorAliasResolver (Plano de Voo) usa igualdade',
    $alias->detectChannel($alias->normalize('Licitação')) === \BIomie\Support\VendorAliasResolver::CHANNEL_LICITACAO
    && $alias->detectChannel($alias->normalize('Licitante')) !== \BIomie\Support\VendorAliasResolver::CHANNEL_LICITACAO);

// ── Parte B: a migração no banco de teste ──────────────────────────────────
$pdo = test_pdo();
$migration = require dirname(__DIR__) . '/database/migrations/Version20260929130000_LicitacaoPorVendedor.php';
$pdo->exec('DROP TABLE IF EXISTS biomie_channel_rules_20260929');
$pdo->exec("DELETE FROM biomie_channel_classification_rules WHERE company_id IN (91, 92)");
$pdo->exec("DELETE FROM biomie_companies WHERE id IN (91, 92)");
$pdo->exec("DELETE FROM biomie_vendedores_omie WHERE company_id IN (91, 92)");
$pdo->exec("SET SESSION sql_mode = ''");
$pdo->exec("INSERT INTO biomie_companies (id, slug, nome_fantasia, canal_padrao, ativo) VALUES
    (91, 'TESTE_ERGO', 'Teste Ergo', 'licitacao', 1), (92, 'TESTE_MATRIZ', 'Teste Matriz', 'licitacao', 1)");
$pdo->exec("INSERT INTO biomie_vendedores_omie (company_id, codigo_omie, nome, ativo) VALUES
    (91, '" . COD_LICITACAO_ERGO . "', 'Licitação', 1), (91, '111', 'NUBIA OLIVEIRA', 1), (91, '222', 'Licitante Silva', 1),
    (92, '10432605713', 'LICITAÇÃO', 1)");
// Regras como estão em produção (Ergo = template de 28/06; Matriz = padrão licitação).
$pdo->exec("INSERT INTO biomie_channel_classification_rules (company_id, priority, rule_type, match_value, target_channel, confidence, ativo) VALUES
    (91, 1, 'cliente_cnpj_list', '" . GRUPO . "', 'outros', 'alta', 1),
    (91, 11, 'vendedor_nome_like', '%LICITAÇ%', 'licitacao', 'alta', 1),
    (91, 22, 'pedido_obs_like', '%edital%', 'licitacao', 'media', 1),
    (91, 999, 'default', '', 'varejo', 'baixa', 1),
    (92, 1, 'cliente_cnpj_list', '" . GRUPO . "', 'outros', 'alta', 1),
    (92, 999, 'default', '', 'licitacao', 'alta', 1)");
$antes = $pdo->query("SELECT id, company_id, priority, rule_type, match_value, target_channel, confidence, ativo
    FROM biomie_channel_classification_rules WHERE company_id IN (91,92) ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

$migration->up($pdo);
$ativas = static function (PDO $pdo, int $cid): array {
    return $pdo->query("SELECT priority, rule_type, match_value, target_channel, confidence
        FROM biomie_channel_classification_rules WHERE company_id = {$cid} AND ativo = 1 ORDER BY priority")
        ->fetchAll(PDO::FETCH_ASSOC);
};
$ergo = $ativas($pdo, 91);
$check('migração: Ergo fica com intra-grupo, código, nome e padrão varejo',
    array_column($ergo, 'rule_type') === ['cliente_cnpj_list', 'vendedor_id_list', 'vendedor_nome_igual', 'default'],
    array_column($ergo, 'rule_type'));
$check('migração: código do vendedor "Licitação" achado no cadastro (e só ele)',
    ($ergo[1]['match_value'] ?? '') === COD_LICITACAO_ERGO, $ergo[1]['match_value'] ?? null);
$check('migração: padrão = varejo com confiança alta',
    ($ergo[3]['target_channel'] ?? '') === 'varejo' && ($ergo[3]['confidence'] ?? '') === 'alta');
$matriz = $ativas($pdo, 92);
$check('🔴 migração: Matriz deixa de ter padrão licitação (regra vale para todas)',
    end($matriz)['target_channel'] === 'varejo' && ($matriz[1]['match_value'] ?? '') === '10432605713',
    $matriz);

// O classificador REAL, lendo as regras gravadas.
$cls = new ChannelClassifierService($pdo, 91);
$c1 = $cls->classify('NUBIA OLIVEIRA', 'Edital 3/2026', '12345678000190', '', '111');
$c2 = $cls->classify('Qualquer Nome', '', '12345678000190', '', COD_LICITACAO_ERGO);
$c3 = $cls->classify(' licitação ', '', '12345678000190', '', '');
$check('classificador real: NUBIA com "edital" na observação => varejo', $c1['channel'] === 'varejo', $c1);
$check('classificador real: código "Licitação" => licitação', $c2['channel'] === 'licitacao' && $c2['source'] === 'seller_licitacao', $c2);
$check('classificador real: nome " licitação " => licitação', $c3['channel'] === 'licitacao', $c3);

$migration->down($pdo);
$depois = $pdo->query("SELECT id, company_id, priority, rule_type, match_value, target_channel, confidence, ativo
    FROM biomie_channel_classification_rules WHERE company_id IN (91,92) ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$check('🔴 down(): regras voltam EXATAMENTE ao estado anterior', $depois === $antes,
    ['antes' => count($antes), 'depois' => count($depois)]);
$snap = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'biomie_channel_rules_20260929'")->fetchColumn();
$check('down(): retrato removido', $snap === 0);

$pdo->exec("DELETE FROM biomie_channel_classification_rules WHERE company_id IN (91, 92)");
$pdo->exec("DELETE FROM biomie_vendedores_omie WHERE company_id IN (91, 92)");
$pdo->exec("DELETE FROM biomie_companies WHERE id IN (91, 92)");

$passed = count(array_filter($checks, static fn (array $r): bool => $r['ok']));
$result = ['ok' => $passed === count($checks), 'total' => count($checks),
           'passed' => $passed, 'failed' => count($checks) - $passed, 'checks' => $checks];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
exit($result['ok'] ? 0 : 1);
