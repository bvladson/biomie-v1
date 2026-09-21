<?php

declare(strict_types=1);

namespace BIomie\Support;

use BIomie\Bootstrap;
use BIomie\Config;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Onde este processo está conectado — e se é onde deveria estar.
 *
 * Em 21/09/2026 a máquina de desenvolvimento ainda apontava para o banco
 * antigo da Hostinger, congelado desde agosto: toda auditoria feita dali lia
 * dados velhos sem aviso nenhum. Agora os scripts administrativos imprimem o
 * quadro abaixo antes de rodar e abortam quando o banco conectado não é o
 * declarado em DB_EXPECTED_TARGET (production | legacy | other).
 */
final class EnvironmentGuard
{
    private const TARGET_LABELS = [
        ProductionDatabaseGuard::TARGET_PRODUCTION => 'PRODUÇÃO (VPS)',
        ProductionDatabaseGuard::TARGET_LEGACY => 'LEGADO CONGELADO (Hostinger)',
        ProductionDatabaseGuard::TARGET_OTHER => 'não protegido (local/teste)',
    ];

    /**
     * @return array{environment:string,expected_target:string,target:string,database:string,server:string,user:string,read_only:bool,code:string}
     */
    public static function describe(PDO $pdo, Config $config): array
    {
        $row = $pdo->query('SELECT DATABASE(), @@hostname, CURRENT_USER()')->fetch(PDO::FETCH_NUM) ?: [];
        $database = (string) ($row[0] ?? '');
        $server = (string) ($row[1] ?? '');

        return [
            'environment' => (string) $config->get('app.env', ''),
            'expected_target' => strtolower(trim((string) $config->get('db.expected_target', ''))),
            'target' => ProductionDatabaseGuard::classify($database, $server),
            'database' => $database,
            'server' => $server,
            'user' => (string) ($row[2] ?? ''),
            'read_only' => self::isReadOnly($pdo),
            'code' => self::codeIdentity(),
        ];
    }

    /**
     * @param array{environment:string,expected_target:string,target:string,database:string,server:string,user:string,read_only:bool,code:string} $info
     */
    public static function banner(array $info): string
    {
        $expected = $info['expected_target'];
        $check = $expected === '' ? '(não declarado)' : ($expected === $info['target'] ? $expected . ' ✓' : $expected . ' ✗');

        return implode(PHP_EOL, [
            '┌─ BIomie ─────────────────────────────────────────────',
            '│ Ambiente : ' . ($info['environment'] !== '' ? $info['environment'] : '?'),
            '│ Banco    : ' . $info['database'] . ' @ ' . $info['server'] . ' — '
                . (self::TARGET_LABELS[$info['target']] ?? $info['target']),
            '│ Usuário  : ' . $info['user'] . ($info['read_only'] ? ' (somente leitura)' : ' (com escrita)'),
            '│ Esperado : ' . $check,
            '│ Código   : ' . $info['code'],
            '└──────────────────────────────────────────────────────',
        ]);
    }

    /**
     * Aborta quando há alvo esperado e ele não confere. Sem alvo declarado
     * (nem na config nem em --expect) só o quadro é impresso.
     *
     * @param array{target:string,database:string,server:string,expected_target:string} $info
     */
    public static function assertExpected(array $info, ?string $expectedOverride = null): void
    {
        $expected = strtolower(trim($expectedOverride ?? $info['expected_target']));
        if ($expected === '' || $expected === $info['target']) {
            return;
        }

        throw new RuntimeException(sprintf(
            'AMBIENTE ERRADO: esperado "%s", mas a conexão é "%s" (%s @ %s). Nada foi executado.',
            $expected,
            $info['target'],
            $info['database'],
            $info['server'],
        ));
    }

    private static function isReadOnly(PDO $pdo): bool
    {
        try {
            $grants = $pdo->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable) {
            return false;
        }

        foreach ($grants as $grant) {
            if (preg_match('/\b(ALL PRIVILEGES|INSERT|UPDATE|DELETE|CREATE|DROP|ALTER)\b/i', (string) $grant) === 1) {
                return false;
            }
        }

        return $grants !== [];
    }

    /** Release em produção (release.json); commit do git no desenvolvimento. */
    private static function codeIdentity(): string
    {
        $label = ReleaseInfo::label();
        if ($label !== '') {
            return $label;
        }

        $sha = self::gitHead(Bootstrap::rootPath('.git'));

        return $sha !== null ? 'dev • git ' . substr($sha, 0, 7) : 'dev';
    }

    private static function gitHead(string $gitDir): ?string
    {
        $head = @file_get_contents($gitDir . '/HEAD');
        if (!is_string($head)) {
            return null;
        }

        $head = trim($head);
        if (!str_starts_with($head, 'ref: ')) {
            return preg_match('/^[0-9a-f]{40}$/', $head) === 1 ? $head : null;
        }

        $ref = substr($head, 5);
        $loose = @file_get_contents($gitDir . '/' . $ref);
        if (is_string($loose) && preg_match('/^[0-9a-f]{40}/', trim($loose), $match) === 1) {
            return $match[0];
        }

        $packed = @file_get_contents($gitDir . '/packed-refs');
        if (is_string($packed) && preg_match('/^([0-9a-f]{40}) ' . preg_quote($ref, '/') . '$/m', $packed, $match) === 1) {
            return $match[1];
        }

        return null;
    }
}
