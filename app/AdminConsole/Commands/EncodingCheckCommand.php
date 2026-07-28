<?php

declare(strict_types=1);

namespace BIomie\AdminConsole\Commands;

use BIomie\AdminConsole\AdminCommandContext;
use BIomie\AdminConsole\AdminCommandInterface;
use BIomie\AdminConsole\AdminCommandResult;
use BIomie\Bootstrap;

final class EncodingCheckCommand implements AdminCommandInterface
{
    private const CRITICAL_FILES = [
        'admin.php',
        'app/Auth.php',
        'app/Database.php',
        'app/Config.php',
        'app/Bootstrap.php',
    ];

    public function name(): string { return 'encoding:check'; }
    public function description(): string { return 'Verifica BOM e UTF-8 nos arquivos PHP criticos.'; }
    public function group(): string { return 'sistema'; }
    public function permission(): string { return 'admin.console'; }
    public function isDestructive(): bool { return false; }
    public function requiresConfirmation(): bool { return false; }

    public function validateArgs(array $args): ?string { return null; }

    public function run(AdminCommandContext $context, array $args = []): AdminCommandResult
    {
        $rows = [];
        $warnings = [];

        foreach (self::CRITICAL_FILES as $relativePath) {
            $fullPath = Bootstrap::rootPath($relativePath);

            if (!is_file($fullPath)) {
                $rows[] = [$relativePath, 'ausente', '-', '-'];
                continue;
            }

            // Read only first 4 bytes to check BOM — no shell, no secret exposure
            $handle = @fopen($fullPath, 'rb');
            if ($handle === false) {
                $rows[] = [$relativePath, 'ilegivel', '-', '-'];
                $warnings[] = "Nao foi possivel abrir {$relativePath}.";
                continue;
            }

            $header = (string) fread($handle, 4);
            fclose($handle);

            $hasBom = str_starts_with($header, "\xEF\xBB\xBF");
            $startsWithPhp = str_contains($header, '<?');
            $status = $hasBom ? 'BOM detectado' : 'ok';

            if ($hasBom) {
                $warnings[] = "Arquivo {$relativePath} possui BOM UTF-8. Pode causar problemas de header.";
            }

            $rows[] = [
                $relativePath,
                $status,
                $hasBom ? 'sim' : 'nao',
                $startsWithPhp ? 'sim' : 'nao',
            ];
        }

        $tables = [[
            'title'   => 'Verificacao de encoding',
            'headers' => ['Arquivo', 'Status', 'BOM detectado', 'Inicia com <?'],
            'rows'    => $rows,
        ]];

        if ($warnings !== []) {
            return AdminCommandResult::warning(
                'Verificacao de encoding',
                'Arquivos com alertas de encoding encontrados.',
                warnings: $warnings,
            )->withDurationMs(0);
        }

        $result = AdminCommandResult::ok(
            'Verificacao de encoding',
            sprintf('%d arquivo(s) verificado(s). Nenhum BOM detectado.', count(self::CRITICAL_FILES)),
            tables: $tables,
        );

        return $result;
    }
}
