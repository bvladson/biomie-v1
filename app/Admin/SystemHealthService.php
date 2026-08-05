<?php

declare(strict_types=1);

namespace BIomie\Admin;

use BIomie\Bootstrap;
use BIomie\Intelligence\IntelligenceJobSla;
use PDO;

final class SystemHealthService
{
    /**
     * @var array<string,array{label:string,table:string,date_columns:list<string>}>
     */
    private const TABLES = [
        'canonical_nf' => [
            'label' => 'NF canônica',
            'table' => 'biomie_notas_fiscais_canonicas',
            'date_columns' => ['data_emissao', 'data_competencia', 'sincronizado_em'],
        ],
        'client_dim' => [
            'label' => 'Clientes dimensão',
            'table' => 'biomie_clientes_dim',
            'date_columns' => ['ultima_compra_em', 'primeira_compra_em', 'sincronizado_em'],
        ],
        'client_geo_dim' => [
            'label' => 'Clientes geo',
            'table' => 'biomie_clientes_geo_dim',
            'date_columns' => [],
        ],
        'geo_client_fact' => [
            'label' => 'Fato geo',
            'table' => 'biomie_vendas_geo_cliente_fact',
            'date_columns' => ['data_referencia'],
        ],
        'geo_daily' => [
            'label' => 'Agregado geo diário',
            'table' => 'biomie_geo_vendas_diaria',
            'date_columns' => ['data_referencia'],
        ],
        'migrations' => [
            'label' => 'Migrations',
            'table' => 'biomie_schema_migrations',
            'date_columns' => ['executed_at', 'rolled_back_at'],
        ],
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $connectionName = 'db',
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function diagnose(): array
    {
        $tables = [];
        foreach (self::TABLES as $key => $definition) {
            $tables[$key] = $this->diagnoseTable($key, $definition);
        }

        $migrations = $this->diagnoseMigrations($tables['migrations']);
        $geoKeys = ['client_geo_dim', 'geo_client_fact', 'geo_daily'];
        $missingGeo = array_values(array_filter(
            $geoKeys,
            static fn (string $key): bool => !($tables[$key]['exists'] ?? false)
        ));

        $messages = [];
        if ($missingGeo !== []) {
            $messages[] = 'As tabelas geográficas ainda não foram instaladas. Rode as migrations antes de atualizar o mapa.';
        }
        if (($tables['canonical_nf']['status'] ?? '') !== 'ok') {
            $messages[] = 'A base fiscal canônica precisa estar disponível para alimentar os diagnósticos comerciais.';
        }

        // Frescor da camada Python. Fica no painel porque o modo de falha dela
        // e silencioso: o job para e a home continua exibindo a ultima leitura
        // ate ela vencer — durante horas, sem nada na tela dizendo que o numero
        // parou de ser atualizado.
        $intelligence = IntelligenceJobSla::snapshot($this->pdo);
        $status = $missingGeo === [] && ($tables['canonical_nf']['status'] ?? '') === 'ok' ? 'ok' : 'warning';
        if ($intelligence !== null && ($intelligence['ok'] ?? true) === false) {
            $status = ($intelligence['severity'] ?? '') === 'critical' ? 'critical' : $status;
            foreach ((array) ($intelligence['jobs'] ?? []) as $job => $info) {
                if ((string) ($info['severity'] ?? 'ok') === 'ok') {
                    continue;
                }
                $messages[] = $info['age_hours'] === null
                    ? sprintf('O job Python "%s" nunca completou uma execução.', (string) $job)
                    : sprintf(
                        'O job Python "%s" está sem sucesso há %sh (limite %sh) — as leituras da home vão vencer.',
                        (string) $job,
                        (string) $info['age_hours'],
                        (string) ($info['limit_hours'] ?? '?'),
                    );
            }
        }

        return [
            'generated_at' => date(DATE_ATOM),
            'connection' => $this->connectionName,
            'status' => $status,
            'tables' => $tables,
            'migrations' => $migrations,
            'geo_ready' => $missingGeo === [],
            'intelligence' => $intelligence,
            'messages' => $messages,
        ];
    }

    /**
     * @param array{label:string,table:string,date_columns:list<string>} $definition
     * @return array<string,mixed>
     */
    private function diagnoseTable(string $key, array $definition): array
    {
        $table = $definition['table'];
        $exists = $this->tableExists($table);
        $base = [
            'key' => $key,
            'label' => $definition['label'],
            'table' => $table,
            'exists' => $exists,
            'rows' => null,
            'latest_dates' => [],
            'status' => 'missing',
            'message' => 'Tabela ausente.',
        ];

        if (!$exists) {
            return $base;
        }

        $columns = $this->tableColumns($table);
        $rowCount = $this->countRows($table);
        $latestDates = $this->latestDates($table, $columns, $definition['date_columns']);
        $status = $rowCount > 0 ? 'ok' : 'empty';

        return array_merge($base, [
            'exists' => true,
            'rows' => $rowCount,
            'latest_dates' => $latestDates,
            'status' => $status,
            'message' => $status === 'ok' ? 'Tabela disponível.' : 'Tabela existe, mas ainda está vazia.',
            'columns' => array_keys($columns),
        ]);
    }

    /**
     * @param array<string,mixed> $tableHealth
     * @return array<string,mixed>
     */
    private function diagnoseMigrations(array $tableHealth): array
    {
        $discovered = $this->discoverMigrationVersions();
        $appliedVersions = [];
        $pending = count($discovered);

        if (($tableHealth['exists'] ?? false) === true) {
            $columns = array_flip($tableHealth['columns'] ?? []);
            $versionColumn = isset($columns['schema_version']) ? 'schema_version' : (isset($columns['version']) ? 'version' : null);
            $statusColumn = isset($columns['status']) ? 'status' : null;

            if ($versionColumn !== null) {
                $sql = sprintf(
                    'SELECT `%s` AS version FROM `%s`%s',
                    $versionColumn,
                    self::TABLES['migrations']['table'],
                    $statusColumn !== null ? " WHERE `$statusColumn` <> 'rolled_back'" : ''
                );
                $rows = $this->pdo->query($sql)?->fetchAll(PDO::FETCH_COLUMN) ?: [];
                $appliedVersions = array_values(array_filter(array_map('strval', $rows)));
                $pending = max(0, count(array_diff($discovered, $appliedVersions)));
            }
        }

        $status = ($tableHealth['exists'] ?? false) !== true
            ? 'missing'
            : ($pending > 0 ? 'warning' : 'ok');

        return [
            'status' => $status,
            'discovered' => count($discovered),
            'applied' => count($appliedVersions),
            'pending' => $pending,
            'table' => $tableHealth,
            'message' => match ($status) {
                'missing' => 'Tabela de migrations ausente.',
                'warning' => 'Há migrations locais sem registro aplicado neste banco.',
                default => 'Migrations registradas sem pendência local detectada.',
            },
        ];
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
        );
        $stmt->execute(['table' => $table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * @return array<string,true>
     */
    private function tableColumns(string $table): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
        );
        $stmt->execute(['table' => $table]);
        $columns = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $column) {
            $columns[(string) $column] = true;
        }
        return $columns;
    }

    private function countRows(string $table): int
    {
        return (int) $this->pdo->query(sprintf('SELECT COUNT(*) FROM `%s`', $table))->fetchColumn();
    }

    /**
     * @param array<string,true> $columns
     * @param list<string> $dateColumns
     * @return array<string,string|null>
     */
    private function latestDates(string $table, array $columns, array $dateColumns): array
    {
        $available = array_values(array_filter(
            $dateColumns,
            static fn (string $column): bool => isset($columns[$column])
        ));
        if ($available === []) {
            return [];
        }

        $select = implode(', ', array_map(
            static fn (string $column): string => sprintf('MAX(`%s`) AS `%s`', $column, $column),
            $available
        ));
        $row = $this->pdo->query(sprintf('SELECT %s FROM `%s`', $select, $table))->fetch(PDO::FETCH_ASSOC) ?: [];

        $dates = [];
        foreach ($available as $column) {
            $dates[$column] = isset($row[$column]) && $row[$column] !== null ? (string) $row[$column] : null;
        }
        return $dates;
    }

    /**
     * @return list<string>
     */
    private function discoverMigrationVersions(): array
    {
        $path = Bootstrap::rootPath('database/migrations');
        $files = glob($path . '/Version*.php') ?: [];
        $versions = [];
        foreach ($files as $file) {
            if (preg_match('/Version(\d+)_/', basename($file), $matches) === 1) {
                $versions[] = $matches[1];
            }
        }
        sort($versions, SORT_STRING);
        return $versions;
    }
}
