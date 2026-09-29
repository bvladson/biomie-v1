<?php

declare(strict_types=1);

/**
 * Reaplica a classificação de canal (varejo / licitação / intra-grupo) às NFs
 * e, opcionalmente, aos pedidos — com relatório para revisão ANTES de gravar.
 *
 * Uso:
 *   php bin/reclassify_unclassified_invoices.php --company=SLUG|all           (simula)
 *   php bin/reclassify_unclassified_invoices.php --company=SLUG|all --apply   (grava)
 *     --todas        reavalia TODAS as NFs não-manuais, não só as sem classificação
 *     --pedidos      reavalia também os pedidos (mesmo classificador da NF)
 *     --csv=ARQUIVO  grava cada mudança proposta, com motivo e origem da regra
 *     --detalhe      lista toda mudança na tela (padrão: as 15 primeiras)
 *
 * SIMULA POR PADRÃO. Sem --apply o MariaDB recusa escrita (sessão READ ONLY).
 * O relatório diz, por empresa, quanto dinheiro muda de coluna e POR QUÊ:
 *
 *   UNI_DESIGN  NFs
 *     varejo -> licitacao        0 NFs   R$ 0,00
 *     licitacao -> outros        1 NF    R$ 330.180,00   (regra #47 cliente do grupo)
 *     sem alteração              ...
 *
 * Usa o mesmo caminho da escrita canônica (CanonicalWriteRepository::
 * reclassifyUnclassifiedInvoices / reclassifyOrders): override manual > regra
 * de vendedor > regras de canal da empresa, na prioridade cadastrada. Não cria
 * nem altera regra. Com --apply, propaga o canal para itens e títulos.
 */

require_once dirname(__DIR__) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

require dirname(__DIR__) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

use BIomie\Canonical\CanonicalWriteRepository;
use BIomie\Canonical\Support\CanonicalHashing;
use BIomie\Canonical\Support\CanonicalJsonCodec;
use BIomie\Config;
use BIomie\Database;
use BIomie\Support\ChannelClassifierService;

$args = array_slice($argv ?? [], 1);
$apply = in_array('--apply', $args, true);
$todas = in_array('--todas', $args, true);
$pedidos = in_array('--pedidos', $args, true);
$detalhe = in_array('--detalhe', $args, true);
$companyArg = '';
$csvPath = '';
foreach ($args as $arg) {
    if (str_starts_with($arg, '--company=')) {
        $companyArg = substr($arg, strlen('--company='));
    } elseif (str_starts_with($arg, '--csv=')) {
        $csvPath = substr($arg, strlen('--csv='));
    }
}
if ($companyArg === '') {
    fwrite(STDERR, "Uso: php bin/reclassify_unclassified_invoices.php --company=SLUG|all [--todas] [--pedidos] [--csv=ARQ] [--detalhe] [--apply]\n");
    exit(2);
}

$config = new Config();
$conn = Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db';
$pdo = Database::connect($config, $conn);

// Em simulacao, o proprio banco recusa escrita: a garantia de "nada foi
// gravado" nao depende so de o codigo seguir o ramo certo.
if (!$apply) {
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
}

if ($companyArg === 'all') {
    $companies = $pdo->query('SELECT id, slug FROM biomie_companies WHERE ativo = 1 ORDER BY id')
        ->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->prepare('SELECT id, slug FROM biomie_companies WHERE slug = :slug AND ativo = 1 LIMIT 1');
    $stmt->execute(['slug' => $companyArg]);
    $companies = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
if ($companies === []) {
    fwrite(STDERR, "Empresa não encontrada ou inativa: {$companyArg}\n");
    exit(2);
}

// Regras por id, para dizer em português por que cada registro muda.
$regras = [];
foreach ($pdo->query(
    'SELECT id, rule_type, match_value, target_channel FROM biomie_channel_classification_rules'
)->fetchAll(PDO::FETCH_ASSOC) as $regra) {
    $regras[(int) $regra['id']] = $regra;
}
$motivo = static function (string $fonte, ?int $ruleId) use ($regras): array {
    if ($fonte === 'seller_missing') {
        return ['Omie sem vendedor => varejo (só o vendedor Licitação define licitação)', 'seller_missing'];
    }
    if ($fonte === 'seller_unresolved') {
        return ['código de vendedor fora do cadastro => varejo (vendedor desconhecido)', 'seller_unresolved'];
    }
    if ($ruleId !== null && isset($regras[$ruleId])) {
        $r = $regras[$ruleId];
        $valor = mb_strimwidth((string) $r['match_value'], 0, 40, '…');
        return match ((string) $r['rule_type']) {
            'cliente_cnpj_list' => $r['target_channel'] === 'outros'
                ? ["regra #{$ruleId}: cliente é empresa do grupo (não é venda)", 'intra_grupo']
                : ["regra #{$ruleId}: CNPJ do cliente na lista", 'cliente_cnpj'],
            'vendedor_id_list' => ["regra #{$ruleId}: código do vendedor \"Licitação\" no Omie", 'seller_licitacao'],
            'vendedor_nome_igual' => ["regra #{$ruleId}: vendedor = \"Licitação\" (nome normalizado)", 'seller_licitacao'],
            'default' => $r['target_channel'] === 'varejo'
                ? ["regra #{$ruleId}: vendedor comum => varejo", 'seller_regular']
                : ["regra #{$ruleId}: padrão da empresa ({$r['target_channel']})", 'padrao_empresa'],
            'projeto_id_list' => ["regra #{$ruleId}: projeto Omie na lista (legada)", 'projeto'],
            'pedido_obs_like' => ["regra #{$ruleId}: observação contém {$valor} (legada)", 'observacao'],
            'vendedor_nome_like' => ["regra #{$ruleId}: nome do vendedor contém {$valor} (legada)", 'vendedor_legado'],
            default => ["regra #{$ruleId}", (string) $r['rule_type']],
        };
    }
    return match (true) {
        $fonte === 'vendor_scope_rules' => ['regra de escopo do vendedor', 'escopo_vendedor'],
        str_starts_with($fonte, 'manual') || $fonte === 'payload_business_channel' => ['override manual preservado', 'manual_override'],
        default => ['sem regra aplicável (fallback varejo)', 'fallback'],
    };
};
$lado = static fn (array $s): string => $s['channel'] === 'varejo' && (int) $s['visible'] === 0
    ? 'varejo-invisivel'
    : (string) $s['channel'];

$codec = new CanonicalJsonCodec();
$hashing = new CanonicalHashing($codec);
$brl = static fn (float $v): string => 'R$ ' . number_format($v, 2, ',', '.');

$csv = null;
if ($csvPath !== '') {
    $csv = fopen($csvPath, 'wb');
    if ($csv === false) {
        fwrite(STDERR, "Não consegui abrir {$csvPath} para escrita.\n");
        exit(2);
    }
    fputcsv($csv, [
        'empresa', 'tipo', 'documento', 'data', 'valor', 'vendedor', 'cliente',
        'canal_atual', 'canal_proposto', 'motivo', 'origem_regra', 'rule_id',
    ], ';', '"', '');
}

echo $apply ? "MODO: GRAVAR (--apply)\n" : "MODO: SIMULACAO (nada sera gravado; use --apply para gravar)\n";
echo $todas ? "NFs: TODAS as não-manuais\n" : "NFs: só as nunca classificadas\n";
echo $pedidos ? "Pedidos: incluídos\n\n" : "Pedidos: não incluídos (use --pedidos)\n\n";

/**
 * Resume as mudanças por direção (canal atual -> proposto) e imprime.
 *
 * @param list<array<string,mixed>> $mudancas
 */
$relatar = static function (
    string $slug,
    string $tipo,
    array $r,
    string $campoValor,
    string $campoDoc,
) use ($motivo, $lado, $brl, $detalhe, &$csv): void {
    $direcoes = [];
    $origens = [];
    foreach ($r['mudancas'] as $m) {
        $de = $lado($m['antes']);
        $para = $lado($m['depois']);
        $chave = $de === $para ? "{$de} (só a flag de visibilidade)" : "{$de} -> {$para}";
        $direcoes[$chave] ??= ['n' => 0, 'valor' => 0.0];
        $direcoes[$chave]['n']++;
        $direcoes[$chave]['valor'] += (float) $m[$campoValor];
        $ruleId = isset($m['rule_id']) && $m['rule_id'] !== null ? (int) $m['rule_id'] : null;
        [$texto, $origem] = $motivo((string) $m['fonte'], $ruleId);
        $origens[$origem] = ($origens[$origem] ?? 0) + 1;
        if ($csv !== null) {
            fputcsv($csv, [
                $slug, $tipo, $m[$campoDoc], substr((string) $m['data_emissao'], 0, 10),
                number_format((float) $m[$campoValor], 2, ',', ''),
                $m['nome_vendedor'] ?? '', $m['nome_cliente'] ?? '',
                $de, $para, $texto, $origem, $ruleId ?? '',
            ], ';', '"', '');
        }
    }

    printf("%s  %s  avaliados=%d  mudam=%d  sem alteração=%d%s\n",
        $slug, $tipo, $r['total'], $r['alteradas'], $r['iguais'], $r['aplicado'] ? '  [GRAVADO]' : '');
    ksort($direcoes);
    foreach ($direcoes as $chave => $d) {
        printf("    %-32s %5d  %16s\n", $chave, $d['n'], $brl($d['valor']));
    }
    if ($origens !== []) {
        arsort($origens);
        $partes = [];
        foreach ($origens as $origem => $n) {
            $partes[] = "{$origem}={$n}";
        }
        echo '    origem das mudanças: ' . implode(', ', $partes) . "\n";
    }
    $lista = $detalhe ? $r['mudancas'] : array_slice($r['mudancas'], 0, 15);
    foreach ($lista as $m) {
        $ruleId = isset($m['rule_id']) && $m['rule_id'] !== null ? (int) $m['rule_id'] : null;
        printf("      %-10s %-10s %-22s %-26s %14s  %s -> %s  (%s)\n",
            $m[$campoDoc],
            substr((string) $m['data_emissao'], 0, 10),
            mb_strimwidth((string) ($m['nome_vendedor'] ?? ''), 0, 22, '…'),
            mb_strimwidth((string) ($m['nome_cliente'] ?? ''), 0, 26, '…'),
            $brl((float) $m[$campoValor]),
            $lado($m['antes']), $lado($m['depois']),
            $motivo((string) $m['fonte'], $ruleId)[0]);
    }
    if (count($lista) < count($r['mudancas'])) {
        printf("      ... e mais %d (use --detalhe ou --csv)\n", count($r['mudancas']) - count($lista));
    }
};

$totalAlteradas = 0;
foreach ($companies as $company) {
    $cid = (int) $company['id'];
    $slug = (string) $company['slug'];
    $repository = new CanonicalWriteRepository(
        static fn (): PDO => $pdo,
        static function () use ($pdo): void { $pdo->beginTransaction(); },
        $hashing,
        $codec,
        $cid,
        'varejo' // usado só por resolveBusinessChannel (títulos), não por NFs/pedidos
    );

    $r = $repository->reclassifyUnclassifiedInvoices($apply, !$todas);
    $totalAlteradas += $r['alteradas'];
    $relatar($slug, 'NFs', $r, 'valor_base_comercial_nf', 'numero_nf');

    if ($pedidos) {
        $p = $repository->reclassifyOrders($apply);
        $totalAlteradas += $p['alteradas'];
        $relatar($slug, 'pedidos', $p, 'valor_mercadorias', 'pedido');
    }

    if ($apply) {
        // Itens e títulos herdam o canal da NF/pedido: sem isto o mix e o
        // financeiro ficariam com o canal antigo.
        $propagado = (new ChannelClassifierService($pdo, $cid))->propagateRelatedChannels(false);
        printf("%s  canal propagado: itens=%d  titulos=%d\n",
            $slug, $propagado['invoice_items'], $propagado['financial_titles']);
    }
    echo "\n";
}

if ($csv !== null) {
    fclose($csv);
    echo "Relatório detalhado: {$csvPath}\n";
}
echo "Total de registros que mudam de canal/visibilidade: {$totalAlteradas}\n";
if (!$apply) {
    echo "Nada foi gravado.\n";
}
