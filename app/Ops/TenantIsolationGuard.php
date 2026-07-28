<?php

declare(strict_types=1);

namespace BIomie\Ops;

use BIomie\Config;
use BIomie\DataPlatform\Repository\CnaeReadRepository;
use BIomie\GoldMartRepository;
use BIomie\Logger;
use BIomie\Support\TenantContext;
use PDO;
use Throwable;

/**
 * Guard de ISOLAMENTO MULTI-EMPRESA das leituras da camada gold (mart_*).
 *
 * Para CADA empresa ativa, boota o tenant, roda os readers de verdade e
 * compara o valor retornado com a FATIA daquela empresa calculada direto do
 * mart. Se um reader perder o filtro de company_id (regressão), ele devolve o
 * ranking/total GLOBAL — e a comparacao falha (item com fatia ~0 mas valor
 * alto = contaminacao de outra empresa). Foi exatamente a classe do bug de
 * 2026-06-27 (MAG vendo clientes da ERGO).
 *
 * Roda barato; pensado para ser chamado pelo OpsSentinel a cada ciclo, de modo
 * que qualquer vazamento futuro dispare alerta no Telegram em minutos.
 */
final class TenantIsolationGuard
{
    /** Tolerância absoluta (R$) para diferenças de arredondamento. */
    private const TOL = 1.0;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @return array{ok:bool,companies:int,checks:int,failures:list<array<string,mixed>>}
     */
    public function run(): array
    {
        $end = date('Y-m-d');
        $start = date('Y-m-01', strtotime('-2 months'));
        $competencias = $this->competencias($start, $end);
        $startComp = $competencias[0] ?? date('Y-m');
        $endComp = end($competencias) ?: date('Y-m');

        $gold = new GoldMartRepository($this->pdo, $this->logger);
        $cnae = new CnaeReadRepository($this->pdo);

        $companies = $this->activeCompanies();
        $failures = [];
        $checks = 0;

        foreach ($companies as $cid => $slug) {
            try {
                TenantContext::bootForCompany($this->pdo, $cid);
            } catch (Throwable $e) {
                $failures[] = ['company' => $slug, 'probe' => 'boot', 'detail' => $e->getMessage()];
                continue;
            }

            // ── Probe 1: top clientes (mart_cliente_periodo) ──────────────────
            $checks++;
            foreach ($this->safe(fn () => $gold->topClientsCommercial($start, $end, 5)) as $row) {
                $cod = (int) ($row['codigo_cliente_omie'] ?? 0);
                $repoVal = (float) ($row['invoice_total'] ?? 0);
                if ($cod <= 0 || $repoVal <= self::TOL) {
                    continue;
                }
                $own = $this->ownSum('mart_cliente_periodo', 'valor_nfe', 'codigo_cliente_omie', (string) $cod, $cid, $competencias);
                if ($repoVal > $own + self::TOL) {
                    $failures[] = $this->leak($slug, 'gold.topClients', 'cliente=' . $cod, $repoVal, $own);
                    break;
                }
            }

            // ── Probe 2: top produtos (mart_produto_periodo) ──────────────────
            $checks++;
            foreach ($this->safe(fn () => $gold->topProductsCommercial($start, $end, 5)) as $row) {
                $cod = trim((string) ($row['product_code'] ?? ''));
                $repoVal = (float) ($row['revenue'] ?? 0);
                if ($cod === '' || $repoVal <= self::TOL) {
                    continue;
                }
                $own = $this->ownSum('mart_produto_periodo', 'valor_total', 'codigo_produto', $cod, $cid, $competencias);
                if ($repoVal > $own + self::TOL) {
                    $failures[] = $this->leak($slug, 'gold.topProducts', 'produto=' . $cod, $repoVal, $own);
                    break;
                }
            }

            // ── Probe 3: faturamento total (mart_vendedor_dia) ────────────────
            $checks++;
            $summary = $this->safe(fn () => $gold->summarizeInvoicesCommercial($start, $end));
            $repoBilled = (float) ($summary['billed_value'] ?? 0);
            if ($repoBilled > self::TOL) {
                $own = $this->ownBilled($cid, $start, $end);
                if ($repoBilled > $own + max(self::TOL, $own * 0.005)) {
                    $failures[] = $this->leak($slug, 'gold.summarizeInvoices', 'billed_value', $repoBilled, $own);
                }
            }

            // ── Probe 4: CNAE freshness rows_total (mart_vendas_por_cnae_cnpj) ─
            $checks++;
            $fresh = $this->safe(fn () => $cnae->freshness());
            $repoRows = (int) ($fresh['rows_total'] ?? 0);
            if ($repoRows > 0) {
                $ownRows = (int) $this->scalar(
                    'SELECT COUNT(*) FROM mart_vendas_por_cnae_cnpj WHERE company_id = :c',
                    [':c' => $cid]
                );
                $globalRows = (int) $this->scalar('SELECT COUNT(*) FROM mart_vendas_por_cnae_cnpj', []);
                // vazamento: reader devolve a contagem global em vez da da empresa
                if ($repoRows !== $ownRows && $repoRows === $globalRows && $globalRows !== $ownRows) {
                    $failures[] = $this->leak($slug, 'cnae.freshness', 'rows_total', (float) $repoRows, (float) $ownRows);
                }
            }
        }

        $ok = $failures === [];
        if (!$ok) {
            $this->logger->error('TenantIsolationGuard: VAZAMENTO entre empresas detectado.', ['failures' => $failures]);
        }

        return [
            'ok' => $ok,
            'companies' => count($companies),
            'checks' => $checks,
            'period' => ['start' => $start, 'end' => $end, 'comps' => $startComp . '..' . $endComp],
            'failures' => $failures,
        ];
    }

    /**
     * @param array<string,mixed> $result
     * @return list<string> linhas de alerta quando ha falha
     */
    public function alertLines(array $result): array
    {
        $lines = ['Vazamento de dados entre empresas detectado nas leituras gold:'];
        foreach ((array) ($result['failures'] ?? []) as $f) {
            $lines[] = sprintf(
                '%s · %s · %s: reader=R$%s vs fatia=R$%s',
                (string) ($f['company'] ?? '?'),
                (string) ($f['probe'] ?? '?'),
                (string) ($f['detail'] ?? ''),
                number_format((float) ($f['reader'] ?? 0), 0, ',', '.'),
                number_format((float) ($f['own'] ?? 0), 0, ',', '.'),
            );
        }
        $lines[] = 'Verifique TenantContext::sqlFilter() nas queries de mart_ recém-alteradas.';
        return $lines;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @return array<int,string> cid => slug */
    private function activeCompanies(): array
    {
        $rows = $this->pdo->query('SELECT id, slug FROM biomie_companies WHERE ativo = 1 ORDER BY id')
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id']] = (string) $r['slug'];
        }
        return $out;
    }

    /** @param list<string> $competencias */
    private function ownSum(string $table, string $valueCol, string $keyCol, string $key, int $cid, array $competencias): float
    {
        if ($competencias === []) {
            return 0.0;
        }
        $ph = implode(',', array_map(static fn (int $i): string => ':m' . $i, array_keys($competencias)));
        $params = [':c' => $cid, ':k' => $key];
        foreach ($competencias as $i => $m) {
            $params[':m' . $i] = $m;
        }
        return (float) $this->scalar(
            "SELECT COALESCE(SUM({$valueCol}),0) FROM {$table}
              WHERE company_id = :c AND {$keyCol} = :k AND competencia IN ({$ph})",
            $params
        );
    }

    private function ownBilled(int $cid, string $start, string $end): float
    {
        return (float) $this->scalar(
            'SELECT COALESCE(SUM(valor_faturado),0) FROM mart_vendedor_dia
              WHERE company_id = :c AND dia BETWEEN :s AND :e AND pedidos_faturados > 0',
            [':c' => $cid, ':s' => $start, ':e' => $end]
        );
    }

    /** @param array<string,mixed> $params */
    private function scalar(string $sql, array $params): mixed
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @param callable():mixed $fn
     * @return array<int|string,mixed>
     */
    private function safe(callable $fn): array
    {
        try {
            $r = $fn();
            return is_array($r) ? $r : [];
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<string,mixed> */
    private function leak(string $slug, string $probe, string $detail, float $reader, float $own): array
    {
        return ['company' => $slug, 'probe' => $probe, 'detail' => $detail, 'reader' => $reader, 'own' => $own];
    }

    /** @return list<string> */
    private function competencias(string $start, string $end): array
    {
        $cursor = new \DateTimeImmutable(date('Y-m-01', strtotime($start)));
        $limit = new \DateTimeImmutable(date('Y-m-01', strtotime($end)));
        $out = [];
        while ($cursor <= $limit) {
            $out[] = $cursor->format('Y-m');
            $cursor = $cursor->modify('+1 month');
        }
        return $out;
    }
}
