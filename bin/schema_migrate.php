<?php

declare(strict_types=1);

use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Logger;
use BIomie\Migrations\MigrationRunner;

require_once dirname(__DIR__) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

require_once dirname(__DIR__) . '/app/Bootstrap.php';
Bootstrap::init();

$config = new Config();
$logger = new Logger(dirname(__DIR__) . '/storage/logs/biomie.log');
$runner = new MigrationRunner(
    $config,
    $logger,
    dirname(__DIR__) . '/' . trim((string) $config->get('migrations.path', 'database/migrations'), '/'),
    (string) $config->get('migrations.table', 'biomie_schema_migrations'),
    (string) $config->get('app.version', (string) $config->get('migrations.app_version', 'dev'))
);

try {
    $argv = $_SERVER['argv'] ?? [];
    $command = strtolower((string) ($argv[1] ?? 'status'));
    $options = parseOptions(array_slice($argv, 2));

    $result = match ($command) {
        'status' => [
            'ok' => true,
            'command' => 'status',
            'result' => $runner->statusWithDiagnostics(),
        ],
        'migrate', 'up' => [
            'ok' => true,
            'command' => 'migrate',
            'target' => $options['target'],
            'result' => $runner->migrate($options['target']),
        ],
        'migrate-one' => [
            'ok' => true,
            'command' => 'migrate-one',
            'result' => migrateOneGuarded($runner, $options),
        ],
        'rollback', 'down' => [
            'ok' => true,
            'command' => 'rollback',
            'version' => $options['version'],
            'result' => $runner->rollback($options['version']),
        ],
        'verify' => buildVerifyResponse($runner->verifyChecksums()),
        'checksum-diagnose' => [
            'ok' => true,
            'command' => 'checksum-diagnose',
            'result' => $runner->checksumDiagnose(),
        ],
        'repair-checksums' => [
            'ok' => true,
            'command' => 'repair-checksums',
            'mode' => 'dry-run',
            'result' => $runner->repairChecksumsDryRun($options['reason'], (bool) $options['apply']),
        ],
        'help', '--help', '-h' => [
            'ok' => true,
            'command' => 'help',
            'usage' => [
                'php bin/schema_migrate.php status',
                'php bin/schema_migrate.php migrate',
                'php bin/schema_migrate.php migrate --target=20260413050300',
                'php bin/schema_migrate.php migrate-one --version=20260718120000',
                'php bin/schema_migrate.php migrate-one --version=... --allow-out-of-order',
                'php bin/schema_migrate.php rollback',
                'php bin/schema_migrate.php rollback --version=20260413050500',
                'php bin/schema_migrate.php verify',
                'php bin/schema_migrate.php checksum-diagnose',
                'php bin/schema_migrate.php repair-checksums --dry-run --reason="normalizar checksum v2 path-independent apos STAB.R3.1c"',
            ],
        ],
        default => throw new RuntimeException('Comando invalido. Use status, migrate, migrate-one, rollback, verify, checksum-diagnose ou repair-checksums.'),
    };

    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;

    if (($result['ok'] ?? true) !== true) {
        exit(2);
    }
} catch (Throwable $exception) {
    $logger->error('Falha ao executar runner de migrations.', [
        'message' => $exception->getMessage(),
        'trace' => $exception->getTraceAsString(),
    ]);

    echo json_encode([
        'ok' => false,
        'error' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;

    exit(1);
}

/**
 * @param list<string> $args
 * @return array{target:?string,version:?string,reason:?string,dry_run:bool,apply:bool}
 */
function parseOptions(array $args): array
{
    $options = [
        'target' => null,
        'version' => null,
        'reason' => null,
        'dry_run' => true,
        'apply' => false,
        'allow_out_of_order' => false,
    ];

    foreach ($args as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }

        [$key, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, null);
        if ($key === 'target' && is_string($value) && $value !== '') {
            $options['target'] = $value;
            continue;
        }

        if ($key === 'version' && is_string($value) && $value !== '') {
            $options['version'] = $value;
            continue;
        }

        if ($key === 'reason' && is_string($value) && $value !== '') {
            $options['reason'] = $value;
            continue;
        }

        if ($key === 'dry-run') {
            $options['dry_run'] = true;
            continue;
        }

        if ($key === 'apply') {
            $options['apply'] = true;
        }

        if ($key === 'allow-out-of-order') {
            $options['allow_out_of_order'] = true;
        }
    }

    return $options;
}

/**
 * Aplica UMA migration específica, com guardas.
 *
 * Existe porque `migrate` aplica TODAS as pendentes e `--target` aplica tudo ATÉ
 * a versão — ambos arrastariam migrations de outros fluxos de trabalho que ainda
 * estejam em andamento. Execução individual é operação normal em ferramentas
 * maduras (Doctrine `execute`, Flyway cherry-pick), mas exige ser explícita.
 *
 * Guardas:
 *   · versão COMPLETA e exata (sem alias, prefixo ou target)
 *   · sem --allow-out-of-order, aborta se houver migrations ANTERIORES pendentes
 *   · recusa versão inexistente, já aplicada ou com checksum divergente
 *   · compara a tabela de controle antes/depois: só 1 registro novo é aceito
 *
 * @param array<string,mixed> $options
 * @return array<string,mixed>
 */
function migrateOneGuarded(MigrationRunner $runner, array $options): array
{
    $version = (string) ($options['version'] ?? '');
    if ($version === '' || preg_match('/^\d{14}$/', $version) !== 1) {
        throw new RuntimeException(
            'Informe --version=<14 digitos> completa e exata. Alias, prefixo ou target nao sao aceitos.'
        );
    }

    $status = $runner->status();
    $alvo = null;
    $pendentesAnteriores = [];
    $pendentesTotais = [];

    foreach ($status['migrations'] as $m) {
        $v = (string) $m['version'];
        if ($v === $version) {
            $alvo = $m;
            continue;
        }
        if (($m['status'] ?? '') === 'pending') {
            $pendentesTotais[] = $v;
            if (strcmp($v, $version) < 0) {
                $pendentesAnteriores[] = $v;
            }
        }
    }

    if ($alvo === null) {
        throw new RuntimeException("Migration {$version} nao encontrada.");
    }
    if (($alvo['status'] ?? '') === 'applied') {
        throw new RuntimeException("Migration {$version} ja esta aplicada.");
    }
    if (($alvo['checksum_match'] ?? true) !== true) {
        throw new RuntimeException("Checksum divergente na migration {$version}. Rode verify antes.");
    }
    if ($pendentesAnteriores !== [] && !$options['allow_out_of_order']) {
        throw new RuntimeException(sprintf(
            'Existem %d migration(s) ANTERIORES pendentes (%s). Use --allow-out-of-order para aplicar %s fora de ordem, conscientemente.',
            count($pendentesAnteriores), implode(', ', $pendentesAnteriores), $version
        ));
    }

    // Retrato ANTES: nenhuma outra versao pode aparecer depois.
    $antes = [];
    foreach ($runner->status()['migrations'] as $m) {
        if (($m['status'] ?? '') === 'applied') { $antes[(string) $m['version']] = true; }
    }

    $result = $runner->migrateOne($version);

    $depois = [];
    foreach ($runner->status()['migrations'] as $m) {
        if (($m['status'] ?? '') === 'applied') { $depois[(string) $m['version']] = true; }
    }
    // array_keys() devolve INT quando a chave e' uma string numerica: PHP
    // converte "20260718120000" em 20260718120000 ao usa-la como chave. Sem o
    // strval(), a comparacao estrita abaixo falharia SEMPRE — inclusive no
    // caminho de sucesso, exatamente como ocorreu no deploy de 2026-07-20.
    $novas = array_map('strval', array_values(array_diff(array_keys($depois), array_keys($antes))));

    if ($novas !== [$version]) {
        throw new RuntimeException(
            'INESPERADO: registros novos na tabela de controle = ' . json_encode($novas)
            . " (esperado apenas {$version}). Investigue antes de seguir."
        );
    }

    // As pendentes alheias devem CONTINUAR pendentes e reconheciveis.
    $aindaPendentes = [];
    foreach ($runner->status()['migrations'] as $m) {
        if (($m['status'] ?? '') === 'pending') { $aindaPendentes[] = (string) $m['version']; }
    }
    $esperadas = array_values(array_diff($pendentesTotais, [$version]));
    sort($esperadas); sort($aindaPendentes);

    return [
        'version' => $version,
        'description' => $alvo['description'] ?? null,
        'class_file' => $alvo['file_basename'] ?? null,
        'checksum' => $alvo['checksum'] ?? null,
        'connection' => $alvo['actual_connection'] ?? null,
        'out_of_order' => $pendentesAnteriores !== [],
        'skipped_pending' => $pendentesAnteriores,
        'applied' => $result['applied'],
        'control_table_new_rows' => $novas,
        'other_pending_before' => $esperadas,
        'other_pending_after' => $aindaPendentes,
        'other_pending_intact' => $esperadas === $aindaPendentes,
    ];
}

/**
 * @param array{verified:list<array<string,mixed>>,mismatches:list<array<string,mixed>>} $result
 * @return array<string,mixed>
 */
function buildVerifyResponse(array $result): array
{
    return [
        'ok' => $result['mismatches'] === [],
        'command' => 'verify',
        'verified_count' => count($result['verified']),
        'mismatch_count' => count($result['mismatches']),
        'result' => $result,
    ];
}
