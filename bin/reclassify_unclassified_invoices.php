<?php

declare(strict_types=1);

/**
 * Reaplica a classificação de canal às NFs que nunca a receberam.
 *
 * Uso:
 *   php bin/reclassify_unclassified_invoices.php --company=SLUG|all           (simula)
 *   php bin/reclassify_unclassified_invoices.php --company=SLUG|all --apply   (grava)
 *
 * SIMULA POR PADRÃO. Sem --apply nada é gravado: o comando lista, por empresa,
 * quantas NFs estão sem classificação, quais mudariam de canal/visibilidade e
 * quanto de base comercial entra ou sai do escopo comercial (Orç-PD).
 *
 * Usa o mesmo caminho da escrita canônica (CanonicalWriteRepository::
 * reclassifyUnclassifiedInvoices): override manual > regra de vendedor >
 * classificador. Não cria nem altera regra. Só toca NFs com
 * channel_classified_at NULL.
 *
 * Contexto: em 2026 as NFs sincronizadas entre 30/06 e 23/07 foram gravadas
 * sem classificação e nunca reprocessadas, ficando fora das regras de venda
 * intra-grupo e com licitações visíveis no escopo comercial.
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

$args = array_slice($argv ?? [], 1);
$apply = in_array('--apply', $args, true);
$companyArg = '';
foreach ($args as $arg) {
    if (str_starts_with($arg, '--company=')) {
        $companyArg = substr($arg, strlen('--company='));
    }
}
if ($companyArg === '') {
    fwrite(STDERR, "Uso: php bin/reclassify_unclassified_invoices.php --company=SLUG|all [--apply]\n");
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

$codec = new CanonicalJsonCodec();
$hashing = new CanonicalHashing($codec);
$brl = static fn (float $v): string => 'R$ ' . number_format($v, 2, ',', '.');

echo $apply ? "MODO: GRAVAR (--apply)\n\n" : "MODO: SIMULACAO (nada sera gravado; use --apply para gravar)\n\n";

$totalAlteradas = 0;
foreach ($companies as $company) {
    $cid = (int) $company['id'];
    $repository = new CanonicalWriteRepository(
        static fn (): PDO => $pdo,
        static function () use ($pdo): void { $pdo->beginTransaction(); },
        $hashing,
        $codec,
        $cid,
        'varejo' // usado só por resolveBusinessChannel (pedidos/títulos), não por NFs
    );

    $r = $repository->reclassifyUnclassifiedInvoices($apply);

    $sai = 0.0;
    $entra = 0.0;
    foreach ($r['mudancas'] as $m) {
        if ($m['antes']['visible'] === 1 && $m['depois']['visible'] === 0) {
            $sai += $m['valor_base_comercial_nf'];
        }
        if ($m['antes']['visible'] === 0 && $m['depois']['visible'] === 1) {
            $entra += $m['valor_base_comercial_nf'];
        }
    }
    $totalAlteradas += $r['alteradas'];

    printf(
        "%-12s sem classificacao=%d  mudam=%d  iguais=%d  sai do comercial=%s  entra no comercial=%s%s\n",
        $company['slug'], $r['total'], $r['alteradas'], $r['iguais'], $brl($sai), $brl($entra),
        $r['aplicado'] ? '  [GRAVADO]' : ''
    );
    foreach ($r['mudancas'] as $m) {
        printf(
            "    NF %-9s %-10s %-32s %14s  %s/%d -> %s/%d  (%s%s)\n",
            $m['numero_nf'],
            substr((string) $m['data_emissao'], 0, 10),
            mb_strimwidth((string) $m['nome_cliente'], 0, 32, '…'),
            $brl($m['valor_base_comercial_nf']),
            $m['antes']['channel'], $m['antes']['visible'],
            $m['depois']['channel'], $m['depois']['visible'],
            $m['fonte'],
            $m['rule_id'] ? ' #' . $m['rule_id'] : ''
        );
    }
}

echo "\nTotal de NFs que mudariam de canal/visibilidade: {$totalAlteradas}\n";
if (!$apply) {
    echo "Nada foi gravado.\n";
}
