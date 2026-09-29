<?php

declare(strict_types=1);

/**
 * ANTI-REGRESSÃO do incidente de 29/09/2026 — cada causa vira alarme ou trava.
 *
 * Monta, no banco de TESTE, cada causa do incidente e exige:
 *   TRAVAS (a venda não some):
 *     - exclusão pelo vendedor "Licitação" é ignorada (pelo cadastro e pelo nome);
 *     - exclusão de outro vendedor continua valendo (a trava é só para licitação).
 *   ALARMES (CommercialIntegrityHealthCheck):
 *     - exclusao_licitacao (crítico), exclusao_vendedor, venda_omie_fora_do_bi,
 *       nf_nunca_classificada, pedido_nf_divergente, acesso_um_canal (alerta),
 *       ferramenta_remocao e todas_desligado (crítico);
 *     - com tudo corrigido, o check fica OK.
 *
 *   php tests/run_commercial_integrity_guard.php
 */

require_once __DIR__ . '/TestSupport.php';

use BIomie\Canonical\CanonicalWriteRepository;
use BIomie\Canonical\Support\CanonicalHashing;
use BIomie\Canonical\Support\CanonicalJsonCodec;
use BIomie\Support\HardDataExclusionPolicy;
use BIomie\SystemHealth\Checks\CommercialIntegrityHealthCheck;

$checks = [];
$check = static function (string $name, bool $ok, mixed $detail = null) use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};

$pdo = test_pdo();
$pdo->exec("SET SESSION sql_mode = ''");
const CID = 95;
const UID = 95;
const COD_LICITACAO = '7777000001';
const COD_OUTRO = '7777000002';

$cleanup = static function (PDO $pdo): void {
    foreach (['biomie_notas_fiscais_canonicas', 'biomie_pedidos_canonicos', 'biomie_vendedores_omie',
              'biomie_data_exclusion_rules', 'biomie_omie_bronze', 'biomie_channel_classification_rules',
              'biomie_notas_fiscais_itens_canonicos'] as $table) {
        $pdo->exec("DELETE FROM {$table} WHERE company_id = " . CID);
    }
    $pdo->exec('DELETE FROM biomie_user_companies WHERE user_id = ' . UID);
    $pdo->exec('DELETE FROM biomie_users WHERE id = ' . UID);
    $pdo->exec('DELETE FROM biomie_companies WHERE id = ' . CID);
};
$cleanup($pdo);
$resetPolicy = static function (): void {
    (new ReflectionProperty(HardDataExclusionPolicy::class, 'rulesByConnection'))->setValue(null, []);
};

$pdo->exec("INSERT INTO biomie_companies (id, slug, nome_fantasia, canal_padrao, ativo) VALUES (" . CID . ", 'TESTE_GUARD', 'Teste Guard', 'varejo', 1)");
$pdo->exec("INSERT INTO biomie_vendedores_omie (company_id, codigo_omie, nome, ativo) VALUES
    (" . CID . ", '" . COD_LICITACAO . "', 'LICITAÇÃO', 1), (" . CID . ", '" . COD_OUTRO . "', 'VENDEDOR TESTE', 1)");
$pdo->exec("INSERT INTO biomie_channel_classification_rules (company_id, priority, rule_type, match_value, target_channel, confidence, ativo) VALUES
    (" . CID . ", 999, 'default', '', 'varejo', 'alta', 1)");

// ── TRAVAS ─────────────────────────────────────────────────────────────────
$pdo->exec("INSERT INTO biomie_data_exclusion_rules (company_id, entity_type, identifier_type, identifier_value, reason, active) VALUES
    (" . CID . ", 'vendor', 'vendor_code', '" . COD_LICITACAO . "', 'Remocao solicitada: dados de Licitacao', 1),
    (" . CID . ", 'vendor', 'vendor_code', '" . COD_OUTRO . "', 'Vendedor de teste do Omie', 1),
    (" . CID . ", 'vendor', 'vendor_code', '9999999999', 'Remocao antiga', 1)");
$resetPolicy();
$check('🔴 trava: exclusão pelo código do vendedor Licitação (cadastro) é ignorada',
    HardDataExclusionPolicy::matches($pdo, ['vendor_code' => COD_LICITACAO], CID) === false);
$check('exclusão de outro vendedor continua valendo',
    HardDataExclusionPolicy::matches($pdo, ['vendor_code' => COD_OUTRO], CID) === true);

$codec = new CanonicalJsonCodec();
$repo = new CanonicalWriteRepository(
    static fn (): PDO => $pdo,
    static function () use ($pdo): void { $pdo->beginTransaction(); },
    new CanonicalHashing($codec), $codec, CID,
);
$nf = static fn (string $key, string $cod, string $nome): array => [
    'nota_key' => $key, 'nota_id_omie' => crc32($key), 'numero_nf' => substr($key, -4), 'serie_nf' => '1',
    'status_fiscal' => 'autorizada', 'data_emissao' => '2026-09-20', 'data_competencia' => '2026-09-20',
    'valor_total' => 1000.00, 'valor_mercadorias' => 1000.00, 'codigo_cliente_omie' => 1,
    'nome_cliente' => 'PREFEITURA TESTE', 'documento_cliente' => '12.345.678/0001-90',
    'codigo_vendedor_omie' => $cod, 'nome_vendedor' => $nome, 'operation_scope' => 'commercial',
    'fonte_sync' => 'backfill', 'chave_nfe' => 'CHAVE-' . $key,
];
$resetPolicy();
$check('🔴 trava: NF do vendedor Licitação entra mesmo com a regra ativa',
    $repo->upsertInvoice($nf('omie:g1', COD_LICITACAO, 'Licitação')) === 'inserted');
$check('🔴 trava: vendedor "Licitação" com código fora do cadastro (9999999999) também entra',
    $repo->upsertInvoice($nf('omie:g2', '9999999999', ' licitação ')) === 'inserted');
$check('NF do outro vendedor excluído continua descartada',
    $repo->upsertInvoice($nf('omie:g3', COD_OUTRO, 'VENDEDOR TESTE')) === 'skipped');

// ── ALARMES ────────────────────────────────────────────────────────────────
// Venda no Omie (bronze) fora da canônica, ingerida há 3 h.
$pdo->exec("INSERT INTO biomie_omie_bronze (company_id, domain_name, entity_name, endpoint, omie_method, natural_key,
        request_hash, payload_hash, idempotency_key, request_params_json, payload_json, ingested_at)
    VALUES (" . CID . ", 'invoices', 'invoice', '/produtos/nfconsultar/', 'ListarNF', 'CHAVE-omie:perdida',
        REPEAT('a',64), REPEAT('b',64), REPEAT('c',64), '{}', '{}', NOW() - INTERVAL 3 HOUR)");
// NF comercial nunca classificada.
$pdo->exec("INSERT INTO biomie_notas_fiscais_canonicas (company_id, nota_key, numero_nf, status_fiscal, operation_scope,
        data_emissao, business_channel, valor_total, channel_classified_at)
    VALUES (" . CID . ", 'omie:g-nunca', '9001', 'autorizada', 'commercial', '2026-09-21', 'licitacao', 10, NULL)");
// Pedido com canal diferente da própria NF (a NF g1 entrou varejo: o fixture só
// tem a regra padrão; o pedido ficou licitação — o caso Uni Design invertido).
$pdo->exec("UPDATE biomie_notas_fiscais_canonicas SET pedido_id_omie = 95001 WHERE company_id = " . CID . " AND nota_key = 'omie:g1'");
$pdo->exec("INSERT INTO biomie_pedidos_canonicos (company_id, pedido_id_omie, codigo_pedido, business_channel, nome_vendedor)
    VALUES (" . CID . ", 95001, '501', 'licitacao', 'Licitação')");
// Usuário que enxerga só licitação.
$pdo->exec("INSERT INTO biomie_users (id, username, full_name, role, is_active) VALUES (" . UID . ", 'teste_guard', 'Teste Guard', 'viewer', 1)");
$pdo->exec("INSERT INTO biomie_user_companies (user_id, company_id, can_varejo, can_licitacao) VALUES (" . UID . ", " . CID . ", 0, 1)");

$toolRoot = sys_get_temp_dir() . '/biomie_guard_' . getmypid();
@mkdir($toolRoot . '/tools/dev', 0777, true);
file_put_contents($toolRoot . '/tools/dev/remove_licitacao_data.php', '<?php // voltou');

$byCode = static function (array $findings): array {
    $out = [];
    foreach ($findings as $f) {
        $out[$f['code']] = $f;
    }
    return $out;
};
$f = $byCode((new CommercialIntegrityHealthCheck($pdo, $toolRoot, false))->evaluate());
$sev = static fn (string $code): string => ($f[$code]['severity'] ?? '?') . '/' . ($f[$code]['count'] ?? '?');

$check('🔴 alarme crítico: exclusão contra licitação', $sev('exclusao_licitacao') === 'critical/1', $sev('exclusao_licitacao'));
$check('alarme: exclusão de vendedor inteiro (2 regras)', $sev('exclusao_vendedor') === 'warning/2', $sev('exclusao_vendedor'));
$check('🔴 alarme: NF do Omie fora da base do BIomie', $sev('venda_omie_fora_do_bi') === 'warning/1', $sev('venda_omie_fora_do_bi'));
$check('alarme: NF nunca classificada', $sev('nf_nunca_classificada') === 'warning/1', $sev('nf_nunca_classificada'));
$check('alarme: pedido com canal diferente da NF', $sev('pedido_nf_divergente') === 'warning/1', $sev('pedido_nf_divergente'));
$check('alarme: acesso de um canal só', $sev('acesso_um_canal') === 'warning/1', $sev('acesso_um_canal'));
$check('🔴 alarme crítico: ferramenta de remoção de licitação voltou', $sev('ferramenta_remocao') === 'critical/1', $sev('ferramenta_remocao'));
$check('🔴 alarme crítico: "Todas as operações" desligado', $sev('todas_desligado') === 'critical/1', $sev('todas_desligado'));
$run = (new CommercialIntegrityHealthCheck($pdo, $toolRoot, false))->run();
$check('check no painel de saúde fica crítico', $run->status === 'critical', $run->summary);

// ── Tudo corrigido => OK ─────────────────────────────────────────────────────
@unlink($toolRoot . '/tools/dev/remove_licitacao_data.php');
$pdo->exec('DELETE FROM biomie_data_exclusion_rules WHERE company_id = ' . CID);
$pdo->exec('DELETE FROM biomie_omie_bronze WHERE company_id = ' . CID);
$pdo->exec("UPDATE biomie_notas_fiscais_canonicas SET channel_classified_at = NOW() WHERE company_id = " . CID);
$pdo->exec("UPDATE biomie_pedidos_canonicos SET business_channel = 'varejo' WHERE company_id = " . CID);
$pdo->exec('UPDATE biomie_user_companies SET can_varejo = 1 WHERE user_id = ' . UID);
$ok = (new CommercialIntegrityHealthCheck($pdo, $toolRoot, true))->run();
$check('🔴 com as causas corrigidas, o check fica OK', $ok->status === 'ok', [$ok->status, $ok->summary]);
$check('o repositório real não tem mais a ferramenta de remoção',
    !is_file(dirname(__DIR__) . '/tools/dev/remove_licitacao_data.php'));

@rmdir($toolRoot . '/tools/dev');
@rmdir($toolRoot . '/tools');
@rmdir($toolRoot);
$cleanup($pdo);

$passed = count(array_filter($checks, static fn (array $r): bool => $r['ok']));
$result = ['ok' => $passed === count($checks), 'total' => count($checks),
           'passed' => $passed, 'failed' => count($checks) - $passed, 'checks' => $checks];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
exit($result['ok'] ? 0 : 1);
