<?php

declare(strict_types=1);

namespace BIomie\AdminConsole\Commands;

use BIomie\AdminConsole\AdminCommandContext;
use BIomie\AdminConsole\AdminCommandInterface;
use BIomie\AdminConsole\AdminCommandResult;
use BIomie\Bootstrap;
use BIomie\Cache;
use BIomie\Config;
use BIomie\CustomerOmieEnrichmentService;
use BIomie\Database;
use BIomie\Logger;
use BIomie\OmieClient;
use BIomie\OmieMethodPolicyRegistry;
use BIomie\Support\SecretMask;
use Throwable;

/**
 * Enriquece todos os clientes elegíveis em lotes sem OFFSET sobre lista mutável.
 * Usa cursor por codigo_cliente_omie para garantir que clientes recém-enriquecidos
 * não sejam saltados nem reprocessados dentro do mesmo run.
 */
final class ClientesEnrichOmieAllCommand implements AdminCommandInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly string $connectionName,
    ) {}

    public function name(): string        { return 'clientes:enrich-omie-all'; }
    public function description(): string { return 'Enriquece clientes elegíveis em lotes (cursor, sem OFFSET mutável). target=geo (padrão) enriquece só endereço; target=cnae só CNAE; target=all ambos. source=sales_buyers (padrão) prioriza clientes com vendas.'; }
    public function group(): string       { return 'clientes'; }
    public function permission(): string  { return 'admin.console'; }
    public function isDestructive(): bool { return false; }
    public function requiresConfirmation(): bool { return false; }

    public function validateArgs(array $args): ?string
    {
        $limit = (int) ($args['limit'] ?? 25);
        if ($limit < 1 || $limit > 50) {
            return 'Parametro limit deve ser entre 1 e 50.';
        }
        $maxBatches = (int) ($args['max_batches'] ?? 20);
        if ($maxBatches < 1 || $maxBatches > 200) {
            return 'Parametro max_batches deve ser entre 1 e 200.';
        }
        $sleepMs = (int) ($args['sleep_ms'] ?? 0);
        if ($sleepMs < 0 || $sleepMs > 10000) {
            return 'Parametro sleep_ms deve ser entre 0 e 10000.';
        }
        $target = $args['target'] ?? 'geo';
        if (!in_array($target, ['geo', 'cnae', 'all'], true)) {
            return 'Parametro target deve ser geo, cnae ou all.';
        }
        $source = $args['source'] ?? 'sales_buyers';
        if (!in_array($source, ['sales_buyers', 'clientes_dim', 'all'], true)) {
            return 'Parametro source deve ser sales_buyers, clientes_dim ou all.';
        }
        return null;
    }

    public function run(AdminCommandContext $context, array $args = []): AdminCommandResult
    {
        if (!$this->config->hasOmieCredentials()) {
            return AdminCommandResult::error(
                'Enriquecimento Omie (All)',
                'Credenciais Omie nao configuradas. Configure APP_KEY e APP_SECRET antes de executar.'
            );
        }

        $dryRun         = in_array($args['dry_run'] ?? '0', ['1', 'true'], true);
        $limit          = max(1, min(50, (int) ($args['limit']              ?? 25)));
        $maxBatches     = max(1, min(200, (int) ($args['max_batches']       ?? 20)));
        $onlyMissing    = !in_array($args['only_missing']    ?? '1', ['0', 'false'], true);
        $sleepMs        = max(0, (int) ($args['sleep_ms']          ?? 0));
        $maxRuntimeSecs = max(0, (int) ($args['max_runtime_seconds'] ?? 0));
        $target         = in_array($args['target'] ?? '', ['geo', 'cnae', 'all'], true) ? $args['target'] : 'geo';
        $source         = in_array($args['source'] ?? '', ['sales_buyers', 'clientes_dim', 'all'], true) ? $args['source'] : 'sales_buyers';

        try {
            $pdo    = Database::connect($this->config, $this->connectionName);
            $cache  = new Cache(Bootstrap::rootPath('storage/cache'));
            $client = new OmieClient(
                (string) $this->config->get('omie.app_key'),
                (string) $this->config->get('omie.app_secret'),
                30,
                $this->logger,
                new OmieMethodPolicyRegistry($this->config),
                $cache,
            );

            $service = new CustomerOmieEnrichmentService($pdo, $client, $this->logger, $this->config);

            $report = $service->runAll([
                'dry_run'              => $dryRun,
                'limit'                => $limit,
                'max_batches'          => $maxBatches,
                'only_missing'         => $onlyMissing,
                'sleep_ms'             => $sleepMs,
                'max_runtime_seconds'  => $maxRuntimeSecs,
                'target'               => $target,
                'source'               => $source,
            ], $context->userId);

        } catch (Throwable $e) {
            $this->logger->error('ClientesEnrichOmieAllCommand: falha.', [
                'message' => $e->getMessage(), 'user_id' => $context->userId,
            ]);
            return AdminCommandResult::error(
                'Enriquecimento Omie (All)',
                'Nao foi possivel executar o enriquecimento.',
                errors: [SecretMask::sanitizeErrorMessage($e->getMessage(), 300)]
            );
        }

        $appKeyMasked = SecretMask::maskSecret((string) $this->config->get('omie.app_key', '')) ?? '(nao configurado)';

        $lines = [
            ($dryRun ? '[DRY RUN] Nenhum dado foi alterado.' : '[REAL] Dados foram atualizados.'),
            "Modo                     : local_first_all (cursor, sem OFFSET mutável)",
            "Alvo (target)            : {$target}",
            "Fonte (source)           : {$source}",
            '',
            "Elegíveis antes           : {$report['total_eligible_before']}",
            "Restantes após            : {$report['total_remaining_after']}",
            "Lotes executados          : {$report['batches_executed']} / {$report['max_batches']}",
            "Parado por               : {$report['stopped_reason']}",
            '',
            "Processados (ConsultarCliente) : {$report['processed']}",
            "Atualizados              : {$report['updated']}",
            "Ignorados (sem mudança)  : {$report['ignored']}",
            "Nao encontrados (Omie)   : {$report['not_found']}",
            "Erros API                : {$report['api_errors']}",
            "Rate limited             : {$report['rate_limited']}",
            "Bronze salvo             : {$report['bronze_saved']}",
            '',
            "Com cidade (retorno Omie): {$report['with_city_from_omie']}",
            "Com bairro (retorno Omie): {$report['with_bairro_from_omie']}",
            "Com CNAE (retorno Omie)  : {$report['with_cnae_from_omie']}",
            '',
            "Cidade atualizado        : {$report['cidade_updated']}",
            "Bairro atualizado        : {$report['bairro_updated']}",
            "CNAE atualizado          : {$report['cnae_updated']}",
            '',
            "Bairros matched oficial  : {$report['bairro_matched']}",
            "Bairros sem match        : {$report['bairro_unmatched']}",
            "Na fila revisao          : {$report['queued_enrichment']}",
            '',
            "App Key                  : {$appKeyMasked}",
        ];

        if ($report['has_more']) {
            $lines[] = '';
            $lines[] = "AVISO: Ainda existem {$report['total_remaining_after']} clientes elegíveis.";
            $lines[] = "Execute novamente para continuar (o cursor recomeça do código 0, pulando já processados).";
        }

        $status       = (string) ($report['status'] ?? 'unknown');
        $rateLimited  = (int) ($report['rate_limited'] ?? 0);
        $apiErrors    = (int) ($report['api_errors']   ?? 0);

        $metrics = [
            'total_eligible_before'  => $report['total_eligible_before'],
            'total_remaining_after'  => $report['total_remaining_after'],
            'batches_executed'       => $report['batches_executed'],
            'processed'              => $report['processed'],
            'updated'                => $report['updated'],
            'not_found'              => $report['not_found'],
            'api_errors'             => $apiErrors,
            'rate_limited'           => $rateLimited,
            'with_city_from_omie'    => $report['with_city_from_omie'],
            'with_bairro_from_omie'  => $report['with_bairro_from_omie'],
            'with_cnae_from_omie'    => $report['with_cnae_from_omie'],
            'cidade_updated'         => $report['cidade_updated'],
            'bairro_updated'         => $report['bairro_updated'],
            'cnae_updated'           => $report['cnae_updated'],
            'bairro_matched'         => $report['bairro_matched'],
            'bairro_unmatched'       => $report['bairro_unmatched'],
            'has_more'               => (int) ($report['has_more'] ?? 0),
            'duration_ms'            => $report['duration_ms'],
        ];

        if ($dryRun) {
            return AdminCommandResult::ok(
                'Enriquecimento Omie (All)',
                "Dry run: {$report['processed']} clientes consultados. Nenhum dado alterado.",
                outputLines: $lines,
                metrics: $metrics,
            )->withDurationMs($report['duration_ms']);
        }

        if ($status === 'rate_limited') {
            return AdminCommandResult::warning(
                'Enriquecimento Omie (All)',
                'Limite Omie atingido. Aguarde 60 segundos e execute novamente.',
                outputLines: $lines,
                warnings: ['API Omie bloqueou por consumo redundante. Progresso parcial salvo.'],
            )->withDurationMs($report['duration_ms']);
        }

        if ($apiErrors > 0) {
            return AdminCommandResult::warning(
                'Enriquecimento Omie (All)',
                "{$report['updated']} clientes enriquecidos com {$apiErrors} erro(s). Verifique os logs.",
                outputLines: $lines,
                warnings: ["Houve {$apiErrors} erro(s) de API. Alguns clientes podem nao ter sido processados."],
            )->withDurationMs($report['duration_ms']);
        }

        $summary = $report['has_more']
            ? "{$report['updated']} clientes enriquecidos neste run. Ainda restam {$report['total_remaining_after']}."
            : "{$report['updated']} clientes enriquecidos. Todos os elegíveis foram processados.";

        return AdminCommandResult::ok(
            'Enriquecimento Omie (All)',
            $summary,
            outputLines: $lines,
            metrics: $metrics,
        )->withDurationMs($report['duration_ms']);
    }
}
