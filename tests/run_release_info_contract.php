<?php

declare(strict_types=1);

/**
 * Contrato da identidade de release: o deploy grava config/release.json e o
 * app o lê para o rodapé ("v2026.09.21.1 • 74c9a28") e os envelopes de erro.
 * Existe porque os hotfixes de 08–09/09/2026 subiram sem commit rastreável.
 */

use BIomie\Support\ReleaseInfo;

$root = dirname(__DIR__);
require_once $root . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

$checks = [];
$check = static function (string $id, bool $ok, mixed $actual = null) use (&$checks): void {
    $checks[] = ['id' => $id, 'ok' => $ok, 'actual' => $actual];
};

$tmp = sys_get_temp_dir() . '/biomie-release-info-' . bin2hex(random_bytes(4)) . '.json';
$write = static function (array $data) use ($tmp): string {
    file_put_contents($tmp, json_encode($data));
    return $tmp;
};

$valid = ReleaseInfo::fromFile($write([
    'release' => '2026.09.21.1',
    'commit' => 'D3CFB0E1234567890ABCDEF1234567890ABCDEF',
    'deployed_at' => '2026-09-21T10:00:00-03:00',
    'release_dir' => '20260921_100000',
]));
$check(
    'valid_file_is_parsed_and_normalized',
    $valid !== null
        && $valid['release'] === '2026.09.21.1'
        && $valid['commit_short'] === 'd3cfb0e',
    $valid,
);
$check(
    'missing_file_yields_null',
    ReleaseInfo::fromFile($tmp . '.inexistente') === null,
);
$check(
    'non_hex_commit_is_rejected',
    ReleaseInfo::fromFile($write(['release' => '2026.09.21.1', 'commit' => 'hotfix-x'])) === null,
);
$check(
    'malformed_release_is_rejected',
    ReleaseInfo::fromFile($write(['release' => '20260921', 'commit' => 'd3cfb0e'])) === null,
);
file_put_contents($tmp, '{nao e json');
$check('invalid_json_is_rejected', ReleaseInfo::fromFile($tmp) === null);
@unlink($tmp);

$remote = (string) file_get_contents($root . '/tools/deploy/vps/remote_deploy.sh');
$local = (string) file_get_contents($root . '/tools/deploy/vps/deploy.sh');
$check(
    'deploy_passes_full_commit',
    str_contains($local, 'COMMIT_FULL="$(git rev-parse "$REF")"')
        && str_contains($local, "'\$REMOTE_TAR' '\$COMMIT_FULL'"),
);
$check(
    'remote_deploy_writes_release_json',
    str_contains($remote, 'cat > "$REL/config/release.json"')
        && str_contains($remote, '"commit": "$COMMIT_FULL"'),
);
$check(
    'release_counter_advances_only_after_smoke',
    preg_match('/smoke OK.*?printf \'%s %s\\\\n\' "\$DAY" "\$SEQ" > "\$SEQ_FILE"/s', $remote) === 1,
);
$check(
    'sidebar_uses_release_label',
    str_contains((string) file_get_contents($root . '/app/PageRenderer.php'), 'ReleaseInfo::label()'),
);

$failed = array_values(array_filter($checks, static fn (array $row): bool => !$row['ok']));
$result = [
    'ok' => $failed === [],
    'passed' => count($checks) - count($failed),
    'total' => count($checks),
    'checks' => $checks,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($result['ok'] ? 0 : 1);
