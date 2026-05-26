<?php

declare(strict_types=1);

namespace BIomie\SystemHealth\Checks;

use BIomie\Http;
use BIomie\SystemHealth\CheckInterface;
use BIomie\SystemHealth\SystemHealthCheck;
use PDO;
use Throwable;

final class GeoDetailConsistencyHealthCheck implements CheckInterface
{
    public function __construct(private readonly ?PDO $pdo) {}
    public function id(): string    { return 'geo_detail_consistency'; }
    public function label(): string { return 'Geo: propagação de bairro e consistência'; }

    public function run(): SystemHealthCheck
    {
        $start = microtime(true);
        if (!$this->pdo instanceof PDO) {
            return SystemHealthCheck::unknown($this->id(), $this->label(), new \RuntimeException('Banco indisponivel.'), 0);
        }

        if (!Http::tableExists($this->pdo, 'mart_compradores_por_geo')) {
            return SystemHealthCheck::warning($this->id(), $this->label(), 'Mart de compradores por geo nao existe.', [], ['Rodar materializacao geo.'], 0);
        }

        $metrics = [
            'buyer_neighborhoods'            => 0,
            'has_bairro_key_col'             => false,
            'bairro_key_null'                => 0,
            'bairro_norm_with_uppercase'     => 0,
            'bairro_norm_with_accent'        => 0,
            'multiple_names_per_key'         => 0,
            // Propagation checks (critical if > 0)
            'propagation_bug_count'          => 0,
            'bairro_norm_not_propagated'     => 0,
            // Structural issues (warning)
            'null_cidade_conflated'          => 0,
            // Data quality (not a bug — outside Fortaleza mesh or truly missing in Omie)
            'outside_current_mesh'           => 0,
            'probably_omie_missing'          => 0,
            'empty_bairro_with_revenue'      => 0,
            'technical_consistency'          => 'ok',
            'data_quality'                   => [],
        ];

        $propagationIssues  = [];
        $technicalIssues    = [];
        $technicalWarnings  = [];
        $technicalActions   = [];
        $emptyBairroRev     = 0.0;

        // Scope propagation checks to the last 3 months to stay fast.
        $recentStart = date('Y-m', strtotime('-3 months'));
        $recentEnd   = date('Y-m');

        try {
            $hasBairroKey      = $this->colExists('mart_compradores_por_geo', 'bairro_key');
            $hasGeoDim         = Http::tableExists($this->pdo, 'biomie_clientes_geo_dim');
            $hasBairroOficial  = $hasGeoDim && $this->colExists('biomie_clientes_geo_dim', 'bairro_oficial_nome_fortaleza');

            $metrics['has_bairro_key_col'] = $hasBairroKey;

            // ── 1. Total de bairros distintos com faturamento ────────────────

            $metrics['buyer_neighborhoods'] = (int) $this->pdo->query(
                "SELECT COUNT(DISTINCT CONCAT(COALESCE(cidade_norm,''),'|',COALESCE(bairro_norm,'')))
                 FROM mart_compradores_por_geo WHERE COALESCE(faturamento,0) > 0"
            )->fetchColumn();

            // ── 2. Propagation checks (critical if > 0) ──────────────────────

            if ($hasGeoDim && $hasBairroOficial) {
                // Clients with bairro_oficial_nome_fortaleza in geo_dim but empty bairro in mart.
                // This is the GeoBuyerMartBuilder bug (fixed in 2026-05-18).
                // After rematerialization this count must be 0.
                $bugStmt = $this->pdo->prepare(
                    "SELECT COUNT(DISTINCT m.codigo_cliente_omie)
                     FROM mart_compradores_por_geo m
                     JOIN biomie_clientes_geo_dim g ON g.codigo_cliente_omie = m.codigo_cliente_omie
                     WHERE m.competencia BETWEEN :s AND :e
                       AND (m.bairro_norm IS NULL OR TRIM(m.bairro_norm) = '')
                       AND g.bairro_oficial_nome_fortaleza IS NOT NULL
                       AND TRIM(g.bairro_oficial_nome_fortaleza) <> ''
                       AND COALESCE(m.faturamento, 0) > 0"
                );
                $bugStmt->execute([':s' => $recentStart, ':e' => $recentEnd]);
                $metrics['propagation_bug_count'] = (int) $bugStmt->fetchColumn();

                if ($metrics['propagation_bug_count'] > 0) {
                    $propagationIssues[] = $metrics['propagation_bug_count']
                        . ' cliente(s) com bairro_oficial_nome_fortaleza em geo_dim mas bairro vazio no mart'
                        . ' (últimos 3 meses). Rematerialize: php bin/materialize_marts.php';
                }
            }

            if ($hasGeoDim) {
                // Clients with bairro_norm in geo_dim but empty in mart.
                $stmt = $this->pdo->prepare(
                    "SELECT COUNT(DISTINCT m.codigo_cliente_omie)
                     FROM mart_compradores_por_geo m
                     JOIN biomie_clientes_geo_dim g ON g.codigo_cliente_omie = m.codigo_cliente_omie
                     WHERE m.competencia BETWEEN :s AND :e
                       AND (m.bairro_norm IS NULL OR TRIM(m.bairro_norm) = '')
                       AND g.bairro_norm IS NOT NULL AND TRIM(g.bairro_norm) <> ''
                       AND COALESCE(m.faturamento, 0) > 0"
                );
                $stmt->execute([':s' => $recentStart, ':e' => $recentEnd]);
                $metrics['bairro_norm_not_propagated'] = (int) $stmt->fetchColumn();
                if ($metrics['bairro_norm_not_propagated'] > 0) {
                    $propagationIssues[] = $metrics['bairro_norm_not_propagated']
                        . ' cliente(s) com bairro_norm em geo_dim mas bairro vazio no mart (últimos 3 meses).';
                }
            }

            // ── 3. Structural issue: cidade_norm IS NULL conflation ──────────

            $stmt = $this->pdo->prepare(
                "SELECT COUNT(DISTINCT codigo_cliente_omie)
                 FROM mart_compradores_por_geo
                 WHERE competencia BETWEEN :s AND :e
                   AND (cidade_norm IS NULL OR TRIM(cidade_norm) = '')
                   AND COALESCE(faturamento, 0) > 0"
            );
            $stmt->execute([':s' => $recentStart, ':e' => $recentEnd]);
            $metrics['null_cidade_conflated'] = (int) $stmt->fetchColumn();

            // ── 4. Technical bairro_key checks ───────────────────────────────

            if (!$hasBairroKey) {
                $technicalIssues[]  = 'Coluna bairro_key nao existe em mart_compradores_por_geo.';
                $technicalActions[] = 'Execute: php bin/migrate.php (migration Version20260517000002)';
                $technicalActions[] = 'Execute: php tools/dev/geo_keys_backfill.php --apply --table=all';
            } else {
                $metrics['bairro_key_null'] = (int) $this->pdo->query(
                    "SELECT COUNT(*) FROM mart_compradores_por_geo
                     WHERE (bairro_key IS NULL OR bairro_key = '')
                       AND bairro_norm IS NOT NULL AND TRIM(bairro_norm) != ''
                       AND COALESCE(faturamento,0) > 0"
                )->fetchColumn();
                if ($metrics['bairro_key_null'] > 0) {
                    $technicalIssues[]  = $metrics['bairro_key_null'] . ' linhas com bairro_norm preenchido mas sem bairro_key (backfill pendente).';
                    $technicalActions[] = 'Execute: php tools/dev/geo_keys_backfill.php --apply --table=mart_compradores_por_geo';
                }

                $metrics['bairro_norm_with_uppercase'] = (int) $this->pdo->query(
                    "SELECT COUNT(DISTINCT bairro_norm) FROM mart_compradores_por_geo
                     WHERE bairro_norm IS NOT NULL AND bairro_norm != ''
                       AND bairro_norm COLLATE utf8mb4_bin != LOWER(bairro_norm)"
                )->fetchColumn();
                if ($metrics['bairro_norm_with_uppercase'] > 0) {
                    $technicalIssues[] = $metrics['bairro_norm_with_uppercase'] . ' valores de bairro_norm com maiusculas.';
                }

                try {
                    $metrics['bairro_norm_with_accent'] = (int) $this->pdo->query(
                        "SELECT COUNT(DISTINCT bairro_norm) FROM mart_compradores_por_geo
                         WHERE bairro_norm IS NOT NULL AND bairro_norm != ''
                           AND LOWER(bairro_norm) REGEXP '[^a-z0-9 _-]'"
                    )->fetchColumn();
                    if ($metrics['bairro_norm_with_accent'] > 0) {
                        $technicalIssues[] = $metrics['bairro_norm_with_accent'] . ' valores de bairro_norm com acentos (rematerialize).';
                    }
                } catch (Throwable) {
                    $metrics['bairro_norm_with_accent'] = -1;
                }

                $metrics['multiple_names_per_key'] = (int) $this->pdo->query(
                    "SELECT COUNT(*) FROM (
                        SELECT bairro_key, COUNT(DISTINCT COALESCE(bairro_nome, bairro_norm,'')) AS cnt
                        FROM mart_compradores_por_geo
                        WHERE bairro_key IS NOT NULL AND bairro_key != ''
                        GROUP BY bairro_key HAVING cnt > 1
                     ) t"
                )->fetchColumn();
                if ($metrics['multiple_names_per_key'] > 0) {
                    $technicalWarnings[] = $metrics['multiple_names_per_key'] . ' bairro_key com nomes humanos conflitantes.';
                }
            }

            // ── 5. Data quality (outside mesh or truly missing — not a bug) ──

            $metrics['empty_bairro_with_revenue'] = (int) $this->pdo->query(
                "SELECT COUNT(*) FROM mart_compradores_por_geo
                 WHERE (bairro_norm IS NULL OR TRIM(bairro_norm) = '')
                   AND COALESCE(faturamento, 0) > 0"
            )->fetchColumn();

            $emptyBairroRev = (float) $this->pdo->query(
                "SELECT COALESCE(SUM(faturamento),0) FROM mart_compradores_por_geo
                 WHERE (bairro_norm IS NULL OR TRIM(bairro_norm) = '')
                   AND COALESCE(faturamento, 0) > 0"
            )->fetchColumn();

        } catch (Throwable $e) {
            return SystemHealthCheck::unknown($this->id(), $this->label(), $e, (int) round((microtime(true) - $start) * 1000));
        }

        $ms = (int) round((microtime(true) - $start) * 1000);

        $metrics['technical_consistency'] = (count($technicalIssues) === 0 && count($technicalWarnings) === 0 && count($propagationIssues) === 0) ? 'ok' : 'issues';
        $metrics['data_quality'] = [
            'empty_bairro_count'   => $metrics['empty_bairro_with_revenue'],
            'empty_bairro_revenue' => round($emptyBairroRev, 2),
            'null_cidade_conflated' => $metrics['null_cidade_conflated'],
            'explanation' => [
                'propagation_bug'       => 'bairro_oficial_nome_fortaleza em geo_dim mas vazio no mart (bug corrigido — rematerialize)',
                'outside_current_mesh'  => 'cliente fora da malha de bairros de Fortaleza (outra cidade/estado — não é bug)',
                'omie_missing_address'  => 'cliente em Fortaleza sem bairro em nenhuma fonte (dado ausente no Omie)',
                'null_cidade_conflated' => 'cidade_norm NULL incluso no mapa Fortaleza pelo filtro IS NULL (risco semântico)',
            ],
        ];

        if ($metrics['buyer_neighborhoods'] === 0) {
            return SystemHealthCheck::warning(
                $this->id(), $this->label(),
                'Nenhum bairro materializado com faturamento.',
                $metrics,
                ['Rodar: php bin/materialize_marts.php'],
                $ms
            );
        }

        // Propagation bugs are critical — bairro exists in geo_dim but not in mart.
        if (count($propagationIssues) > 0) {
            return SystemHealthCheck::critical(
                $this->id(), $this->label(),
                'Bug de propagação de bairro: ' . implode(' | ', $propagationIssues),
                $metrics,
                ['Rematerialize o mart: php bin/materialize_marts.php ou php tools/dev/geo_holistic_audit.php --json para diagnóstico completo.'],
                $ms
            );
        }

        if (count($technicalIssues) > 0) {
            return SystemHealthCheck::critical(
                $this->id(), $this->label(),
                'Inconsistencias tecnicas no mapa geo: ' . implode(' | ', $technicalIssues),
                $metrics,
                $technicalActions,
                $ms
            );
        }

        if (count($technicalWarnings) > 0) {
            return SystemHealthCheck::warning(
                $this->id(), $this->label(),
                'Avisos de nomenclatura geo: ' . implode(' | ', $technicalWarnings),
                $metrics,
                ['Manter conflitos documentados ou padronizar nomes humanos em fase geo dedicada.'],
                $ms
            );
        }

        if ($metrics['null_cidade_conflated'] > 0) {
            return SystemHealthCheck::warning(
                $this->id(), $this->label(),
                $metrics['null_cidade_conflated'] . ' cliente(s) com cidade_norm nula aparecem no mapa Fortaleza devido ao filtro IS NULL (risco semântico).',
                $metrics,
                ['Verificar biomie_clientes_geo_dim.cidade_norm para clientes sem cidade cadastrada.'],
                $ms
            );
        }

        if ($metrics['empty_bairro_with_revenue'] > 0) {
            return SystemHealthCheck::warning(
                $this->id(), $this->label(),
                $metrics['empty_bairro_with_revenue'] . ' registro(s) sem bairro resolvido. '
                    . 'Possíveis causas: clientes fora da malha de Fortaleza ou dado ausente no Omie (não é bug de propagação).',
                $metrics,
                ['Rodar php tools/dev/geo_holistic_audit.php --only-missing-bairro --json para classificar cada caso.'],
                $ms
            );
        }

        return SystemHealthCheck::ok(
            $this->id(), $this->label(),
            $metrics['buyer_neighborhoods'] . ' bairros com faturamento. Propagação e consistência geo OK.',
            $metrics,
            $ms
        );
    }

    private function colExists(string $table, string $col): bool
    {
        try {
            return (int) $this->pdo->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' AND COLUMN_NAME = '{$col}'"
            )->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }
}
