<?php

declare(strict_types=1);

/**
 * Projecao fiscal por regime tributario.
 *
 * A apuracao (mart_fiscal_monthly_settlement) soma apenas o imposto DESTACADO
 * nas notas de saida — correto na origem, incompleto como informacao. Este
 * endpoint devolve o que cada empresa realmente deve, considerando o regime:
 * DAS estimado no Simples, IRPJ/CSLL por fora no Presumido, e a lacuna de
 * credito de entrada no Lucro Real.
 *
 * Sem company_id, projeta TODAS as empresas que o usuario enxerga.
 */

use BIomie\Auth;
use BIomie\Database;
use BIomie\Http;
use BIomie\Modules\Fiscal\Infrastructure\FiscalRegimeProjectionService;
use BIomie\Support\TenantContext;

require __DIR__ . '/bootstrap.php';

Auth::requireAnyCapability(['fiscal.view', 'finance.view'], true);

try {
    $start = (string) Http::query('start', date('Y-01-01'));
    $end   = (string) Http::query('end', date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
        $start = date('Y-01-01');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
        $end = date('Y-m-d');
    }

    // Config explicito em vez do $config global do bootstrap: evita depender de
    // variavel de arquivo incluido (que o PHPStan legitimamente nao enxerga).
    $pdo = Database::connect(new \BIomie\Config(), 'db');

    // Escopo: so as empresas que o contexto do usuario autoriza. Projetar o
    // grupo inteiro para quem enxerga uma empresa seria vazamento.
    $allowed = TenantContext::allowedCompanyIds();
    $companies = [];
    if ($allowed !== []) {
        $placeholders = implode(',', array_map(static fn (int $i): string => ':c' . $i, array_keys($allowed)));
        $stmt = $pdo->prepare(
            "SELECT id, slug FROM biomie_companies
              WHERE ativo = 1 AND id IN ({$placeholders}) ORDER BY id"
        );
        foreach (array_values($allowed) as $i => $cid) {
            $stmt->bindValue(':c' . $i, (int) $cid, \PDO::PARAM_INT);
        }
        $stmt->execute();
        $companies = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    $only = trim((string) Http::query('company_id', ''));
    if ($only !== '') {
        $companies = array_values(array_filter(
            $companies,
            static fn (array $c): bool => (string) $c['id'] === $only
        ));
    }

    if ($companies === []) {
        Http::json([
            'ok' => true,
            'period' => ['start' => $start, 'end' => $end],
            'companies' => [],
            'total_apuracao_atual' => 0,
            'total_projetado' => 0,
            'total_invisivel' => 0,
            'message' => 'Nenhuma empresa no escopo atual.',
        ]);
    }

    $service = new FiscalRegimeProjectionService($pdo);
    $payload = $service->forCompanies($companies, $start, $end);
    $payload['ok'] = true;
    $payload['request_id'] = \BIomie\RequestContext::id();

    Http::json($payload);
} catch (\Throwable $exception) {
    global $logger;
    $logger?->error('fiscal_regime_projection error', [
        'exception' => $exception->getMessage(),
        'request_id' => \BIomie\RequestContext::id(),
    ]);

    Http::json([
        'ok' => false,
        'error' => 'fiscal_regime_projection_error',
        'message' => 'Nao foi possivel projetar os tributos por regime agora.',
        'request_id' => \BIomie\RequestContext::id(),
    ], 500);
}
