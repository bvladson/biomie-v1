<?php

declare(strict_types=1);

namespace BIomie\Support;

use RuntimeException;

final class EnvWriter
{
    /**
     * @param array<string,string> $values
     * @return array{written:bool,backup:?string,path:string}
     */
    public function writeLocalPhp(string $path, array $values, bool $backupExisting = true): array
    {
        $existing = [];
        $backup = null;

        if (is_file($path)) {
            $loaded = include $path;
            if (is_array($loaded)) {
                foreach ($loaded as $key => $value) {
                    if (is_scalar($value) || $value === null) {
                        $existing[(string) $key] = (string) $value;
                    }
                }
            }

            if ($backupExisting) {
                $backup = $path . '.backup.' . date('YmdHis');
                if (!copy($path, $backup)) {
                    throw new RuntimeException('Nao foi possivel criar backup da configuracao existente.');
                }
            }
        }

        $merged = array_merge($existing, $values);
        $content = "<?php\n\nreturn " . var_export($merged, true) . ";\n";
        if (file_put_contents($path, $content, LOCK_EX) === false) {
            throw new RuntimeException('Nao foi possivel gravar a configuracao local.');
        }

        @chmod($path, 0640);

        return [
            'written' => true,
            'backup' => $backup,
            'path' => $path,
        ];
    }
}
