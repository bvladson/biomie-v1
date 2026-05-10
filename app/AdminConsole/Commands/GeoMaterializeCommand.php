<?php

declare(strict_types=1);

namespace BIomie\AdminConsole\Commands;

use BIomie\AdminConsole\AdminCommandContext;
use BIomie\AdminConsole\AdminCommandInterface;
use BIomie\AdminConsole\AdminCommandResult;
use BIomie\Config;
use BIomie\Database;
use BIomie\GeoCnaeMaterializationService;
use BIomie\Logger;
use BIomie\TableHealthService;
use Throwable;

final class GeoMaterializeCommand implements AdminCommandInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly string $connectionName,
    ) {}

    public function name(): string { return 'geo:materialize'; }
    public function description(): string { return 'Geo completo: skeletons, match 121 bairros, fila, fact, diaria, compradores_geo, vendas_cnae.'; }
    public function group(): string { return 'geo'; }
    public function permission(): string { return 'admin.console'; }
    public function isDestructive(): bool { return false; }
    public function requiresConfirmation(): bool { return true; }

    /**
     * @param array<string,string> $args
     */
    public function validateArgs(array $args): ?string
    {
        foreach (['dry_run', 'rebuild'] as $param) {
            $v = $args[$param] ?? '';
            if (!in_array($v, ['', '0', '1', 'true', 'false'], true)) {
                return "Parametro {$param} invalido. Use: 0 ou 1.";
            }
        }
        return null;
    }

    /**
     * @param array<string,string> $args
     */
    public function run(AdminCommandContext $context, array $args = []): AdminCommandResult
    {
        $dryRun  = in_array($args['dry_run']  ?? '0', ['1', 'true'], true);
        $rebuild = in_array($args['rebuild']  ?? '0', ['1', 'true'], true);

        try {
            $pdo         = Database::connect($this->config, $this->connectionName);
            $tableHealth = new TableHealthService($pdo, $this->logger);
            $service     = new GeoCnaeMaterializationService($pdo, $this->logger, $tableHealth);

            if ($dryRun) {
                return $this->runDryRun($service);
            }

            $report  = $service->run(['dry_run' => false, 'rebuild' => $rebuild], $context->userId);

            $ok          = (bool)   ($report['ok']                       ?? false);
            $status      = (string) ($report['status']                   ?? 'unknown');
            $durationMs  = (int)    ($report['duration_ms']              ?? 0);
            $skeletons   = (int)    ($report['skeletons_created']        ?? 0);
            $matched     = (int)    ($report['bairros_matched']          ?? 0);
            $queued      = (int)    ($report['queued_for_enrichment']    ?? 0);
            $geoFact     = (int)    ($report['geo_fact_inserted']        ?? 0);
            $geoDaily    = (int)    ($report['geo_daily_inserted']       ?? 0);
            $compGeo     = (int)    ($report['compradores_geo_inserted'] ?? 0);
            $vendasCnae  = (int)    ($report['vendas_cnae_inserted']     ?? 0);
            $coverage    = is_array($report['coverage']     ?? null) ? $report['coverage']     : [];
            $zeroReasons = is_array($report['zero_reasons'] ?? null) ? $report['zero_reasons'] : [];

            $warnings = is_array($report['warnings'] ?? null) ? array_values(array_map('strval', $report['warnings'])) : [];
            $errors   = is_array($report['errors']   ?? null) ? array_values(array_map('strval', $report['errors']))   : [];

            $lines = [
                "Esqueletos geo criados   : {$skeletons}",
                "Bairros com match oficial: {$matched}",
                "Enfileirados enriquecimento: {$queued}",
                "Geo fact inseridos       : {$geoFact}",
                "Geo diaria inseridos     : {$geoDaily}",
                "mart_compradores_por_geo : {$compGeo}",
                "mart_vendas_cnae_cnpj    : {$vendasCnae}",
            ];

            if ($coverage !== []) {
                $lines[] = "Cobertura geo — total: " . ($coverage['total_geo'] ?? '?')
                    . " | com bairro oficial: " . ($coverage['com_bairro_oficial'] ?? '?')
                    . " | sem: " . ($coverage['sem_bairro_oficial'] ?? '?');
            }

            foreach ($zeroReasons as $key => $msg) {
                $warnings[] = "[{$key}] {$msg}";
            }

            $metrics = [
                'status'                   => $status,
                'skeletons_created'        => $skeletons,
                'bairros_matched'          => $matched,
                'queued_for_enrichment'    => $queued,
                'compradores_geo_inserted' => $compGeo,
                'vendas_cnae_inserted'     => $vendasCnae,
                'duration_ms'              => $durationMs,
            ];

            if ($ok) {
                return AdminCommandResult::ok(
                    'Geo / CNAE',
                    sprintf(
                        'Geo materializado: %d skeletons, %d bairros, %d compradores_geo, %d vendas_cnae.',
                        $skeletons, $matched, $compGeo, $vendasCnae
                    ),
                    outputLines: $lines,
                    warnings:    $warnings,
                    metrics:     $metrics,
                )->withDurationMs($durationMs);
            }

            if ($errors === [] && $warnings !== []) {
                return AdminCommandResult::warning(
                    'Geo / CNAE',
                    "Materializacao geo concluida com avisos. Status: {$status}.",
                    outputLines: $lines,
                    warnings:    $warnings,
                )->withDurationMs($durationMs);
            }

            return AdminCommandResult::error(
                'Geo / CNAE',
                "Materializacao geo nao concluida. Status: {$status}.",
                errors: $errors !== [] ? $errors : ["Status final: {$status}"],
            );

        } catch (Throwable $e) {
            $this->logger->error('AdminConsole: geo:materialize falhou.', [
                'message' => $e->getMessage(),
                'user_id' => $context->userId,
            ]);
            return AdminCommandResult::error(
                'Geo / CNAE',
                'Nao foi possivel executar a materializacao geo agora.',
                errors: [$e->getMessage()],
            );
        }
    }

    private function runDryRun(GeoCnaeMaterializationService $service): AdminCommandResult
    {
        $snap  = $service->dryRunSnapshot();
        $lines = ['[DRY RUN] Nenhum dado sera alterado.', ''];

        // Prerequisites table
        $tableRows = [];
        foreach ($snap['tables'] ?? [] as $table => $info) {
            $tableRows[] = [
                $table,
                $info['exists'] ? 'ok' : 'AUSENTE',
                number_format((int) ($info['count'] ?? 0), 0, ',', '.'),
            ];
        }

        // Schema detection
        $schemaRows = [];
        foreach ($snap['schema'] ?? [] as $check => $present) {
            $schemaRows[] = [$check, $present ? 'sim' : 'nao'];
        }

        // Geo coverage
        $cov = $snap['geo_coverage'] ?? [];
        if ($cov !== []) {
            $lines[] = 'Cobertura geo atual:';
            $lines[] = "  Total geo_dim      : " . ($cov['total'] ?? 0);
            $lines[] = "  Com bairro_norm    : " . ($cov['com_bairro_norm'] ?? 0);
            $lines[] = "  Com bairro_raw     : " . ($cov['com_bairro_raw'] ?? 0);
            $lines[] = "  Com match oficial  : " . ($cov['com_match_oficial'] ?? 0);
            $lines[] = "  Sem bairro_norm    : " . ($cov['sem_bairro_norm'] ?? 0);
            $lines[] = "  Clientes CE        : " . ($cov['clientes_ce'] ?? 0);
            $lines[] = '';
        }

        // Client dim stats
        $dimStats = $snap['clientes_dim_stats'] ?? [];
        if ($dimStats !== []) {
            $lines[] = 'Clientes dim:';
            $lines[] = "  Total     : " . ($dimStats['total'] ?? 0);
            $lines[] = "  Com doc   : " . ($dimStats['com_doc'] ?? 0);
            $lines[] = "  Com vend  : " . ($dimStats['com_vend'] ?? 0);
            $lines[] = "  Com CNAE  : " . ($dimStats['com_cnae'] ?? 0) . " / sem CNAE: " . ($dimStats['sem_cnae'] ?? 0);
            $lines[] = "  Com cidade: " . ($dimStats['com_cidade'] ?? 0);
            $lines[] = '';
        }

        // Bairros sem match
        $semMatch = $snap['bairros_sem_match'] ?? [];
        if ($semMatch !== []) {
            $lines[] = 'Top bairros sem match (para criar alias):';
            foreach ($semMatch as $r) {
                $lines[] = "  '{$r['bairro_norm']}' — {$r['clientes']} cliente(s)";
            }
            $lines[] = '';
        } elseif (($cov['com_bairro_norm'] ?? 0) === 0) {
            $lines[] = 'Nenhum bairro_norm preenchido. Endereco precisa ser sincronizado via Omie ou enriquecido.';
            $lines[] = '';
        }

        // Estimates
        $est = $snap['estimates'] ?? [];
        $lines[] = 'Estimativas de saida:';
        $lines[] = "  Skeletons a criar      : " . ($est['skeletons_a_criar']     ?? 0);
        $lines[] = "  Bairros a fazer match  : " . ($est['bairros_a_fazer_match'] ?? 0);
        $lines[] = "  A enfileirar           : " . ($est['clientes_a_enfileirar'] ?? 0);
        $lines[] = "  mart_compradores_geo   : ~" . ($est['compradores_geo_est']  ?? 0) . " (de mart_cliente_periodo)";

        $tables = [
            [
                'title'   => 'Tabelas (dry run)',
                'headers' => ['Tabela', 'Status', 'Registros'],
                'rows'    => $tableRows,
            ],
            [
                'title'   => 'Schema detectado',
                'headers' => ['Verificacao', 'Resultado'],
                'rows'    => $schemaRows,
            ],
        ];

        return AdminCommandResult::ok(
            'Geo / CNAE (dry run)',
            'Simulacao concluida. Nenhum dado foi alterado. Execute sem dry_run para materializar.',
            outputLines: $lines,
            tables:      $tables,
        );
    }
}
