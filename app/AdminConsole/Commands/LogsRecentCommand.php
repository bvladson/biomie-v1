<?php

declare(strict_types=1);

namespace BIomie\AdminConsole\Commands;

use BIomie\AdminConsole\AdminCommandContext;
use BIomie\AdminConsole\AdminCommandInterface;
use BIomie\AdminConsole\AdminCommandResult;
use PDO;
use Throwable;

final class LogsRecentCommand implements AdminCommandInterface
{
    private const ALLOWED_LEVELS = ['ALL', 'INFO', 'WARNING', 'ERROR'];
    private const ALLOWED_DOMAINS = ['ALL', 'omie', 'gold', 'migrations', 'http', 'sync', 'admin'];

    public function __construct(private readonly PDO $pdo) {}

    public function name(): string { return 'logs:recent'; }
    public function description(): string { return 'Exibe ultimos logs operacionais (limit, level, domain).'; }
    public function group(): string { return 'logs'; }
    public function permission(): string { return 'admin.console'; }
    public function isDestructive(): bool { return false; }
    public function requiresConfirmation(): bool { return false; }

    /**
     * @param array<string,string> $args
     */
    public function validateArgs(array $args): ?string
    {
        if (isset($args['limit'])) {
            $limit = (int) $args['limit'];
            if ($limit < 5 || $limit > 50) {
                return 'Parametro limit deve estar entre 5 e 50.';
            }
        }
        if (isset($args['level']) && !in_array(strtoupper($args['level']), self::ALLOWED_LEVELS, true)) {
            return 'Parametro level invalido. Use: ' . implode(', ', self::ALLOWED_LEVELS) . '.';
        }
        if (isset($args['domain']) && !in_array(strtolower($args['domain']), array_map('strtolower', self::ALLOWED_DOMAINS), true)) {
            return 'Parametro domain invalido. Use: ' . implode(', ', self::ALLOWED_DOMAINS) . '.';
        }
        return null;
    }

    public function run(AdminCommandContext $context, array $args = []): AdminCommandResult
    {
        $limit = max(5, min(50, (int) ($args['limit'] ?? 20)));
        $level = strtoupper($args['level'] ?? 'ALL');
        $domain = strtolower($args['domain'] ?? 'all');

        try {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
            );
            $stmt->execute([':t' => 'biomie_operational_events']);
            $tableExists = (int) $stmt->fetchColumn() > 0;

            if (!$tableExists) {
                return AdminCommandResult::warning(
                    'Logs recentes',
                    'Tabela biomie_operational_events nao existe. Execute as migrations.',
                    warnings: ['Tabela de eventos operacionais ausente.'],
                );
            }

            // Build safe parameterized query using allowlisted values only
            $whereClauses = [];
            $params = [];

            if ($level !== 'ALL') {
                // $level is already validated against ALLOWED_LEVELS
                $whereClauses[] = 'level_name = :level';
                $params[':level'] = $level;
            }

            if ($domain !== 'all') {
                // $domain is already validated against ALLOWED_DOMAINS
                $whereClauses[] = 'domain_name = :domain';
                $params[':domain'] = $domain;
            }

            $where = $whereClauses !== [] ? 'WHERE ' . implode(' AND ', $whereClauses) : '';
            $sql = "SELECT id, level_name, event_name, domain_name, source_name, summary, occurred_at
                    FROM biomie_operational_events
                    {$where}
                    ORDER BY id DESC
                    LIMIT :limit";

            $stmt = $this->pdo->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value, PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();

            $events = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return AdminCommandResult::error(
                'Logs recentes',
                'Nao foi possivel consultar os logs operacionais.',
                errors: ['Erro interno registrado no log.'],
            );
        }

        if ($events === []) {
            return AdminCommandResult::ok(
                'Logs recentes',
                'Nenhum evento encontrado para os filtros informados.',
            );
        }

        $rows = [];
        foreach ($events as $event) {
            $summary = mb_substr((string) ($event['summary'] ?? ''), 0, 80);
            $rows[] = [
                (string) ($event['occurred_at'] ?? '-'),
                (string) ($event['level_name'] ?? '-'),
                (string) ($event['domain_name'] ?? '-'),
                (string) ($event['event_name'] ?? '-'),
                $summary,
            ];
        }

        $tables = [[
            'title'   => "Logs recentes ({$limit} mais recentes)",
            'headers' => ['Data/Hora', 'Level', 'Dominio', 'Evento', 'Resumo'],
            'rows'    => $rows,
        ]];

        $filterDesc = [];
        if ($level !== 'ALL') { $filterDesc[] = "level={$level}"; }
        if ($domain !== 'all') { $filterDesc[] = "domain={$domain}"; }
        $filterStr = $filterDesc !== [] ? ' (' . implode(', ', $filterDesc) . ')' : '';

        return AdminCommandResult::ok(
            'Logs recentes',
            sprintf('%d evento(s) encontrado(s)%s.', count($events), $filterStr),
            tables: $tables,
            metrics: ['count' => count($events), 'limit' => $limit],
        );
    }
}
