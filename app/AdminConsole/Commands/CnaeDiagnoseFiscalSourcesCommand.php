<?php

declare(strict_types=1);

namespace BIomie\AdminConsole\Commands;

use BIomie\AdminConsole\AdminCommandContext;
use BIomie\AdminConsole\AdminCommandInterface;
use BIomie\AdminConsole\AdminCommandResult;
use BIomie\CnaeSourceInspectorService;
use BIomie\Telemetry;
use PDO;
use Throwable;

/**
 * Diagnostica CNAE em todas as fontes fiscais/comerciais e explica
 * por que mart_vendas_por_cnae_cnpj pode estar vazio.
 */
final class CnaeDiagnoseFiscalSourcesCommand implements AdminCommandInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function name(): string        { return 'cnae:diagnose-fiscal-sources'; }
    public function description(): string { return 'Diagnostica CNAE em todas as fontes fiscais/comerciais (bronze, NF canonica, pedido, marts) e explica por que mart_vendas_por_cnae_cnpj pode estar vazio.'; }
    public function group(): string       { return 'cnae'; }
    public function permission(): string  { return 'admin.console'; }
    public function isDestructive(): bool { return false; }
    public function requiresConfirmation(): bool { return false; }

    public function validateArgs(array $args): ?string { return null; }

    public function run(AdminCommandContext $context, array $args = []): AdminCommandResult
    {
        $telemetry = Telemetry::global();
        $span      = $telemetry?->startSpan('cnae.diagnose_fiscal', ['actor_user_id' => $context->userId], 'cnae', 'cnae_diagnose_fiscal');
        try { $telemetry?->event('cnae.diagnose_fiscal.started', ['actor_user_id' => $context->userId], 'cnae'); } catch (Throwable) {}

        $inspector = new CnaeSourceInspectorService($this->pdo);
        $report    = $inspector->inspect();

        $lines   = [];
        $tables  = [];

        // ── Header ─────────────────────────────────────────────────────────────
        $lines[] = '=== Diagnostico de CNAE em fontes fiscais/comerciais ===';
        $lines[] = 'Varredura: bronze, NFs canonicas, pedidos canonicos, marts gold.';
        $lines[] = '';

        $vendasCnaeAtual = 0;
        try {
            $vendasCnaeAtual = (int) $this->pdo->query("SELECT COUNT(*) FROM mart_vendas_por_cnae_cnpj")->fetchColumn();
        } catch (Throwable) {}
        $lines[] = "mart_vendas_por_cnae_cnpj atual: {$vendasCnaeAtual} linhas.";
        $lines[] = '';

        // ── Per-source breakdown ───────────────────────────────────────────────
        $totalFontesComCnae = 0;
        $primeiraFonteComCnae = null;

        foreach ($report as $sourceKey => $src) {
            $exists   = $src['table_exists']    ?? false;
            $total    = $src['total_registros'] ?? 0;
            $comCnae  = $src['registros_com_cnae'] ?? 0;
            $colFound = $src['cnae_coluna_encontrada'] ?? null;
            $jsonKeys = $src['json_cnae_keys']          ?? [];
            $chavesEnc = $src['chaves_cnae_encontradas'] ?? [];

            $lines[] = "--- {$sourceKey} ---";

            if (!$exists) {
                $lines[] = '  Tabela ausente.';
                $lines[] = '';
                continue;
            }

            $lines[] = "  Total registros             : {$total}";

            if ($src['registros_com_data'] ?? 0) {
                $lines[] = "  Com data                    : " . ($src['registros_com_data'] ?? 0);
            }
            if ($src['registros_com_documento'] ?? 0) {
                $lines[] = "  Com documento               : " . ($src['registros_com_documento'] ?? 0);
            }
            if ($src['registros_com_codigo_cliente'] ?? 0) {
                $lines[] = "  Com codigo_cliente_omie     : " . ($src['registros_com_codigo_cliente'] ?? 0);
            }
            if ($src['registros_com_nf'] ?? 0) {
                $lines[] = "  Com referencia NF           : " . ($src['registros_com_nf'] ?? 0);
            }
            if ($src['registros_com_pedido'] ?? 0) {
                $lines[] = "  Com referencia pedido       : " . ($src['registros_com_pedido'] ?? 0);
            }

            $cnaeLabel = $colFound ? " (coluna: {$colFound})" : '';
            $lines[]   = "  Registros com CNAE          : {$comCnae}{$cnaeLabel}";

            if (!empty($chavesEnc)) {
                $lines[] = '  Chaves CNAE encontradas no JSON:';
                foreach ($chavesEnc as $k => $n) {
                    $lines[] = "    \"{$k}\": {$n} payloads";
                }
            }
            if (!empty($jsonKeys)) {
                $lines[] = '  Chaves JSON com CNAE:';
                foreach ($jsonKeys as $k => $n) {
                    $lines[] = "    \"{$k}\": {$n} registros";
                }
            }

            if ($comCnae > 0) {
                $lines[] = "  Match por codigo_omie       : " . ($src['match_por_codigo_cliente_omie'] ?? 0);
                $lines[] = "  Match por documento_norm    : " . ($src['match_por_documento_normalizado'] ?? 0);
                $lines[] = "  Sem match em clientes_dim   : " . ($src['sem_match'] ?? 0);
                $totalFontesComCnae += $comCnae;
                if ($primeiraFonteComCnae === null) {
                    $primeiraFonteComCnae = $sourceKey;
                }

                // Sample table
                if (!empty($src['exemplos'])) {
                    $sampleRows = [];
                    foreach ($src['exemplos'] as $ex) {
                        $sampleRows[] = [
                            (string) ($ex['codigo_cliente_omie'] ?? '-'),
                            (string) ($ex['cnae_codigo']         ?? '-'),
                            (string) ($ex['documento_mascarado'] ?? '***'),
                            (string) ($ex['match_codigo']        ?? '-'),
                            (string) ($ex['match_doc']           ?? '-'),
                        ];
                    }
                    $tables[] = [
                        'title'   => "Amostra com CNAE: {$sourceKey}",
                        'headers' => ['Codigo Omie', 'CNAE', 'Doc mascarado', 'Match codigo', 'Match doc'],
                        'rows'    => $sampleRows,
                    ];
                }
            }

            if ($src['error'] ?? null) {
                $lines[] = '  ERRO: ' . $src['error'];
            }

            $lines[] = '';
        }

        // ── Diagnostico clientes_dim ───────────────────────────────────────────
        $clientesTotais  = 0;
        $clientesComCnae = 0;
        $martPeriodoRows = 0;
        $clientesComCnaeESales = 0;

        try {
            $clientesTotais  = (int) $this->pdo->query("SELECT COUNT(*) FROM biomie_clientes_dim")->fetchColumn();
            $clientesComCnae = (int) $this->pdo->query(
                "SELECT COUNT(*) FROM biomie_clientes_dim WHERE cnae_codigo IS NOT NULL AND cnae_codigo != ''"
            )->fetchColumn();
        } catch (Throwable) {}

        try {
            $martPeriodoRows = (int) $this->pdo->query("SELECT COUNT(*) FROM mart_cliente_periodo")->fetchColumn();
        } catch (Throwable) {}

        try {
            $clientesComCnaeESales = (int) $this->pdo->query(
                "SELECT COUNT(DISTINCT cd.codigo_cliente_omie)
                 FROM biomie_clientes_dim cd
                 INNER JOIN mart_cliente_periodo cp ON cp.codigo_cliente_omie = cd.codigo_cliente_omie
                 WHERE cd.cnae_codigo IS NOT NULL AND cd.cnae_codigo != ''
                   AND (cp.nfes_emitidas > 0 OR cp.valor_nfe > 0)"
            )->fetchColumn();
        } catch (Throwable) {}

        $clientesSemCnae = $clientesTotais - $clientesComCnae;
        $nfSrc           = $report['biomie_notas_fiscais_canonicas'] ?? [];
        $pedSrc          = $report['biomie_pedidos_canonicos']       ?? [];
        $nfComCnae       = $nfSrc['registros_com_cnae']  ?? 0;
        $pedComCnae      = $pedSrc['registros_com_cnae'] ?? 0;

        $lines[] = '=== Cruzamento com clientes_dim e marts ===';
        $lines[] = "  clientes_dim: {$clientesTotais} total / {$clientesComCnae} com CNAE / {$clientesSemCnae} sem CNAE.";
        $lines[] = "  mart_cliente_periodo: {$martPeriodoRows} linhas.";
        $lines[] = "  Clientes com CNAE E vendas no mart_cliente_periodo: {$clientesComCnaeESales}.";
        $lines[] = '';

        // ── Diagnose motivos ───────────────────────────────────────────────────
        $lines[] = '=== Diagnostico: por que mart_vendas_por_cnae_cnpj pode estar vazio ===';
        $lines[] = '';

        $motivosPossiveis = [];

        if ($clientesComCnae === 0 && $nfComCnae === 0 && $pedComCnae === 0) {
            $motivosPossiveis[] = 'NENHUM CNAE encontrado em nenhuma fonte sincronizada.';
            $motivosPossiveis[] = 'O campo "CNAE PRINCIPAL" visivel no relatorio Omie nao esta presente nos payloads';
            $motivosPossiveis[] = 'sincronizados via API (bronze, NF canonica, pedido canonico).';
            $motivosPossiveis[] = 'Solucao: execute clientes:enrich-omie-all para re-sincronizar todos os clientes.';
            $motivosPossiveis[] = 'Se o CNAE ainda nao aparecer, o endpoint fiscal (ListarNFe / ConsultarResumoNFe)';
            $motivosPossiveis[] = 'precisa ser sincronizado separadamente. Nao use XLSX/CSV.';
        } elseif ($clientesComCnae === 0 && ($nfComCnae > 0 || $pedComCnae > 0)) {
            $motivosPossiveis[] = "CNAE disponivel em NF/pedido ({$nfComCnae} NFs, {$pedComCnae} pedidos) mas NAO foi transferido para clientes_dim.";
            $motivosPossiveis[] = 'Execute cnae:materialize para tentar a transferencia.';
            $motivosPossiveis[] = 'Depois execute geo:materialize para reconstruir os marts.';
        } elseif ($clientesComCnae > 0 && $clientesComCnaeESales === 0) {
            $motivosPossiveis[] = "{$clientesComCnae} cliente(s) tem CNAE em clientes_dim, mas NENHUM possui";
            $motivosPossiveis[] = 'vendas em mart_cliente_periodo no periodo disponivel.';
            $motivosPossiveis[] = 'Verifique o periodo de filtro ou se esses clientes compraram fora do recorte.';
        } elseif ($clientesComCnaeESales > 0 && $vendasCnaeAtual === 0) {
            $motivosPossiveis[] = "{$clientesComCnaeESales} cliente(s) com CNAE tem vendas, mas mart_vendas_por_cnae_cnpj esta vazio.";
            $motivosPossiveis[] = 'Execute geo:materialize (ou cnae:materialize) para reconstruir o mart.';
        } elseif ($vendasCnaeAtual > 0) {
            $motivosPossiveis[] = "mart_vendas_por_cnae_cnpj tem {$vendasCnaeAtual} linhas — nao esta vazio.";
        } else {
            $motivosPossiveis[] = 'Motivo nao identificado automaticamente. Veja os detalhes por fonte acima.';
        }

        foreach ($motivosPossiveis as $i => $m) {
            $lines[] = '  ' . ($i + 1) . '. ' . $m;
        }

        // ── Recomendacao ───────────────────────────────────────────────────────
        $lines[] = '';
        $lines[] = '=== Recomendacao ===';

        if ($clientesComCnae === 0 && $nfComCnae === 0 && $pedComCnae === 0) {
            $lines[] = '  1. Execute clientes:enrich-omie-all para re-sincronizar via API Omie.';
            $lines[] = '  2. Execute cnae:diagnose-sources para verificar payloads bronze.';
            $lines[] = '  3. Se o CNAE nao aparecer, verifique qual endpoint Omie retorna o CNAE PRINCIPAL.';
        } elseif ($clientesComCnae === 0 && ($nfComCnae > 0 || $pedComCnae > 0)) {
            $lines[] = '  Execute: cnae:materialize real';
            $lines[] = '  Depois : geo:materialize real';
        } elseif ($clientesComCnaeESales > 0 && $vendasCnaeAtual === 0) {
            $lines[] = '  Execute: geo:materialize real  (reconstruira mart_vendas_por_cnae_cnpj)';
        } elseif ($clientesComCnae > 0) {
            $lines[] = '  Execute: geo:materialize real';
        }

        $metrics = [
            'clientes_dim_total'          => $clientesTotais,
            'clientes_com_cnae'           => $clientesComCnae,
            'clientes_sem_cnae'           => $clientesSemCnae,
            'clientes_com_cnae_e_vendas'  => $clientesComCnaeESales,
            'mart_periodo_rows'           => $martPeriodoRows,
            'nf_canonical_com_cnae'       => $nfComCnae,
            'pedido_canonical_com_cnae'   => $pedComCnae,
            'mart_vendas_cnae_atual'      => $vendasCnaeAtual,
            'total_fontes_com_cnae'       => $totalFontesComCnae,
        ];

        try { $telemetry?->event('cnae.diagnose_fiscal.finished', array_merge($metrics, ['actor_user_id' => $context->userId]), 'cnae'); } catch (Throwable) {}
        $span?->finish();

        $summary = sprintf(
            '%d/%d clientes com CNAE. NFs canonicas: %d. Pedidos: %d. Mart atual: %d linhas.',
            $clientesComCnae, $clientesTotais, $nfComCnae, $pedComCnae, $vendasCnaeAtual
        );

        return AdminCommandResult::ok(
            'CNAE Diagnose Fiscal Sources',
            $summary,
            outputLines: $lines,
            metrics:     $metrics,
            tables:      $tables,
        );
    }
}
