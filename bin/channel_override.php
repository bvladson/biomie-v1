<?php

declare(strict_types=1);

/**
 * Override MANUAL do canal de uma NF ou pedido (varejo / licitacao / outros).
 *
 * Uso:
 *   php bin/channel_override.php --company=SLUG --nf=NUMERO --canal=licitacao --motivo="..."
 *   php bin/channel_override.php --company=SLUG --pedido=CODIGO --canal=varejo --motivo="..." --apply
 *
 * SIMULA POR PADRÃO (sessão READ ONLY); só grava com --apply.
 *
 * POR QUE EXISTE
 * As regras de canal decidem pela ordem cadastrada; quando só a regra PADRÃO da
 * empresa casa (confiança baixa), a decisão pode estar errada — ex.: venda a um
 * órgão público feita por vendedor nomeado, que a regra padrão manda para
 * varejo. O override manual é a forma DURÁVEL de corrigir: channel_confidence
 * = 'manual' é preservado pela escrita canônica (upsertInvoice/upsertOrder) e
 * pelos backfills, então o próximo sync do Omie não desfaz a decisão.
 * Remover o override: --canal=auto (a linha volta às regras no próximo passe).
 */

require_once dirname(__DIR__) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

require dirname(__DIR__) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Config;
use BIomie\Database;
use BIomie\Support\ChannelClassifierService;
use BIomie\Support\LicitacaoSeller;

$opts = [];
foreach (array_slice($argv ?? [], 1) as $arg) {
    if ($arg === '--apply') {
        $opts['apply'] = true;
    } elseif (preg_match('/^--([a-z]+)=(.*)$/s', $arg, $m) === 1) {
        $opts[$m[1]] = trim($m[2]);
    }
}
$apply = isset($opts['apply']);
$canal = strtolower((string) ($opts['canal'] ?? ''));
$motivo = (string) ($opts['motivo'] ?? '');
$isNf = isset($opts['nf']);
$doc = (string) ($opts['nf'] ?? $opts['pedido'] ?? '');

$uso = "Uso: php bin/channel_override.php --company=SLUG (--nf=NUMERO|--pedido=CODIGO) "
    . "--canal=varejo|licitacao|outros|auto --motivo=\"...\" [--apply]\n";
if (($opts['company'] ?? '') === '' || $doc === '' || (isset($opts['nf']) && isset($opts['pedido']))) {
    fwrite(STDERR, $uso);
    exit(2);
}
if ($canal !== 'auto' && ChannelClassifierService::normalizeChannel($canal) === null) {
    fwrite(STDERR, "Canal inválido: {$canal}\n" . $uso);
    exit(2);
}
if ($canal !== 'auto' && mb_strlen($motivo) < 5) {
    fwrite(STDERR, "Informe --motivo (quem decidiu e por quê) — fica gravado na origem da classificação.\n");
    exit(2);
}

$config = new Config();
$conn = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo = Database::connect($config, $conn);
if (!$apply) {
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
}

$stmt = $pdo->prepare('SELECT id FROM biomie_companies WHERE slug = :slug AND ativo = 1');
$stmt->execute(['slug' => strtoupper((string) $opts['company'])]);
$companyId = (int) $stmt->fetchColumn();
if ($companyId <= 0) {
    fwrite(STDERR, "Empresa não encontrada: {$opts['company']}\n");
    exit(2);
}

$table = $isNf ? 'biomie_notas_fiscais_canonicas' : 'biomie_pedidos_canonicos';
$docColumn = $isNf ? 'numero_nf' : 'codigo_pedido';
$valueColumn = $isNf ? 'valor_base_comercial_nf' : 'valor_mercadorias';
// Número de NF é gravado com zeros à esquerda (00000270); aceita os dois jeitos.
$stmt = $pdo->prepare(
    "SELECT id, {$docColumn} AS doc, data_emissao, nome_vendedor, nome_cliente, {$valueColumn} AS valor,
            business_channel, is_biomie_commercial_visible, channel_confidence, visibility_rule_source
       FROM {$table}
      WHERE company_id = :cid
        AND (TRIM(LEADING '0' FROM {$docColumn}) = TRIM(LEADING '0' FROM :doc))"
);
$stmt->execute(['cid' => $companyId, 'doc' => $doc]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (count($rows) !== 1) {
    fwrite(STDERR, sprintf("Esperava 1 registro para %s %s, achei %d.\n", $isNf ? 'NF' : 'pedido', $doc, count($rows)));
    exit(1);
}
$row = $rows[0];

$brl = static fn (float $v): string => 'R$ ' . number_format($v, 2, ',', '.');
printf("%s %s  %s  %s  %s  %s\n  atual: %s (visivel=%d, confiança=%s, origem=%s)\n",
    $isNf ? 'NF' : 'pedido', $row['doc'], substr((string) $row['data_emissao'], 0, 10),
    $row['nome_vendedor'], $row['nome_cliente'], $brl((float) $row['valor']),
    $row['business_channel'], (int) $row['is_biomie_commercial_visible'],
    $row['channel_confidence'] ?? '-', $row['visibility_rule_source'] ?? '-');

if ($canal === 'auto') {
    // Devolve a linha às regras: o próximo passe (sync ou backfill) reclassifica.
    $sql = "UPDATE {$table} SET channel_confidence = NULL, channel_classified_at = NULL WHERE id = :id";
    $params = ['id' => $row['id']];
    echo "  proposto: remover override (volta às regras no próximo passe)\n";
} else {
    $visible = $canal === 'varejo' ? 1 : 0;
    $scope = match ($canal) {
        'licitacao' => 'licitacao',
        'outros' => 'outros',
        default => 'comercial',
    };
    $source = mb_substr(LicitacaoSeller::SOURCE_MANUAL . ': ' . $motivo, 0, 80);
    $sql = "UPDATE {$table}
               SET business_channel = :canal, business_unit_scope = :scope,
                   is_biomie_commercial_visible = :vis, channel_confidence = 'manual',
                   channel_rule_id = NULL, visibility_rule_source = :src,
                   visibility_checked_at = NOW(), channel_classified_at = NOW()
             WHERE id = :id";
    $params = ['canal' => $canal, 'scope' => $scope, 'vis' => $visible, 'src' => $source, 'id' => $row['id']];
    printf("  proposto: %s (visivel=%d, confiança=manual, origem=%s)\n", $canal, $visible, $source);
}

if (!$apply) {
    echo "SIMULACAO — nada foi gravado. Use --apply para gravar.\n";
    exit(0);
}

$pdo->beginTransaction();
$pdo->prepare($sql)->execute($params);
$pdo->commit();
// Itens e títulos seguem o canal da NF/pedido.
$propagado = (new ChannelClassifierService($pdo, $companyId))->propagateRelatedChannels(false);
printf("GRAVADO. Canal propagado: itens=%d titulos=%d. Rematerialize o período para os marts.\n",
    $propagado['invoice_items'], $propagado['financial_titles']);
