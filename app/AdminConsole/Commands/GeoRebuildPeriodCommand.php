<?php

declare(strict_types=1);

namespace BIomie\AdminConsole\Commands;

use BIomie\AdminConsole\AdminCommandContext;
use BIomie\AdminConsole\AdminCommandInterface;
use BIomie\AdminConsole\AdminCommandResult;
use BIomie\DataPlatform\Gold\GeoPeriodRebuildService;
use BIomie\Logger;
use PDO;
use Throwable;

final class GeoRebuildPeriodCommand implements AdminCommandInterface
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Logger $logger,
    ) {
    }

    public function name(): string { return 'geo:rebuild-period'; }
    public function description(): string { return 'Reconstroi mart_cliente_periodo e mart_compradores_por_geo por periodo/competencia com lock e transacao.'; }
    public function group(): string { return 'geo'; }
    public function permission(): string { return 'admin.console'; }
    public function isDestructive(): bool { return false; }
    public function requiresConfirmation(): bool { return false; }

    /** @param array<string,string> $args */
    public function validateArgs(array $args): ?string
    {
        $start = trim($args['start'] ?? '');
        $end = trim($args['end'] ?? '');
        if ($start === '' || $end === '') {
            return 'Informe start e end (YYYY-MM-DD). Ex: start=2026-05-01 end=2026-05-31';
        }
        foreach (['start' => $start, 'end' => $end] as $name => $value) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                return "Parametro {$name} invalido: use YYYY-MM-DD.";
            }
        }
        if ($start > $end) {
            return 'start deve ser anterior ou igual a end.';
        }
        $months = ((int) substr($end, 0, 4) - (int) substr($start, 0, 4)) * 12
            + ((int) substr($end, 5, 2) - (int) substr($start, 5, 2));
        if ($months > 3) {
            return 'Maximo de 3 meses por rebuild. Divida o periodo.';
        }
        return null;
    }

    /** @param array<string,string> $args */
    public function run(AdminCommandContext $context, array $args = []): AdminCommandResult
    {
        try {
            $service = new GeoPeriodRebuildService($this->pdo, $this->logger);
            $result = $service->rebuild((string) $args['start'], (string) $args['end'], [
                'assert' => $this->truthy($args['assert'] ?? '0'),
                'dry_run' => $this->truthy($args['dry_run'] ?? '0'),
                'force_full_month' => $this->truthy($args['force_full_month'] ?? '0'),
                'allow_open_month' => $this->truthy($args['allow_open_month'] ?? '0'),
                'actor_user_id' => $context->userId,
                'source' => 'admin_console_geo_rebuild_period',
            ]);

            $lines = $this->formatLines($result);
            $metrics = [
                'status' => $result['status'] ?? 'unknown',
                'mart_cliente_periodo_deleted' => (int) ($result['deleted']['mart_cliente_periodo'] ?? 0),
                'mart_cliente_periodo_inserted' => (int) ($result['inserted']['mart_cliente_periodo'] ?? 0),
                'mart_compradores_geo_deleted' => (int) ($result['deleted']['mart_compradores_por_geo'] ?? 0),
                'mart_compradores_geo_inserted' => (int) ($result['inserted']['mart_compradores_por_geo'] ?? 0),
                'duration_ms' => (int) ($result['duration_ms'] ?? 0),
            ];

            if (($result['status'] ?? '') === 'locked') {
                return new AdminCommandResult(
                    success: false,
                    status: 'locked',
                    title: 'Geo Rebuild Period',
                    message: 'Outro rebuild geografico esta em execucao.',
                    outputLines: $lines,
                    metrics: $metrics,
                    errors: array_values(array_map('strval', (array) ($result['errors'] ?? []))),
                    durationMs: (int) ($result['duration_ms'] ?? 0),
                );
            }

            if (!empty($result['ok'])) {
                $message = !empty($result['dry_run'])
                    ? 'Simulacao concluida. Nenhum dado foi alterado.'
                    : 'Rebuild geografico por periodo concluido.';

                return AdminCommandResult::ok(
                    'Geo Rebuild Period',
                    $message,
                    outputLines: $lines,
                    metrics: $metrics,
                    warnings: array_values(array_map('strval', (array) ($result['warnings'] ?? []))),
                )->withDurationMs((int) ($result['duration_ms'] ?? 0));
            }

            return new AdminCommandResult(
                success: false,
                status: (string) ($result['status'] ?? 'error'),
                title: 'Geo Rebuild Period',
                message: 'Rebuild geografico falhou.',
                outputLines: $lines,
                metrics: $metrics,
                errors: array_values(array_map('strval', (array) ($result['errors'] ?? []))),
                durationMs: (int) ($result['duration_ms'] ?? 0),
            );
        } catch (Throwable $exception) {
            $this->logger->error('geo:rebuild-period falhou.', [
                'message' => $exception->getMessage(),
                'actor' => $context->userId,
            ]);

            return AdminCommandResult::error(
                'Geo Rebuild Period',
                'Nao foi possivel executar o rebuild geografico.',
                [$exception->getMessage()],
            );
        }
    }

    private function truthy(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', 'true', 'sim', 'yes'], true);
    }

    /** @param array<string,mixed> $result @return list<string> */
    private function formatLines(array $result): array
    {
        $window = is_array($result['window'] ?? null) ? $result['window'] : [];
        $source = is_array($result['source'] ?? null) ? $result['source'] : [];
        $reconciliation = is_array($result['reconciliation'] ?? null) ? $result['reconciliation'] : [];
        $diagnosis = is_array($result['diagnosis'] ?? null) ? $result['diagnosis'] : [];

        return [
            'Status: ' . (string) ($result['status'] ?? 'unknown'),
            'Solicitado: ' . (string) ($window['requested_start'] ?? '-') . ' ate ' . (string) ($window['requested_end'] ?? '-'),
            'Efetivo: ' . (string) ($window['effective_start'] ?? '-') . ' ate ' . (string) ($window['effective_end'] ?? '-'),
            'Competencias: ' . implode(', ', (array) ($window['competencias'] ?? [])),
            'Modo: ' . (string) ($window['mode'] ?? '-'),
            'NFs canonicas autorizadas: ' . (int) ($source['canonical_nfs_autorizadas'] ?? 0),
            'mart_cliente_periodo deleted/inserted: '
                . (int) ($result['deleted']['mart_cliente_periodo'] ?? 0)
                . '/'
                . (int) ($result['inserted']['mart_cliente_periodo'] ?? 0),
            'mart_compradores_por_geo deleted/inserted: '
                . (int) ($result['deleted']['mart_compradores_por_geo'] ?? 0)
                . '/'
                . (int) ($result['inserted']['mart_compradores_por_geo'] ?? 0),
            'Reconciliacao: ' . (string) ($reconciliation['status'] ?? '-'),
            'Proxima acao: ' . (string) ($diagnosis['next_action'] ?? '-'),
        ];
    }
}
