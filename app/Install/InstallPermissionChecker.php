<?php

declare(strict_types=1);

namespace BIomie\Install;

use BIomie\Bootstrap;

final class InstallPermissionChecker
{
    /**
     * @return array{rows:list<array<string,string|bool>>,blocking:bool}
     */
    public function check(): array
    {
        $rows = [];
        $this->path($rows, 'config/', true, true, true, 'Necessario para gravar installed.lock e configuracao local.');
        $this->path($rows, 'storage/', true, true, true, 'Necessario para cache e logs.');
        $this->path($rows, 'storage/logs/', true, true, false, 'Recomendado para gravar install.log.');
        $this->path($rows, 'storage/cache/', true, true, false, 'Recomendado para cache operacional.');
        $this->path($rows, 'database/migrations/', true, false, true, 'Necessario para ler migrations.');

        if (is_dir(Bootstrap::rootPath('uploads'))) {
            $this->path($rows, 'uploads/', true, true, false, 'Necessario apenas se uploads forem usados.');
        }
        if (is_dir(Bootstrap::rootPath('public/uploads'))) {
            $this->path($rows, 'public/uploads/', true, true, false, 'Necessario apenas se uploads publicos forem usados.');
        }

        $tempDir = sys_get_temp_dir();
        $tempOk = is_dir($tempDir) && is_writable($tempDir);
        $rows[] = [
            'name' => 'PHP temp',
            'found' => $tempOk ? 'gravavel' : 'indisponivel',
            'path' => 'PHP temp',
            'exists' => is_dir($tempDir),
            'readable' => is_readable($tempDir),
            'writable' => is_writable($tempDir),
            'required' => 'gravavel',
            'status' => $tempOk ? 'OK' : 'Atencao',
            'ok' => $tempOk,
            'blocking' => false,
            'action' => is_writable($tempDir) ? 'Tudo certo' : 'Revise upload_tmp_dir no PHP.',
        ];

        return [
            'rows' => $rows,
            'blocking' => $this->hasBlockingError($rows),
        ];
    }

    /**
     * @param list<array<string,string|bool>> $rows
     */
    private function path(array &$rows, string $relative, bool $mustRead, bool $mustWrite, bool $blocking, string $action): void
    {
        $absolute = Bootstrap::rootPath($relative);
        if (!is_dir($absolute) && str_ends_with($relative, '/')) {
            @mkdir($absolute, 0775, true);
        }

        $exists = file_exists($absolute);
        $readable = $exists && is_readable($absolute);
        $writable = $exists && is_writable($absolute);
        $ok = $exists && (!$mustRead || $readable) && (!$mustWrite || $writable);

        $rows[] = [
            'name' => rtrim($relative, '/'),
            'found' => $exists
                ? ($writable ? 'legivel e gravavel' : ($readable ? 'legivel' : 'existe sem leitura'))
                : 'ausente',
            'path' => rtrim($relative, '/'),
            'exists' => $exists,
            'readable' => $readable,
            'writable' => $writable,
            'required' => $mustWrite ? 'leitura e escrita' : 'leitura',
            'status' => $ok ? 'OK' : ($blocking ? 'Erro' : 'Atencao'),
            'ok' => $ok,
            'blocking' => $blocking && !$ok,
            'action' => $ok ? 'Tudo certo' : $action . ' Nao use chmod 777; ajuste dono/grupo e permissoes minimas.',
        ];
    }

    /**
     * @param list<array<string,string|bool>> $rows
     */
    private function hasBlockingError(array $rows): bool
    {
        foreach ($rows as $row) {
            if (($row['blocking'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }
}
