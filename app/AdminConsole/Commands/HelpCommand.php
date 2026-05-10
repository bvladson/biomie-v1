<?php

declare(strict_types=1);

namespace BIomie\AdminConsole\Commands;

use BIomie\AdminConsole\AdminCommandContext;
use BIomie\AdminConsole\AdminCommandInterface;
use BIomie\AdminConsole\AdminCommandRegistry;
use BIomie\AdminConsole\AdminCommandResult;

final class HelpCommand implements AdminCommandInterface
{
    public function __construct(private readonly AdminCommandRegistry $registry) {}

    public function name(): string { return 'help'; }
    public function description(): string { return 'Lista todos os comandos disponiveis para o usuario atual.'; }
    public function group(): string { return 'sistema'; }
    public function permission(): string { return 'admin.console'; }
    public function isDestructive(): bool { return false; }
    public function requiresConfirmation(): bool { return false; }

    public function validateArgs(array $args): ?string { return null; }

    public function run(AdminCommandContext $context, array $args = []): AdminCommandResult
    {
        $groups = [];
        foreach ($this->registry->list() as $command) {
            $group = $command->group();
            $groups[$group][] = [
                'command'      => $command->name(),
                'description'  => $command->description(),
                'permission'   => $command->permission(),
                'destructive'  => $command->isDestructive() ? 'sim' : 'nao',
                'confirmacao'  => $command->requiresConfirmation() ? 'sim' : 'nao',
                'disponivel'   => in_array($command->permission(), $context->permissions, true) ? 'sim' : 'nao',
            ];
        }

        $tables = [];
        $groupLabels = [
            'sistema'    => 'Sistema',
            'banco'      => 'Banco de Dados',
            'migrations' => 'Migrations',
            'gold'       => 'Gold / Marts',
            'clientes'   => 'Clientes / Dimensoes',
            'cnae'       => 'CNAE / Fiscal',
            'geo'        => 'Geo / Mapa',
            'omie'       => 'Omie / Integracao',
            'logs'       => 'Logs',
            'rbac'       => 'RBAC / Perfis',
        ];

        foreach ($groupLabels as $key => $label) {
            if (!isset($groups[$key])) {
                continue;
            }
            $tables[] = [
                'title'   => $label,
                'headers' => ['Comando', 'Descricao', 'Permissao', 'Destrutivo', 'Confirmacao', 'Disponivel'],
                'rows'    => array_map(
                    static fn (array $r): array => [
                        $r['command'],
                        $r['description'],
                        $r['permission'],
                        $r['destructive'],
                        $r['confirmacao'],
                        $r['disponivel'],
                    ],
                    $groups[$key]
                ),
            ];
        }

        return AdminCommandResult::ok(
            'Comandos disponíveis',
            sprintf('%d comandos registrados no console administrativo.', count($this->registry->list())),
            tables: $tables,
        );
    }
}
