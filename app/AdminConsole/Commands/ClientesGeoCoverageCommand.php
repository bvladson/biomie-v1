<?php

declare(strict_types=1);

namespace BIomie\AdminConsole\Commands;

use BIomie\AdminConsole\AdminCommandContext;
use BIomie\AdminConsole\AdminCommandInterface;
use BIomie\AdminConsole\AdminCommandResult;
use BIomie\Shared\Database\ColumnResolver;
use BIomie\Support\DocumentMask;
use PDO;
use Throwable;

/**
 * Diagnoses GEO coverage of biomie_clientes_dim, showing how many clients
 * have address data vs. how much revenue is at risk without enrichment.
 */
final class ClientesGeoCoverageCommand implements AdminCommandInterface
{
    private readonly ColumnResolver $resolver;

    public function __construct(private readonly PDO $pdo)
    {
        $this->resolver = new ColumnResolver($pdo);
    }

    public function name(): string        { return 'clientes:geo-coverage'; }
    public function description(): string { return 'Cobertura GEO dos clientes: mostra totais com cidade/bairro/match, faturamento com e sem bairro, top 20 compradores sem bairro.'; }
    public function group(): string       { return 'clientes'; }
    public function permission(): string  { return 'admin.console'; }
    public function isDestructive(): bool { return false; }
    public function requiresConfirmation(): bool { return false; }

    public function validateArgs(array $args): ?string { return null; }

    public function run(AdminCommandContext $context, array $args = []): AdminCommandResult
    {
        $startedAt = microtime(true);
        $lines = [];

        // ── 1. biomie_clientes_dim coverage ──────────────────────────────────
        $lines[] = '── biomie_clientes_dim ──────────────────────────────────────────';

        try {
            // bairro normalizado pode se chamar bairro_normalizado (migration 20260502) ou bairro_norm (geo_dim)
            $bairroNormCol = null;
            foreach (['bairro_normalizado', 'bairro_norm'] as $candidate) {
                if ($this->resolver->hasColumn('biomie_clientes_dim', $candidate)) {
                    $bairroNormCol = $candidate;
                    break;
                }
            }
            $bairroNormExpr = $bairroNormCol !== null
                ? "SUM(CASE WHEN `{$bairroNormCol}` IS NOT NULL AND `{$bairroNormCol}` <> '' THEN 1 ELSE 0 END)"
                : '0';

            $row = $this->pdo->query(
                "SELECT
                    COUNT(*) AS total,
                    SUM(CASE WHEN codigo_cliente_omie IS NOT NULL AND codigo_cliente_omie > 0 THEN 1 ELSE 0 END) AS com_codigo_omie,
                    SUM(CASE WHEN cidade IS NOT NULL AND cidade <> '' THEN 1 ELSE 0 END) AS com_cidade,
                    SUM(CASE WHEN bairro IS NOT NULL AND bairro <> '' THEN 1 ELSE 0 END) AS com_bairro,
                    {$bairroNormExpr} AS com_bairro_norm,
                    SUM(CASE WHEN cnae_codigo IS NOT NULL AND cnae_codigo <> '' THEN 1 ELSE 0 END) AS com_cnae,
                    SUM(CASE WHEN (cidade IS NULL OR cidade = '') AND (bairro IS NULL OR bairro = '') THEN 1 ELSE 0 END) AS sem_endereco,
                    SUM(CASE WHEN cnae_codigo IS NULL OR cnae_codigo = '' THEN 1 ELSE 0 END) AS sem_cnae
                 FROM biomie_clientes_dim"
            )?->fetch(PDO::FETCH_ASSOC);

            $total       = (int) ($row['total'] ?? 0);
            $comCodigo   = (int) ($row['com_codigo_omie'] ?? 0);
            $comCidade   = (int) ($row['com_cidade'] ?? 0);
            $comBairro   = (int) ($row['com_bairro'] ?? 0);
            $comNorm     = (int) ($row['com_bairro_norm'] ?? 0);
            $comCnae     = (int) ($row['com_cnae'] ?? 0);
            $semEndereco = (int) ($row['sem_endereco'] ?? 0);
            $semCnae     = (int) ($row['sem_cnae'] ?? 0);

            $pct = static fn(int $n, int $d): string => $d > 0 ? sprintf('%d%%', (int) round($n / $d * 100)) : '-';

            $lines[] = sprintf('Total clientes          : %d', $total);
            $lines[] = sprintf('Com codigo Omie         : %d  (%s)', $comCodigo, $pct($comCodigo, $total));
            $lines[] = sprintf('Com cidade              : %d  (%s)', $comCidade, $pct($comCidade, $total));
            $lines[] = sprintf('Com bairro              : %d  (%s)', $comBairro, $pct($comBairro, $total));
            $lines[] = sprintf('Com bairro_norm         : %d  (%s)', $comNorm, $pct($comNorm, $total));
            $lines[] = sprintf('Com CNAE                : %d  (%s)', $comCnae, $pct($comCnae, $total));
            $lines[] = sprintf('Sem endereco (geo gaps)  : %d  (%s)', $semEndereco, $pct($semEndereco, $total));
            $lines[] = sprintf('Sem CNAE                : %d  (%s)', $semCnae, $pct($semCnae, $total));
        } catch (Throwable $e) {
            $lines[] = 'ERRO ao ler biomie_clientes_dim: ' . $e->getMessage();
        }

        // ── 2. Revenue split by bairro presence ──────────────────────────────
        $lines[] = '';
        $lines[] = '── Faturamento por cobertura de bairro ──────────────────────────';

        $revComBairro = 0.0;
        $revSemBairro = 0.0;

        foreach (['mart_compradores_por_geo', 'mart_cliente_periodo'] as $martTable) {
            try {
                $probe = $this->pdo->query("SELECT 1 FROM `{$martTable}` LIMIT 0");
                if ($probe === false) { continue; }

                $hasRevCol = $this->resolver->hasColumn($martTable, 'faturamento');
                if (!$hasRevCol) { continue; }

                $bairroCol = $this->resolver->hasColumn($martTable, 'bairro_norm') ? 'bairro_norm'
                    : ($this->resolver->hasColumn($martTable, 'bairro') ? 'bairro' : null);

                if ($bairroCol === null) { continue; }

                $revRow = $this->pdo->query(
                    "SELECT
                        SUM(CASE WHEN {$bairroCol} IS NOT NULL AND TRIM({$bairroCol}) <> '' THEN faturamento ELSE 0 END) AS com_bairro,
                        SUM(CASE WHEN {$bairroCol} IS NULL OR TRIM({$bairroCol}) = '' THEN faturamento ELSE 0 END) AS sem_bairro
                     FROM `{$martTable}`"
                )?->fetch(PDO::FETCH_ASSOC);

                $revComBairro = (float) ($revRow['com_bairro'] ?? 0);
                $revSemBairro = (float) ($revRow['sem_bairro'] ?? 0);
                $revTotal     = $revComBairro + $revSemBairro;

                $lines[] = sprintf('Tabela: %s', $martTable);
                $lines[] = sprintf('  Faturamento COM bairro : R$ %s  (%s)', number_format($revComBairro, 2, ',', '.'), $pct((int) $revComBairro, (int) $revTotal));
                $lines[] = sprintf('  Faturamento SEM bairro : R$ %s  (%s)', number_format($revSemBairro, 2, ',', '.'), $pct((int) $revSemBairro, (int) $revTotal));
                $lines[] = sprintf('  Total                  : R$ %s', number_format($revTotal, 2, ',', '.'));
                break;
            } catch (Throwable) {}
        }

        // ── 3. Clients with sales but no bairro ──────────────────────────────
        $lines[] = '';
        $lines[] = '── Top 20 compradores SEM bairro (elegíveis para enriquecimento) ─';

        try {
            $salesUnion = null;
            foreach (['mart_cliente_periodo', 'mart_fiscal_nfe', 'biomie_vendas_geo_cliente_fact'] as $t) {
                try {
                    $this->pdo->query("SELECT 1 FROM `{$t}` LIMIT 0");
                    $salesUnion = $t;
                    break;
                } catch (Throwable) {}
            }

            if ($salesUnion !== null) {
                $revCol  = $this->resolver->hasColumn($salesUnion, 'faturamento') ? 'faturamento' : '0';
                $nameCol = $this->resolver->hasColumn($salesUnion, 'cliente_nome') ? 'cliente_nome'
                    : ($this->resolver->hasColumn($salesUnion, 'customer_name') ? 'customer_name' : 'NULL');
                $docCol  = $this->resolver->hasColumn($salesUnion, 'documento') ? 'documento'
                    : ($this->resolver->hasColumn($salesUnion, 'documento_normalizado') ? 'documento_normalizado' : 'NULL');

                $docCol = $this->resolver->hasColumn('biomie_clientes_dim', 'documento_normalizado')
                    ? 'cd.documento_normalizado' : 'cd.cpf_cnpj';

                $rows = $this->pdo->query(
                    "SELECT
                        cd.codigo_cliente_omie,
                        cd.nome_cliente AS razao_social,
                        {$docCol} AS documento_normalizado,
                        COALESCE(SUM(s.{$revCol}), 0) AS revenue
                     FROM biomie_clientes_dim cd
                     INNER JOIN `{$salesUnion}` s ON s.codigo_cliente_omie = cd.codigo_cliente_omie
                     WHERE (cd.bairro IS NULL OR cd.bairro = '')
                       AND cd.codigo_cliente_omie IS NOT NULL AND cd.codigo_cliente_omie > 0
                     GROUP BY cd.codigo_cliente_omie, cd.nome_cliente, {$docCol}
                     ORDER BY revenue DESC
                     LIMIT 20"
                )?->fetchAll(PDO::FETCH_ASSOC) ?: [];

                if ($rows === []) {
                    $lines[] = '  Nenhum comprador encontrado sem bairro nessa fonte.';
                } else {
                    $lines[] = sprintf('  %-8s  %-40s  %-20s  %s', 'Cod.Omie', 'Razao Social', 'Documento', 'Faturamento');
                    $lines[] = '  ' . str_repeat('-', 90);
                    foreach ($rows as $r) {
                        $doc = DocumentMask::maskDocument(
                            is_string($r['documento_normalizado'] ?? null) ? $r['documento_normalizado'] : null,
                            'Nao informado'
                        ) ?? 'Nao informado';
                        $lines[] = sprintf(
                            '  %-8d  %-40s  %-20s  R$ %s',
                            (int) ($r['codigo_cliente_omie'] ?? 0),
                            mb_substr((string) ($r['razao_social'] ?? 'Sem nome'), 0, 40),
                            $doc,
                            number_format((float) ($r['revenue'] ?? 0), 2, ',', '.')
                        );
                    }
                }
            } else {
                $lines[] = '  Nenhuma mart de vendas disponivel (mart_cliente_periodo, mart_fiscal_nfe, biomie_vendas_geo_cliente_fact).';
            }
        } catch (Throwable $e) {
            $lines[] = '  ERRO ao consultar compradores sem bairro: ' . $e->getMessage();
        }

        // ── 4. Enrichment eligibility summary ────────────────────────────────
        $lines[] = '';
        $lines[] = '── Elegibilidade para enriquecimento ────────────────────────────';
        try {
            $eligGeo = (int) $this->pdo->query(
                "SELECT COUNT(*) FROM biomie_clientes_dim
                 WHERE codigo_cliente_omie IS NOT NULL AND codigo_cliente_omie > 0
                   AND (cidade IS NULL OR cidade = '' OR bairro IS NULL OR bairro = '')"
            )?->fetchColumn();
            $eligCnae = (int) $this->pdo->query(
                "SELECT COUNT(*) FROM biomie_clientes_dim
                 WHERE codigo_cliente_omie IS NOT NULL AND codigo_cliente_omie > 0
                   AND (cnae_codigo IS NULL OR cnae_codigo = '')"
            )?->fetchColumn();
            $lines[] = sprintf('Elegíveis target=geo    : %d', $eligGeo);
            $lines[] = sprintf('Elegíveis target=cnae   : %d', $eligCnae);
            $lines[] = '';
            $lines[] = 'Proximos passos:';
            $lines[] = '  clientes:enrich-omie-all target=geo source=sales_buyers  → enderecos dos que mais compram';
            $lines[] = '  clientes:enrich-omie-all target=cnae source=clientes_dim → CNAE de todos os clientes';
        } catch (Throwable $e) {
            $lines[] = 'ERRO ao calcular elegibilidade: ' . $e->getMessage();
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        return AdminCommandResult::ok(
            'Cobertura GEO dos clientes',
            sprintf('Total: %d clientes | Com bairro: %d | Sem bairro (GEO gap): %d', $total ?? 0, $comBairro ?? 0, $semEndereco ?? 0),
            outputLines: $lines,
            metrics: [
                'total_clientes'   => $total ?? 0,
                'com_bairro'       => $comBairro ?? 0,
                'sem_endereco'     => $semEndereco ?? 0,
                'sem_cnae'         => $semCnae ?? 0,
                'rev_com_bairro'   => $revComBairro,
                'rev_sem_bairro'   => $revSemBairro,
                'duration_ms'      => $durationMs,
            ],
        )->withDurationMs($durationMs);
    }

}
