<?php

declare(strict_types=1);

use BIomie\Database;
use BIomie\Modules\Fiscal\Infrastructure\FiscalRegimeProjectionService;
use BIomie\Support\TenantCliContext;

require_once dirname(__DIR__) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();
require_once dirname(__DIR__) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

$pdo = Database::connect(new \BIomie\Config(), 'db');
$tenant = TenantCliContext::boot($pdo, $argv ?? []);
$competencia = date('Y-m');
$start = $competencia . '-01';
$end = date('Y-m-t', strtotime($start));
$result = (new FiscalRegimeProjectionService($pdo))->forCompany(
    (int) $tenant['company_id'], (string) $tenant['slug'], $start, $end
);
$docs = (int) ($result['documentos_entrada'] ?? 0);
$linked = (float) ($result['vinculo_pedido_pct'] ?? 0);
$confidence = $docs > 0 && $linked >= 90 ? 'alta' : ($docs > 0 && $linked >= 70 ? 'media' : 'baixa');
$stmt = $pdo->prepare(
    'INSERT INTO intelligence_fiscal_projections
      (company_id,competencia,regime,assessed_tax,projected_tax,invisible_tax,input_documents,
       linked_invoices_pct,confidence,projection_json,model_version,projected_at)
     VALUES (:company,:competencia,:regime,:assessed,:projected,:invisible,:docs,:linked,
             :confidence,:payload,:version,UTC_TIMESTAMP())'
);
$stmt->execute([
    'company' => (int) $tenant['company_id'], 'competencia' => $competencia,
    'regime' => (string) $result['regime'], 'assessed' => (float) $result['apuracao_atual'],
    'projected' => (float) $result['projecao']['tributo_total'], 'invisible' => (float) $result['diferenca'],
    'docs' => $docs, 'linked' => $linked, 'confidence' => $confidence,
    'payload' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    'version' => 'regime-tax-projector-v1',
]);
echo json_encode(['ok' => true, 'company' => $tenant['slug'], 'competencia' => $competencia,
    'projected_tax' => $result['projecao']['tributo_total'], 'confidence' => $confidence], JSON_UNESCAPED_UNICODE) . "\n";
