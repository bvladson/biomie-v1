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
    public function label(): string { return 'Geo: bairro_key e consistencia'; }

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
            'buyer_neighborhoods' => 0,
            'bairro_key_null'     => 0,
            'bairro_norm_with_uppercase' => 0,
            'bairro_norm_with_accent'    => 0,
            'multiple_names_per_key'     => 0,
            'empty_key_with_revenue'     => 0,
            'has_bairro_key_col'         => false,
        ];
        $issues   = [];
        $actions  = [];

        try {
            $hasBairroKey = $this->colExists('mart_compradores_por_geo', 'bairro_key');
            $metrics['has_bairro_key_col'] = $hasBairroKey;

            // 1. Total de bairros distintos com faturamento
            $metrics['buyer_neighborhoods'] = (int) $this->pdo->query(
                "SELECT COUNT(DISTINCT CONCAT(COALESCE(cidade_norm,''),'|',COALESCE(bairro_norm,'')))
                 FROM mart_compradores_por_geo WHERE COALESCE(faturamento,0) > 0"
            )->fetchColumn();

            if ($hasBairroKey) {
                // 2. Linhas com bairro_key NULL mas com faturamento
                $metrics['bairro_key_null'] = (int) $this->pdo->query(
                    "SELECT COUNT(*) FROM mart_compradores_por_geo
                     WHERE (bairro_key IS NULL OR bairro_key = '') AND COALESCE(faturamento,0) > 0"
                )->fetchColumn();
                if ($metrics['bairro_key_null'] > 0) {
                    $issues[] = $metrics['bairro_key_null'] . ' linhas com faturamento sem bairro_key.';
                    $actions[] = 'Execute: php tools/dev/geo_keys_backfill.php --apply --table=mart_compradores_por_geo';
                }

                // 5. Multiplos nomes humanos para o mesmo bairro_key
                $metrics['multiple_names_per_key'] = (int) $this->pdo->query(
                    "SELECT COUNT(*) FROM (
                        SELECT bairro_key, COUNT(DISTINCT COALESCE(bairro_nome, bairro_norm,'')) AS cnt
                        FROM mart_compradores_por_geo
                        WHERE bairro_key IS NOT NULL AND bairro_key != ''
                        GROUP BY bairro_key HAVING cnt > 1
                     ) t"
                )->fetchColumn();
                if ($metrics['multiple_names_per_key'] > 0) {
                    $issues[] = $metrics['multiple_names_per_key'] . ' bairro_key com multiplos nomes humanos (conflito de exibicao).';
                }
            } else {
                $issues[] = 'Coluna bairro_key nao existe em mart_compradores_por_geo.';
                $actions[] = 'Execute a migration 20260517000002: php bin/migrate.php';
                $actions[] = 'Depois faca backfill: php tools/dev/geo_keys_backfill.php --apply --table=all';
            }

            // 3. bairro_norm com maiusculas (deve ser sempre lowercase)
            $metrics['bairro_norm_with_uppercase'] = (int) $this->pdo->query(
                "SELECT COUNT(DISTINCT bairro_norm) FROM mart_compradores_por_geo
                 WHERE bairro_norm IS NOT NULL AND bairro_norm != '' AND bairro_norm COLLATE utf8mb4_bin != LOWER(bairro_norm)"
            )->fetchColumn();
            if ($metrics['bairro_norm_with_uppercase'] > 0) {
                $issues[] = $metrics['bairro_norm_with_uppercase'] . ' valores distintos de bairro_norm com maiusculas (inconsistencia legada).';
            }

            // 4. bairro_norm com acentos (hex check via REGEXP on latin chars outside a-z0-9)
            try {
                $metrics['bairro_norm_with_accent'] = (int) $this->pdo->query(
                    "SELECT COUNT(DISTINCT bairro_norm) FROM mart_compradores_por_geo
                     WHERE bairro_norm IS NOT NULL AND bairro_norm != ''
                       AND LOWER(bairro_norm) REGEXP '[^a-z0-9 _-]'"
                )->fetchColumn();
                if ($metrics['bairro_norm_with_accent'] > 0) {
                    $issues[] = $metrics['bairro_norm_with_accent'] . ' valores de bairro_norm contendo acentos ou caracteres especiais.';
                    $actions[] = 'Rematerialize: php bin/materialize_marts.php (os builders agora normalizam corretamente).';
                }
            } catch (Throwable) {
                $metrics['bairro_norm_with_accent'] = -1;
            }

            // 6. Linhas com faturamento mas bairro_norm vazio
            $metrics['empty_key_with_revenue'] = (int) $this->pdo->query(
                "SELECT COUNT(*) FROM mart_compradores_por_geo
                 WHERE (bairro_norm IS NULL OR TRIM(bairro_norm) = '')
                   AND COALESCE(faturamento, 0) > 0"
            )->fetchColumn();
            if ($metrics['empty_key_with_revenue'] > 0) {
                $issues[] = $metrics['empty_key_with_revenue'] . ' linhas com faturamento sem bairro_norm (nao aparecem no mapa).';
                $actions[] = 'Verifique enriquecimento de endereco em biomie_clientes_geo_dim.';
            }

        } catch (Throwable $e) {
            return SystemHealthCheck::unknown($this->id(), $this->label(), $e, (int) round((microtime(true) - $start) * 1000));
        }

        $ms = (int) round((microtime(true) - $start) * 1000);

        if ($metrics['buyer_neighborhoods'] === 0) {
            return SystemHealthCheck::warning(
                $this->id(), $this->label(),
                'Nenhum bairro materializado com faturamento.',
                $metrics,
                ['Rodar: php bin/materialize_marts.php'],
                $ms
            );
        }

        $criticalCount = ($metrics['bairro_key_null'] ?? 0)
            + ($metrics['has_bairro_key_col'] ? 0 : 1);

        if ($criticalCount > 0) {
            return SystemHealthCheck::critical(
                $this->id(), $this->label(),
                'Inconsistencias criticas no mapa geo: ' . implode(' | ', $issues),
                $metrics,
                $actions,
                $ms
            );
        }

        $warnCount = ($metrics['bairro_norm_with_uppercase'] ?? 0)
            + ($metrics['bairro_norm_with_accent'] > 0 ? $metrics['bairro_norm_with_accent'] : 0)
            + ($metrics['multiple_names_per_key'] ?? 0)
            + ($metrics['empty_key_with_revenue'] ?? 0);

        if ($warnCount > 0) {
            return SystemHealthCheck::warning(
                $this->id(), $this->label(),
                'Inconsistencias de normalizacao geo (drilldown pode ter fallback): ' . implode(' | ', $issues),
                $metrics,
                array_merge($actions, ['Endpoints tem fallback COLLATE; backfill e recomendado para performance.']),
                $ms
            );
        }

        return SystemHealthCheck::ok(
            $this->id(), $this->label(),
            $metrics['buyer_neighborhoods'] . ' bairros com faturamento. bairro_key consistente.',
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
