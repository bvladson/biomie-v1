<?php

declare(strict_types=1);

namespace BIomie\AdminConsole\Commands;

use BIomie\AdminConsole\AdminCommandContext;
use BIomie\AdminConsole\AdminCommandInterface;
use BIomie\AdminConsole\AdminCommandResult;
use BIomie\TableHealthService;
use PDO;
use Throwable;

final class GeoStatusCommand implements AdminCommandInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function name(): string { return 'geo:status'; }
    public function description(): string { return 'Mostra status atual da camada geo: geo_dim, bairros, marts compradores e CNAE.'; }
    public function group(): string { return 'geo'; }
    public function permission(): string { return 'admin.console'; }
    public function isDestructive(): bool { return false; }
    public function requiresConfirmation(): bool { return false; }

    public function validateArgs(array $args): ?string { return null; }

    public function run(AdminCommandContext $context, array $args = []): AdminCommandResult
    {
        $tableHealth = new TableHealthService($this->pdo, null);
        $rows = [];
        $lines = [];

        $tables = [
            ['biomie_bairros_fortaleza_dim',    'ref',     'Bairros oficiais Fortaleza (121 esperados)'],
            ['biomie_clientes_dim',             'silver',  'Dimensao de clientes'],
            ['biomie_clientes_geo_dim',         'silver',  'Dimensao geo dos clientes'],
            ['biomie_clientes_para_enriquecer', 'fila',    'Fila de enriquecimento'],
            ['biomie_vendas_geo_cliente_fact',  'fact',    'Fact vendas com geo'],
            ['biomie_geo_vendas_diaria',        'gold',    'Agregado diario geo'],
            ['mart_compradores_por_geo',        'mart',    'Mart compradores por bairro'],
            ['mart_vendas_por_cnae_cnpj',       'mart',    'Mart vendas por CNAE'],
        ];

        foreach ($tables as [$table, $layer, $desc]) {
            $exists = $tableHealth->tableExists($table);
            $count  = $exists ? (int) ($tableHealth->safeCount($table) ?? 0) : 0;
            $status = !$exists ? 'AUSENTE' : ($count === 0 ? 'VAZIO' : 'OK');
            $rows[] = [$table, $layer, $desc, $status, number_format($count, 0, ',', '.')];
        }

        // Coverage details
        if ($tableHealth->tableExists('biomie_clientes_geo_dim')) {
            try {
                $row = $this->pdo->query(
                    "SELECT
                        COUNT(*)                                                            AS total,
                        SUM(bairro_norm IS NOT NULL AND bairro_norm != '')                  AS com_bairro_norm,
                        SUM(bairro_oficial_id_fortaleza IS NOT NULL)                        AS com_match,
                        SUM(bairro_oficial_id_fortaleza IS NULL)                            AS sem_match,
                        SUM(uf = 'CE')                                                      AS clientes_ce
                     FROM biomie_clientes_geo_dim"
                )->fetch(PDO::FETCH_ASSOC);

                $total    = (int) ($row['total']          ?? 0);
                $comNorm  = (int) ($row['com_bairro_norm'] ?? 0);
                $comMatch = (int) ($row['com_match']       ?? 0);
                $semMatch = (int) ($row['sem_match']       ?? 0);
                $ce       = (int) ($row['clientes_ce']     ?? 0);

                $lines[] = '';
                $lines[] = 'Cobertura geo_dim:';
                $lines[] = "  Total                     : {$total}";
                $lines[] = "  Com bairro_norm preenchido: {$comNorm} / " . ($total - $comNorm) . " sem";
                $lines[] = "  Com match bairro oficial  : {$comMatch} / {$semMatch} sem match";
                $lines[] = "  Clientes CE               : {$ce}";
            } catch (Throwable) {}
        }

        // Mart compradores details
        if ($tableHealth->tableExists('mart_compradores_por_geo')) {
            try {
                $row = $this->pdo->query(
                    "SELECT
                        COUNT(*) AS total_rows,
                        COUNT(DISTINCT competencia) AS periodos,
                        COUNT(DISTINCT codigo_cliente_omie) AS clientes,
                        COUNT(DISTINCT CASE WHEN bairro_norm IS NOT NULL AND bairro_norm != '' THEN bairro_norm END) AS bairros_com_venda,
                        COALESCE(SUM(faturamento), 0) AS faturamento_total,
                        MAX(refreshed_at) AS ultimo_refresh
                     FROM mart_compradores_por_geo"
                )->fetch(PDO::FETCH_ASSOC);

                $lines[] = '';
                $lines[] = 'mart_compradores_por_geo:';
                $lines[] = "  Rows       : " . number_format((int) ($row['total_rows'] ?? 0), 0, ',', '.');
                $lines[] = "  Periodos   : " . ($row['periodos'] ?? 0);
                $lines[] = "  Clientes   : " . ($row['clientes'] ?? 0);
                $lines[] = "  Bairros    : " . ($row['bairros_com_venda'] ?? 0) . " com venda";
                $lines[] = "  Faturamento: R$ " . number_format((float) ($row['faturamento_total'] ?? 0), 2, ',', '.');
                $lines[] = "  Refresh    : " . ($row['ultimo_refresh'] ?? 'nunca');
            } catch (Throwable) {}
        }

        $summary = count(array_filter($rows, static fn ($r): bool => $r[3] === 'VAZIO')) > 0
            ? 'Alguns marts vazios. Execute geo:materialize para popular.'
            : 'Status geo OK.';

        $tables = [[
            'title'   => 'Status Geo / CNAE',
            'headers' => ['Tabela', 'Camada', 'Descricao', 'Status', 'Registros'],
            'rows'    => $rows,
        ]];

        $hasVazio = count(array_filter($rows, static fn ($r): bool => in_array($r[3], ['VAZIO', 'AUSENTE']))) > 0;

        return $hasVazio
            ? AdminCommandResult::warning('Geo / CNAE Status', $summary, outputLines: $lines, tables: $tables)
            : AdminCommandResult::ok('Geo / CNAE Status', $summary, outputLines: $lines, tables: $tables);
    }
}
