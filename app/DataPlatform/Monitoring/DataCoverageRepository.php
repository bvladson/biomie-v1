<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\Monitoring;

use BIomie\Logger;
use PDO;
use Throwable;

final class DataCoverageRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Logger $logger
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getDatabaseInfo(): array
    {
        return [
            'database' => $this->safeQuery("SELECT DATABASE()"),
            'current_user' => $this->safeQuery("SELECT CURRENT_USER()"),
            'version' => $this->safeQuery("SELECT VERSION()"),
        ];
    }

    public function checkTableCoverage(string $table, string $month): array
    {
        $info = [
            'exists' => false,
            'total_rows' => 0,
            'rows_in_period' => 0,
            'revenue_in_period' => null,
            'min_date' => null,
            'max_date' => null,
            'last_updated_at' => null,
            'date_column_used' => null,
            'value_column_used' => null,
            'warnings' => [],
        ];

        try {
            $stmt = $this->pdo->query("SHOW TABLES LIKE '{$table}'");
            if ($stmt === false || $stmt->rowCount() === 0) {
                return $info;
            }
            $info['exists'] = true;
        } catch (Throwable) {
            return $info;
        }

        $columns = [];
        try {
            $stmt = $this->pdo->query("DESCRIBE {$table}");
            if ($stmt !== false) {
                $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
            }
        } catch (Throwable) {
            $info['warnings'][] = "Failed to describe table {$table}";
            return $info;
        }

        $dateCol = $this->resolveDateColumn($table, $columns);
        $valCol = $this->resolveValueColumn($table, $columns);
        
        $info['date_column_used'] = $dateCol;
        $info['value_column_used'] = $valCol;

        if (in_array('refreshed_at', $columns, true)) {
            $info['last_updated_at'] = $this->safeQuery("SELECT MAX(refreshed_at) FROM {$table}");
        } elseif (in_array('updated_at', $columns, true)) {
            $info['last_updated_at'] = $this->safeQuery("SELECT MAX(updated_at) FROM {$table}");
        } elseif (in_array('ingested_at', $columns, true)) {
            $info['last_updated_at'] = $this->safeQuery("SELECT MAX(ingested_at) FROM {$table}");
        }

        $info['total_rows'] = (int) $this->safeQuery("SELECT COUNT(*) FROM {$table}");

        if ($dateCol) {
            $info['min_date'] = $this->safeQuery("SELECT MIN({$dateCol}) FROM {$table}");
            $info['max_date'] = $this->safeQuery("SELECT MAX({$dateCol}) FROM {$table}");
            
            $start = "{$month}-01";
            $end = date('Y-m-t', strtotime($start));
            
            // Special handling for bronze payloads since it doesn't have a date column easily queriable
            if ($table === 'biomie_omie_bronze' && in_array('payload_json', $columns, true)) {
                $info['rows_in_period'] = (int) $this->safeQuery("SELECT COUNT(*) FROM {$table} WHERE payload_json LIKE '%{$month}%'");
            } else {
                // If it's a varchar 'competencia' like YYYY-MM
                if (in_array('competencia', $columns, true)) {
                    $info['rows_in_period'] = (int) $this->safeQuery("SELECT COUNT(*) FROM {$table} WHERE competencia = '{$month}'");
                } else {
                    $info['rows_in_period'] = (int) $this->safeQuery("SELECT COUNT(*) FROM {$table} WHERE {$dateCol} BETWEEN '{$start}' AND '{$end} 23:59:59'");
                }
            }

            if ($valCol) {
                if (in_array('competencia', $columns, true)) {
                    $info['revenue_in_period'] = (float) $this->safeQuery("SELECT COALESCE(SUM({$valCol}), 0) FROM {$table} WHERE competencia = '{$month}'");
                } else {
                    $info['revenue_in_period'] = (float) $this->safeQuery("SELECT COALESCE(SUM({$valCol}), 0) FROM {$table} WHERE {$dateCol} BETWEEN '{$start}' AND '{$end} 23:59:59'");
                }
            }
        } else {
            $info['warnings'][] = "No suitable date column found for period filter";
        }

        return $info;
    }

    private function resolveDateColumn(string $table, array $columns): ?string
    {
        if (in_array('competencia', $columns, true)) return 'competencia';
        if (in_array('data_emissao', $columns, true)) return 'data_emissao';
        if (in_array('issue_date', $columns, true)) return 'issue_date';
        if (in_array('data_previsao', $columns, true)) return 'data_previsao';
        if (in_array('created_at', $columns, true)) return 'created_at';
        if (in_array('ingested_at', $columns, true)) return 'ingested_at';
        if (in_array('id', $columns, true)) return 'id'; // fallback
        return null;
    }

    private function resolveValueColumn(string $table, array $columns): ?string
    {
        if (in_array('faturamento', $columns, true)) return 'faturamento';
        if (in_array('valor_mercadorias', $columns, true)) return 'valor_mercadorias';
        if (in_array('valor_total', $columns, true)) return 'valor_total';
        if (in_array('revenue', $columns, true)) return 'revenue';
        if (in_array('total', $columns, true)) return 'total';
        return null;
    }

    private function safeQuery(string $sql): mixed
    {
        try {
            $stmt = $this->pdo->query($sql);
            if ($stmt !== false) {
                return $stmt->fetchColumn();
            }
        } catch (Throwable) {
            return null;
        }
        return null;
    }
}
