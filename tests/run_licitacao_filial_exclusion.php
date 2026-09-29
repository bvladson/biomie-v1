<?php

declare(strict_types=1);

/**
 * LICITAÇÃO DA MAG FILIAL (29/09/2026) — exclusão dura desativada.
 *
 * Regras de biomie_data_exclusion_rules criadas em 08/06/2026 ("Remocao
 * solicitada: dados de Licitacao") descartavam, antes de gravar, toda NF/pedido
 * do vendedor "Licitação" da Filial. Medido no bronze: 7 NFs (R$ 472.397,50)
 * entre 01/07 e 23/09/2026 nunca entraram no BIomie.
 *
 * Prova, no banco de TESTE, pelo caminho real da escrita:
 *   antes da migração 20260929150000 a NF é descartada ('skipped');
 *   depois ela entra e vira licitação pela regra oficial (seller_licitacao);
 *   regras com outro motivo continuam ativas; down() reativa exatamente.
 *
 *   php tests/run_licitacao_filial_exclusion.php
 */

require_once __DIR__ . '/TestSupport.php';

use BIomie\Canonical\CanonicalWriteRepository;
use BIomie\Canonical\Support\CanonicalHashing;
use BIomie\Canonical\Support\CanonicalJsonCodec;

$checks = [];
$check = static function (string $name, bool $ok, mixed $detail = null) use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};

$pdo = test_pdo();
$pdo->exec("SET SESSION sql_mode = ''");
const CID = 94;
const COD_LICITACAO = '8766069381';
const MOTIVO = 'Remocao solicitada: dados de Licitacao';

$cleanup = static function (PDO $pdo): void {
    foreach (['biomie_notas_fiscais_canonicas', 'biomie_channel_classification_rules', 'biomie_vendedores_omie',
              'biomie_data_exclusion_rules', 'biomie_notas_fiscais_itens_canonicos'] as $table) {
        $pdo->exec("DELETE FROM {$table} WHERE company_id = " . CID);
    }
    $pdo->exec('DELETE FROM biomie_companies WHERE id = ' . CID);
};
$cleanup($pdo);

$pdo->exec("INSERT INTO biomie_companies (id, slug, nome_fantasia, canal_padrao, ativo) VALUES (" . CID . ", 'TESTE_FILIAL', 'Teste Filial', 'varejo', 1)");
$pdo->exec("INSERT INTO biomie_vendedores_omie (company_id, codigo_omie, nome, ativo) VALUES
    (" . CID . ", '" . COD_LICITACAO . "', 'Licitação', 1), (" . CID . ", '8766948159', 'ROBERTO SIQ.', 1)");
$pdo->exec("INSERT INTO biomie_channel_classification_rules (company_id, priority, rule_type, match_value, target_channel, confidence, ativo) VALUES
    (" . CID . ", 999, 'default', '', 'varejo', 'baixa', 1)");
// Como em produção: o vendedor Licitação E NFs listadas uma a uma (as 11 de
// 08/06/2026), mais uma regra com outro motivo que tem de continuar valendo.
$pdo->exec("INSERT INTO biomie_data_exclusion_rules (company_id, entity_type, identifier_type, identifier_value, reason, active) VALUES
    (" . CID . ", 'vendor', 'vendor_code', '" . COD_LICITACAO . "', '" . MOTIVO . "', 1),
    (" . CID . ", 'invoice', 'nota_key', 'omie:t94fev', '" . MOTIVO . "', 1),
    (" . CID . ", 'invoice', 'nota_key', 'omie:t94outra', 'Duplicidade de importacao', 1)");
$pdo->exec('DROP TABLE IF EXISTS biomie_channel_rules_20260929');
$regraVendedor = require dirname(__DIR__) . '/database/migrations/Version20260929130000_LicitacaoPorVendedor.php';
$regraVendedor->up($pdo);
$reativar = require dirname(__DIR__) . '/database/migrations/Version20260929150000_ReativarLicitacaoFilial.php';

// HardDataExclusionPolicy guarda as regras por conexão durante o processo; em
// produção cada request/job é um processo novo. Aqui cada fase zera esse cache
// (Database::connect devolve a mesma conexão), o equivalente a um processo novo.
$conexao = static function () use ($pdo): PDO {
    (new ReflectionProperty(\BIomie\Support\HardDataExclusionPolicy::class, 'rulesByConnection'))->setValue(null, []);
    return $pdo;
};
$repo = static function (PDO $c): CanonicalWriteRepository {
    $codec = new CanonicalJsonCodec();
    return new CanonicalWriteRepository(
        static fn (): PDO => $c,
        static function () use ($c): void { $c->beginTransaction(); },
        new CanonicalHashing($codec),
        $codec,
        CID,
    );
};
$nf = [
    'nota_key' => 'omie:t94paracuru', 'nota_id_omie' => 94001, 'numero_nf' => '00002537', 'serie_nf' => '2',
    'status_fiscal' => 'autorizada', 'data_emissao' => '2026-09-23', 'data_competencia' => '2026-09-23',
    'valor_total' => 80750.00, 'valor_mercadorias' => 78207.50, 'codigo_cliente_omie' => 8829876874,
    'nome_cliente' => 'MUNICIPIO DE PARACURU', 'documento_cliente' => '07.592.298/0001-15',
    'codigo_vendedor_omie' => COD_LICITACAO, 'nome_vendedor' => 'Licitação', 'operation_scope' => 'commercial',
    'fonte_sync' => 'backfill',
];
$linha = static fn (PDO $c): array => $c->query("SELECT business_channel ch, visibility_rule_source src, valor_total
    FROM biomie_notas_fiscais_canonicas WHERE company_id = " . CID . " AND nota_key = 'omie:t94paracuru'")
    ->fetch(PDO::FETCH_ASSOC) ?: [];

$nfFev = ['nota_key' => 'omie:t94fev', 'nota_id_omie' => 94002, 'numero_nf' => '00000402', 'data_emissao' => '2026-02-10',
    'data_competencia' => '2026-02-10', 'valor_total' => 15000.00, 'valor_mercadorias' => 15000.00] + $nf;
$existe = static fn (PDO $c, string $key): bool => (int) $c->query("SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas
    WHERE company_id = " . CID . " AND nota_key = '{$key}'")->fetchColumn() === 1;

// 1. Com as regras ATIVAS: a trava de código já não deixa o vendedor Licitação
//    ser descartado (antes do 29/09 esta NF era 'skipped').
$c1 = $conexao();
$antes = $repo($c1)->upsertInvoice($nf);
$l = $linha($c1);
$check('🔴 trava: com a regra ativa, NF do vendedor Licitação já entra (antes era descartada)',
    $antes === 'inserted' && ($l['ch'] ?? '') === 'licitacao' && ($l['src'] ?? '') === 'seller_licitacao', [$antes, $l]);
$check('NF listada uma a uma com motivo de licitação ainda é descartada antes da migração',
    $repo($c1)->upsertInvoice($nfFev) === 'skipped' && !$existe($c1, 'omie:t94fev'));

// 2. Migração: desativa só as regras de licitação.
$reativar->up($pdo);
$ativas = $pdo->query('SELECT identifier_value, reason, active FROM biomie_data_exclusion_rules WHERE company_id = ' . CID . ' ORDER BY id')
    ->fetchAll(PDO::FETCH_ASSOC);
$check('migração desativa as regras de licitação (vendedor e NF)',
    (int) $ativas[0]['active'] === 0 && (int) $ativas[1]['active'] === 0, $ativas);
$check('🔴 regra com outro motivo continua ativa', (int) $ativas[2]['active'] === 1, $ativas);

// 3. Depois: a NF listada entra — o valor volta para os números.
$c2 = $conexao();
$depois = $repo($c2)->upsertInvoice($nfFev);
$check('🔴 depois da migração: a NF listada entra e é licitação',
    $depois === 'inserted' && $existe($c2, 'omie:t94fev'), $depois);
$check('a NF de setembro continua na base (R$ 80.750,00)', (float) ($linha($c2)['valor_total'] ?? 0) === 80750.00, $linha($c2));

// 4. down(): reativa exatamente.
$reativar->down($pdo);
$check('down() reativa a regra de licitação',
    (int) $pdo->query('SELECT active FROM biomie_data_exclusion_rules WHERE company_id = ' . CID . " AND identifier_type = 'vendor_code'")->fetchColumn() === 1);

$regraVendedor->down($pdo);
$cleanup($pdo);

$passed = count(array_filter($checks, static fn (array $r): bool => $r['ok']));
$result = ['ok' => $passed === count($checks), 'total' => count($checks),
           'passed' => $passed, 'failed' => count($checks) - $passed, 'checks' => $checks];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
exit($result['ok'] ? 0 : 1);
