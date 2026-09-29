<?php

declare(strict_types=1);

/**
 * VENDEDOR ATRIBUÍDO NO BIOMIE (29/09/2026) — ciclo completo no banco de TESTE.
 *
 * Prioridade: intra-grupo > vendedor vindo do Omie > atribuição no BI (só se o
 * Omie estiver sem vendedor) > seller_missing / seller_unresolved.
 *
 * NF/pedido chega do Omie sem vendedor => varejo, origem do canal
 * seller_missing e seller_source 'missing'. Código fora do cadastro =>
 * seller_unresolved / 'unresolved' ("vendedor desconhecido"). Admin atribui um
 * vendedor do cadastro (só no BI) => canal pela regra oficial, seller_source
 * 'bi'. Sync seguinte com o Omie ainda sem vendedor => mantém. Omie passa a
 * informar vendedor => o Omie vence e a atribuição vira 'superada'. Remover =>
 * volta ao que o Omie mandou.
 *
 * INVARIANTE PERMANENTE: atribuir vendedor NUNCA muda "Todas as operações" —
 * só redistribui (sem vendedor -> vendedor; no máximo varejo <-> licitação).
 *
 *   php tests/run_seller_assignment.php
 */

require_once __DIR__ . '/TestSupport.php';

use BIomie\Canonical\CanonicalWriteRepository;
use BIomie\Canonical\SellerAssignmentService;
use BIomie\Canonical\Support\CanonicalHashing;
use BIomie\Canonical\Support\CanonicalJsonCodec;
use BIomie\Support\CommercialNfUniverse;

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

$atribuicoes = require dirname(__DIR__) . '/database/migrations/Version20260929140000_SellerAssignments.php';
$atribuicoes->up($pdo);

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
$nfPayload = static fn (string $key, string $num, float $valor, string $cod, string $nome, string $doc = '12.345.678/0001-90'): array => [
    'nota_key' => $key, 'nota_id_omie' => (int) $num + 93000, 'numero_nf' => $num, 'serie_nf' => '1',
    'status_fiscal' => 'autorizada', 'data_emissao' => '2026-09-10', 'data_competencia' => '2026-09-10',
    'valor_total' => $valor, 'valor_mercadorias' => $valor, 'codigo_cliente_omie' => 555,
    'nome_cliente' => 'INSTITUTO QUALQUER', 'documento_cliente' => $doc,
    'codigo_vendedor_omie' => $cod, 'nome_vendedor' => $nome, 'operation_scope' => 'commercial',
    'fonte_sync' => 'backfill',
];
$nf1 = static fn (string $cod, string $nome): array => $nfPayload('omie:t93nf1', '00000501', 1500.00, $cod, $nome);
$pedPayload = static fn (string $cod, string $nome): array => [
    'pedido_id_omie' => 930001, 'codigo_pedido' => '301', 'codigo_vendedor_omie' => $cod,
    'nome_vendedor' => $nome, 'codigo_cliente_omie' => 555, 'nome_cliente' => 'PREFEITURA X',
    'documento_cliente' => '98.765.432/0001-10', 'status_comercial' => 'outro', 'data_emissao' => '2026-09-11',
    'data_faturamento' => null, 'data_cancelamento' => null,
    'valor_total' => 800.00, 'valor_mercadorias' => 800.00, 'fonte_sync' => 'backfill',
];
$linha = static fn (string $table, string $where): array => $pdo->query("SELECT codigo_vendedor_omie cod, nome_vendedor nome,
    business_channel ch, visibility_rule_source src, seller_source vend FROM {$table} WHERE company_id = " . CID . " AND {$where}")
    ->fetch(PDO::FETCH_ASSOC) ?: [];
$nf = static fn (string $key = 'omie:t93nf1'): array => $linha('biomie_notas_fiscais_canonicas', "nota_key = '{$key}'");
$ped = static fn (): array => $linha('biomie_pedidos_canonicos', 'pedido_id_omie = 930001');
$status = static fn (string $tipo, string $chave): string => (string) $pdo->query("SELECT status FROM biomie_seller_assignments
    WHERE company_id = " . CID . " AND entity_type = '{$tipo}' AND entity_key = '{$chave}'")->fetchColumn();
// Totais pelo universo comercial oficial (o mesmo do painel em "Todas").
$totais = static function () use ($pdo): array {
    $u = CommercialNfUniverse::sql('nf');
    $row = $pdo->query("SELECT ROUND(COALESCE(SUM(nf.valor_total),0),2) todas,
            ROUND(COALESCE(SUM(CASE WHEN nf.business_channel = 'varejo' THEN nf.valor_total END),0),2) varejo,
            ROUND(COALESCE(SUM(CASE WHEN nf.business_channel = 'licitacao' THEN nf.valor_total END),0),2) licitacao,
            ROUND(COALESCE(SUM(CASE WHEN nf.seller_source IN ('missing','unresolved') THEN nf.valor_total END),0),2) sem_vendedor
        FROM biomie_notas_fiscais_canonicas nf WHERE nf.company_id = " . CID . " AND {$u}")->fetch(PDO::FETCH_ASSOC);
    return array_map('floatval', $row);
};

// 1. Chega do Omie sem vendedor / com código fora do cadastro.
$repo()->upsertInvoice($nf1('', 'Sem vendedor'));
$repo()->upsertInvoice($nfPayload('omie:t93nf2', '00000502', 700.00, '8916374948', 'Sem vendedor'));
$repo()->upsertInvoice($nfPayload('omie:t93nf3', '00000503', 300.00, '7003', 'MARLENE'));
$repo()->upsertOrder($pedPayload('0', 'Sem vendedor'));
$check('NF sem vendedor => varejo / seller_missing / seller_source missing',
    ($nf()['ch'] ?? '') === 'varejo' && ($nf()['src'] ?? '') === 'seller_missing' && ($nf()['vend'] ?? '') === 'missing', $nf());
$check('🔴 NF com código fora do cadastro => varejo / seller_unresolved / "vendedor desconhecido"',
    ($nf('omie:t93nf2')['src'] ?? '') === 'seller_unresolved' && ($nf('omie:t93nf2')['vend'] ?? '') === 'unresolved', $nf('omie:t93nf2'));
$check('NF com vendedor do Omie => seller_source omie', ($nf('omie:t93nf3')['vend'] ?? '') === 'omie', $nf('omie:t93nf3'));
$check('pedido sem vendedor => varejo / seller_missing', ($ped()['ch'] ?? '') === 'varejo' && ($ped()['src'] ?? '') === 'seller_missing', $ped());

$service = new SellerAssignmentService($pdo, CID);
$pend = $service->pending();
$situacoes = array_column($pend, 'situacao', 'documento');
$check('fila traz as 2 NFs e o pedido, separando ausente de desconhecido',
    count($pend) === 3 && ($situacoes['00000501'] ?? '') === 'missing' && ($situacoes['00000502'] ?? '') === 'unresolved',
    $situacoes);

// 2. Atribuições no BI — e o invariante: "Todas" não muda.
$antes = $totais();
$service->assign('nf', 'omie:t93nf1', '7002', 'vendedor confirmado pelo comercial', 1);
$depoisRoberto = $totais();
$check('🔴 NF atribuída a ROBERTO => varejo, canal seller_regular, seller_source bi',
    ($nf()['nome'] ?? '') === 'ROBERTO SIQ.' && ($nf()['ch'] ?? '') === 'varejo'
    && ($nf()['src'] ?? '') === 'seller_regular' && ($nf()['vend'] ?? '') === 'bi', $nf());
$check('🔴 INVARIANTE: atribuir vendedor não muda "Todas"', $depoisRoberto['todas'] === $antes['todas'], [$antes, $depoisRoberto]);
$check('atribuir só tira de "sem vendedor"', $depoisRoberto['sem_vendedor'] === round($antes['sem_vendedor'] - 1500.00, 2), [$antes, $depoisRoberto]);

$service->assign('nf', 'omie:t93nf2', '7001', 'venda do pregão 7/2026', 1);
$depoisLic = $totais();
$check('🔴 NF desconhecida atribuída ao vendedor Licitação => licitação / seller_licitacao / bi',
    ($nf('omie:t93nf2')['ch'] ?? '') === 'licitacao' && ($nf('omie:t93nf2')['src'] ?? '') === 'seller_licitacao'
    && ($nf('omie:t93nf2')['vend'] ?? '') === 'bi', $nf('omie:t93nf2'));
$check('🔴 INVARIANTE: atribuir o vendedor Licitação também não muda "Todas" (só varejo -> licitação)',
    $depoisLic['todas'] === $antes['todas']
    && $depoisLic['licitacao'] === 700.00
    && $depoisLic['varejo'] === round($antes['varejo'] - 700.00, 2), [$antes, $depoisLic]);

$service->assign('pedido', '930001', '7001', 'pedido do pregão 12/2026', 1);
$check('🔴 pedido atribuído ao vendedor Licitação => licitação, seller_source bi',
    ($ped()['ch'] ?? '') === 'licitacao' && ($ped()['src'] ?? '') === 'seller_licitacao' && ($ped()['vend'] ?? '') === 'bi', $ped());

// 3. Recusas.
$erro = static function (callable $fn): string {
    try { $fn(); return '(não recusou)'; } catch (InvalidArgumentException $e) { return $e->getMessage(); }
};
$check('vendedor fora do cadastro é recusado',
    str_contains($erro(fn () => $service->assign('nf', 'omie:t93nf1', '9999', 'teste', 1)), 'cadastro'));
$check('motivo é obrigatório',
    str_contains($erro(fn () => $service->assign('nf', 'omie:t93nf1', '7003', '', 1)), 'motivo'));
$check('operação com vendedor do Omie não aceita atribuição',
    str_contains($erro(fn () => $service->assign('nf', 'omie:t93nf3', '7002', 'tentativa', 1)), 'já tem vendedor'));

// 4. Sync seguinte com o Omie AINDA sem vendedor: a atribuição permanece.
$repo()->upsertInvoice($nf1('', 'Sem vendedor'));
$repo()->upsertOrder($pedPayload('0', 'Sem vendedor'));
$check('🔴 sync com Omie sem vendedor mantém ROBERTO na NF (seller_source bi)',
    ($nf()['nome'] ?? '') === 'ROBERTO SIQ.' && ($nf()['vend'] ?? '') === 'bi', $nf());
$check('🔴 sync com Omie sem vendedor mantém Licitação no pedido', ($ped()['ch'] ?? '') === 'licitacao', $ped());
$check('atribuição da NF continua ativa', $status('nf', 'omie:t93nf1') === 'ativa');
$check('INVARIANTE: sync não muda "Todas"', $totais()['todas'] === $antes['todas'], $totais());

// 5. O Omie passa a informar vendedor na NF: o Omie vence.
$repo()->upsertInvoice($nf1('7003', 'MARLENE'));
$check('🔴 Omie com vendedor vence: NF fica com MARLENE, seller_source omie',
    ($nf()['nome'] ?? '') === 'MARLENE' && ($nf()['src'] ?? '') === 'seller_regular' && ($nf()['vend'] ?? '') === 'omie', $nf());
$check('🔴 atribuição da NF vira superada', $status('nf', 'omie:t93nf1') === 'superada');

// 6. Remover a atribuição do pedido: volta ao que o Omie mandou.
$service->remove('pedido', '930001', 1);
$check('🔴 remover devolve "Sem vendedor", varejo / seller_missing / missing',
    ($ped()['nome'] ?? '') === 'Sem vendedor' && ($ped()['ch'] ?? '') === 'varejo'
    && ($ped()['src'] ?? '') === 'seller_missing' && ($ped()['vend'] ?? '') === 'missing', $ped());
$check('atribuição do pedido fica removida', $status('pedido', '930001') === 'removida');
$repo()->upsertOrder($pedPayload('0', 'Sem vendedor'));
$check('sync depois de remover não reaplica', ($ped()['nome'] ?? '') === 'Sem vendedor', $ped());
$service->remove('nf', 'omie:t93nf2', 1);
$check('remover da NF desconhecida devolve o código original e seller_unresolved',
    ($nf('omie:t93nf2')['cod'] ?? '') === '8916374948' && ($nf('omie:t93nf2')['src'] ?? '') === 'seller_unresolved', $nf('omie:t93nf2'));
$check('INVARIANTE: remover não muda "Todas"', $totais()['todas'] === $antes['todas'], $totais());

// 7. down() da migração: devolve o original das atribuições ativas e tira a coluna.
$service->assign('pedido', '930001', '7002', 'para testar o rollback', 1);
$antesDown = $ped();
$atribuicoes->down($pdo);
$depoisDown = $pdo->query('SELECT codigo_vendedor_omie cod, nome_vendedor nome FROM biomie_pedidos_canonicos
    WHERE company_id = ' . CID . ' AND pedido_id_omie = 930001')->fetch(PDO::FETCH_ASSOC) ?: [];
$semColuna = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'biomie_pedidos_canonicos' AND COLUMN_NAME = 'seller_source'")->fetchColumn() === 0;
$check('down(): pedido volta a "Sem vendedor", tabela e coluna seller_source saem',
    ($antesDown['nome'] ?? '') === 'ROBERTO SIQ.' && ($depoisDown['nome'] ?? '') === 'Sem vendedor' && $semColuna,
    [$antesDown, $depoisDown, $semColuna]);

$regraVendedor->down($pdo);
$cleanup($pdo);

$passed = count(array_filter($checks, static fn (array $r): bool => $r['ok']));
$result = ['ok' => $passed === count($checks), 'total' => count($checks),
           'passed' => $passed, 'failed' => count($checks) - $passed, 'checks' => $checks];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
exit($result['ok'] ? 0 : 1);
