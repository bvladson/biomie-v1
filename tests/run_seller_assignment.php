<?php

declare(strict_types=1);

/**
 * VENDEDOR ATRIBUÍDO NO BIOMIE (29/09/2026) — ciclo completo no banco de TESTE.
 *
 * NF/pedido chega do Omie sem vendedor => varejo com origem seller_missing.
 * Admin atribui um vendedor do cadastro Omie (só no BI) => canal pela regra
 * oficial, origem com ":atribuido_bi". Sync seguinte, Omie ainda sem vendedor
 * => a atribuição permanece. Omie passa a informar vendedor => o Omie vence e a
 * atribuição vira 'superada'. Remover => volta ao que o Omie mandou.
 *
 *   php tests/run_seller_assignment.php
 */

require_once __DIR__ . '/TestSupport.php';

use BIomie\Canonical\CanonicalWriteRepository;
use BIomie\Canonical\SellerAssignmentService;
use BIomie\Canonical\Support\CanonicalHashing;
use BIomie\Canonical\Support\CanonicalJsonCodec;

$checks = [];
$check = static function (string $name, bool $ok, mixed $detail = null) use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};

$pdo = test_pdo();
$pdo->exec("SET SESSION sql_mode = ''");
const CID = 93;
const GRUPO = '26537584000394,40914338000173';

$cleanup = static function (PDO $pdo): void {
    foreach ([
        'biomie_notas_fiscais_canonicas', 'biomie_pedidos_canonicos', 'biomie_channel_classification_rules',
        'biomie_vendedores_omie', 'biomie_notas_fiscais_itens_canonicos', 'biomie_titulos_financeiros_canonicos',
    ] as $table) {
        $pdo->exec("DELETE FROM {$table} WHERE company_id = " . CID);
    }
    $pdo->exec('DELETE FROM biomie_companies WHERE id = ' . CID);
    $exists = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biomie_seller_assignments'")->fetchColumn();
    if ($exists > 0) {
        $pdo->exec('DELETE FROM biomie_seller_assignments WHERE company_id = ' . CID);
    }
};
$cleanup($pdo);

(require dirname(__DIR__) . '/database/migrations/Version20260929140000_SellerAssignments.php')->up($pdo);

$pdo->exec("INSERT INTO biomie_companies (id, slug, nome_fantasia, canal_padrao, ativo) VALUES (" . CID . ", 'TESTE_ATRIB', 'Teste Atribuição', 'licitacao', 1)");
$pdo->exec("INSERT INTO biomie_vendedores_omie (company_id, codigo_omie, nome, ativo) VALUES
    (" . CID . ", '7001', 'Licitação', 1), (" . CID . ", '7002', 'ROBERTO SIQ.', 1), (" . CID . ", '7003', 'MARLENE', 1)");
// Regras exatamente como a migração oficial as cria (código do vendedor
// "Licitação" descoberto no cadastro, nome, padrão varejo) + intra-grupo.
$pdo->exec("INSERT INTO biomie_channel_classification_rules (company_id, priority, rule_type, match_value, target_channel, confidence, ativo) VALUES
    (" . CID . ", 1, 'cliente_cnpj_list', '" . GRUPO . "', 'outros', 'alta', 1),
    (" . CID . ", 999, 'default', '', 'licitacao', 'alta', 1)");
$pdo->exec('DROP TABLE IF EXISTS biomie_channel_rules_20260929');
$regraVendedor = require dirname(__DIR__) . '/database/migrations/Version20260929130000_LicitacaoPorVendedor.php';
$regraVendedor->up($pdo);

$codec = new CanonicalJsonCodec();
$repo = static fn (): CanonicalWriteRepository => new CanonicalWriteRepository(
    static fn (): PDO => $pdo,
    static function () use ($pdo): void { $pdo->beginTransaction(); },
    new CanonicalHashing($codec),
    $codec,
    CID,
);
$nfPayload = static fn (string $cod, string $nome): array => [
    'nota_key' => 'omie:t93nf1', 'nota_id_omie' => 93001, 'numero_nf' => '00000501', 'serie_nf' => '1',
    'status_fiscal' => 'autorizada', 'data_emissao' => '2026-09-10', 'data_competencia' => '2026-09-10',
    'valor_total' => 1500.00, 'valor_mercadorias' => 1500.00, 'codigo_cliente_omie' => 555,
    'nome_cliente' => 'INSTITUTO QUALQUER', 'documento_cliente' => '12.345.678/0001-90',
    'codigo_vendedor_omie' => $cod, 'nome_vendedor' => $nome, 'operation_scope' => 'commercial',
    'fonte_sync' => 'backfill',
];
$pedPayload = static fn (string $cod, string $nome): array => [
    'pedido_id_omie' => 930001, 'codigo_pedido' => '301', 'codigo_vendedor_omie' => $cod,
    'nome_vendedor' => $nome, 'codigo_cliente_omie' => 555, 'nome_cliente' => 'PREFEITURA X',
    'documento_cliente' => '98.765.432/0001-10', 'status_comercial' => 'outro', 'data_emissao' => '2026-09-11',
    'data_faturamento' => null, 'data_cancelamento' => null,
    'valor_total' => 800.00, 'valor_mercadorias' => 800.00, 'fonte_sync' => 'backfill',
];
$nf = static fn (): array => $pdo->query("SELECT codigo_vendedor_omie cod, nome_vendedor nome, business_channel ch,
    visibility_rule_source src FROM biomie_notas_fiscais_canonicas WHERE company_id = " . CID . " AND nota_key = 'omie:t93nf1'")
    ->fetch(PDO::FETCH_ASSOC) ?: [];
$ped = static fn (): array => $pdo->query("SELECT codigo_vendedor_omie cod, nome_vendedor nome, business_channel ch,
    visibility_rule_source src FROM biomie_pedidos_canonicos WHERE company_id = " . CID . " AND pedido_id_omie = 930001")
    ->fetch(PDO::FETCH_ASSOC) ?: [];
$status = static fn (string $tipo, string $chave): string => (string) $pdo->query("SELECT status FROM biomie_seller_assignments
    WHERE company_id = " . CID . " AND entity_type = '{$tipo}' AND entity_key = '{$chave}'")->fetchColumn();

// 1. Chega do Omie sem vendedor: varejo, mas a origem diz que faltou vendedor.
$repo()->upsertInvoice($nfPayload('', 'Sem vendedor'));
$repo()->upsertOrder($pedPayload('0', 'Sem vendedor'));
$check('NF sem vendedor => varejo / seller_missing', ($nf()['ch'] ?? '') === 'varejo' && ($nf()['src'] ?? '') === 'seller_missing', $nf());
$check('pedido sem vendedor => varejo / seller_missing', ($ped()['ch'] ?? '') === 'varejo' && ($ped()['src'] ?? '') === 'seller_missing', $ped());

$service = new SellerAssignmentService($pdo, CID);
$pend = $service->pending();
$check('lista de pendências traz a NF e o pedido', count($pend) === 2, array_column($pend, 'documento'));

// 2. Atribuições no BI.
$rNf = $service->assign('nf', 'omie:t93nf1', '7002', 'vendedor confirmado pelo comercial', 1);
$check('🔴 NF atribuída a ROBERTO => varejo, origem seller_regular:atribuido_bi',
    ($nf()['nome'] ?? '') === 'ROBERTO SIQ.' && ($nf()['ch'] ?? '') === 'varejo'
    && ($nf()['src'] ?? '') === 'seller_regular:atribuido_bi', ['linha' => $nf(), 'ret' => $rNf]);
$service->assign('pedido', '930001', '7001', 'pedido do pregão 12/2026', 1);
$check('🔴 pedido atribuído ao vendedor Licitação => licitação, origem seller_licitacao:atribuido_bi',
    ($ped()['ch'] ?? '') === 'licitacao' && ($ped()['src'] ?? '') === 'seller_licitacao:atribuido_bi', $ped());

// 3. Recusas.
$erro = static function (callable $fn): string {
    try { $fn(); return '(não recusou)'; } catch (InvalidArgumentException $e) { return $e->getMessage(); }
};
$check('vendedor fora do cadastro é recusado',
    str_contains($erro(fn () => $service->assign('nf', 'omie:t93nf1', '9999', 'teste', 1)), 'cadastro'));
$check('motivo é obrigatório',
    str_contains($erro(fn () => $service->assign('nf', 'omie:t93nf1', '7003', '', 1)), 'motivo'));

// 4. Sync seguinte com o Omie AINDA sem vendedor: a atribuição permanece.
$repo()->upsertInvoice($nfPayload('', 'Sem vendedor'));
$repo()->upsertOrder($pedPayload('0', 'Sem vendedor'));
$check('🔴 sync com Omie sem vendedor mantém ROBERTO na NF',
    ($nf()['nome'] ?? '') === 'ROBERTO SIQ.' && ($nf()['src'] ?? '') === 'seller_regular:atribuido_bi', $nf());
$check('🔴 sync com Omie sem vendedor mantém Licitação no pedido', ($ped()['ch'] ?? '') === 'licitacao', $ped());
$check('atribuição da NF continua ativa', $status('nf', 'omie:t93nf1') === 'ativa');

// 5. O Omie passa a informar vendedor na NF: o Omie vence.
$repo()->upsertInvoice($nfPayload('7003', 'MARLENE'));
$check('🔴 Omie com vendedor vence: NF fica com MARLENE, origem sem sufixo do BI',
    ($nf()['nome'] ?? '') === 'MARLENE' && ($nf()['src'] ?? '') === 'seller_regular', $nf());
$check('🔴 atribuição da NF vira superada', $status('nf', 'omie:t93nf1') === 'superada');
$check('operação com vendedor no Omie não aceita atribuição',
    str_contains($erro(fn () => $service->assign('nf', 'omie:t93nf1', '7002', 'tentativa', 1)), 'já tem vendedor'));

// 6. Remover a atribuição do pedido: volta ao que o Omie mandou.
$service->remove('pedido', '930001', 1);
$check('🔴 remover devolve "Sem vendedor" e varejo / seller_missing',
    ($ped()['nome'] ?? '') === 'Sem vendedor' && ($ped()['ch'] ?? '') === 'varejo' && ($ped()['src'] ?? '') === 'seller_missing', $ped());
$check('atribuição do pedido fica removida', $status('pedido', '930001') === 'removida');
$repo()->upsertOrder($pedPayload('0', 'Sem vendedor'));
$check('sync depois de remover não reaplica', ($ped()['nome'] ?? '') === 'Sem vendedor', $ped());

// 7. down() da migração: devolve o original das atribuições ativas.
$service->assign('pedido', '930001', '7002', 'para testar o rollback', 1);
$pdoCheck = $ped();
(require dirname(__DIR__) . '/database/migrations/Version20260929140000_SellerAssignments.php')->down($pdo);
$check('down(): pedido volta a "Sem vendedor" antes de a tabela sair',
    ($pdoCheck['nome'] ?? '') === 'ROBERTO SIQ.' && ($ped()['nome'] ?? '') === 'Sem vendedor', [$pdoCheck, $ped()]);

$regraVendedor->down($pdo);
$cleanup($pdo);

$passed = count(array_filter($checks, static fn (array $r): bool => $r['ok']));
$result = ['ok' => $passed === count($checks), 'total' => count($checks),
           'passed' => $passed, 'failed' => count($checks) - $passed, 'checks' => $checks];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
exit($result['ok'] ? 0 : 1);
