<?php

declare(strict_types=1);

namespace BIomie\Support;

use BIomie\Bootstrap;

/**
 * Identidade da release em execução, lida de `config/release.json`.
 *
 * O arquivo é gerado pelo deploy (tools/deploy/vps/remote_deploy.sh) e nunca
 * editado à mão nem versionado: ele diz qual commit está no ar. Foi a falta
 * disso que deixou os hotfixes de 08–09/09/2026 em produção sem rastro no git
 * — o `build.php` escrito à mão dizia só "hotfix-...", sem commit nenhum.
 *
 * Sem o arquivo (desenvolvimento local, release antiga) tudo devolve vazio:
 * mostrar uma versão inventada seria pior que não mostrar nada.
 */
final class ReleaseInfo
{
    /** @var array{release:string,commit:string,commit_short:string,deployed_at:string}|null */
    private static ?array $cache = null;
    private static bool $loaded = false;

    /** @return array{release:string,commit:string,commit_short:string,deployed_at:string}|null */
    public static function get(): ?array
    {
        if (!self::$loaded) {
            self::$loaded = true;
            self::$cache = self::fromFile(Bootstrap::rootPath('config/release.json'));
        }

        return self::$cache;
    }

    /**
     * Rótulo curto para a interface: "v2026.09.21.1 • 74c9a28".
     */
    public static function label(): string
    {
        $info = self::get();
        if ($info === null) {
            return '';
        }

        return 'v' . $info['release'] . ' • ' . $info['commit_short'];
    }

    public static function commitShort(): ?string
    {
        return self::get()['commit_short'] ?? null;
    }

    /**
     * Lê e valida o arquivo. Qualquer campo fora do formato invalida o todo:
     * um commit que não é hex, por exemplo, mandaria comparar um incidente com
     * um commit que não existe.
     *
     * @return array{release:string,commit:string,commit_short:string,deployed_at:string}|null
     */
    public static function fromFile(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            return null;
        }

        $release = is_string($data['release'] ?? null) ? trim($data['release']) : '';
        $commit = is_string($data['commit'] ?? null) ? strtolower(trim($data['commit'])) : '';
        $deployedAt = is_string($data['deployed_at'] ?? null) ? trim($data['deployed_at']) : '';

        if (preg_match('/^\d{4}\.\d{2}\.\d{2}\.\d{1,3}$/', $release) !== 1
            || preg_match('/^[0-9a-f]{7,40}$/', $commit) !== 1
        ) {
            return null;
        }

        return [
            'release' => $release,
            'commit' => $commit,
            'commit_short' => substr($commit, 0, 7),
            'deployed_at' => $deployedAt,
        ];
    }
}
