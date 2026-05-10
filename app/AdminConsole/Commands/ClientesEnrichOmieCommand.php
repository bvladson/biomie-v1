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

final class ClientesEnrichOmieCommand implements AdminCommandInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly string $connectionName,
    ) {}

    public function name(): string        { return 'clientes:enrich-omie'; }
    public function description(): string { return 'Enriquece clientes_dim com endereco/CNAE via API Omie. Padrao: local-first (ConsultarCliente por codigo).'; }
    public function group(): string       { return 'clientes'; }
    public function permission(): string  { return 'admin.console'; }
    public function isDestructive(): bool { return false; }
    public function requiresConfirmation(): bool { return false; }

    /**
     * @param array<string,string> $args
     */
    public function validateArgs(array $args): ?string
    {
        $limit = (int) ($args['limit'] ?? 25);
        if ($limit < 1 || $limit > 100) {
            return 'Parametro limit deve ser entre 1 e 100.';
        }
        $offset = (int) ($args['offset'] ?? 0);
        if ($offset < 0) {
            return 'Parametro offset deve ser >= 0.';
        }
        $mode = $args['mode'] ?? 'local_first';
        if (!in_array($mode, ['local_first', 'omie_page'], true)) {
            return 'Parametro mode deve ser local_first ou omie_page.';
        }
        if ($mode === 'omie_page') {
            $pagina = (int) ($args['pagina'] ?? 1);
            if ($pagina < 1) {
                return 'Parametro pagina deve ser >= 1.';
            }
        }
        return null;
    }

    /**
     * @param array<string,string> $args
     */
    public function run(AdminCommandContext $context, array $args = []): AdminCommandResult
    {
        if (!$this->config->hasOmieCredentials()) {
            return AdminCommandResult::error(
                'Enriquecimento Omie',
                'Credenciais Omie nao configuradas. Configure APP_KEY e APP_SECRET antes de executar o enriquecimento.'
            );
        }

        $dryRun      = in_array($args['dry_run'] ?? '0', ['1', 'true'], true);
        $mode        = $args['mode'] ?? 'local_first';
        $limit       = max(1, min(100, (int) ($args['limit'] ?? 25)));
        $offset      = max(0, (int) ($args['offset'] ?? 0));
        $onlyMissing = !in_array($args['only_missing'] ?? '1', ['0', 'false'], true);
        $pagina      = max(1, (int) ($args['pagina'] ?? 1));

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

            $service = new CustomerOmieEnrichmentService(
                $pdo,
                $client,
                $this->logger,
                $this->config,
            );

            $report = $service->run([
                'dry_run'      => $dryRun,
                'mode'         => $mode,
                'limit'        => $limit,
                'offset'       => $offset,
                'only_missing' => $onlyMissing,
                'pagina'       => $pagina,
            ], $context->userId);

        } catch (Throwable $e) {
            $this->logger->error('ClientesEnrichOmieCommand: falha.', [
                'message' => $e->getMessage(),
                'user_id' => $context->userId,
            ]);
            return AdminCommandResult::error(
                'Enriquecimento Omie',
                'Nao foi possivel executar o enriquecimento.',
                errors: [SecretMask::sanitizeErrorMessage($e->getMessage(), 300)]
            );
        }

        $ok     = (bool) ($report['ok'] ?? false);
        $status = (string) ($report['status'] ?? 'unknown');

        $appKeyMasked = SecretMask::maskSecret((string) $this->config->get('omie.app_key', '')) ?? '(nao configurado)';
        $modeLabel    = $mode === 'omie_page' ? 'omie_page (ListarClientes)' : 'local_first (ConsultarCliente)';

        $lines = [
            ($dryRun ? '[DRY RUN] Nenhum dado foi alterado.' : '[REAL] Dados foram atualizados.'),
            "Modo                   : {$modeLabel}",
            '',
        ];

        if ($mode === 'local_first') {
            $lines[] = "Clientes locais lidos   : {$report['clientes_locais_lidos']}";
            $lines[] = "Total elegíveis         : {$report['total_eligible']}";
            $lines[] = "Nao encontrados (Omie)  : {$report['clientes_nao_encontrados']}";
            $hasMore = (bool) ($report['has_more'] ?? false);
            $nextOffset = $report['next_offset'] ?? null;
            if ($hasMore && $nextOffset !== null) {
                $lines[] = "Proximo offset          : {$nextOffset}";
            }
        } else {
            $lines[] = "Pagina Omie             : {$report['pagina_atual']} / {$report['total_paginas_omie']}";
            $lines[] = "Total Omie              : {$report['total_registros_omie']} registros";
        }

        $lines[] = '';
        $lines[] = "Consultados Omie        : {$report['clientes_consultados_omie']}";
        $lines[] = "Atualizados             : {$report['clientes_atualizados']}";
        $lines[] = "Ignorados               : {$report['clientes_ignorados']}";
        $lines[] = "Erros API               : {$report['api_errors']}";
        $lines[] = "Rate limited            : {$report['api_rate_limited']}";
        $lines[] = "Bronze salvo            : {$report['bronze_saved']}";
        $lines[] = '';
        $lines[] = "Com cidade (Omie)       : {$report['clientes_com_cidade']}";
        $lines[] = "Com bairro (Omie)       : {$report['clientes_com_bairro']}";
        $lines[] = "Com CNAE (Omie)         : {$report['clientes_com_cnae']}";
        $lines[] = "Sem bairro (Omie)       : {$report['clientes_sem_bairro']}";
        $lines[] = "Sem CNAE (Omie)         : {$report['clientes_sem_cnae']}";
        $lines[] = '';
        $lines[] = "Cidade atualizados      : {$report['cidade_atualizados']}";
        $lines[] = "Bairro atualizados      : {$report['bairro_atualizados']}";
        $lines[] = "CNAE atualizados        : {$report['cnae_atualizados']}";
        $lines[] = '';
        $lines[] = "Bairros matched         : {$report['bairros_matched']}";
        $lines[] = "Bairros nao mapeados    : {$report['bairros_unmatched']}";
        $lines[] = "Na fila revisao         : {$report['queued']}";
        $lines[] = '';
        $lines[] = "App Key                 : {$appKeyMasked}";

        if ($dryRun && !empty($report['preview'])) {
            $lines[] = '';
            $lines[] = 'Amostra (10 primeiros):';
            foreach ($report['preview'] as $p) {
                $matchInfo = $p['bairro_match'] !== null ? "→ {$p['bairro_match']}" : '(sem match)';
                $lines[] = sprintf(
                    '  [%d] %s — cidade: %s/%s bairro: %s %s cnae: %s',
                    $p['codigo_cliente_omie'] ?? 0,
                    mb_substr((string) ($p['razao_social'] ?? ''), 0, 35),
                    $p['cidade'] !== '' ? $p['cidade'] : '(vazio)',
                    $p['uf'] !== '' ? $p['uf'] : '?',
                    $p['bairro'] !== '' ? $p['bairro'] : '(sem bairro)',
                    $p['bairro'] !== '' ? $matchInfo : '',
                    $p['cnae'] !== '' ? $p['cnae'] : '(sem CNAE)',
                );
            }
        }

        $metrics = [
            'clientes_locais_lidos'      => $report['clientes_locais_lidos'],
            'total_eligible'             => $report['total_eligible'],
            'clientes_consultados_omie'  => $report['clientes_consultados_omie'],
            'clientes_atualizados'       => $report['clientes_atualizados'],
            'clientes_ignorados'         => $report['clientes_ignorados'],
            'clientes_nao_encontrados'   => $report['clientes_nao_encontrados'],
            'api_errors'                 => $report['api_errors'],
            'api_rate_limited'           => $report['api_rate_limited'],
            'bronze_saved'               => $report['bronze_saved'],
            'clientes_com_cidade'        => $report['clientes_com_cidade'],
            'clientes_com_bairro'        => $report['clientes_com_bairro'],
            'clientes_com_cnae'          => $report['clientes_com_cnae'],
            'clientes_sem_bairro'        => $report['clientes_sem_bairro'],
            'clientes_sem_cnae'          => $report['clientes_sem_cnae'],
            'cidade_atualizados'         => $report['cidade_atualizados'],
            'bairro_atualizados'         => $report['bairro_atualizados'],
            'cnae_atualizados'           => $report['cnae_atualizados'],
            'bairros_matched'            => $report['bairros_matched'],
            'bairros_unmatched'          => $report['bairros_unmatched'],
            'queued'                     => $report['queued'],
            'not_found'                  => $report['not_found'],
            'has_more'                   => (int) ($report['has_more'] ?? 0),
            'next_offset'                => $report['next_offset'] ?? null,
            'duration_ms'                => $report['duration_ms'],
        ];

        if (!$ok && isset($report['error_message'])) {
            $safeMsg     = SecretMask::sanitizeErrorMessage($report['error_message'], 300);
            $isRateLimit = ($report['api_rate_limited'] ?? 0) > 0;
            return AdminCommandResult::error(
                'Enriquecimento Omie',
                $isRateLimit
                    ? 'Limite Omie atingido. Aguarde 60 segundos antes de continuar.'
                    : $safeMsg,
                errors: [$safeMsg],
            );
        }

        if ($status === 'no_clients') {
            return AdminCommandResult::ok(
                'Enriquecimento Omie',
                'Enriquecimento concluido para os clientes locais elegiveis.',
                outputLines: $lines,
                metrics: $metrics,
            )->withDurationMs($report['duration_ms']);
        }

        $hasMore = (bool) ($report['has_more'] ?? false);
        $nextOff = $report['next_offset'] ?? null;

        if ($dryRun) {
            $summary = "Dry run: {$report['clientes_consultados_omie']} clientes consultados. Nenhum dado alterado.";
        } elseif ($hasMore && $nextOff !== null) {
            $summary = "Lote concluido. {$report['clientes_atualizados']} enriquecidos. Execute novamente com offset={$nextOff} para continuar.";
        } else {
            $matchLine = $report['bairros_matched'] > 0
                ? " {$report['bairros_matched']} bairros mapeados."
                : ($report['bairros_unmatched'] > 0 ? " {$report['bairros_unmatched']} bairros nao mapeados (fila revisao)." : '');
            $summary = "Enriquecimento concluido para os clientes locais elegiveis. {$report['clientes_atualizados']} enriquecidos.{$matchLine}";
        }

        if ($status === 'rate_limited') {
            return AdminCommandResult::warning(
                'Enriquecimento Omie',
                'Limite Omie atingido. Aguarde 60 segundos antes de continuar.',
                outputLines: $lines,
                warnings: ['API Omie bloqueou por consumo redundante.'],
            )->withDurationMs($report['duration_ms']);
        }

        if ($status === 'partial') {
            return AdminCommandResult::warning(
                'Enriquecimento Omie',
                $summary,
                outputLines: $lines,
                warnings: ["Houve {$report['api_errors']} erro(s) durante o enriquecimento. Verifique os logs."],
            )->withDurationMs($report['duration_ms']);
        }

        return AdminCommandResult::ok(
            'Enriquecimento Omie',
            $summary,
            outputLines: $lines,
            metrics: $metrics,
        )->withDurationMs($report['duration_ms']);
    }
}
