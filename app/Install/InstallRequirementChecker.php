<?php

declare(strict_types=1);

namespace BIomie\Install;

final class InstallRequirementChecker
{
    /**
     * @return array{rows:list<array<string,string|bool>>,blocking:bool}
     */
    public function check(): array
    {
        $rows = [];
        $this->add($rows, 'PHP', PHP_VERSION, '8.1+', version_compare(PHP_VERSION, '8.1.0', '>='), true, 'Atualize a versao do PHP.');

        foreach (['pdo', 'pdo_mysql', 'mbstring', 'json', 'curl', 'openssl', 'fileinfo'] as $extension) {
            $this->add(
                $rows,
                'Extensao ' . $extension,
                extension_loaded($extension) ? 'ativa' : 'ausente',
                'ativa',
                extension_loaded($extension),
                true,
                'Ative a extensao no painel da hospedagem.'
            );
        }

        foreach (['intl', 'zip'] as $extension) {
            $this->add(
                $rows,
                'Extensao ' . $extension,
                extension_loaded($extension) ? 'ativa' : 'ausente',
                'recomendada',
                extension_loaded($extension),
                false,
                'Ative se usar importacao, backup ou formatacao avancada.'
            );
        }

        $this->add($rows, 'Sessoes PHP', session_status() !== PHP_SESSION_DISABLED ? 'ativas' : 'desativadas', 'ativas', session_status() !== PHP_SESSION_DISABLED, true, 'Habilite session no PHP.');
        $this->add($rows, 'Timezone', date_default_timezone_get() ?: 'nao definido', 'definido', date_default_timezone_get() !== '', false, 'Configure APP_TIMEZONE ou date.timezone.');

        $memoryLimit = ini_get('memory_limit') ?: '';
        $memoryOk = $this->bytesFromIni($memoryLimit) === -1 || $this->bytesFromIni($memoryLimit) >= 128 * 1024 * 1024;
        $this->add($rows, 'memory_limit', $memoryLimit, '128M recomendado', $memoryOk, false, 'Use 128M ou mais para migrations maiores.');

        $maxExecution = (int) (ini_get('max_execution_time') ?: 0);
        $timeOk = $maxExecution === 0 || $maxExecution >= 60;
        $this->add($rows, 'max_execution_time', (string) $maxExecution, '60s recomendado', $timeOk, false, 'Use 60s ou mais durante instalacao.');

        return [
            'rows' => $rows,
            'blocking' => $this->hasBlockingError($rows),
        ];
    }

    /**
     * @param list<array<string,string|bool>> $rows
     */
    private function add(array &$rows, string $name, string $found, string $required, bool $ok, bool $mandatory, string $action): void
    {
        $rows[] = [
            'name' => $name,
            'found' => $found,
            'required' => $required,
            'status' => $ok ? 'OK' : ($mandatory ? 'Erro' : 'Atencao'),
            'mandatory' => $mandatory,
            'ok' => $ok,
            'action' => $ok ? 'Tudo certo' : $action,
        ];
    }

    /**
     * @param list<array<string,string|bool>> $rows
     */
    private function hasBlockingError(array $rows): bool
    {
        foreach ($rows as $row) {
            if (($row['mandatory'] ?? false) === true && ($row['ok'] ?? false) !== true) {
                return true;
            }
        }

        return false;
    }

    private function bytesFromIni(string $value): int
    {
        $value = trim($value);
        if ($value === '-1') {
            return -1;
        }

        $unit = strtolower(substr($value, -1));
        $number = (int) $value;
        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
