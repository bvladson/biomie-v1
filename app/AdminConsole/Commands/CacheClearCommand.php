<?php

declare(strict_types=1);

namespace BIomie\AdminConsole\Commands;

use BIomie\AdminConsole\AdminCommandContext;
use BIomie\AdminConsole\AdminCommandInterface;
use BIomie\AdminConsole\AdminCommandResult;
use Throwable;

/**
 * Apaga todos os arquivos .json e .tmp do diretório de cache da aplicação.
 * Útil quando dados stale aparecem nos dashboards após uma atualização de dados.
 */
final class CacheClearCommand implements AdminCommandInterface
{
    public function __construct(private readonly string $cacheDir) {}

    public function name(): string        { return 'cache:clear'; }
    public function description(): string { return 'Apaga todos os arquivos de cache da aplicacao (storage/cache). Use quando dashboards exibirem dados desatualizados.'; }
    public function group(): string       { return 'sistema'; }
    public function permission(): string  { return 'admin.console'; }
    public function isDestructive(): bool { return false; }
    public function requiresConfirmation(): bool { return false; }

    public function validateArgs(array $args): ?string { return null; }

    public function run(AdminCommandContext $context, array $args = []): AdminCommandResult
    {
        $startedAt = microtime(true);
        $dir       = rtrim($this->cacheDir, '/\\');

        if (!is_dir($dir)) {
            return AdminCommandResult::ok(
                'Cache Clear',
                "Diretório de cache não existe: {$dir}. Nada a fazer.",
            );
        }

        $deleted = 0;
        $errors  = 0;
        $lines   = [];

        try {
            $files = glob($dir . '/*.json') ?: [];
            $files = array_merge($files, glob($dir . '/*.tmp') ?: []);
            $files = array_merge($files, glob($dir . '/*.lock') ?: []);

            foreach ($files as $file) {
                try {
                    if (@unlink($file)) {
                        $deleted++;
                        $lines[] = 'Removido: ' . basename($file);
                    } else {
                        $errors++;
                        $lines[] = 'ERRO ao remover: ' . basename($file);
                    }
                } catch (Throwable) {
                    $errors++;
                }
            }
        } catch (Throwable $e) {
            return AdminCommandResult::error(
                'Cache Clear',
                'Falha ao listar arquivos de cache: ' . $e->getMessage(),
            );
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        $message = $errors === 0
            ? "{$deleted} arquivo(s) removidos do cache."
            : "{$deleted} removidos, {$errors} erro(s).";

        $result = $errors === 0
            ? AdminCommandResult::ok('Cache Clear', $message, outputLines: $lines, metrics: ['arquivos_removidos' => $deleted])
            : AdminCommandResult::warning('Cache Clear', $message, outputLines: $lines, warnings: ["{$errors} arquivo(s) não puderam ser removidos."]);

        return $result->withDurationMs($durationMs);
    }
}
