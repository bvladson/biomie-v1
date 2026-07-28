<?php

declare(strict_types=1);

namespace BIomie\AdminConsole\Commands;

use BIomie\AdminConsole\AdminCommandContext;
use BIomie\AdminConsole\AdminCommandInterface;
use BIomie\AdminConsole\AdminCommandResult;
use BIomie\TableHealthService;
use PDO;
use Throwable;

final class ClientesStatusCommand implements AdminCommandInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function name(): string { return 'clientes:status'; }
    public function description(): string { return 'Resumo da dimensao de clientes: documento, vendedor, geo, CNAE, NFs.'; }
    public function group(): string { return 'clientes'; }
    public function permission(): string { return 'admin.console'; }
    public function isDestructive(): bool { return false; }
    public function requiresConfirmation(): bool { return false; }

    public function validateArgs(array $args): ?string { return null; }

    public function run(AdminCommandContext $context, array $args = []): AdminCommandResult
    {
        $tableHealth = new TableHealthService($this->pdo, null);

        if (!$tableHealth->tableExists('biomie_clientes_dim')) {
            return AdminCommandResult::error(
                'Clientes Status',
                'biomie_clientes_dim ausente. Execute migrations e clientes:materialize-dim primeiro.'
            );
        }

        $total = (int) ($tableHealth->safeCount('biomie_clientes_dim') ?? 0);

        if ($total === 0) {
            return AdminCommandResult::warning(
                'Clientes Status',
                'biomie_clientes_dim vazia. Execute clientes:materialize-dim para popular.',
            );
        }

        $lines = [];
        $tables = [];

        // Main stats
        try {
            $row = $this->pdo->query(
                "SELECT
                    COUNT(*)                                                                         AS total,
                    SUM(documento_normalizado IS NOT NULL AND documento_normalizado != '')           AS com_doc,
                    SUM(documento_normalizado IS NULL OR documento_normalizado = '')                 AS sem_doc,
                    SUM(tipo_pessoa = 'PF')                                                          AS pf,
                    SUM(tipo_pessoa = 'PJ')                                                          AS pj,
                    SUM(tipo_pessoa IS NULL OR tipo_pessoa NOT IN ('PF','PJ'))                       AS sem_tipo,
                    SUM(vendedor_responsavel_confidence > 0)                                         AS com_vend,
                    SUM(vendedor_responsavel_confidence = 0)                                         AS sem_vend,
                    SUM(cidade IS NOT NULL AND cidade != '')                                         AS com_cidade,
                    SUM(bairro IS NOT NULL AND bairro != '')                                         AS com_bairro,
                    SUM(cnae_codigo IS NOT NULL AND cnae_codigo != '')                               AS com_cnae,
                    SUM(total_nf_autorizadas > 0)                                                    AS com_nf_aut,
                    SUM(total_nf_canceladas > 0)                                                     AS com_nf_can,
                    SUM(total_pedidos > 0)                                                           AS com_pedido
                 FROM biomie_clientes_dim"
            )->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return AdminCommandResult::error('Clientes Status', 'Erro ao consultar biomie_clientes_dim.');
        }

        if (!is_array($row)) {
            return AdminCommandResult::error('Clientes Status', 'Query nao retornou dados.');
        }

        $tables[] = [
            'title'   => 'Dimensao de Clientes',
            'headers' => ['Indicador', 'Qtd', 'Pct'],
            'rows'    => [
                ['Total',             number_format((int) ($row['total']     ?? 0), 0, ',', '.'), '100%'],
                ['Com documento',     number_format((int) ($row['com_doc']   ?? 0), 0, ',', '.'), $this->pct($row['com_doc'], $row['total'])],
                ['Sem documento',     number_format((int) ($row['sem_doc']   ?? 0), 0, ',', '.'), $this->pct($row['sem_doc'], $row['total'])],
                ['PF',                number_format((int) ($row['pf']        ?? 0), 0, ',', '.'), $this->pct($row['pf'],      $row['total'])],
                ['PJ',                number_format((int) ($row['pj']        ?? 0), 0, ',', '.'), $this->pct($row['pj'],      $row['total'])],
                ['Com vendedor',      number_format((int) ($row['com_vend']  ?? 0), 0, ',', '.'), $this->pct($row['com_vend'],$row['total'])],
                ['Sem vendedor',      number_format((int) ($row['sem_vend']  ?? 0), 0, ',', '.'), $this->pct($row['sem_vend'],$row['total'])],
                ['Com cidade',        number_format((int) ($row['com_cidade']?? 0), 0, ',', '.'), $this->pct($row['com_cidade'],$row['total'])],
                ['Com bairro',        number_format((int) ($row['com_bairro']?? 0), 0, ',', '.'), $this->pct($row['com_bairro'],$row['total'])],
                ['Com CNAE',          number_format((int) ($row['com_cnae']  ?? 0), 0, ',', '.'), $this->pct($row['com_cnae'], $row['total'])],
                ['Com NF autorizada', number_format((int) ($row['com_nf_aut']?? 0), 0, ',', '.'), $this->pct($row['com_nf_aut'],$row['total'])],
                ['Com NF cancelada',  number_format((int) ($row['com_nf_can']?? 0), 0, ',', '.'), $this->pct($row['com_nf_can'],$row['total'])],
                ['Com pedido',        number_format((int) ($row['com_pedido']?? 0), 0, ',', '.'), $this->pct($row['com_pedido'],$row['total'])],
            ],
        ];

        // Top vendedores
        try {
            $stmt = $this->pdo->query(
                "SELECT
                    COALESCE(vendedor_responsavel_nome, 'Sem vendedor') AS vendedor,
                    vendedor_responsavel_origem                          AS origem,
                    vendedor_responsavel_confidence                      AS conf,
                    COUNT(*)                                             AS clientes,
                    SUM(total_nf_autorizadas)                           AS nf_aut,
                    SUM(total_receita_autorizada)                       AS receita
                 FROM biomie_clientes_dim
                 GROUP BY vendedor, origem, conf
                 ORDER BY clientes DESC
                 LIMIT 10"
            );
            $topVendRows = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $v) {
                $topVendRows[] = [
                    (string) ($v['vendedor'] ?? ''),
                    (string) ($v['origem']   ?? ''),
                    (string) ($v['conf']     ?? '0'),
                    (string) ($v['clientes'] ?? '0'),
                    number_format((int) ($v['nf_aut'] ?? 0), 0, ',', '.'),
                    'R$ ' . number_format((float) ($v['receita'] ?? 0), 0, ',', '.'),
                ];
            }
            if ($topVendRows !== []) {
                $tables[] = [
                    'title'   => 'Top vendedores responsaveis',
                    'headers' => ['Vendedor', 'Origem', 'Conf', 'Clientes', 'NF aut', 'Receita'],
                    'rows'    => $topVendRows,
                ];
            }
        } catch (Throwable) {}

        // Warnings
        $warnings = [];
        if ((int) ($row['sem_doc'] ?? 0) > 0) {
            $warnings[] = (int) $row['sem_doc'] . " cliente(s) sem documento normalizado.";
        }
        if ((int) ($row['sem_vend'] ?? 0) > 0) {
            $warnings[] = (int) $row['sem_vend'] . " cliente(s) sem vendedor responsavel.";
        }
        if ((int) ($row['com_cnae'] ?? 0) === 0) {
            $warnings[] = "Nenhum cliente com CNAE. Execute enriquecimento cadastral para popular cnae_codigo.";
        }
        if ((int) ($row['com_cidade'] ?? 0) === 0) {
            $warnings[] = "Nenhum cliente com cidade. Sincronize dados de endereco via Omie.";
        }

        $summary = "biomie_clientes_dim: {$total} clientes. "
            . "Com doc: " . ($row['com_doc'] ?? 0) . " | com vend: " . ($row['com_vend'] ?? 0)
            . " | com CNAE: " . ($row['com_cnae'] ?? 0) . ".";

        return count($warnings) > 0
            ? AdminCommandResult::warning('Clientes Status', $summary, tables: $tables, warnings: $warnings, outputLines: $lines)
            : AdminCommandResult::ok('Clientes Status', $summary, tables: $tables, outputLines: $lines);
    }

    private function pct(mixed $value, mixed $total): string
    {
        $t = (int) $total;
        if ($t === 0) {
            return '—';
        }
        return round(((int) $value / $t) * 100, 1) . '%';
    }
}
