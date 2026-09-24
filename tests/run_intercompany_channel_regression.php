<?php

declare(strict_types=1);

/**
 * Regressão do incidente de canal intra-grupo (setembro/2026).
 *
 * O QUE ACONTECEU
 * NFs sincronizadas entre 30/06 e 23/07/2026 foram gravadas sem classificação
 * de canal e nunca reprocessadas. Duas consequências medidas em produção:
 *   - vendas para empresas do próprio grupo (ex.: MAG FILIAL -> UNI DESIGN,
 *     NF 1851, R$ 7.979,37; UNI DESIGN -> EXPERT, NF 26, R$ 330.180,00)
 *     escaparam da regra cliente_cnpj_list e ficaram contadas como receita;
 *   - licitações ficaram com is_biomie_commercial_visible = 1, violando o
 *     invariante "só varejo é comercial (Orç-PD)".
 *
 * O QUE ESTE TESTE GARANTE
 * O caminho de escrita (resolveInvoiceVisibility) — o mesmo usado pelo
 * backfill CanonicalWriteRepository::reclassifyUnclassifiedInvoices — classifica
 * corretamente os casos do incidente, inclusive com o CNPJ gravado COM máscara,
 * que é como documento_cliente fica na tabela canônica.
 *
 * Teste unitário puro: regras injetadas por Reflection, sem banco.
 */

require_once __DIR__ . '/../app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Canonical\CanonicalWriteRepository;
use BIomie\Support\ChannelClassifierService;

$checks = [];
$check = static function (string $id, bool $ok, mixed $actual = null) use (&$checks): void {
    $checks[] = ['id' => $id, 'ok' => $ok, 'actual' => $actual];
};

// CNPJs das cinco empresas do grupo, exatamente como nas regras 45 a 49.
const CNPJS_DO_GRUPO = '26537584000394,40914338000173,49410629000107,26537584000122,49410635000156';

$repoRef = new ReflectionClass(CanonicalWriteRepository::class);
$resolve = $repoRef->getMethod('resolveInvoiceVisibility');
$resolve->setAccessible(true);
$preserveManual = $repoRef->getMethod('existingManualInvoiceVisibility');
$preserveManual->setAccessible(true);

/**
 * Repositório com as regras reais do incidente: primeiro a exclusão intra-grupo
 * (prioridade 1, como a regra 45), depois um default configurável.
 */
$repositorio = static function (string $canalDefault) use ($repoRef): CanonicalWriteRepository {
    $clsRef = new ReflectionClass(ChannelClassifierService::class);
    /** @var ChannelClassifierService $classificador */
    $classificador = $clsRef->newInstanceWithoutConstructor();
    $clsRef->getProperty('rules')->setValue($classificador, [
        [
            'id' => 45,
            'rule_type' => 'cliente_cnpj_list',
            'match_value' => CNPJS_DO_GRUPO,
            'target_channel' => 'outros',
            'confidence' => 'alta',
        ],
        [
            'id' => 900,
            'rule_type' => 'default',
            'match_value' => '',
            'target_channel' => $canalDefault,
            'confidence' => 'baixa',
        ],
    ]);
    $clsRef->getProperty('rulesLoaded')->setValue($classificador, true);

    /** @var CanonicalWriteRepository $repo */
    $repo = $repoRef->newInstanceWithoutConstructor();
    $repoRef->getProperty('vendorScopeRules')->setValue($repo, []);
    $repoRef->getProperty('channelClassifier')->setValue($repo, $classificador);
    return $repo;
};

/** NF como chega do Omie: sem canal explícito no payload. */
$nf = static fn (string $documento): array => [
    'business_channel' => null,
    'codigo_vendedor_omie' => '10120611147',
    'nome_vendedor' => 'VENDEDOR TESTE',
    'observacoes' => '',
    'documento_cliente' => $documento,
    'projeto_id_omie' => '',
];

// ── 1. O caso exato da NF 1851: CNPJ do grupo gravado COM máscara ────────────
$r = $resolve->invoke($repositorio('varejo'), $nf('49.410.629/0001-07'));
$check(
    'intra_grupo_cnpj_com_mascara_vira_outros_invisivel',
    $r['channel'] === 'outros' && (int) $r['visible'] === 0 && (int) $r['rule_id'] === 45,
    $r,
);

// ── 2. Mesmo CNPJ sem máscara ────────────────────────────────────────────────
$r = $resolve->invoke($repositorio('varejo'), $nf('49410629000107'));
$check('intra_grupo_cnpj_sem_mascara_vira_outros_invisivel',
    $r['channel'] === 'outros' && (int) $r['visible'] === 0, $r);

// ── 3. O caso da NF 26: venda intra-grupo que o default mandaria para
//       licitação continua sendo intra-grupo ──────────────────────────────────
$r = $resolve->invoke($repositorio('licitacao'), $nf('40.914.338/0001-73'));
$check('intra_grupo_vence_default_de_licitacao',
    $r['channel'] === 'outros' && (int) $r['visible'] === 0 && (int) $r['rule_id'] === 45, $r);

// ── 4. Cliente fora do grupo segue o default ─────────────────────────────────
$r = $resolve->invoke($repositorio('varejo'), $nf('12.345.678/0001-90'));
$check('cliente_fora_do_grupo_segue_default_varejo',
    $r['channel'] === 'varejo' && (int) $r['visible'] === 1, $r);

// ── 5. Licitação nunca é visível no comercial (Orç-PD) ───────────────────────
$r = $resolve->invoke($repositorio('licitacao'), $nf('12.345.678/0001-90'));
$check('licitacao_nunca_visivel_no_comercial',
    $r['channel'] === 'licitacao' && (int) $r['visible'] === 0, $r);

// ── 6. Invariante: visível se e somente se o canal for varejo ────────────────
foreach (['varejo', 'licitacao', 'outros'] as $canal) {
    $r = $resolve->invoke($repositorio($canal), $nf('12.345.678/0001-90'));
    $check("invariante_visivel_sse_varejo_{$canal}",
        ((int) $r['visible'] === 1) === ($r['channel'] === 'varejo'), $r);
}

// ── 7. Override manual continua preservado pelo backfill ─────────────────────
$manual = $preserveManual->invoke($repositorio('varejo'), [
    'channel_confidence' => 'manual',
    'business_channel' => 'varejo',
    'business_unit_scope' => 'comercial',
    'is_biomie_commercial_visible' => 1,
    'channel_rule_id' => null,
    'visibility_rule_source' => 'operador',
]);
$check('override_manual_preservado',
    is_array($manual) && $manual['channel'] === 'varejo' && $manual['confidence'] === 'manual', $manual);

// ── 8. O backfill existe e é público (contrato do comando) ───────────────────
$check('backfill_publico_disponivel',
    $repoRef->hasMethod('reclassifyUnclassifiedInvoices')
        && $repoRef->getMethod('reclassifyUnclassifiedInvoices')->isPublic());

$failed = array_values(array_filter($checks, static fn (array $row): bool => !$row['ok']));
$result = [
    'ok' => $failed === [],
    'passed' => count($checks) - count($failed),
    'total' => count($checks),
    'checks' => $checks,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($result['ok'] ? 0 : 1);
