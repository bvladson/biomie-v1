<?php

declare(strict_types=1);

use BIomie\Auth;
use BIomie\Database;
use BIomie\Http;
use BIomie\Modules\Fiscal\Infrastructure\FiscalIntelligenceService;

require __DIR__ . '/bootstrap.php';

Auth::requireAnyCapability(['fiscal.view', 'finance.view'], true);

try {
    $notaKey = trim(Http::query('nota_key', ''));
    if ($notaKey === '') {
        Http::json(['ok' => false, 'message' => 'nota_key obrigatório.'], 400);
    }

    $config = new \BIomie\Config();
    $conn   = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
    $pdo    = Database::connect($config, $conn);
    $svc    = new FiscalIntelligenceService(static fn () => $pdo);
    if (\BIomie\Support\TenantContext::isConsolidado()) {
        Http::json([
            'ok' => false,
            'message' => 'Selecione uma empresa para abrir o detalhe fiscal de uma NF.',
        ], 422);
    }

    $fmt  = static fn (float $v): string => 'R$ ' . number_format($v, 2, ',', '.');
    $pct  = static fn (float $v): string => number_format($v, 2, ',', '.') . '%';

    // NF header from canonical
    $cid = \BIomie\Support\TenantContext::allowedCompanyIds()[0];
    $stmt = $pdo->prepare("
        SELECT numero_nf, serie_nf, chave_nfe, data_emissao, nome_cliente,
               documento_cliente, nome_vendedor, valor_total, valor_icms,
               valor_pis, valor_cofins, valor_ipi, valor_iss, valor_trib_total,
               status_fiscal
        FROM biomie_notas_fiscais_canonicas WHERE nota_key = :nk AND company_id = {$cid} LIMIT 1
    ");
    $stmt->execute([':nk' => $notaKey]);
    $header = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$header) {
        Http::json(['ok' => false, 'message' => 'NF não encontrada.'], 404);
    }

    $items = $svc->nfItems($notaKey);
    $hasXml = trim((string) ($header['chave_nfe'] ?? '')) !== '';
    $hasMissingNcm = false;
    $hasIcmsZero = false;
    foreach ($items as $item) {
        if (trim((string) ($item['ncm'] ?? '')) === '') {
            $hasMissingNcm = true;
        }
        if ((float) ($item['valor_icms'] ?? 0) === 0.0 && (float) ($item['valor_total_item'] ?? 0) > 500) {
            $hasIcmsZero = true;
        }
    }

    $itemsFmt = array_map(static function (array $it) use ($fmt, $pct): array {
        return [
            'seq'             => (int) $it['sequencia_item'] + 1,
            'codigo'          => $it['codigo_produto']  ?? '-',
            'descricao'       => $it['descricao_produto'] ?? '-',
            'ncm'             => $it['ncm']  ?? '-',
            'cfop'            => $it['cfop'] ?? '-',
            'origem'          => $it['origem_produto'] ?? '-',
            'quantidade'      => number_format((float) $it['quantidade'], 2, ',', '.'),
            'valor_produto'   => $fmt((float) $it['valor_produto']),
            'valor_desconto'  => $fmt((float) $it['valor_desconto']),
            'valor_total'     => $fmt((float) $it['valor_total_item']),
            'aliq_icms'       => $pct((float) $it['aliq_icms']),
            'aliq_pis'        => $pct((float) $it['aliq_pis']),
            'aliq_cofins'     => $pct((float) $it['aliq_cofins']),
            'aliq_ipi'        => $pct((float) $it['aliq_ipi']),
            'valor_icms'      => $fmt((float) $it['valor_icms']),
            'valor_pis'       => $fmt((float) $it['valor_pis']),
            'valor_cofins'    => $fmt((float) $it['valor_cofins']),
            'valor_ipi'       => $fmt((float) $it['valor_ipi']),
            'valor_iss'       => $fmt((float) $it['valor_iss']),
            'valor_trib'      => $fmt((float) $it['valor_trib_total']),
            'cbs_info'        => $fmt((float) $it['valor_cbs']),
            'ibs_info'        => $fmt((float) $it['valor_ibs']),
            'aliq_cbs'        => $pct((float) $it['aliq_cbs']),
            '_trib'           => (float) $it['valor_trib_total'],
            '_fat'            => (float) $it['valor_total_item'],
        ];
    }, $items);

    $fat  = (float) $header['valor_total'];
    $trib = (float) $header['valor_trib_total'];
    Http::json([
        'ok'     => true,
        'header' => [
            'numero_nf'     => $header['numero_nf'],
            'serie_nf'      => $header['serie_nf'] ?? '',
            'chave_nfe'     => $header['chave_nfe'] ?? '',
            'data_emissao'  => $header['data_emissao'] ?? '',
            'nome_cliente'  => $header['nome_cliente'],
            'documento'     => $header['documento_cliente'] ?? '',
            'nome_vendedor' => $header['nome_vendedor'],
            'status'        => $header['status_fiscal'],
            'has_xml'       => $hasXml,
            'has_missing_ncm' => $hasMissingNcm,
            'has_icms_zero' => $hasIcmsZero,
            'valor_total'   => $fmt($fat),
            'receita_pos_tributos' => $fmt(max(0.0, $fat - $trib)),
            'trib_total'    => $fmt($trib),
            'icms'          => $fmt((float) $header['valor_icms']),
            'pis'           => $fmt((float) $header['valor_pis']),
            'cofins'        => $fmt((float) $header['valor_cofins']),
            'ipi'           => $fmt((float) $header['valor_ipi']),
            'carga_pct'     => $fat > 0 ? round($trib / $fat * 100, 2) : 0,
        ],
        'items'       => $itemsFmt,
        'has_items'   => count($itemsFmt) > 0,
    ]);
} catch (\Throwable $e) {
    Http::json(['ok' => false, 'message' => 'Erro ao carregar detalhe da NF.'], 500);
}
