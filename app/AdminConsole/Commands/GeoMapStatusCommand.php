<?php

declare(strict_types=1);

namespace BIomie\AdminConsole\Commands;

use BIomie\AdminConsole\AdminCommandContext;
use BIomie\AdminConsole\AdminCommandInterface;
use BIomie\AdminConsole\AdminCommandResult;
use BIomie\Support\GeoMapSupport;
use BIomie\Support\MapFilterSupport;
use PDO;
use Throwable;

final class GeoMapStatusCommand implements AdminCommandInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function name(): string { return 'geo:map-status'; }
    public function description(): string { return 'Diagnostica o mapa de vendas: filtros, data column, rows, faturamento, bairros, motivo_zero.'; }
    public function group(): string { return 'geo'; }
    public function permission(): string { return 'admin.console'; }
    public function isDestructive(): bool { return false; }
    public function requiresConfirmation(): bool { return false; }

    public function validateArgs(array $args): ?string { return null; }

    public function run(AdminCommandContext $context, array $args = []): AdminCommandResult
    {
        $lines = [];

        // Build synthetic $_GET from CLI args
        $get = [];
        foreach (['date_from', 'date_to', 'city', 'neighborhood', 'cnae', 'seller_id'] as $key) {
            if (isset($args[$key]) && $args[$key] !== '') {
                $get[$key] = $args[$key];
            }
        }

        $f            = MapFilterSupport::fromRequest($get);
        $cityNorm     = $f['city'] ?? GeoMapSupport::normalizeKey('Fortaleza');
        $neighborhoodNorm = $f['neighborhood'];

        $lines[] = '=== Filtros recebidos ===';
        foreach ($f['filters_received'] as $k => $v) {
            $lines[] = sprintf('  %-16s: %s', $k, $v ?? '(nao informado)');
        }

        $lines[] = '';
        $lines[] = '=== Filtros normalizados ===';
        foreach ($f['filters_normalized'] as $k => $v) {
            $lines[] = sprintf('  %-16s: %s', $k, $v ?? '(nulo)');
        }

        $lines[] = '';
        $lines[] = '=== Periodo ===';
        $lines[] = "  date_from  : {$f['date_from']}";
        $lines[] = "  date_to    : {$f['date_to']}";
        $lines[] = "  start_comp : {$f['start_comp']}";
        $lines[] = "  end_comp   : {$f['end_comp']}";

        $table = 'mart_compradores_por_geo';

        // Check table exists
        try {
            $exists = (bool) $this->pdo->query("SELECT 1 FROM `{$table}` LIMIT 1")->fetchColumn();
        } catch (Throwable) {
            $exists = false;
        }

        if (!$exists) {
            $lines[] = '';
            $lines[] = "ERRO: tabela {$table} nao existe ou esta vazia.";
            return AdminCommandResult::ok(
                'Geo Map Status',
                "Tabela {$table} ausente ou vazia.",
                outputLines: $lines,
            );
        }

        // Detect date column
        $dateCol = MapFilterSupport::detectDateColumn($this->pdo, $table);
        $lines[] = '';
        $lines[] = '=== Tabela mart_compradores_por_geo ===';
        $lines[] = "  Date column detectado: " . ($dateCol ?? '(nenhum)');

        // Total rows without filter
        $totalRows = MapFilterSupport::countMartRows($this->pdo, $table);
        $lines[] = "  Total rows (sem filtro): {$totalRows}";

        // Period range available
        if ($dateCol !== null) {
            $range = MapFilterSupport::probePeriodRange($this->pdo, $table, $dateCol);
            $lines[] = "  Periodo disponivel: {$range['min']} ate {$range['max']}";
        }

        // Rows after filter
        try {
            $where    = ['competencia BETWEEN :start_comp AND :end_comp'];
            $params   = ['start_comp' => $f['start_comp'], 'end_comp' => $f['end_comp']];

            $where[] = '(LOWER(cidade_norm) = :city OR cidade_norm IS NULL OR TRIM(cidade_norm) = \'\')';
            $params['city'] = $cityNorm;

            if ($neighborhoodNorm !== null) {
                $where[] = 'LOWER(bairro_norm) = :neighborhood';
                $params['neighborhood'] = $neighborhoodNorm;
            }

            if ($f['cnae'] !== null) {
                if (strtoupper($f['cnae']) === 'SEM_CNAE') {
                    $where[] = '(cnae_codigo IS NULL OR TRIM(cnae_codigo) = \'\')';
                } else {
                    $where[] = 'cnae_codigo = :cnae';
                    $params['cnae'] = $f['cnae'];
                }
            }

            $whereSql = implode(' AND ', $where);
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) AS rows_after,
                        COALESCE(SUM(faturamento), 0) AS revenue,
                        COUNT(DISTINCT CASE WHEN bairro_norm IS NOT NULL AND TRIM(bairro_norm) != '' THEN bairro_norm END) AS bairros_com_venda,
                        MAX(competencia) AS max_comp_in_filter,
                        MIN(competencia) AS min_comp_in_filter
                 FROM `{$table}`
                 WHERE {$whereSql}"
            );
            foreach ($params as $k => $v) {
                $stmt->bindValue(':' . $k, $v);
            }
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $rowsAfter  = (int) ($row['rows_after'] ?? 0);
            $revenue    = (float) ($row['revenue'] ?? 0);
            $bairros    = (int) ($row['bairros_com_venda'] ?? 0);
            $minComp    = (string) ($row['min_comp_in_filter'] ?? '');
            $maxComp    = (string) ($row['max_comp_in_filter'] ?? '');

            $lines[] = '';
            $lines[] = '=== Resultado com filtros ===';
            $lines[] = "  Rows com filtro    : {$rowsAfter}";
            $lines[] = "  Faturamento        : R$ " . number_format($revenue, 2, ',', '.');
            $lines[] = "  Bairros com venda  : {$bairros}";
            $lines[] = "  Competencia range  : {$minComp} ate {$maxComp}";

            if ($rowsAfter === 0 || $revenue <= 0.0) {
                $lines[] = '';
                $lines[] = '=== Diagnostico motivo_zero ===';

                try {
                    $martTotalRev = (float) $this->pdo->query(
                        "SELECT COALESCE(SUM(faturamento),0) FROM `{$table}`"
                    )->fetchColumn();
                    $martMinComp  = (string) ($this->pdo->query("SELECT MIN(competencia) FROM `{$table}`")->fetchColumn() ?: '');
                    $martMaxComp  = (string) ($this->pdo->query("SELECT MAX(competencia) FROM `{$table}`")->fetchColumn() ?: '');

                    if ($totalRows === 0) {
                        $motivo = 'mart_vazio';
                    } elseif ($martTotalRev <= 0.0) {
                        $motivo = 'periodo_sem_vendas';
                    } else {
                        $motivo = 'filtros_nao_reconhecidos';
                    }

                    $lines[] = "  motivo_zero        : {$motivo}";
                    $lines[] = "  total_rows_mart    : {$totalRows}";
                    $lines[] = "  faturamento_mart   : R$ " . number_format($martTotalRev, 2, ',', '.');
                    $lines[] = "  competencia_mart   : {$martMinComp} ate {$martMaxComp}";

                    if ($motivo === 'filtros_nao_reconhecidos') {
                        $lines[] = '';
                        $lines[] = "  HINT: O mart tem dados mas os filtros atuais excluem tudo.";
                        $lines[] = "  Verifique se start_comp/end_comp ({$f['start_comp']} - {$f['end_comp']}) cobre {$martMinComp} - {$martMaxComp}.";
                        $lines[] = "  Verifique se city_norm='{$cityNorm}' bate com os valores no mart.";
                        $lines[] = '';
                        $lines[] = "  Para ver todos os periodos disponiveis:";
                        $lines[] = "    SELECT DISTINCT competencia, COUNT(*), SUM(faturamento) FROM mart_compradores_por_geo GROUP BY 1 ORDER BY 1;";
                    }
                } catch (Throwable $e) {
                    $lines[] = "  Erro ao diagnosticar: " . $e->getMessage();
                }
            } else {
                $lines[] = '';
                $lines[] = 'STATUS: OK — mart retorna dados com os filtros atuais.';
            }
        } catch (Throwable $e) {
            $lines[] = '';
            $lines[] = 'ERRO SQL: ' . $e->getMessage();
        }

        return AdminCommandResult::ok(
            'Geo Map Status',
            'Diagnostico do mapa concluido.',
            outputLines: $lines,
        );
    }
}
