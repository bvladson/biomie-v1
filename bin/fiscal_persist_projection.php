<?php

declare(strict_types=1);

/**
 * Persiste a projecao tributaria por regime, competencia a competencia.
 *
 * ─── POR QUE NAO SO O MES CORRENTE ──────────────────────────────────────────
 * A primeira versao fazia `$competencia = date('Y-m')` e projetava apenas o mes
 * em curso. O efeito anulava o proposito do trabalho: a EXPERT e Simples
 * Nacional, faturou R$ 1,65 milhao entre janeiro e julho, e em agosto ainda nao
 * tinha emitido nota. A projecao gravava R$ 0 — os R$ 154 mil de DAS
 * continuavam invisiveis, e ainda apareciam como "zero", que e a confusao entre
 * AUSENCIA e ZERO que o resto desta base combate.
 *
 * A auditoria de coerencia ja varre 15 meses. A projecao passa a varrer a mesma
 * janela: as duas olham o mesmo periodo ou uma delas mente por omissao.
 *
 * ─── COMPETENCIA SEM RECEITA E PULADA, NAO ZERADA ───────────────────────────
 * Gravar R$ 0 para um mes sem faturamento afirmaria "nao houve imposto"; nao
 * gravar diz "nao havia o que apurar". Sao coisas diferentes e a tela precisa
 * poder distinguir.
 *
 *   php bin/fiscal_persist_projection.php --company=MAG_FILIAL
 *   php bin/fiscal_persist_projection.php --company=all --meses=15
 *   php bin/fiscal_persist_projection.php --company=all --competencia=2026-02
 */

use BIomie\Database;
use BIomie\Modules\Fiscal\Infrastructure\FiscalRegimeProjectionService;
use BIomie\Support\TenantCliContext;

require_once dirname(__DIR__) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

/** Mesma janela da auditoria de coerencia — as duas precisam enxergar igual. */
const JANELA_MESES_PADRAO = 15;

$argv = $_SERVER['argv'] ?? [];
$meses = JANELA_MESES_PADRAO;
$competenciaUnica = null;
foreach ($argv as $arg) {
    if (preg_match('/^--meses=(\d+)$/', (string) $arg, $m) === 1) {
        $meses = max(1, min((int) $m[1], 60));
    }
    if (preg_match('/^--competencia=(\d{4}-\d{2})$/', (string) $arg, $m) === 1) {
        $competenciaUnica = $m[1];
    }
}

$pdo = Database::connect(new \BIomie\Config(), 'db');
$tenant = TenantCliContext::boot($pdo, $argv);
$companyId = (int) $tenant['company_id'];

/**
 * Competencias que TIVERAM faturamento — a fonte canonica, nao o mart.
 *
 * De proposito: se o mart estiver defasado, a projecao ainda cobre o periodo
 * real e a divergencia aparece na auditoria de coerencia, em vez de os dois
 * concordarem em estar errados.
 */
if ($competenciaUnica !== null) {
    $competencias = [$competenciaUnica];
} else {
    $stmt = $pdo->prepare(
        "SELECT DISTINCT DATE_FORMAT(COALESCE(data_competencia, data_emissao), '%Y-%m') AS competencia
           FROM biomie_notas_fiscais_canonicas
          WHERE company_id = :company
            AND status_fiscal = 'autorizada'
            AND COALESCE(data_competencia, data_emissao) >= DATE_SUB(UTC_DATE(), INTERVAL {$meses} MONTH)
          ORDER BY competencia"
    );
    $stmt->execute(['company' => $companyId]);
    $competencias = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'competencia');
}

$service = new FiscalRegimeProjectionService($pdo);
$stmt = $pdo->prepare(
    'INSERT INTO intelligence_fiscal_projections
      (company_id,competencia,regime,assessed_tax,projected_tax,invisible_tax,input_documents,
       linked_invoices_pct,confidence,projection_json,model_version,projected_at)
     VALUES (:company,:competencia,:regime,:assessed,:projected,:invisible,:docs,:linked,
             :confidence,:payload,:version,UTC_TIMESTAMP())'
);

$gravadas = [];
$puladas = [];
$falhas = [];

foreach ($competencias as $competencia) {
    $start = $competencia . '-01';
    $end = date('Y-m-t', strtotime($start) ?: time());

    try {
        $result = $service->forCompany($companyId, (string) $tenant['slug'], $start, $end);
    } catch (Throwable $e) {
        // Uma competencia que falha nao pode levar as outras catorze junto.
        $falhas[] = $competencia . ': ' . $e->getMessage();
        continue;
    }

    $receita = (float) ($result['faturamento'] ?? $result['receita_base'] ?? 0);
    $projetado = (float) ($result['projecao']['tributo_total'] ?? 0);
    $apurado = (float) ($result['apuracao_atual'] ?? 0);

    if ($receita <= 0 && $projetado <= 0 && $apurado <= 0) {
        $puladas[] = $competencia;
        continue;
    }

    $docs = (int) ($result['documentos_entrada'] ?? 0);
    $linked = (float) ($result['vinculo_pedido_pct'] ?? 0);
    $confidence = $docs > 0 && $linked >= 90 ? 'alta' : ($docs > 0 && $linked >= 70 ? 'media' : 'baixa');

    $stmt->execute([
        'company' => $companyId, 'competencia' => $competencia,
        'regime' => (string) $result['regime'], 'assessed' => $apurado,
        'projected' => $projetado, 'invisible' => (float) ($result['diferenca'] ?? 0),
        'docs' => $docs, 'linked' => $linked, 'confidence' => $confidence,
        'payload' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'version' => 'regime-tax-projector-v1',
    ]);

    $gravadas[] = [
        'competencia' => $competencia,
        'apurado' => round($apurado, 2),
        'projetado' => round($projetado, 2),
        'invisivel' => round((float) ($result['diferenca'] ?? 0), 2),
        'confianca' => $confidence,
    ];
}

echo json_encode([
    'ok' => $falhas === [],
    'company' => $tenant['slug'],
    'competencias_gravadas' => count($gravadas),
    'competencias_sem_movimento' => $puladas,
    'invisivel_total' => round(array_sum(array_column($gravadas, 'invisivel')), 2),
    'detalhe' => $gravadas,
    'falhas' => $falhas,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";

exit($falhas === [] ? 0 : 1);
